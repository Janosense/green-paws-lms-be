<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook\Handlers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\LookupResult;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\Handlers\MeetingStartedHandler;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemoryPostMetaAccessor;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\StubPostLookup;
use WP_Post;

final class MeetingStartedHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id, string $type ): WP_Post {
		$p            = Mockery::mock( 'WP_Post' );
		$p->ID        = $id;
		$p->post_type = $type;
		return $p;
	}

	private function request( string $meeting_id ): WebhookRequest {
		return new WebhookRequest( 'meeting.started', [ 'id' => $meeting_id ], 'A', 'tr', 1, '{}', '' );
	}

	public function test_unknown_meeting_id_is_noop(): void {
		$lookup  = new StubPostLookup();
		$meta    = new InMemoryPostMetaAccessor();
		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingStartedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( '123' ) );

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'unknown_meeting_id', $outcome->action_label );
	}

	public function test_scheduled_status_is_advanced_to_live(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_session_status', 'scheduled' );

		$captured = null;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $post_id, string $key, $value ) use ( &$captured ): bool {
				$captured = [ $post_id, $key, $value ];
				return true;
			}
		);

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingStartedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( 'm-1' ) );

		self::assertFalse( $outcome->was_no_op );
		self::assertSame( 'status_advanced_to_live', $outcome->action_label );
		self::assertSame( [ 11, '_vl_session_status', 'live' ], $captured );
	}

	public function test_already_live_is_noop(): void {
		$post                         = $this->post( 11, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_webinar_status', 'live' );

		Functions\expect( 'update_post_meta' )->never();

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingStartedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( 'm-1' ) );

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'status_already_advanced', $outcome->action_label );
	}

	public function test_cancelled_is_noop(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_session_status', 'cancelled' );

		Functions\expect( 'update_post_meta' )->never();

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingStartedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( 'm-1' ) );

		self::assertTrue( $outcome->was_no_op );
	}
}
