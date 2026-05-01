<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Sync\SyncDecision;
use VL\LMS\Services\Zoom\Sync\SyncReason;
use VL\LMS\Services\Zoom\Sync\SyncResult;

final class SyncResultTest extends TestCase {

	public function test_created_carries_meeting_id_and_no_reason(): void {
		$r = SyncResult::created( 42, PostKind::SESSION, 'mtg-1' );

		self::assertSame( 42, $r->post_id );
		self::assertSame( PostKind::SESSION, $r->kind );
		self::assertSame( SyncDecision::CREATE, $r->decision );
		self::assertSame( 'mtg-1', $r->meeting_id );
		self::assertNull( $r->reason );
		self::assertNull( $r->exception );
	}

	public function test_updated_carries_meeting_id(): void {
		$r = SyncResult::updated( 42, PostKind::WEBINAR, 'mtg-2' );

		self::assertSame( SyncDecision::UPDATE, $r->decision );
		self::assertSame( 'mtg-2', $r->meeting_id );
	}

	public function test_deleted_has_null_meeting_id(): void {
		$r = SyncResult::deleted( 5, PostKind::SESSION );

		self::assertSame( SyncDecision::DELETE, $r->decision );
		self::assertNull( $r->meeting_id );
	}

	public function test_noop_carries_reason_only(): void {
		$r = SyncResult::noop( 1, PostKind::SESSION, SyncReason::NO_DIFF );

		self::assertSame( SyncDecision::NOOP, $r->decision );
		self::assertSame( SyncReason::NO_DIFF, $r->reason );
		self::assertNull( $r->meeting_id );
	}

	public function test_skipped_carries_reason_only(): void {
		$r = SyncResult::skipped( 1, PostKind::SESSION, SyncReason::NOT_CONFIGURED );

		self::assertSame( SyncDecision::SKIPPED, $r->decision );
		self::assertSame( SyncReason::NOT_CONFIGURED, $r->reason );
	}

	public function test_failed_captures_exception_and_intended_decision(): void {
		$ex = new \RuntimeException( 'boom' );
		$r  = SyncResult::failed( 7, PostKind::WEBINAR, SyncDecision::CREATE, $ex );

		self::assertSame( SyncDecision::CREATE, $r->decision );
		self::assertSame( $ex, $r->exception );
		self::assertNull( $r->meeting_id );
	}
}
