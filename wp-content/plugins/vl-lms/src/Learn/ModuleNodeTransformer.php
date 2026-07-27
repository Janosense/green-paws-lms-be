<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Learn\Progression\LockMap;
use VL\LMS\Support\PlainText;
use WP_Post;
use WP_Query;

/**
 * Composes the module node returned inside a curriculum response.
 *
 * Modules are pure groupings — they carry no progress field of their own
 * (the frontend computes module-level state visually from constituent
 * lessons). Each module node fans out into a list of lesson nodes via
 * {@see LessonNodeTransformer}, then its own module-level quizzes via
 * {@see QuizNodeTransformer} (quizzes whose `post_parent` is the module).
 *
 * The lesson query is isolated in {@see self::query_child_lessons()} so
 * unit tests can subclass and bypass `WP_Query` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class ModuleNodeTransformer {

	public function __construct(
		private readonly LessonNodeTransformer $lesson_transformer,
		private readonly QuizNodeTransformer $quiz_transformer
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform(
		WP_Post $module,
		ProgressOverlay $overlay,
		QuizStatusOverlay $quiz_overlay,
		LockMap $locks
	): array {
		$module_id = (int) $module->ID;

		$lessons = [];
		foreach ( $this->query_child_lessons( $module_id ) as $lesson ) {
			$lessons[] = $this->lesson_transformer->transform( $lesson, $overlay, $quiz_overlay, $locks );
		}

		return [
			'id'         => $module_id,
			'slug'       => (string) $module->post_name,
			'title'      => PlainText::from_html( (string) get_the_title( $module ) ),
			'menu_order' => (int) $module->menu_order,
			'lessons'    => $lessons,
			'quizzes'    => $this->quiz_transformer->transform_children( $module_id, $quiz_overlay, $locks ),
		];
	}

	/**
	 * Query published lessons whose `post_parent` is the given module, ordered
	 * by `menu_order ASC, ID ASC`. Isolated so tests can override without
	 * instantiating `WP_Query`.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_child_lessons( int $parent_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_lesson',
				'post_parent'            => $parent_id,
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
}
