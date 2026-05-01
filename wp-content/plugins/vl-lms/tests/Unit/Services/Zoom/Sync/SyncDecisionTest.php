<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\SyncDecision;

final class SyncDecisionTest extends TestCase {

	public function test_cases_have_stable_string_values(): void {
		self::assertSame( 'create', SyncDecision::CREATE->value );
		self::assertSame( 'update', SyncDecision::UPDATE->value );
		self::assertSame( 'delete', SyncDecision::DELETE->value );
		self::assertSame( 'noop', SyncDecision::NOOP->value );
		self::assertSame( 'skipped', SyncDecision::SKIPPED->value );
	}

	public function test_case_count_is_five(): void {
		self::assertCount( 5, SyncDecision::cases() );
	}
}
