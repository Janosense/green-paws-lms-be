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
use VL\LMS\Services\Zoom\Webhook\HandlerException;
use VL\LMS\Services\Zoom\Webhook\Handlers\ParticipantJoinedHandler;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemorySessionAttendanceRepository;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\InMemoryWebinarJoinTracker;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\StubPostLookup;
use WP_Post;

final class ParticipantJoinedHandlerTest extends TestCase {

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

	/**
	 * @param array<string, mixed> $participant
	 */
	private function request( string $meeting_id, array $participant ): WebhookRequest {
		return new WebhookRequest(
			'meeting.participant_joined',
			[
				'id'          => $meeting_id,
				'participant' => $participant,
			],
			'A',
			'tr',
			1,
			'{}',
			''
		);
	}

	public function test_unknown_meeting_id_is_noop(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$handler = new ParticipantJoinedHandler(
			new StubPostLookup(),
			new InMemorySessionAttendanceRepository(),
			new InMemoryWebinarJoinTracker(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'X',
				[
					'participant_uuid' => 'uuid',
					'user_name'        => 'Foo',
					'email'            => '',
					'join_time'        => '2026-05-01T09:00:00Z',
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'unknown_meeting_id', $outcome->action_label );
	}

	public function test_session_join_with_email_resolves_user_id(): void {
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, string $value ) {
				if ( 'email' === $field && 'a@b.test' === $value ) {
					$u     = new \stdClass();
					$u->ID = 77;
					return $u;
				}
				return false;
			}
		);

		$post = $this->post( 11, 'vl_session' );

		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$attendance                   = new InMemorySessionAttendanceRepository();

		$handler = new ParticipantJoinedHandler(
			$lookup,
			$attendance,
			new InMemoryWebinarJoinTracker(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'user_name'        => 'Alice',
					'email'            => 'a@b.test',
					'join_time'        => '2026-05-01T09:00:00Z',
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertSame( 'session_join_recorded', $outcome->action_label );

		$open = $attendance->find_open( 11, 'uuid-1' );
		self::assertNotNull( $open );
		self::assertSame( 77, $open->user_id );
	}

	public function test_session_join_without_matching_email_falls_back_to_null_user(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$attendance                   = new InMemorySessionAttendanceRepository();

		$handler = new ParticipantJoinedHandler(
			$lookup,
			$attendance,
			new InMemoryWebinarJoinTracker(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'user_name'        => 'Alice',
					'email'            => 'unknown@example.test',
					'join_time'        => '2026-05-01T09:00:00Z',
				]
			)
		);

		$open = $attendance->find_open( 11, 'uuid-1' );
		self::assertNotNull( $open );
		self::assertNull( $open->user_id );
	}

	public function test_session_duplicate_join_is_idempotent(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$attendance                   = new InMemorySessionAttendanceRepository();

		$handler = new ParticipantJoinedHandler(
			$lookup,
			$attendance,
			new InMemoryWebinarJoinTracker(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$payload = [
			'participant_uuid' => 'uuid-1',
			'user_name'        => 'Alice',
			'email'            => '',
			'join_time'        => '2026-05-01T09:00:00Z',
		];

		$first  = $handler->handle( $this->request( 'm-1', $payload ) );
		$second = $handler->handle( $this->request( 'm-1', $payload ) );

		self::assertFalse( $first->was_no_op );
		self::assertFalse( $second->was_no_op );
		self::assertCount( 1, $attendance->list_for_session( 11 ) );
	}

	public function test_webinar_join_is_tracked_in_transient(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );
		$tracker                      = new InMemoryWebinarJoinTracker();

		$handler = new ParticipantJoinedHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			$tracker,
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'user_name'        => 'Alice',
					'email'            => '',
					'join_time'        => '2026-05-01T09:00:00Z',
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertArrayHasKey( 'vl_lms_zoom_webinar_join_22_uuid-1', $tracker->store );
	}

	public function test_missing_participant_uuid_throws_handler_exception(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );

		$handler = new ParticipantJoinedHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			new InMemoryWebinarJoinTracker(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$this->expectException( HandlerException::class );
		$handler->handle(
			$this->request(
				'm-1',
				[
					'user_name' => 'Alice',
					'email'     => '',
					'join_time' => '2026-05-01T09:00:00Z',
				]
			)
		);
	}
}
