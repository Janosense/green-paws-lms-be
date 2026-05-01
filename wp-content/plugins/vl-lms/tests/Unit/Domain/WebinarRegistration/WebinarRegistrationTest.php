<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\WebinarRegistration;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;

final class WebinarRegistrationTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'                        => '12',
			'webinar_id'                => '500',
			'user_id'                   => '7',
			'status'                    => 'active',
			'source'                    => 'self_signup',
			'registered_at'             => '2026-04-20 09:00:00',
			'cancelled_at'              => null,
			'attended'                  => '0',
			'attended_duration_seconds' => '0',
			'created_at'                => '2026-04-20 09:00:00',
			'updated_at'                => '2026-04-20 09:00:00',
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row = array_merge(
			self::sample_row(),
			[
				'status'                    => 'cancelled',
				'source'                    => 'manual',
				'cancelled_at'              => '2026-04-21 09:00:00',
				'attended'                  => '1',
				'attended_duration_seconds' => '3600',
			]
		);

		$reg = WebinarRegistration::from_row( $row );

		self::assertSame( 12, $reg->id );
		self::assertSame( 500, $reg->webinar_id );
		self::assertSame( 7, $reg->user_id );
		self::assertSame( WebinarRegistrationStatus::CANCELLED, $reg->status );
		self::assertSame( WebinarRegistrationSource::MANUAL, $reg->source );
		self::assertSame( '2026-04-20 09:00:00', $reg->registered_at );
		self::assertSame( '2026-04-21 09:00:00', $reg->cancelled_at );
		self::assertTrue( $reg->attended );
		self::assertSame( 3600, $reg->attended_duration_seconds );
	}

	public function test_from_row_preserves_null_cancelled_at(): void {
		$reg = WebinarRegistration::from_row( self::sample_row() );

		self::assertNull( $reg->cancelled_at );
		self::assertFalse( $reg->attended );
		self::assertSame( 0, $reg->attended_duration_seconds );
	}

	public function test_from_row_rejects_unknown_status(): void {
		$row           = self::sample_row();
		$row['status'] = 'pending';

		$this->expectException( \InvalidArgumentException::class );

		WebinarRegistration::from_row( $row );
	}

	public function test_from_row_rejects_unknown_source(): void {
		$row           = self::sample_row();
		$row['source'] = 'affiliate';

		$this->expectException( \InvalidArgumentException::class );

		WebinarRegistration::from_row( $row );
	}
}
