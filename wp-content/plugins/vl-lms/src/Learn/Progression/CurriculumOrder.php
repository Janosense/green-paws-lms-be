<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

use WP_Post;
use WP_Query;

/**
 * Builds the canonical curriculum order for a course.
 *
 * The order is the same one {@see \VL\LMS\Learn\NextEntityResolver}
 * walks and the frontend's `useLearnNavigation.flattenCurriculum`
 * mirrors:
 *
 *   1. modules in `menu_order`; per module, its lessons in `menu_order`
 *      (lesson page, then its topics, then its quizzes), then the
 *      module's own quizzes;
 *   2. course-direct "orphan" lessons, same lesson rule;
 *   3. cohort sessions in `_vl_session_scheduled_start ASC`, each
 *      followed by its quizzes;
 *   4. course-level quizzes last (the common final-exam placement).
 *
 * The lesson page is emitted as a stop even when it has topics. That
 * matches `flattenCurriculum` and is what locking needs — `/learn/{slug}`
 * is a real destination that must be lockable. It is the one place this
 * walk deliberately differs from `NextEntityResolver`, which skips such a
 * lesson as a *"what's next" candidate* in favour of its first
 * incomplete topic. Ordering is identical; only candidacy differs.
 *
 * Where the curriculum transformers fan out one query per parent, this
 * batches by level with `post_parent__in`, so a course of any size costs
 * a fixed **five** queries (four when it is not a cohort course, three
 * when no lesson has topics). That matters because the access gates call
 * this on ordinary lesson reads, which build no tree today.
 *
 * Every query is isolated in a `protected` method so unit tests can
 * subclass and bypass `WP_Query` without booting WordPress, matching the
 * seam style of {@see \VL\LMS\Learn\CurriculumTransformer}.
 *
 * @author Tymofii Synianskyi
 */
class CurriculumOrder {

	/** @var array<int, list<CurriculumStop>> */
	private array $memo = [];

	/**
	 * Modules are not stops, so `counts_for()` cannot recover their number
	 * from the stop list — `build()` records it here as a side product.
	 *
	 * @var array<int, int>
	 */
	private array $module_counts = [];

	/**
	 * Canonical stop list for a course, memoised per request.
	 *
	 * @return list<CurriculumStop>
	 */
	public function for_course( int $course_id ): array {
		if ( ! isset( $this->memo[ $course_id ] ) ) {
			$this->memo[ $course_id ] = $this->build( $course_id );
		}
		return $this->memo[ $course_id ];
	}

	/**
	 * @return list<CurriculumStop>
	 */
	private function build( int $course_id ): array {
		$modules    = $this->query_children( $course_id, 'vl_module' );
		$module_ids = $this->ids_of( $modules );

		$this->module_counts[ $course_id ] = count( $modules );

		// One query covers both module-owned lessons and course-direct
		// orphan lessons; they are bucketed by `post_parent` below.
		$lessons           = $this->query_children_of_many( [ $course_id, ...$module_ids ], 'vl_lesson' );
		$lessons_by_parent = $this->bucket_by_parent( $lessons );
		$lesson_ids        = $this->ids_of( $lessons );

		$topics_by_parent = [] === $lesson_ids
			? []
			: $this->bucket_by_parent( $this->query_children_of_many( $lesson_ids, 'vl_topic' ) );

		$sessions    = $this->is_cohort_course( $course_id )
			? $this->query_sessions( $course_id )
			: [];
		$session_ids = $this->ids_of( $sessions );

		$quizzes_by_parent = $this->bucket_by_parent(
			$this->query_children_of_many(
				[ $course_id, ...$module_ids, ...$lesson_ids, ...$session_ids ],
				'vl_quiz'
			)
		);

		$stops = [];

		foreach ( $modules as $module ) {
			foreach ( $lessons_by_parent[ (int) $module->ID ] ?? [] as $lesson ) {
				$this->emit_lesson( $stops, $lesson, $topics_by_parent, $quizzes_by_parent );
			}
			$this->emit_quizzes( $stops, $quizzes_by_parent[ (int) $module->ID ] ?? [] );
		}

		foreach ( $lessons_by_parent[ $course_id ] ?? [] as $orphan ) {
			$this->emit_lesson( $stops, $orphan, $topics_by_parent, $quizzes_by_parent );
		}

		foreach ( $sessions as $session ) {
			$stops[] = new CurriculumStop( CurriculumStop::KIND_SESSION, (int) $session->ID );
			$this->emit_quizzes( $stops, $quizzes_by_parent[ (int) $session->ID ] ?? [] );
		}

		$this->emit_quizzes( $stops, $quizzes_by_parent[ $course_id ] ?? [] );

		return $stops;
	}

	/**
	 * Lesson page, then its topics, then its quizzes.
	 *
	 * @param list<CurriculumStop>        $stops             Accumulator, by reference.
	 * @param array<int, list<WP_Post>>   $topics_by_parent
	 * @param array<int, list<WP_Post>>   $quizzes_by_parent
	 */
	private function emit_lesson(
		array &$stops,
		WP_Post $lesson,
		array $topics_by_parent,
		array $quizzes_by_parent
	): void {
		$lesson_id = (int) $lesson->ID;

		$stops[] = new CurriculumStop( CurriculumStop::KIND_LESSON, $lesson_id );

		foreach ( $topics_by_parent[ $lesson_id ] ?? [] as $topic ) {
			$stops[] = new CurriculumStop( CurriculumStop::KIND_TOPIC, (int) $topic->ID );
		}

		$this->emit_quizzes( $stops, $quizzes_by_parent[ $lesson_id ] ?? [] );
	}

