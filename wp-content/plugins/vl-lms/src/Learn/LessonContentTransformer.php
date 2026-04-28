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
use WP_Query;

/**
 * Composes the JSON payload returned by `GET /vl/v1/learn/lessons/{slug}`.
 *
 * Pure assembly: hierarchy walk, meta lookup, block parser → registry,
 * video payload, child-topic summaries, and the caller's progress row.
 * No access decisions live here — {@see Access\LessonAccessGate} owns
 * those, and the `AccessDecision` it produced is passed in so this class
 * can reflect (e.g.) the preview flag without re-deriving it.
 *
 * The child-topic query is isolated in {@see self::query_child_topics()}
 * so unit tests can subclass and bypass `WP_Query` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class LessonContentTransformer {

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
	public function transform( WP_Post $lesson, int $user_id, AccessDecision $decision ): array {
		$lesson_id = (int) $lesson->ID;

		$video_url      = (string) get_post_meta( $lesson_id, '_vl_lesson_video_url', true );
		$video_provider = (string) get_post_meta( $lesson_id, '_vl_lesson_video_provider', true );
		$duration       = (int) get_post_meta( $lesson_id, '_vl_lesson_duration_seconds', true );
		$is_preview     = (bool) get_post_meta( $lesson_id, '_vl_lesson_is_preview', true );
		$requires       = (bool) get_post_meta( $lesson_id, '_vl_lesson_requires_completion', true );
		$attachments    = get_post_meta( $lesson_id, '_vl_lesson_attachments', true );

		$course = $this->hierarchy->resolveCourse( $lesson );
		$module = $this->hierarchy->resolveModule( $lesson );

		return [
			'id'                  => $lesson_id,
			'slug'                => (string) $lesson->post_name,
			'title'               => (string) get_the_title( $lesson ),
			'course'              => $this->reference( $course ),
			'module'              => $this->reference( $module ),
			'menu_order'          => (int) $lesson->menu_order,
			'duration_seconds'    => $duration,
			'is_preview'          => $is_preview,
			'requires_completion' => $requires,
			'video'               => $this->video_builder->build( $video_provider, $video_url ),
			'attachments'         => $this->normalize_attachments( $attachments ),
			'content'             => [
				'blocks' => $this->blocks( (string) $lesson->post_content ),
			],
			'topics'              => $this->topic_summaries( $lesson_id ),
			'progress'            => $this->progress_payload( $user_id, $lesson_id ),
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
	 * @param mixed $raw The unfiltered `_vl_lesson_attachments` meta value.
	 *
	 * @return list<array{url:string,name:string,size:int}>
	 */
	private function normalize_attachments( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url  = isset( $entry['url'] ) && is_string( $entry['url'] ) ? (string) $entry['url'] : '';
			$name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? (string) $entry['name'] : '';
			if ( '' === $url || '' === $name ) {
				continue;
			}
			$size = 0;
			if ( isset( $entry['size'] ) && is_numeric( $entry['size'] ) ) {
				$size = (int) $entry['size'];
			}
			$out[] = [
				'url'  => $url,
				'name' => $name,
				'size' => $size,
			];
		}
		return $out;
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
	 * @return list<array{id:int,slug:string,title:string,menu_order:int,duration_seconds:int}>
	 */
	private function topic_summaries( int $lesson_id ): array {
		$topics = $this->query_child_topics( $lesson_id );

		$out = [];
		foreach ( $topics as $topic ) {
			$topic_id = (int) $topic->ID;
			$out[]    = [
				'id'               => $topic_id,
				'slug'             => (string) $topic->post_name,
				'title'            => (string) get_the_title( $topic ),
				'menu_order'       => (int) $topic->menu_order,
				'duration_seconds' => (int) get_post_meta( $topic_id, '_vl_topic_duration_seconds', true ),
			];
		}
		return $out;
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
	 * @return array{status:string,position_seconds:?int,completed_at:?string}
	 */
	private function progress_payload( int $user_id, int $lesson_id ): array {
		$progress = $this->progress->find( $user_id, EntityType::LESSON, $lesson_id );
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
