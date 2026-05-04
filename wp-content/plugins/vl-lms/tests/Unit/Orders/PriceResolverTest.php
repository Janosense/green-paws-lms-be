<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Orders\PriceResolver;

final class PriceResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_course_returns_money_for_non_zero_price(): void {
		Functions\when( 'get_post_meta' )
			->alias(
				static function ( int $id, string $key, bool $single ): string {
					if ( '_vl_course_price' === $key && 100 === $id && $single ) {
						return '1500.00';
					}
					return '';
				}
			);

		$resolver = new PriceResolver();

		$money = $resolver->resolve( 100, PurchasableEntityType::COURSE );

		self::assertNotNull( $money );
		self::assertSame( '1500.00', $money->to_major_decimal() );
		self::assertSame( 'UAH', $money->currency() );
	}

	public function test_webinar_uses_webinar_price_meta(): void {
		Functions\when( 'get_post_meta' )
			->alias(
				static function ( int $id, string $key ): string {
					if ( '_vl_webinar_price' === $key && 200 === $id ) {
						return '500.00';
					}
					return '';
				}
			);

		$resolver = new PriceResolver();

		$money = $resolver->resolve( 200, PurchasableEntityType::WEBINAR );

		self::assertNotNull( $money );
		self::assertSame( '500.00', $money->to_major_decimal() );
	}

	public function test_missing_meta_returns_null(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$resolver = new PriceResolver();

		self::assertNull( $resolver->resolve( 100, PurchasableEntityType::COURSE ) );
	}

	public function test_zero_price_returns_null(): void {
		Functions\when( 'get_post_meta' )->justReturn( '0.00' );

		$resolver = new PriceResolver();

		self::assertNull( $resolver->resolve( 100, PurchasableEntityType::COURSE ) );
	}

	public function test_zero_int_string_returns_null(): void {
		Functions\when( 'get_post_meta' )->justReturn( '0' );

		$resolver = new PriceResolver();

		self::assertNull( $resolver->resolve( 100, PurchasableEntityType::COURSE ) );
	}

	public function test_non_numeric_meta_returns_null(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'not-a-number' );

		$resolver = new PriceResolver();

		self::assertNull( $resolver->resolve( 100, PurchasableEntityType::COURSE ) );
	}
}
