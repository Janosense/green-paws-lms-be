<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Tests\Fixtures\InMemoryWebinarRegistrationRepository;

final class InMemoryWebinarRegistrationRepositoryTest extends TestCase {

	private InMemoryWebinarRegistrationRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new InMemoryWebinarRegistrationRepository();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_register_inserts_new_active_row(): void {
		$reg = $this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );

		self::assertSame( 500, $reg->webinar_id );
		self::assertSame( 7, $reg->user_id );
		self::assertSame( WebinarRegistrationStatus::ACTIVE, $reg->status );
	}

	public function test_register_after_cancel_flips_back_to_active(): void {
		$first = $this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->cancel( 500, 7, self::utc( '2026-04-21 09:00:00' ) );
		$reactivated = $this->repo->register( 500, 7, WebinarRegistrationSource::MANUAL, self::utc( '2026-04-22 09:00:00' ) );

		self::assertSame( $first->id, $reactivated->id );
		self::assertSame( WebinarRegistrationStatus::ACTIVE, $reactivated->status );
		self::assertSame( WebinarRegistrationSource::MANUAL, $reactivated->source );
		self::assertNull( $reactivated->cancelled_at );
	}

	public function test_cancel_returns_null_when_no_row(): void {
		self::assertNull( $this->repo->cancel( 500, 7, self::utc( '2026-04-22 09:00:00' ) ) );
	}

	public function test_find_active_skips_cancelled(): void {
		$this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->cancel( 500, 7, self::utc( '2026-04-21 09:00:00' ) );

		self::assertNull( $this->repo->find_active( 500, 7 ) );
	}

	public function test_count_active_for_webinar_excludes_cancelled(): void {
		$this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->register( 500, 8, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->cancel( 500, 8, self::utc( '2026-04-20 10:00:00' ) );

		self::assertSame( 1, $this->repo->count_active_for_webinar( 500 ) );
	}

	public function test_mark_attended_accumulates_duration(): void {
		$this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );

		$this->repo->mark_attended( 500, 7, 600 );
		$this->repo->mark_attended( 500, 7, 300 );

		$reg = $this->repo->find( 500, 7 );
		self::assertNotNull( $reg );
		self::assertTrue( $reg->attended );
		self::assertSame( 900, $reg->attended_duration_seconds );
	}

	public function test_mark_attended_no_op_when_no_row(): void {
		$this->repo->mark_attended( 500, 7, 600 );

		self::assertNull( $this->repo->find( 500, 7 ) );
	}

	public function test_list_for_user_filters_by_status(): void {
		$this->repo->register( 500, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->register( 501, 7, WebinarRegistrationSource::SELF_SIGNUP, self::utc( '2026-04-20 09:00:00' ) );
		$this->repo->cancel( 501, 7, self::utc( '2026-04-21 09:00:00' ) );

		self::assertCount( 1, $this->repo->list_for_user( 7, WebinarRegistrationStatus::ACTIVE ) );
		self::assertCount( 1, $this->repo->list_for_user( 7, WebinarRegistrationStatus::CANCELLED ) );
		self::assertCount( 2, $this->repo->list_for_user( 7 ) );
	}
}
