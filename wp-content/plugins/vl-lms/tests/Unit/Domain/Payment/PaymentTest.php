<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;

final class PaymentTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function unsaved_payment(): Payment {
		return new Payment(
			null,
			42,
			PaymentProvider::LIQPAY,
			'lp-payment-9001',
			'pay',
			PaymentStatus::SUCCESS,
			'success',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{"status":"success"}',
			self::utc( '2026-05-01 12:10:00' ),
			'liqpay:lp-payment-9001:pay:success'
		);
	}

	public function test_construction_preserves_field_values(): void {
		$payment = self::unsaved_payment();

		self::assertNull( $payment->id );
		self::assertSame( 42, $payment->order_id );
		self::assertSame( PaymentProvider::LIQPAY, $payment->provider );
		self::assertSame( 'lp-payment-9001', $payment->provider_payment_id );
		self::assertSame( 'pay', $payment->provider_action );
		self::assertSame( PaymentStatus::SUCCESS, $payment->status );
		self::assertSame( 'success', $payment->raw_provider_status );
		self::assertSame( PaymentTransactionType::CHARGE, $payment->transaction_type );
		self::assertSame( '1500.00', $payment->amount->to_major_decimal() );
		self::assertSame( '{"status":"success"}', $payment->raw_payload );
		self::assertSame( 'liqpay:lp-payment-9001:pay:success', $payment->idempotency_key );
	}

	public function test_with_id_attaches_pk(): void {
		$payment = self::unsaved_payment();

		$persisted = $payment->with_id( 99 );

		self::assertNull( $payment->id );
		self::assertSame( 99, $persisted->id );
	}

	public function test_with_id_throws_when_already_persisted(): void {
		$payment = self::unsaved_payment()->with_id( 99 );

		$this->expectException( \DomainException::class );

		$payment->with_id( 100 );
	}

	public function test_constructor_rejects_empty_idempotency_key(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Payment(
			null,
			42,
			PaymentProvider::LIQPAY,
			null,
			'pay',
			PaymentStatus::SUCCESS,
			'success',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{}',
			self::utc( '2026-05-01 12:10:00' ),
			''
		);
	}

	public function test_constructor_rejects_empty_provider_action(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Payment(
			null,
			42,
			PaymentProvider::LIQPAY,
			null,
			'',
			PaymentStatus::SUCCESS,
			'success',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{}',
			self::utc( '2026-05-01 12:10:00' ),
			'liqpay:x:pay:success'
		);
	}

	public function test_provider_payment_id_can_be_null(): void {
		$payment = new Payment(
			null,
			42,
			PaymentProvider::LIQPAY,
			null,
			'pay',
			PaymentStatus::ERROR,
			'error',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '0.00', 'UAH' ),
			'{}',
			self::utc( '2026-05-01 12:10:00' ),
			'liqpay::pay:error'
		);

		self::assertNull( $payment->provider_payment_id );
	}
}
