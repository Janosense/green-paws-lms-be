<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Learn\Content\BlockParser;
use VL\LMS\Learn\Content\BlockTransformerRegistry;
use VL\LMS\Learn\Video\VideoPayloadBuilder;
use VL\LMS\Repositories\ProgressRepository;
use WP_Post;

/**
 * Composes the JSON payload returned by `GET /vl/v1/learn/topics/{slug}`.
 *
 * Symmetric to {@see LessonContentTransformer} but pared down to the topic
 * shape: there are no child entities to summarise, no attachments, no
 * preview / requires-completion flags. The hierarchy walk produces three
 * reference fields (`course`, `module`, `lesson`).
 *
 * @author Tymofii Synianskyi
 */
class TopicContentTransformer {

	public function __construct(
		private readonly BlockParser $parser,
		private readonly BlockTransformerRegistry $registry,
		private readonly VideoPayloadBuilder $video_builder,
		private readonly EntityHierarchy $hierarchy,
		private readonly ProgressRepository $progress
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $decision reserved for 5.3 progress-write semantics; keeping the signature stable now avoids a breaking change.
	public function transform( WP_Post $topic, int $user_id, AccessDecision $decision ): array {
		$topic_id = (int) $topic->ID;

		$video_url      = (string) get_post_meta( $topic_id, '_vl_topic_video_url', true );
		$video_provider = (string) get_post_meta( $topic_id, '_vl_topic_video_provider', true );
		$duration       = (int) get_post_meta( $topic_id, '_vl_topic_duration_seconds', true );

		$course = $this->hierarchy->resolveCourse( $topic );
		$module = $this->hierarchy->resolveModule( $topic );
		$lesson = $this->hierarchy->resolveLesson( $topic );

		return [
			'id'               => $topic_id,
			'slug'             => (string) $topic->post_name,
			'title'            => (string) get_the_title( $topic ),
			'course'           => $this->reference( $course ),
			'module'           => $this->reference( $module ),
			'lesson'           => $this->reference( $lesson ),
			'menu_order'       => (int) $topic->menu_order,
			'duration_seconds' => $duration,
			'video'            => $this->video_builder->build( $video_provider, $video_url ),
			'content'          => [
				'blocks' => $this->blocks( (string) $topic->post_content ),
			],
			'progress'         => $this->progress_payload( $user_id, $topic_id ),
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function reference( ?WP_Post $post ): ?array {
		if ( null === $post ) {
			return null;
		}
		return [
			'id'    => (int) $post->ID,
			'slug'  => (string) $post->post_name,
			'title' => (string) get_the_title( $post ),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function blocks( string $post_content ): array {
		$parsed = $this->parser->parse( $post_content );
		$out    = [];
		foreach ( $parsed as $block ) {
			$out[] = $this->registry->transform( $block );
		}
		return $out;
	}

	/**
	 * @return array{status:string,position_seconds:?int,completed_at:?string}
	 */
	private function progress_payload( int $user_id, int $topic_id ): array {
		$progress = $this->progress->find( $user_id, EntityType::TOPIC, $topic_id );
		return $this->serialize_progress( $progress );
	}

	/**
	 * @return array{status:string,position_seconds:?int,completed_at:?string}
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
