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
	 *
	 * @return array{type: string, id: int, slug: string, lesson_slug: string}|null
	 */
	public function resolve( array $modules, array $orphan_lessons ): ?array {
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

		return $this->pick_from_lessons( $orphan_lessons );
	}

	/**
	 * @param array<int, mixed> $lessons
	 *
	 * @return array{type: string, id: int, slug: string, lesson_slug: string}|null
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
	 * @return array{type: string, id: int, slug: string, lesson_slug: string}|null
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
