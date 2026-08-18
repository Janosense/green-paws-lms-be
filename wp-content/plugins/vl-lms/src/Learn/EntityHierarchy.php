<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use WP_Post;
use WP_Query;

/**
 * Walks the `post_parent` chain for the LMS curriculum hierarchy.
 *
 * The four LMS post types form an ordered tree:
 * `vl_course → vl_module → vl_lesson → vl_topic`. A lesson MAY skip the
 * module level and hang directly off a course. The attachment CPTs
 * `vl_quiz` / `vl_assignment` hang off any of the four parent types the
 * curriculum picker offers (course, module, lesson, session), and
 * `vl_session` parents directly to a course — all of them resolve to a
 * course too. Anything else is treated as a missing link and resolved to
 * `null`.
 *
 * Keep the attachment arms in sync with
 * {@see \VL\LMS\Admin\MetaBoxes\CurriculumParentPickerTrait}: the E2
 * final-exam-arm discovery in `CompletionPropagator` filters flagged
 * quizzes through {@see self::resolveCourse()}, so a missing arm silently
 * empties the final-exam list and the arm auto-passes.
 *
 * Pure helper — only consumes `get_post()` and `WP_Query`, no DB writes.
 * Used by {@see Access\LessonAccessGate} now and by the curriculum
 * transformer in 5.2.
 *
 * The sibling query is isolated in {@see self::query_published_siblings()}
 * so unit tests can subclass and bypass `WP_Query` without booting WP.
 *
 * Public method names use camelCase to match the Phase 5.1 spec contract;
 * each is annotated with a `phpcs:ignore` so the WordPress sniffer does
 * not flag the deviation.
 *
 * @author Tymofii Synianskyi
 */
class EntityHierarchy {

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Phase 5.1 spec contract; see class docblock.
	public function resolveCourse( WP_Post $post ): ?WP_Post {
		return match ( $post->post_type ) {
			'vl_course'  => $post,
			'vl_module'  => $this->parent_of_type( $post, 'vl_course' ),
			'vl_lesson'  => $this->resolve_lesson_course( $post ),
			'vl_topic'   => $this->resolve_topic_course( $post ),
			'vl_session' => $this->parent_of_type( $post, 'vl_course' ),
			'vl_quiz', 'vl_assignment' => $this->resolve_attachment_course( $post ),
			default      => null,
		};
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Phase 5.1 spec contract; see class docblock.
	public function resolveModule( WP_Post $post ): ?WP_Post {
		return match ( $post->post_type ) {
			'vl_lesson' => $this->parent_of_type( $post, 'vl_module' ),
			'vl_topic'  => $this->resolve_topic_module( $post ),
			default     => null,
		};
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Phase 5.1 spec contract; see class docblock.
	public function resolveLesson( WP_Post $post ): ?WP_Post {
		if ( 'vl_topic' !== $post->post_type ) {
			return null;
		}
		return $this->parent_of_type( $post, 'vl_lesson' );
	}

	/**
	 * @return list<WP_Post>
	 */
	public function siblings( WP_Post $post ): array {
		return $this->query_published_siblings( $post->post_type, (int) $post->post_parent );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Phase 5.1 spec contract; see class docblock.
	public function previousSibling( WP_Post $post ): ?WP_Post {
		$siblings = $this->siblings( $post );
		$prev     = null;
		foreach ( $siblings as $sibling ) {
			if ( (int) $sibling->ID === (int) $post->ID ) {
				return $prev;
			}
			$prev = $sibling;
		}
		return null;
	}

	/**
	 * Query the published-sibling list. Isolated so tests can override
	 * without instantiating `WP_Query`.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_published_siblings( string $post_type, int $parent_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_parent'            => $parent_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$posts = $query->posts;
		$out   = [];
		foreach ( $posts as $sibling ) {
			if ( $sibling instanceof WP_Post ) {
				$out[] = $sibling;
			}
		}
		return $out;
	}

	private function resolve_lesson_course( WP_Post $lesson ): ?WP_Post {
		$parent = $this->published_post_by_id( (int) $lesson->post_parent );
		if ( null === $parent ) {
			return null;
		}
		if ( 'vl_course' === $parent->post_type ) {
			return $parent;
		}
		if ( 'vl_module' === $parent->post_type ) {
			return $this->parent_of_type( $parent, 'vl_course' );
		}
		return null;
	}

	private function resolve_attachment_course( WP_Post $post ): ?WP_Post {
		$parent = $this->published_post_by_id( (int) $post->post_parent );
		if ( null === $parent ) {
			return null;
		}
		return match ( $parent->post_type ) {
			'vl_course'  => $parent,
			'vl_module'  => $this->parent_of_type( $parent, 'vl_course' ),
			'vl_lesson'  => $this->resolve_lesson_course( $parent ),
			'vl_session' => $this->parent_of_type( $parent, 'vl_course' ),
			default      => null,
		};
	}

	private function resolve_topic_course( WP_Post $topic ): ?WP_Post {
		$lesson = $this->parent_of_type( $topic, 'vl_lesson' );
		if ( null === $lesson ) {
			return null;
		}
		return $this->resolve_lesson_course( $lesson );
	}

	private function resolve_topic_module( WP_Post $topic ): ?WP_Post {
		$lesson = $this->parent_of_type( $topic, 'vl_lesson' );
		if ( null === $lesson ) {
			return null;
		}
		return $this->parent_of_type( $lesson, 'vl_module' );
	}

	private function parent_of_type( WP_Post $post, string $expected_type ): ?WP_Post {
		$parent = $this->published_post_by_id( (int) $post->post_parent );
		if ( null === $parent ) {
			return null;
		}
		if ( $parent->post_type !== $expected_type ) {
			return null;
		}
		return $parent;
	}

	private function published_post_by_id( int $id ): ?WP_Post {
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}
}
