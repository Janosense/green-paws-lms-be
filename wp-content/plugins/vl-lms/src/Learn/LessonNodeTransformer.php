<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Domain\Progress\Progress;
use WP_Post;
use WP_Query;

/**
 * Composes the lesson node returned inside a curriculum response.
 *
 * Each lesson node carries the navigation-shape fields plus its child
 * topics (transformed in turn by {@see TopicNodeTransformer}) and its
 * lesson-level quizzes (via {@see QuizNodeTransformer}). Detail-only
 * fields — content blocks, attachments, the full video payload —
 * deliberately stay out of this shape; the curriculum endpoint is a
 * navigation map, not a content read.
 *
 * Progress comes from a pre-built {@see ProgressOverlay}; the WP_Query that
 * fetches child topics is isolated in {@see self::query_child_topics()} so
 * unit tests can subclass and bypass `WP_Query` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class LessonNodeTransformer {

	public function __construct(
		private readonly TopicNodeTransformer $topic_transformer,
		private readonly QuizNodeTransformer $quiz_transformer
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $lesson, ProgressOverlay $overlay, QuizStatusOverlay $quiz_overlay ): array {
		$lesson_id = (int) $lesson->ID;

		$duration   = (int) get_post_meta( $lesson_id, '_vl_lesson_duration_seconds', true );
		$is_preview = (bool) get_post_meta( $lesson_id, '_vl_lesson_is_preview', true );
		$requires   = (bool) get_post_meta( $lesson_id, '_vl_lesson_requires_completion', true );

		$topics = [];
		foreach ( $this->query_child_topics( $lesson_id ) as $topic ) {
			$topics[] = $this->topic_transformer->transform( $topic, $overlay );
		}

		return [
			'id'                  => $lesson_id,
			'slug'                => (string) $lesson->post_name,
			'title'               => (string) get_the_title( $lesson ),
			'menu_order'          => (int) $lesson->menu_order,
			'duration_seconds'    => $duration,
			'is_preview'          => $is_preview,
			'requires_completion' => $requires,
			'has_topics'          => [] !== $topics,
			'progress'            => $this->serialize_progress( $overlay->lesson( $lesson_id ) ),
			'topics'              => $topics,
			'quizzes'             => $this->quiz_transformer->transform_children( $lesson_id, $quiz_overlay ),
		];
	}

	/**
	 * Query published topics whose `post_parent` is the given lesson, ordered
	 * by `menu_order ASC, ID ASC`. Isolated so tests can override without
	 * instantiating `WP_Query`.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_child_topics( int $lesson_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_topic',
				'post_parent'            => $lesson_id,
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
		foreach ( $query->posts as $topic ) {
			if ( $topic instanceof WP_Post ) {
				$out[] = $topic;
			}
		}
		return $out;
	}

	/**
	 * @return array{status: string, position_seconds: ?int, completed_at: ?string}
	 */
	private function serialize_progress( ?Progress $progress ): array {
		if ( null === $progress ) {
			return [
				'status'           => 'not_started',
				'position_seconds' => null,
				'completed_at'     => null,
			];
		}
		return [
			'status'           => $progress->status->value,
			'position_seconds' => $progress->position_seconds,
			'completed_at'     => null === $progress->completed_at
				? null
				: $progress->completed_at->format( \DateTimeInterface::ATOM ),
		];
	}
}
