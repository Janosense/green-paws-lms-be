<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Access;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Access\AccessDecision;

final class AccessDecisionTest extends TestCase {

	public function test_allow_factory_sets_allowed_true_and_clears_reason(): void {
		$decision = AccessDecision::allow( 7, false );

		self::assertTrue( $decision->allowed );
		self::assertNull( $decision->reason );
		self::assertSame( 7, $decision->course_id );
		self::assertFalse( $decision->is_preview );
	}

	public function test_allow_factory_carries_preview_flag(): void {
		$decision = AccessDecision::allow( 7, true );

		self::assertTrue( $decision->is_preview );
	}

	public function test_deny_factory_carries_reason_and_zero_course_id_by_default(): void {
		$decision = AccessDecision::deny( 'parent_not_found' );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'parent_not_found', $decision->reason );
		self::assertSame( 0, $decision->course_id );
		self::assertFalse( $decision->is_preview );
	}

	public function test_deny_factory_carries_explicit_course_id_when_known(): void {
		$decision = AccessDecision::deny( 'not_enrolled', 42 );

		self::assertSame( 42, $decision->course_id );
		self::assertSame( 'not_enrolled', $decision->reason );
	}

	public function test_constructor_assigns_all_properties_directly(): void {
		$decision = new AccessDecision( true, null, 99, true );

		self::assertTrue( $decision->allowed );
		self::assertNull( $decision->reason );
		self::assertSame( 99, $decision->course_id );
		self::assertTrue( $decision->is_preview );
	}

	public function test_properties_are_readonly(): void {
		$decision = AccessDecision::allow( 1, false );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Property.ReadOnlyAssignNotInScope
		$decision->allowed = false;
	}
}
