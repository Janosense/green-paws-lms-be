<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use WP_Post;

/**
 * Reshapes a single `vl_module` post (with its pre-fetched lessons) into
 * the curriculum module entry.
 *
 * Lessons are not queried here — the {@see CurriculumTransformer} batch-
 * fetches all lessons for every module on the page in one round trip
 * and hands the per-module slice to this transformer.
 *
 * @author Tymofii Synianskyi
 */
final class ModuleTransformer {

	public function __construct(
		private readonly LessonSummaryTransformer $lesson_summary,
	) {
	}

	/**
	 * @param list<WP_Post> $lessons Lessons whose `post_parent` equals `$module->ID`,
	 *                               pre-sorted by `menu_order ASC`, then `ID ASC`.
	 *
	 * @return array{
	 *     id: int,
	 *     title: string,
	 *     duration_minutes: int,
	 *     passing_threshold: int|null,
	 *     intro_video_url: string,
	 *     lessons: list<array{id: int, title: string, duration_seconds: int, is_preview: bool}>
	 * }
	 */
	public function transform( WP_Post $module, array $lessons ): array {
		$module_id = (int) $module->ID;

		$threshold     = (int) get_post_meta( $module_id, '_vl_module_passing_threshold', true );
		$threshold_out = $threshold > 0 ? $threshold : null;

		return [
			'id'                => $module_id,
			'title'             => (string) get_the_title( $module ),
			'duration_minutes'  => (int) get_post_meta( $module_id, '_vl_module_duration_minutes', true ),
			'passing_threshold' => $threshold_out,
			'intro_video_url'   => (string) get_post_meta( $module_id, '_vl_module_intro_video_url', true ),
			'lessons'           => array_map(
				fn ( WP_Post $lesson ): array => $this->lesson_summary->transform( $lesson ),
				$lessons
			),
		];
	}
}
