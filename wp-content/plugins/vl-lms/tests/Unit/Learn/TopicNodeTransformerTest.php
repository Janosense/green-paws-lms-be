<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\Progression\LockMap;
use VL\LMS\Learn\ProgressOverlay;
use VL\LMS\Learn\TopicNodeTransformer;
use WP_Post;

final class TopicNodeTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	private TopicNodeTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Titles now round-trip through PlainText::from_html().
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $html ): string => strip_tags( $html )
		);

		$this->meta = [];
		$meta_ref   = &$this->meta;

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) use ( &$meta_ref ): mixed {
				return $meta_ref[ $key ][ $post_id ] ?? '';
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);

		$this->transformer = new TopicNodeTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function topic( int $id, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_topic';
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function progress_row(
		int $entity_id,
		ProgressStatus $status,
		?int $position_seconds = null,
		?\DateTimeImmutable $completed_at = null
	): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: $entity_id * 10,
			user_id: 5,
			entity_type: EntityType::TOPIC,
			entity_id: $entity_id,
			course_id: 100,
			status: $status,
			position_seconds: $position_seconds,
			completed_at: $completed_at,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now,
		);
	}

	public function test_full_happy_path_shape(): void {
		$topic = $this->topic( 200, 'anatomy-of-feline-heart', 'Anatomy of feline heart', 1 );
		$this->meta['_vl_topic_duration_seconds'][200] = 600;

		$overlay = ProgressOverlay::fromList(
			[ $this->progress_row( 200, ProgressStatus::IN_PROGRESS, 240 ) ]
		);

		$node = $this->transformer->transform( $topic, $overlay, LockMap::empty() );

		self::assertSame(
			[
				'id'               => 200,
				'slug'             => 'anatomy-of-feline-heart',
				'title'            => 'Anatomy of feline heart',
				'menu_order'       => 1,
				'duration_seconds' => 600,
				'progress'         => [
					'status'           => 'in_progress',
					'position_seconds' => 240,
					'completed_at'     => null,
				],
				'lock'             => null,
			],
			$node
		);
	}

	public function test_progress_defaults_to_not_started_when_overlay_misses(): void {
		$topic = $this->topic( 200, 't', 'T' );
		$this->meta['_vl_topic_duration_seconds'][200] = 60;

		$node = $this->transformer->transform( $topic, ProgressOverlay::fromList( [] ), LockMap::empty() );

		self::assertSame(
			[
				'status'           => 'not_started',
				'position_seconds' => null,
				'completed_at'     => null,
			],
			$node['progress']
		);
	}

	public function test_missing_duration_meta_defaults_to_zero(): void {
		$topic = $this->topic( 200, 't', 'T' );

		$node = $this->transformer->transform( $topic, ProgressOverlay::fromList( [] ), LockMap::empty() );

		self::assertSame( 0, $node['duration_seconds'] );
	}

	public function test_completed_at_iso_when_set(): void {
		$completed_at = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		$topic        = $this->topic( 200, 't', 'T' );
		$overlay      = ProgressOverlay::fromList(
			[ $this->progress_row( 200, ProgressStatus::COMPLETED, null, $completed_at ) ]
		);

		$node = $this->transformer->transform( $topic, $overlay, LockMap::empty() );

		self::assertSame( 'completed', $node['progress']['status'] );
		self::assertSame(
			$completed_at->format( \DateTimeInterface::ATOM ),
			$node['progress']['completed_at']
		);
	}
}
