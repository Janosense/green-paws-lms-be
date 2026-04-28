<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\ProgressRepository;
use WP_Post;
use WP_Query;

/**
 * Composes the personalised curriculum payload returned by
 * `GET /vl/v1/learn/courses/{slug}/curriculum`.
 *
 * Pure assembly: one `ProgressRepository::list_for_user_in_course()` round
 * trip materialises the {@see ProgressOverlay}; one
 * `EnrollmentRepository::find_for_user_and_course()` call sources the
 * `course.enrollment` block; three WP_Query round trips fetch the module
 * subtree. Per-leaf progress is then read from the overlay during the tree
 * walk — no per-lesson or per-topic repository fan-out.
 *
 * `EnrollmentRepository` (rather than `EnrollmentService`) is consulted for
 * the enrollment row because the service surface only exposes a boolean
 * `has_active_access()` — fetching the actual `Enrollment` value object
 * means going through the repository directly. The service still owns the
 * access decision (in {@see CurriculumController}); this class only needs
 * the row's metadata for the response shape.
 *
 * The three WP_Query calls are isolated in protected methods so unit tests
 * can subclass and bypass `WP_Query` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class CurriculumTransformer {

	public function __construct(
		private readonly ModuleNodeTransformer $module_transformer,
		private readonly LessonNodeTransformer $lesson_transformer,
		private readonly NextEntityResolver $next_resolver,
		private readonly ProgressRepository $progress,
		private readonly EnrollmentRepository $enrollments
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $course, int $user_id ): array {
		$course_id = (int) $course->ID;

		$overlay = ProgressOverlay::fromList(
			$this->progress->list_for_user_in_course( $user_id, $course_id )
		);

		$modules = [];
		foreach ( $this->query_child_modules( $course_id ) as $module ) {
			$modules[] = $this->module_transformer->transform( $module, $overlay );
		}

		$orphan_lessons = [];
		foreach ( $this->query_orphan_lessons( $course_id ) as $lesson ) {
			$orphan_lessons[] = $this->lesson_transformer->transform( $lesson, $overlay );
		}

		$total_duration = $this->sum_duration( $modules, $orphan_lessons );
		$next_entity    = $this->next_resolver->resolve( $modules, $orphan_lessons );

		$enrollment = $this->enrollments->find_for_user_and_course( $user_id, $course_id );

		return [
			'course'         => [
				'id'               => $course_id,
				'slug'             => (string) $course->post_name,
				'title'            => (string) get_the_title( $course ),
				'duration_seconds' => $total_duration,
				'enrollment'       => $this->enrollment_payload( $enrollment ),
			],
			'modules'        => $modules,
			'orphan_lessons' => $orphan_lessons,
			'next_entity'    => $next_entity,
		];
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_child_modules( int $course_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_module',
				'post_parent'            => $course_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			]
		);

		$out = [];
		foreach ( $query->posts as $module ) {
			if ( $module instanceof WP_Post ) {
				$out[] = $module;
			}
		}
		return $out;
	}

	/**
	 * Course-direct lessons — `vl_lesson` posts whose `post_parent` is the
	 * course itself rather than a module. Fully module-organised courses
	 * yield an empty list here.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_orphan_lessons( int $course_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_lesson',
				'post_parent'            => $course_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			]
		);

		$out = [];
		foreach ( $query->posts as $lesson ) {
			if ( $lesson instanceof WP_Post ) {
				$out[] = $lesson;
			}
		}
		return $out;
	}

	/**
	 * Sum every leaf's `duration_seconds`. A lesson with topics contributes
	 * the sum of its topics rather than the lesson's own duration — the
	 * per-lesson meta is the entire lesson's video runtime, but with topics
	 * the topic-level meta is the source of truth.
	 *
	 * @param list<array<string, mixed>> $modules
	 * @param list<array<string, mixed>> $orphan_lessons
	 */
	private function sum_duration( array $modules, array $orphan_lessons ): int {
		$total = 0;

		foreach ( $modules as $module ) {
			$lessons = is_array( $module['lessons'] ?? null ) ? $module['lessons'] : [];
			foreach ( $lessons as $lesson ) {
				if ( is_array( $lesson ) ) {
					$total += $this->lesson_duration( $lesson );
				}
			}
		}

		foreach ( $orphan_lessons as $lesson ) {
			if ( is_array( $lesson ) ) {
				$total += $this->lesson_duration( $lesson );
			}
		}

		return $total;
	}

	/**
	 * @param array<string, mixed> $lesson
	 */
	private function lesson_duration( array $lesson ): int {
		$topics = is_array( $lesson['topics'] ?? null ) ? $lesson['topics'] : [];
		if ( [] !== $topics ) {
			$sum = 0;
			foreach ( $topics as $topic ) {
				if ( is_array( $topic ) ) {
					$sum += (int) ( $topic['duration_seconds'] ?? 0 );
				}
			}
			return $sum;
		}
		return (int) ( $lesson['duration_seconds'] ?? 0 );
	}

	/**
	 * @return array{
	 *     status: string,
	 *     progress_pct: int,
	 *     enrolled_at: string,
	 *     completed_at: ?string
	 * }|null
	 */
	private function enrollment_payload( ?Enrollment $enrollment ): ?array {
		if ( null === $enrollment ) {
			return null;
		}

		return [
			'status'       => $enrollment->status->value,
			'progress_pct' => $enrollment->progress_pct,
			'enrolled_at'  => $this->format_iso( $enrollment->enrolled_at ),
			'completed_at' => null === $enrollment->completed_at
				? null
				: $this->format_iso( $enrollment->completed_at ),
		];
	}

	/**
	 * Convert the repository's UTC `Y-m-d H:i:s` strings to ISO 8601.
	 */
	private function format_iso( string $datetime ): string {
		return ( new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) ) )
			->format( \DateTimeInterface::ATOM );
	}
}
