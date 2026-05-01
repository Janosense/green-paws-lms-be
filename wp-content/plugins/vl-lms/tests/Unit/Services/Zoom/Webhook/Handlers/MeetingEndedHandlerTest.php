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
use VL\LMS\Services\Zoom\Webhook\Handlers\MeetingEndedHandler;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemoryPostMetaAccessor;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\StubPostLookup;
use WP_Post;

final class MeetingEndedHandlerTest extends TestCase {

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
		return new WebhookRequest( 'meeting.ended', [ 'id' => $meeting_id ], 'A', 'tr', 1, '{}', '' );
	}

	public function test_unknown_meeting_id_is_noop(): void {
		$lookup  = new StubPostLookup();
		$meta    = new InMemoryPostMetaAccessor();
		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingEndedHandler( $lookup, $meta, $logger );

		self::assertTrue( $handler->handle( $this->request( 'x' ) )->was_no_op );
	}

	public function test_live_advances_to_completed(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_session_status', 'live' );

		$captured = null;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $k, $v ) use ( &$captured ): bool {
				$captured = [ $id, $k, $v ];
				return true;
			}
		);

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingEndedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( 'm-1' ) );

		self::assertFalse( $outcome->was_no_op );
		self::assertSame( [ 11, '_vl_session_status', 'completed' ], $captured );
	}

	public function test_scheduled_advances_to_completed(): void {
		$post                         = $this->post( 11, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_webinar_status', 'scheduled' );

		Functions\when( 'update_post_meta' )->justReturn( true );

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingEndedHandler( $lookup, $meta, $logger );

		$outcome = $handler->handle( $this->request( 'm-1' ) );

		self::assertFalse( $outcome->was_no_op );
	}

	public function test_already_completed_is_noop(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_session_status', 'completed' );

		Functions\expect( 'update_post_meta' )->never();

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingEndedHandler( $lookup, $meta, $logger );

		self::assertTrue( $handler->handle( $this->request( 'm-1' ) )->was_no_op );
	}

	public function test_cancelled_is_noop(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$meta                         = new InMemoryPostMetaAccessor();
		$meta->seed( 11, '_vl_session_status', 'cancelled' );

		Functions\expect( 'update_post_meta' )->never();

		$logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$handler = new MeetingEndedHandler( $lookup, $meta, $logger );

		self::assertTrue( $handler->handle( $this->request( 'm-1' ) )->was_no_op );
	}
}
