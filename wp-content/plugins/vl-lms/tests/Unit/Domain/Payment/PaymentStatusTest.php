<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Payment\PaymentStatus;

final class PaymentStatusTest extends TestCase {

	public function test_known_liqpay_values_resolve_to_named_cases(): void {
		self::assertSame( PaymentStatus::SUCCESS, PaymentStatus::try_from_liqpay( 'success' ) );
		self::assertSame( PaymentStatus::SANDBOX, PaymentStatus::try_from_liqpay( 'sandbox' ) );
		self::assertSame( PaymentStatus::REVERSED, PaymentStatus::try_from_liqpay( 'reversed' ) );
		self::assertSame( PaymentStatus::FAILURE, PaymentStatus::try_from_liqpay( 'failure' ) );
		self::assertSame( PaymentStatus::ERROR, PaymentStatus::try_from_liqpay( 'error' ) );
		self::assertSame( PaymentStatus::WAIT_SECURE, PaymentStatus::try_from_liqpay( 'wait_secure' ) );
		self::assertSame( PaymentStatus::WAIT_ACCEPT, PaymentStatus::try_from_liqpay( 'wait_accept' ) );
		self::assertSame( PaymentStatus::WAIT_COMPENSATION, PaymentStatus::try_from_liqpay( 'wait_compensation' ) );
		self::assertSame( PaymentStatus::PROCESSING, PaymentStatus::try_from_liqpay( 'processing' ) );
	}

	public function test_try_from_liqpay_returns_other_for_unknown_values(): void {
		self::assertSame( PaymentStatus::OTHER, PaymentStatus::try_from_liqpay( 'not_a_real_status' ) );
		self::assertSame( PaymentStatus::OTHER, PaymentStatus::try_from_liqpay( '' ) );
	}

	public function test_from_string_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );

		PaymentStatus::from_string( 'not-a-status' );
	}

	public function test_is_terminal_correct_across_all_cases(): void {
		self::assertTrue( PaymentStatus::SUCCESS->is_terminal() );
		self::assertTrue( PaymentStatus::SANDBOX->is_terminal() );
		self::assertTrue( PaymentStatus::REVERSED->is_terminal() );
		self::assertTrue( PaymentStatus::FAILURE->is_terminal() );
		self::assertTrue( PaymentStatus::ERROR->is_terminal() );
		self::assertFalse( PaymentStatus::WAIT_SECURE->is_terminal() );
		self::assertFalse( PaymentStatus::WAIT_ACCEPT->is_terminal() );
		self::assertFalse( PaymentStatus::WAIT_COMPENSATION->is_terminal() );
		self::assertFalse( PaymentStatus::PROCESSING->is_terminal() );
		self::assertFalse( PaymentStatus::OTHER->is_terminal() );
	}

	public function test_is_terminal_success_only_success_and_sandbox(): void {
		self::assertTrue( PaymentStatus::SUCCESS->is_terminal_success() );
		self::assertTrue( PaymentStatus::SANDBOX->is_terminal_success() );
		foreach ( PaymentStatus::cases() as $case ) {
			if ( PaymentStatus::SUCCESS === $case || PaymentStatus::SANDBOX === $case ) {
				continue;
			}
			self::assertFalse( $case->is_terminal_success(), sprintf( '%s should not be terminal_success', $case->value ) );
		}
	}

	public function test_is_terminal_failure_only_failure_and_error(): void {
		self::assertTrue( PaymentStatus::FAILURE->is_terminal_failure() );
		self::assertTrue( PaymentStatus::ERROR->is_terminal_failure() );
		foreach ( PaymentStatus::cases() as $case ) {
			if ( PaymentStatus::FAILURE === $case || PaymentStatus::ERROR === $case ) {
				continue;
			}
			self::assertFalse( $case->is_terminal_failure(), sprintf( '%s should not be terminal_failure', $case->value ) );
		}
	}

	public function test_is_pending_covers_four_wait_processing_cases(): void {
		self::assertTrue( PaymentStatus::WAIT_SECURE->is_pending() );
		self::assertTrue( PaymentStatus::WAIT_ACCEPT->is_pending() );
		self::assertTrue( PaymentStatus::WAIT_COMPENSATION->is_pending() );
		self::assertTrue( PaymentStatus::PROCESSING->is_pending() );

		self::assertFalse( PaymentStatus::SUCCESS->is_pending() );
		self::assertFalse( PaymentStatus::OTHER->is_pending() );
	}
}