	/**
	 * @param list<CurriculumStop> $stops   Accumulator, by reference.
	 * @param list<WP_Post>        $quizzes
	 */
	private function emit_quizzes( array &$stops, array $quizzes ): void {
		foreach ( $quizzes as $quiz ) {
			$quiz_id = (int) $quiz->ID;
			$stops[] = new CurriculumStop(
				CurriculumStop::KIND_QUIZ,
				$quiz_id,
				(string) $quiz->post_name,
				$this->quiz_title( $quiz ),
				$this->meta_flag( $quiz_id, '_vl_quiz_blocks_progression' ),
				$this->meta_flag( $quiz_id, '_vl_quiz_requires_all_quizzes_passed' ),
				$this->meta_flag( $quiz_id, '_vl_quiz_is_final_exam' )
			);
		}
	}

	/**
	 * `true` when at least one quiz in the course carries either gating
	 * flag. Callers use this to skip the overlay round trips entirely on
	 * the (overwhelmingly common) ungated course.
	 *
	 * @param list<CurriculumStop> $stops
	 */
	public function has_gated_quizzes( array $stops ): bool {
		foreach ( $stops as $stop ) {
			if ( $stop->is_quiz() && ( $stop->blocks_progression || $stop->requires_all_quizzes ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Curriculum tallies for the dashboard stats surface, derived from the
	 * memoised stop list plus the module count `build()` records — a
	 * second consumer in the same request pays zero extra queries.
	 * Sessions are not tallied; see {@see CurriculumCounts} for the exact
	 * semantics.
	 */
	public function counts_for( int $course_id ): CurriculumCounts {
		$stops = $this->for_course( $course_id );

		$lessons        = 0;
		$topics         = 0;
		$quizzes        = 0;
		$has_final_exam = false;

		foreach ( $stops as $stop ) {
			switch ( $stop->kind ) {
				case CurriculumStop::KIND_LESSON:
					++$lessons;
					break;
				case CurriculumStop::KIND_TOPIC:
					++$topics;
					break;
				case CurriculumStop::KIND_QUIZ:
					++$quizzes;
					$has_final_exam = $has_final_exam || $stop->is_final_exam;
					break;
			}
		}

		return new CurriculumCounts(
			$this->module_counts[ $course_id ] ?? 0,
			$lessons,
			$topics,
			$quizzes,
			$has_final_exam
		);
	}

	/**
	 * Checkbox meta is stored as `'1'` / `''` by
	 * {@see \VL\LMS\Admin\MetaBoxes\AbstractMetaBox::post_checkbox()}.
	 */
	protected function meta_flag( int $post_id, string $key ): bool {
		return '1' === (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Raw `post_title` — not `get_the_title()`. This string is echoed
	 * back through JSON as part of a lock payload, and `get_the_title()`
	 * runs `wptexturize()`, which would emit smart punctuation as HTML
	 * entities into a plain-text field. See `ARCHITECTURE.md` §4.5.
	 */
	protected function quiz_title( WP_Post $quiz ): string {
		return (string) $quiz->post_title;
	}

	protected function is_cohort_course( int $course_id ): bool {
		return 'cohort' === (string) get_post_meta( $course_id, '_vl_course_type', true );
	}

	/**
	 * @param list<WP_Post> $posts
	 *
	 * @return list<int>
	 */
	private function ids_of( array $posts ): array {
		return array_map( static fn ( WP_Post $p ): int => (int) $p->ID, $posts );
	}

	/**
	 * Group a flat, already-sorted list by `post_parent`, preserving the
	 * incoming relative order inside each bucket.
	 *
	 * @param list<WP_Post> $posts
	 *
	 * @return array<int, list<WP_Post>>
	 */
	private function bucket_by_parent( array $posts ): array {
		$out = [];
		foreach ( $posts as $post ) {
			$out[ (int) $post->post_parent ][] = $post;
		}
		return $out;
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_children( int $parent_id, string $post_type ): array {
		return $this->run(
			[
				'post_type'   => $post_type,
				'post_parent' => $parent_id,
			]
		);
	}

	/**
	 * @param list<int> $parent_ids
	 *
	 * @return list<WP_Post>
	 */
	protected function query_children_of_many( array $parent_ids, string $post_type ): array {
		if ( [] === $parent_ids ) {
			return [];
		}
		return $this->run(
			[
				'post_type'       => $post_type,
				'post_parent__in' => array_values( array_unique( $parent_ids ) ),
			]
		);
	}

	/**
	 * Top-level cohort sessions, ordered by scheduled start — the same
	 * ordering {@see \VL\LMS\Learn\CurriculumTransformer} uses.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_sessions( int $course_id ): array {
		return $this->run(
			[
				'post_type'   => 'vl_session',
				'post_parent' => $course_id,
				'meta_key'    => '_vl_session_scheduled_start',
				'orderby'     => 'meta_value',
				'order'       => 'ASC',
			]
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return list<WP_Post>
	 */
	private function run( array $args ): array {
		$defaults = [
			'post_status'            => 'publish',
			'orderby'                => [
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			],
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		];

		$query = new WP_Query( array_merge( $defaults, $args ) );

		$out = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}
