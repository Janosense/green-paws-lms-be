<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use WP_Post;

/**
 * Builds the `curriculum: { modules, orphan_lessons }` block for a
 * course detail response.
 *
 * Query shape — three round trips per course, all routed through
 * {@see PostFinder}:
 *
 *   1. modules: `vl_module` with `post_parent = $course_id`.
 *   2. lessons-in-modules: one query for `vl_lesson` with
 *      `post_parent__in = […all module IDs…]` (zero queries when there
 *      are no modules).
 *   3. orphan-lessons: `vl_lesson` with `post_parent = $course_id`
 *      (lessons attached directly to the course, used for module-less
 *      courses).
 *
 * Both shapes are always present in the response; either may be empty.
 * Lessons emit only the four keys defined in {@see LessonSummaryTransformer}
 * — content, video URL, and attachments are not leaked from this path.
 *
 * @author Tymofii Synianskyi
 */
final class CurriculumTransformer {

	public function __construct(
		private readonly ModuleTransformer $module,
		private readonly LessonSummaryTransformer $lesson_summary,
		private readonly PostFinder $finder,
	) {
	}

	/**
	 * @return array{
	 *     modules: list<array<string, mixed>>,
	 *     orphan_lessons: list<array{id: int, title: string, duration_seconds: int, is_preview: bool}>
	 * }
	 */
	public function transform( int $course_id ): array {
		$modules = $this->fetch_modules( $course_id );

		$lessons_by_module = [] === $modules
			? []
			: $this->fetch_lessons_for_modules(
				array_map( static fn ( WP_Post $m ): int => (int) $m->ID, $modules )
			);

		$orphan_lessons = $this->fetch_orphan_lessons( $course_id );

		return [
			'modules'        => array_map(
				fn ( WP_Post $m ): array => $this->module->transform(
					$m,
					$lessons_by_module[ (int) $m->ID ] ?? []
				),
				$modules
			),
			'orphan_lessons' => array_map(
				fn ( WP_Post $l ): array => $this->lesson_summary->transform( $l ),
				$orphan_lessons
			),
		];
	}

	/**
	 * @return list<WP_Post>
	 */
	private function fetch_modules( int $course_id ): array {
		return $this->finder->find(
			[
				'post_type'      => 'vl_module',
				'post_status'    => 'publish',
				'post_parent'    => $course_id,
				'posts_per_page' => -1,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'no_found_rows'  => true,
			]
		);
	}

	/**
	 * @param list<int> $module_ids
	 *
	 * @return array<int, list<WP_Post>>
	 */
	private function fetch_lessons_for_modules( array $module_ids ): array {
		if ( [] === $module_ids ) {
			return [];
		}

		$lessons = $this->finder->find(
			[
				'post_type'       => 'vl_lesson',
				'post_status'     => 'publish',
				'post_parent__in' => $module_ids,
				'posts_per_page'  => -1,
				'orderby'         => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'no_found_rows'   => true,
			]
		);

		$grouped = [];
		foreach ( $lessons as $lesson ) {
			$grouped[ (int) $lesson->post_parent ][] = $lesson;
		}
		return $grouped;
	}

	/**
	 * @return list<WP_Post>
	 */
	private function fetch_orphan_lessons( int $course_id ): array {
		return $this->finder->find(
			[
				'post_type'      => 'vl_lesson',
				'post_status'    => 'publish',
				'post_parent'    => $course_id,
				'posts_per_page' => -1,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'no_found_rows'  => true,
			]
		);
	}
}
