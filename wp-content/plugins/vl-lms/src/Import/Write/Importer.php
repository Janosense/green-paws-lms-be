<?php

declare(strict_types=1);

namespace VL\LMS\Import\Write;

use RuntimeException;
use Throwable;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Plan\CoursePlan;
use VL\LMS\Import\Plan\ImportPlan;
use VL\LMS\Import\Plan\LessonPlan;
use VL\LMS\Import\Plan\QuizPlan;

/**
 * Writes an {@see ImportPlan} as a new draft course tree: `vl_course` →
 * terms → each `vl_module` with its `vl_lesson`s and `vl_quiz` →
 * course-direct lessons → the final exam; every quiz followed by its
 * `vl_quiz_question`s. Parents are always created before their children.
 *
 * Writes go through WordPress core functions only, in the shapes
 * `docs/DATA-MODEL.md` documents (course-import FEATURE.md → Data):
 *
 * - Every post is a `draft` authored by the chosen lead instructor — child
 *   posts too, because instructors edit only what they author — and carries
 *   `_vl_import_id` in `meta_input`, so the marker is there before any
 *   `save_post` hook runs.
 * - Post data is slashed: WordPress unslashes post fields and meta values.
 * - Body HTML passes `wp_kses_post()` here, because `wp_insert_post()` skips
 *   kses for administrators.
 * - The course slug is made unique the way WordPress would on publish;
 *   WordPress itself never suffixes a draft slug (`docs/DECISIONS.md`
 *   2026-09-11).
 *
 * Every created post is recorded in an {@see ImportLedger}; the first failure
 * — a `WP_Error` or any exception — rolls the ledger back and nothing is
 * kept but the taxonomy terms (`docs/DECISIONS.md` 2026-09-11 — compensating
 * deletes).
 *
 * @author Tymofii Synianskyi
 */
final class Importer {

	public const SLUG_CHANGED       = 'import.slug_changed';
	public const DIFFICULTY_MISSING = 'import.difficulty_missing';

	private const IMPORT_ID_META     = '_vl_import_id';
	private const IMPORT_SOURCE_META = '_vl_import_source';

	private const COURSE_POST_TYPE   = 'vl_course';
	private const MODULE_POST_TYPE   = 'vl_module';
	private const LESSON_POST_TYPE   = 'vl_lesson';
	private const QUIZ_POST_TYPE     = 'vl_quiz';
	private const QUESTION_POST_TYPE = 'vl_quiz_question';

	private const DIFFICULTY_TAXONOMY = 'vl_difficulty';
	private const CATEGORY_TAXONOMY   = 'vl_category';
	private const TAG_TAXONOMY        = 'vl_tag';

	/**
	 * What every imported course is (`docs/DECISIONS.md` 2026-09-11 — scope of the importer v1).
	 */
	private const COURSE_TYPE     = 'self_paced';
	private const COMPLETION_MODE = 'free';
	private const CURRENCY        = 'UAH';

	/**
	 * Every imported question is worth one point.
	 */
	private const QUESTION_POINTS = 1;

	public function run( ImportPlan $plan, ImportContext $context ): ImportResult {
		$ledger = new ImportLedger();
		$issues = new IssueList();
		foreach ( $plan->issues->all() as $issue ) {
			$issues->add( $issue );
		}

		try {
			$course_id = $this->create_course( $plan, $context, $ledger, $issues );
			$this->assign_terms( $course_id, $plan->course, $issues );

			foreach ( $plan->modules as $module ) {
				$module_id = $this->insert(
					$ledger,
					$context,
					[
						'post_type'    => self::MODULE_POST_TYPE,
						'post_title'   => $module->title,
						'post_content' => wp_kses_post( $module->html ),
						'post_parent'  => $course_id,
						'menu_order'   => $module->menu_order,
					]
				);

				$this->create_lessons( $module->lessons, $module_id, $context, $ledger );

				if ( null !== $module->quiz ) {
					$this->create_quiz( $module->quiz, $module_id, $plan->course->pass_percent, $context, $ledger );
				}
			}

			$this->create_lessons( $plan->lessons, $course_id, $context, $ledger );
			$this->create_quiz( $plan->final_quiz, $course_id, $plan->course->pass_percent, $context, $ledger );
		} catch ( Throwable $error ) {
			return ImportResult::failed( $error->getMessage(), $ledger->rollback() );
		}

		return ImportResult::created( $course_id, $ledger->summary(), $issues );
	}

