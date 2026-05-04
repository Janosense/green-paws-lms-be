<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\SyncReason;

final class SyncReasonTest extends TestCase {

	public function test_cases_have_stable_string_values(): void {
		self::assertSame( 'not_configured', SyncReason::NOT_CONFIGURED->value );
		self::assertSame( 'revision_or_autosave', SyncReason::REVISION_OR_AUTOSAVE->value );
		self::assertSame( 'invalid_post_status', SyncReason::INVALID_POST_STATUS->value );
		self::assertSame( 'locked', SyncReason::LOCKED->value );
		self::assertSame( 'cancelled_without_meeting', SyncReason::CANCELLED_WITHOUT_MEETING->value );
		self::assertSame( 'no_diff', SyncReason::NO_DIFF->value );
		self::assertSame( 'missing_required_meta', SyncReason::MISSING_REQUIRED_META->value );
		self::assertSame( 'demo_bypass', SyncReason::DEMO_BYPASS->value );
	}

	public function test_case_count_is_eight(): void {
		self::assertCount( 8, SyncReason::cases() );
	}
}
