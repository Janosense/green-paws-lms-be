<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Support\PlainText;
use WP_Post;
use WP_Query;

/**
 * Curriculum-tree leaf transformer for `vl_quiz` posts.
 *
 * Quizzes are flexible-parent CPTs — a `vl_quiz` may hang off a course, a
 * module, a lesson, or a cohort session (the same `post_parent` contract the
 * admin parent-picker writes). Whichever level a parent transformer is
 * composing, it calls {@see self::transform_children()} with that parent's
 * post ID to fetch and shape the attached quizzes, so the child-quiz query
 * lives in exactly one place.
 *
 * Per-user status comes from a pre-built {@see QuizStatusOverlay}; no
 * per-quiz repository fan-out happens here. The child query is isolated in
 * {@see self::query_child_quizzes()} so unit tests can subclass and bypass
 * `WP_Query` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class QuizNodeTransformer {

	/**
	 * Transform every published `vl_quiz` whose `post_parent` is `$parent_id`,
	 * ordered by `menu_order ASC, ID ASC`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function transform_children( int $parent_id, QuizStatusOverlay $overlay ): array {
		$out = [];
		foreach ( $this->query_child_quizzes( $parent_id ) as $quiz ) {
			$out[] = $this->transform( $quiz, $overlay );
		}
		return $out;
	}

	/**
	 * @return array{
	 *     type: string,
	 *     id: int,
	 *     slug: string,
	 *     title: string,
	 *     menu_order: int,
	 *     is_final_exam: bool,
	 *     passing_threshold: int,
	 *     status: string,
	 *     best_score_pct: float|null
	 * }
	 */
	public function transform( WP_Post $quiz, QuizStatusOverlay $overlay ): array {
		$quiz_id = (int) $quiz->ID;

		return [
			'type'              => 'quiz',
			'id'                => $quiz_id,
			'slug'              => (string) $quiz->post_name,
			'title'            => PlainText::from_html( (string) get_the_title( $quiz ) ),
			'menu_order'        => (int) $quiz->menu_order,
			'is_final_exam'     => (bool) get_post_meta( $quiz_id, '_vl_quiz_is_final_exam', true ),
			'passing_threshold' => (int) get_post_meta( $quiz_id, '_vl_quiz_passing_threshold', true ),
			'status'            => $overlay->status( $quiz_id ),
			'best_score_pct'    => $overlay->best_score_pct( $quiz_id ),
		];
	}

	/**
	 * Query published quizzes whose `post_parent` is the given entity, ordered
	 * by `menu_order ASC, ID ASC`. Isolated so tests can override without
	 * instantiating `WP_Query`.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_child_quizzes( int $parent_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_quiz',
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
		foreach ( $query->posts as $quiz ) {
			if ( $quiz instanceof WP_Post ) {
				$out[] = $quiz;
			}
		}
		return $out;
	}
}
