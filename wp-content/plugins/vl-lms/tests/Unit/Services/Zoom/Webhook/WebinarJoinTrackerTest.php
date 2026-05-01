<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\InMemoryWebinarJoinTracker;

final class WebinarJoinTrackerTest extends TestCase {

	private function utc( string $iso ): \DateTimeImmutable {
		return new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
	}

	public function test_consume_returns_duration_after_record(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) );

		$duration = $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:42:30Z' ) );

		self::assertSame( 42 * 60 + 30, $duration );
	}

	public function test_consume_returns_null_when_no_record(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		self::assertNull( $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) ) );
	}

	public function test_consume_clears_transient_so_double_consume_returns_null(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) );

		$first  = $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:30:00Z' ) );
		$second = $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:30:00Z' ) );

		self::assertSame( 1800, $first );
		self::assertNull( $second );
	}

	public function test_record_open_uses_24h_ttl(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) );

		self::assertSame( 86400, $tracker->writes[0]['ttl'] );
	}

	public function test_keys_are_isolated_per_webinar_and_participant(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) );
		$tracker->record_open( 200, 'uuid-A', $this->utc( '2026-05-01T09:05:00Z' ) );

		self::assertCount( 2, $tracker->store );
		self::assertSame( 600, $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:10:00Z' ) ) );
		self::assertSame( 300, $tracker->consume( 200, 'uuid-A', $this->utc( '2026-05-01T09:10:00Z' ) ) );
	}

	public function test_negative_duration_clamped_to_zero(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->record_open( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) );

		self::assertSame( 0, $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T08:00:00Z' ) ) );
	}

	public function test_malformed_stored_value_consumes_to_null(): void {
		$tracker = new InMemoryWebinarJoinTracker();
		$tracker->store['vl_lms_zoom_webinar_join_100_uuid-A'] = 'not-a-date';

		self::assertNull( $tracker->consume( 100, 'uuid-A', $this->utc( '2026-05-01T09:00:00Z' ) ) );
	}
}
