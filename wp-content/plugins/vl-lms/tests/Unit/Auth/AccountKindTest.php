<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\AccountKind;

final class AccountKindTest extends TestCase {

	public function test_student_is_in_allow_list(): void {
		self::assertContains( AccountKind::STUDENT, AccountKind::ALLOWED );
	}

	public function test_allow_list_currently_contains_only_student(): void {
		self::assertSame( [ AccountKind::STUDENT ], AccountKind::ALLOWED );
	}

	public function test_student_value_is_lower_case_string(): void {
		self::assertSame( 'student', AccountKind::STUDENT );
	}
}
