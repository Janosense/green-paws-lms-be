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
	 *     lessons: list<array{id: int, title: string, duration_seconds: int, is_preview: bool}>
	 * }
	 */
	public function transform( WP_Post $module, array $lessons ): array {
		return [
			'id'      => (int) $module->ID,
			'title'   => (string) get_the_title( $module ),
			'lessons' => array_map(
				fn ( WP_Post $lesson ): array => $this->lesson_summary->transform( $lesson ),
				$lessons
			),
		];
	}
}
