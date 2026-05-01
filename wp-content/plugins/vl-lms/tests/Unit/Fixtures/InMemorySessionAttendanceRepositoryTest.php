<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Tests\Fixtures\InMemorySessionAttendanceRepository;

final class InMemorySessionAttendanceRepositoryTest extends TestCase {

	private InMemorySessionAttendanceRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new InMemorySessionAttendanceRepository();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_record_join_creates_open_row(): void {
		$row = $this->repo->record_join( 101, 7, 'uuid-a', 'p@ex.com', 'Pat', self::utc( '2026-04-23 10:00:00' ) );

		self::assertSame( 101, $row->session_id );
		self::assertSame( 'uuid-a', $row->zoom_participant_uuid );
		self::assertNull( $row->left_at );
	}

	public function test_record_join_is_idempotent_for_same_uuid(): void {
		$first  = $this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 10:00:00' ) );
		$second = $this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 10:05:00' ) );

		self::assertSame( $first->id, $second->id );
		self::assertCount( 1, $this->repo->list_for_session( 101 ) );
	}

	public function test_record_leave_stamps_left_at_and_duration(): void {
		$this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 10:00:00' ) );

		$result = $this->repo->record_leave( 101, 'uuid-a', self::utc( '2026-04-23 10:45:00' ) );

		self::assertNotNull( $result );
		self::assertSame( '2026-04-23 10:45:00', $result->left_at );
		self::assertSame( 2700, $result->duration_seconds );
	}

	public function test_record_leave_returns_null_for_no_open_row(): void {
		self::assertNull( $this->repo->record_leave( 101, 'uuid-missing', self::utc( '2026-04-23 10:45:00' ) ) );
	}

	public function test_record_leave_then_rejoin_creates_new_row(): void {
		$this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 10:00:00' ) );
		$this->repo->record_leave( 101, 'uuid-a', self::utc( '2026-04-23 10:30:00' ) );
		$this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 11:00:00' ) );

		self::assertCount( 2, $this->repo->list_for_session( 101 ) );
	}

	public function test_list_for_user_filters_by_session_id(): void {
		$this->repo->record_join( 101, 7, 'uuid-a', null, null, self::utc( '2026-04-23 10:00:00' ) );
		$this->repo->record_join( 102, 7, 'uuid-b', null, null, self::utc( '2026-04-23 11:00:00' ) );

		self::assertCount( 1, $this->repo->list_for_user( 7, 101 ) );
		self::assertCount( 2, $this->repo->list_for_user( 7 ) );
	}

	public function test_list_for_user_skips_null_user_rows(): void {
		$this->repo->record_join( 101, null, 'uuid-anon', null, null, self::utc( '2026-04-23 10:00:00' ) );

		self::assertCount( 0, $this->repo->list_for_user( 7 ) );
	}
}
