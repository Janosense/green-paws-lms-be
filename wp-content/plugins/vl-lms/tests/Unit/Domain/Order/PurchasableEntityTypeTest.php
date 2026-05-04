<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Order;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\PurchasableEntityType;

final class PurchasableEntityTypeTest extends TestCase {

	public function test_case_values_are_stable_strings(): void {
		self::assertSame( 'course', PurchasableEntityType::COURSE->value );
		self::assertSame( 'webinar', PurchasableEntityType::WEBINAR->value );
	}

	public function test_wp_post_type_maps_course_to_vl_course(): void {
		self::assertSame( 'vl_course', PurchasableEntityType::COURSE->wp_post_type() );
	}

	public function test_wp_post_type_maps_webinar_to_vl_webinar(): void {
		self::assertSame( 'vl_webinar', PurchasableEntityType::WEBINAR->wp_post_type() );
	}

	public function test_from_string_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );

		PurchasableEntityType::from_string( 'session' );
	}

	public function test_from_string_returns_case_for_valid_value(): void {
		self::assertSame( PurchasableEntityType::COURSE, PurchasableEntityType::from_string( 'course' ) );
	}
}