	private function create_course( ImportPlan $plan, ImportContext $context, ImportLedger $ledger, IssueList $issues ): int {
		$course = $plan->course;
		$source = $plan->source;
		$slug   = wp_unique_post_slug( $course->slug, 0, 'publish', self::COURSE_POST_TYPE, 0 );

		if ( $slug !== $course->slug ) {
			$issues->add(
				ImportIssue::warning(
					self::SLUG_CHANGED,
					null,
					/* translators: 1: the slug from the file; 2: the unique slug the course received */
					sprintf( __( 'Курс зі slug «%1$s» на сайті вже є, тому новий курс отримав slug «%2$s».', 'vl-lms' ), $course->slug, $slug )
				)
			);
		}

		$meta = [
			'_vl_course_type'              => self::COURSE_TYPE,
			'_vl_course_completion_mode'   => self::COMPLETION_MODE,
			'_vl_course_currency'          => self::CURRENCY,
			'_vl_course_passing_threshold' => $course->pass_percent,
		];
		if ( null !== $course->duration_hours ) {
			$meta['_vl_course_duration_hours'] = $course->duration_hours;
		}
		$meta[ self::IMPORT_SOURCE_META ] = [
			'slug'         => $source->slug,
			'version'      => $source->version,
			'status'       => $source->status,
			'author'       => $source->author,
			'author_org'   => $source->author_org,
			'source_type'  => $source->source_type,
			'source_title' => $source->source_title,
			'source_date'  => $source->source_date,
			'imported_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() ),
			'imported_by'  => $context->imported_by,
		];

		return $this->insert(
			$ledger,
			$context,
			[
				'post_type'    => self::COURSE_POST_TYPE,
				'post_title'   => $course->title,
				'post_name'    => $slug,
				'post_content' => wp_kses_post( $course->html ),
			],
			$meta
		);
	}

	/**
	 * `vl_difficulty` is a closed vocabulary: a missing term is a warning and
	 * the course gets none. Categories and tags are created when missing.
	 */
	private function assign_terms( int $course_id, CoursePlan $course, IssueList $issues ): void {
		$difficulty = term_exists( $course->difficulty_slug, self::DIFFICULTY_TAXONOMY );

		if ( empty( $difficulty ) ) {
			$issues->add(
				ImportIssue::warning(
					self::DIFFICULTY_MISSING,
					null,
					/* translators: %s: vl_difficulty term slug, e.g. "advanced" */
					sprintf( __( 'Рівня складності «%s» на сайті немає, тому курс створено без рівня складності.', 'vl-lms' ), $course->difficulty_slug )
				)
			);
		} else {
			$this->set_terms( $course_id, [ $this->term_id( $difficulty ) ], self::DIFFICULTY_TAXONOMY );
		}

		if ( null !== $course->category_slug ) {
			$this->set_terms( $course_id, [ $this->ensure_term( $course->category_slug, self::CATEGORY_TAXONOMY ) ], self::CATEGORY_TAXONOMY );
		}

		if ( [] !== $course->tag_slugs ) {
			$term_ids = [];
			foreach ( $course->tag_slugs as $slug ) {
				$term_ids[] = $this->ensure_term( $slug, self::TAG_TAXONOMY );
			}
			$this->set_terms( $course_id, $term_ids, self::TAG_TAXONOMY );
		}
	}

	private function ensure_term( string $slug, string $taxonomy ): int {
		$existing = term_exists( $slug, $taxonomy );
		if ( ! empty( $existing ) ) {
			return $this->term_id( $existing );
		}

		$created = wp_insert_term( wp_slash( $slug ), $taxonomy, [ 'slug' => $slug ] );
		if ( is_wp_error( $created ) ) {
			$this->fail( $created->get_error_message() );
		}

		return (int) $created['term_id'];
	}

