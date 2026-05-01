<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\DiffDetector;
use VL\LMS\Services\Zoom\Sync\MeetingPayloadBuilder;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemoryPostMetaAccessor;
use WP_Post;

final class DiffDetectorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Brain Monkey shim alias.
			static fn ( $value ): string|false => json_encode( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id ): WP_Post {
		$p     = Mockery::mock( 'WP_Post' );
		$p->ID = $id;
		return $p;
	}

	public function test_returns_true_when_no_persisted_hash(): void {
		$detector = new DiffDetector( new InMemoryPostMetaAccessor() );

		self::assertTrue(
			$detector->needs_update( $this->post( 1 ), PostKind::SESSION, [ 'topic' => 'X' ] )
		);
	}

	public function test_returns_false_when_hash_matches_payload(): void {
		$meta    = new InMemoryPostMetaAccessor();
		$payload = [
			'topic' => 'X',
			'type'  => 2,
		];
		$meta->seed( 1, '_vl_session_zoom_synced_payload_hash', MeetingPayloadBuilder::fingerprint( $payload ) );

		$detector = new DiffDetector( $meta );

		self::assertFalse(
			$detector->needs_update( $this->post( 1 ), PostKind::SESSION, $payload )
		);
	}

	public function test_returns_true_when_payload_changes(): void {
		$meta = new InMemoryPostMetaAccessor();
		$old  = [ 'topic' => 'Old' ];
		$new  = [ 'topic' => 'New' ];
		$meta->seed( 1, '_vl_session_zoom_synced_payload_hash', MeetingPayloadBuilder::fingerprint( $old ) );

		$detector = new DiffDetector( $meta );

		self::assertTrue(
			$detector->needs_update( $this->post( 1 ), PostKind::SESSION, $new )
		);
	}

	public function test_session_and_webinar_hashes_are_isolated(): void {
		$meta    = new InMemoryPostMetaAccessor();
		$payload = [ 'topic' => 'X' ];
		$hash    = MeetingPayloadBuilder::fingerprint( $payload );
		$meta->seed( 1, '_vl_session_zoom_synced_payload_hash', $hash );

		$detector = new DiffDetector( $meta );

		self::assertFalse(
			$detector->needs_update( $this->post( 1 ), PostKind::SESSION, $payload ),
			'session hash should match'
		);
		self::assertTrue(
			$detector->needs_update( $this->post( 1 ), PostKind::WEBINAR, $payload ),
			'webinar has no hash → needs update'
		);
	}
}
