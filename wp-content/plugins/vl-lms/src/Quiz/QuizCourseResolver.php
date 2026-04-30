<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use WP_Post;

/**
 * Walks the `post_parent` chain from a `vl_quiz` up to its ancestor
 * `vl_course`.
 *
 * Quizzes are flexible in the curriculum — they can hang off a lesson
 * (`vl_quiz → vl_lesson → vl_module → vl_course`), off a session
 * (`vl_quiz → vl_session → vl_course`), or off a module / course
 * directly. This resolver normalises that variation so callers (gate,
 * service) get one straight answer.
 *
 * The walk is bounded to 5 hops: that is enough to cover the deepest
 * supported chain (quiz → topic → lesson → module → course) plus one
 * cycle-protection slack hop, and it makes runaway parent loops in
 * malformed data observable rather than infinite.
 *
 * The single `get_post()` lookup is isolated in
 * {@see self::resolve_post_parent()} as a `protected` seam — tests
 * subclass and stub the chain without booting WordPress.
 *
 * @author Tymofii Synianskyi
 */
class QuizCourseResolver {

	private const int MAX_HOPS = 5;

	/**
	 * Returns the post ID of the ancestor `vl_course`, or `null` if the
	 * chain breaks before reaching one.
	 *
	 * "Breaks" includes: missing parent, unpublished ancestor, unknown
	 * `post_type`, exceeded {@see self::MAX_HOPS}, or a cycle.
	 */
	public function find_course_id_for_quiz( int $quiz_id ): ?int {
		if ( $quiz_id <= 0 ) {
			return null;
		}

		$current = $quiz_id;
		$visited = [ $current => true ];

		for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
			$parent = $this->resolve_post_parent( $current );
			if ( null === $parent ) {
				return null;
			}
			if ( isset( $visited[ $parent['id'] ] ) ) {
				// Cycle in the parent chain — treat as broken.
				return null;
			}
			if ( 'vl_course' === $parent['type'] ) {
				return $parent['id'];
			}

			$visited[ $parent['id'] ] = true;
			$current                  = $parent['id'];
		}

		return null;
	}

	/**
	 * Resolves the parent of a single post and returns its ID + post_type.
	 *
	 * Returns `null` when the post or its parent does not exist, or when
	 * the parent isn't published. Subclassed in tests so the lookup can
	 * be stubbed without instantiating `WP_Post`.
	 *
	 * @return array{id: int, type: string}|null
	 */
	protected function resolve_post_parent( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$parent_id = (int) $post->post_parent;
		if ( $parent_id <= 0 ) {
			return null;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post ) {
			return null;
		}
		if ( 'publish' !== $parent->post_status ) {
			return null;
		}

		return [
			'id'   => (int) $parent->ID,
			'type' => (string) $parent->post_type,
		];
	}
}
