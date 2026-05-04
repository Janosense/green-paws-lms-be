<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

/**
 * Picks the first not-yet-completed leaf in a curriculum tree.
 *
 * Pure function over already-transformed array data — no `WP_Post`, no
 * repository calls. The walking order matches the user's natural learning
 * sequence:
 *
 *   1. Walk modules in `menu_order`. For each module's lessons:
 *      - If the lesson has topics, walk topics in `menu_order`; the first
 *        non-completed topic is the candidate.
 *      - Otherwise the lesson itself is the candidate.
 *   2. Then walk orphan lessons (course-direct lessons with no module)
 *      in `menu_order`, applying the same lesson-or-topic rule.
 *   3. (Phase 7.4, cohort courses only) Walk top-level session nodes in
 *      `_vl_session_scheduled_start ASC`. A session is "completed" when
 *      `is_completed` is true on the node (i.e. an attendance row exists).
 *
 * Returns `null` for an empty curriculum (no candidates) or a fully
 * completed curriculum (every candidate is `completed`). The frontend
 * renders a "Course complete" state in that case.
 *
 * @author Tymofii Synianskyi
 */
final class NextEntityResolver {

	/**
	 * @param list<array<string, mixed>> $modules        Module nodes (already transformed).
	 * @param list<array<string, mixed>> $orphan_lessons Lesson nodes (already transformed).
	 * @param list<array<string, mixed>> $sessions       Session nodes for cohort courses (Phase 7.4).
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve( array $modules, array $orphan_lessons, array $sessions = [] ): ?array {
		foreach ( $modules as $module ) {
			$lessons = $module['lessons'] ?? [];
			if ( ! is_array( $lessons ) ) {
				continue;
			}
			$candidate = $this->pick_from_lessons( $lessons );
			if ( null !== $candidate ) {
				return $candidate;
			}
		}

		$lesson_candidate = $this->pick_from_lessons( $orphan_lessons );
		if ( null !== $lesson_candidate ) {
			return $lesson_candidate;
		}

		return $this->pick_from_sessions( $sessions );
	}

	/**
	 * @param list<array<string, mixed>> $sessions
	 *
	 * @return array{type: string, id: int, slug: string, title: string, scheduled_start: ?string}|null
	 */
	private function pick_from_sessions( array $sessions ): ?array {
		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}
			if ( true === ( $session['is_completed'] ?? false ) ) {
				continue;
			}
			$scheduled_start = $session['scheduled_start'] ?? null;
			return [
				'type'            => 'session',
				'id'              => (int) ( $session['id'] ?? 0 ),
				'slug'            => (string) ( $session['slug'] ?? '' ),
				'title'           => (string) ( $session['title'] ?? '' ),
				'scheduled_start' => is_string( $scheduled_start ) ? $scheduled_start : null,
			];
		}
		return null;
	}

	/**
	 * @param array<int, mixed> $lessons
	 *
	 * @return array<string, mixed>|null
	 */
	private function pick_from_lessons( array $lessons ): ?array {
		foreach ( $lessons as $lesson ) {
			if ( ! is_array( $lesson ) ) {
				continue;
			}

			$topics = is_array( $lesson['topics'] ?? null ) ? $lesson['topics'] : [];
			if ( [] !== $topics ) {
				$topic_candidate = $this->pick_from_topics( $topics, (string) ( $lesson['slug'] ?? '' ) );
				if ( null !== $topic_candidate ) {
					return $topic_candidate;
				}
				continue;
			}

			if ( ! $this->is_completed( $lesson ) ) {
				$slug = (string) ( $lesson['slug'] ?? '' );
				return [
					'type'        => 'lesson',
					'id'          => (int) ( $lesson['id'] ?? 0 ),
					'slug'        => $slug,
					'lesson_slug' => $slug,
				];
			}
		}
		return null;
	}

	/**
	 * @param array<int, mixed> $topics
	 *
	 * @return array<string, mixed>|null
	 */
	private function pick_from_topics( array $topics, string $lesson_slug ): ?array {
		foreach ( $topics as $topic ) {
			if ( ! is_array( $topic ) ) {
				continue;
			}
			if ( ! $this->is_completed( $topic ) ) {
				return [
					'type'        => 'topic',
					'id'          => (int) ( $topic['id'] ?? 0 ),
					'slug'        => (string) ( $topic['slug'] ?? '' ),
					'lesson_slug' => $lesson_slug,
				];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private function is_completed( array $node ): bool {
		$progress = $node['progress'] ?? null;
		if ( ! is_array( $progress ) ) {
			return false;
		}
		return 'completed' === ( $progress['status'] ?? null );
	}
}
