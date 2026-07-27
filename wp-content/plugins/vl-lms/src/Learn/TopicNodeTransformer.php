<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockMap;
use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Composes the topic node returned inside a curriculum response.
 *
 * Each topic node carries enough metadata for the frontend to render the
 * navigation row (title, duration, progress dot) without a follow-up read
 * to the topic detail endpoint. The full body / blocks live behind
 * {@see TopicContentTransformer}.
 *
 * Progress is read from a pre-built {@see ProgressOverlay} — no repository
 * calls happen here; the curriculum-level transformer issues exactly one
 * `ProgressRepository::list_for_user_in_course()` call and feeds it down.
 *
 * @author Tymofii Synianskyi
 */
class TopicNodeTransformer {

	/**
	 * @return array{
	 *     id: int,
	 *     slug: string,
	 *     title: string,
	 *     menu_order: int,
	 *     duration_seconds: int,
	 *     progress: array{status: string, position_seconds: ?int, completed_at: ?string},
	 *     lock: array<string, mixed>|null
	 * }
	 */
	public function transform( WP_Post $topic, ProgressOverlay $overlay, LockMap $locks ): array {
		$topic_id = (int) $topic->ID;
		$duration = (int) get_post_meta( $topic_id, '_vl_topic_duration_seconds', true );

		return [
			'id'               => $topic_id,
			'slug'             => (string) $topic->post_name,
			'title'            => PlainText::from_html( (string) get_the_title( $topic ) ),
			'menu_order'       => (int) $topic->menu_order,
			'duration_seconds' => $duration,
			'progress'         => $this->serialize_progress( $overlay->topic( $topic_id ) ),
			'lock'             => $locks->to_node_value( CurriculumStop::KIND_TOPIC, $topic_id ),
		];
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
