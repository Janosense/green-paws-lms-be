<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Domain;

use PHPUnit\Framework\TestCase;
use VL\LMS\StudyTime\Domain\StudyKind;

final class StudyKindTest extends TestCase {

	public function test_backed_values_are_the_ledger_and_heartbeat_strings(): void {
		// The values are the `vl_study_time.kind` column and the heartbeat
		// body contract; renaming one orphans stored rows.
		self::assertSame( [ 'video', 'reading' ], array_map( static fn ( StudyKind $kind ): string => $kind->value, StudyKind::cases() ) );
	}
}