	/**
	 * @param list<int> $term_ids
	 */
	private function set_terms( int $course_id, array $term_ids, string $taxonomy ): void {
		$result = wp_set_object_terms( $course_id, $term_ids, $taxonomy );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
	}

	/**
	 * @param mixed $term A `term_exists()` hit: an array with `term_id`, or the id itself.
	 */
	private function term_id( mixed $term ): int {
		return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}

	/**
	 * @param list<LessonPlan> $lessons
	 */
	private function create_lessons( array $lessons, int $parent_id, ImportContext $context, ImportLedger $ledger ): void {
		foreach ( $lessons as $lesson ) {
			$this->insert(
				$ledger,
				$context,
				[
					'post_type'    => self::LESSON_POST_TYPE,
					'post_title'   => $lesson->title,
					'post_content' => wp_kses_post( $lesson->html ),
					'post_parent'  => $parent_id,
					'menu_order'   => $lesson->menu_order,
				]
			);
		}
	}

	/**
	 * A quiz gets the course pass percent; only «Підсумковий тест» is the final
	 * exam. Progression flags are never set (course-import FEATURE.md → Invariants).
	 */
	private function create_quiz( QuizPlan $quiz, int $parent_id, int $pass_percent, ImportContext $context, ImportLedger $ledger ): void {
		$meta = [ '_vl_quiz_passing_threshold' => $pass_percent ];
		if ( $quiz->is_final_exam ) {
			$meta['_vl_quiz_is_final_exam'] = true;
		}

		$quiz_id = $this->insert(
			$ledger,
			$context,
			[
				'post_type'    => self::QUIZ_POST_TYPE,
				'post_title'   => $quiz->title,
				'post_content' => '',
				'post_parent'  => $parent_id,
			],
			$meta
		);

		foreach ( $quiz->questions as $question ) {
			$question_meta = [
				'_vl_question_type'    => $question->type,
				'_vl_question_points'  => self::QUESTION_POINTS,
				'_vl_question_answers' => array_map(
					static fn ( array $answer ): array => [
						'text'        => $answer['text'],
						'is_correct'  => $answer['is_correct'],
						'explanation' => '',
					],
					$question->answers
				),
			];
			if ( null !== $question->explanation ) {
				$question_meta['_vl_question_explanation'] = $question->explanation;
			}

			$this->insert(
				$ledger,
				$context,
				[
					'post_type'    => self::QUESTION_POST_TYPE,
					'post_title'   => $question->text,
					'post_content' => '',
					'post_parent'  => $quiz_id,
					'menu_order'   => $question->menu_order,
				],
				$question_meta
			);
		}
	}

	/**
	 * Inserts one draft post by the lead instructor, with the import marker
	 * first in its meta, and records it in the ledger.
	 *
	 * @param array{post_type: string, post_title: string, post_content: string, post_name?: string, post_parent?: int, menu_order?: int} $post
	 * @param array<string, mixed>                                                                                                        $meta
	 *
	 * @throws RuntimeException When WordPress does not create the post.
	 */
	private function insert( ImportLedger $ledger, ImportContext $context, array $post, array $meta = [] ): int {
		$postarr = array_merge(
			$post,
			[
				'post_status' => 'draft',
				'post_author' => $context->instructor_id,
				'meta_input'  => array_merge( [ self::IMPORT_ID_META => $context->token ], $meta ),
			]
		);

		$id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $id ) ) {
			$this->fail( $id->get_error_message() );
		}
		if ( 0 === $id ) {
			/* translators: %s: post type, e.g. "vl_lesson" */
			$this->fail( sprintf( __( 'WordPress не створив запис типу %s.', 'vl-lms' ), $post['post_type'] ) );
		}

		$ledger->record_post( $post['post_type'], $id, $post['post_title'] );

		return $id;
	}

	/**
	 * Aborts the run; {@see self::run()} rolls back and returns the message as the failure reason.
	 *
	 * @throws RuntimeException Always.
	 */
	private function fail( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in run() and carried as ImportResult::$reason data; the report screen escapes it.
		throw new RuntimeException( $message );
	}
}
