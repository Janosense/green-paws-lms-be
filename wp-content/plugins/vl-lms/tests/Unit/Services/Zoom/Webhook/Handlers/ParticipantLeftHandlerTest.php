<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook\Handlers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Services\Zoom\LookupResult;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\Handlers\ParticipantLeftHandler;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemorySessionAttendanceRepository;
use VL\LMS\Tests\Fixtures\InMemoryWebinarRegistrationRepository;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\InMemoryWebinarJoinTracker;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\StubPostLookup;
use WP_Post;

final class ParticipantLeftHandlerTest extends TestCase {

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
			'meeting.participant_left',
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

	private function utc( string $iso ): \DateTimeImmutable {
		return new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
	}

	public function test_session_open_row_closed_with_duration(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );
		$attendance                   = new InMemorySessionAttendanceRepository();
		$attendance->record_join( 11, null, 'uuid-1', null, null, $this->utc( '2026-05-01T09:00:00Z' ) );

		$handler = new ParticipantLeftHandler(
			$lookup,
			$attendance,
			new InMemoryWebinarJoinTracker(),
			new InMemoryWebinarRegistrationRepository(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'leave_time'       => '2026-05-01T09:30:00Z',
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertSame( 'session_leave_recorded', $outcome->action_label );

		$rows = $attendance->list_for_session( 11 );
		self::assertCount( 1, $rows );
		self::assertNotNull( $rows[0]->left_at );
		self::assertSame( 1800, $rows[0]->duration_seconds );
	}

	public function test_session_orphan_leave_is_warning_noop(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldReceive( 'warning' )->once();
		$logger->shouldIgnoreMissing();

		$handler = new ParticipantLeftHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			new InMemoryWebinarJoinTracker(),
			new InMemoryWebinarRegistrationRepository(),
			$logger
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'orphan',
					'leave_time'       => '2026-05-01T09:30:00Z',
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'orphan_leave_session', $outcome->action_label );
	}

	public function test_webinar_credits_attended_duration_when_user_registered(): void {
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

		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 22, 'uuid-1', $this->utc( '2026-05-01T09:00:00Z' ) );

		$registrations = new InMemoryWebinarRegistrationRepository();
		$registrations->register( 22, 77, WebinarRegistrationSource::PURCHASE, $this->utc( '2026-04-01T00:00:00Z' ) );

		$handler = new ParticipantLeftHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			$tracker,
			$registrations,
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'email'            => 'a@b.test',
					'leave_time'       => '2026-05-01T10:00:00Z',
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertSame( 'webinar_attendance_credited', $outcome->action_label );

		$reg = $registrations->find( 22, 77 );
		self::assertNotNull( $reg );
		self::assertTrue( $reg->attended );
		self::assertSame( 3600, $reg->attended_duration_seconds );
	}

	public function test_webinar_anonymous_leave_no_email(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 22, 'uuid-1', $this->utc( '2026-05-01T09:00:00Z' ) );

		$handler = new ParticipantLeftHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			$tracker,
			new InMemoryWebinarRegistrationRepository(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'email'            => '',
					'leave_time'       => '2026-05-01T10:00:00Z',
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'webinar_anonymous_leave', $outcome->action_label );
	}

	public function test_webinar_unregistered_user_is_noop(): void {
		Functions\when( 'get_user_by' )->alias(
			static function () {
				$u     = new \stdClass();
				$u->ID = 77;
				return $u;
			}
		);

		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 22, 'uuid-1', $this->utc( '2026-05-01T09:00:00Z' ) );

		$handler = new ParticipantLeftHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			$tracker,
			new InMemoryWebinarRegistrationRepository(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing()
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'email'            => 'noreg@b.test',
					'leave_time'       => '2026-05-01T10:00:00Z',
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'webinar_unregistered_leave', $outcome->action_label );
	}

	public function test_webinar_orphan_leave_no_open_join(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldReceive( 'warning' )->once();
		$logger->shouldIgnoreMissing();

		$handler = new ParticipantLeftHandler(
			$lookup,
			new InMemorySessionAttendanceRepository(),
			new InMemoryWebinarJoinTracker(),
			new InMemoryWebinarRegistrationRepository(),
			$logger
		);

		$outcome = $handler->handle(
			$this->request(
				'm-1',
				[
					'participant_uuid' => 'uuid-1',
					'email'            => '',
					'leave_time'       => '2026-05-01T10:00:00Z',
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'orphan_leave_webinar', $outcome->action_label );
	}
}
