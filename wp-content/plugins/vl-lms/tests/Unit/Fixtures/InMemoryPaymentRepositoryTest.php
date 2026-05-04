<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Tests\Fixtures\InMemoryPaymentRepository;

final class InMemoryPaymentRepositoryTest extends TestCase {

	private InMemoryPaymentRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new InMemoryPaymentRepository();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function payment(
		int $order_id = 42,
		string $idempotency_key = 'liqpay:lp-pay-1:pay:success',
		string $received_at = '2026-05-01 12:10:00',
		?string $provider_payment_id = 'lp-pay-1',
		PaymentStatus $status = PaymentStatus::SUCCESS,
		string $raw_status = 'success',
		PaymentTransactionType $transaction_type = PaymentTransactionType::CHARGE
	): Payment {
		return new Payment(
			null,
			$order_id,
			PaymentProvider::LIQPAY,
			$provider_payment_id,
			'pay',
			$status,
			$raw_status,
			$transaction_type,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{"status":"success"}',
			self::utc( $received_at ),
			$idempotency_key
		);
	}

	public function test_insert_assigns_id_and_round_trips(): void {
		$id = $this->repo->insert( self::payment() );

		self::assertSame( 1, $id );
		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( 42, $loaded->order_id );
		self::assertSame( '1500.00', $loaded->amount->to_major_decimal() );
		self::assertSame( PaymentStatus::SUCCESS, $loaded->status );
		self::assertSame( 'success', $loaded->raw_provider_status );
	}

	public function test_insert_rejects_already_persisted_payment(): void {
		$payment = self::payment()->with_id( 99 );

		$this->expectException( \DomainException::class );

		$this->repo->insert( $payment );
	}

	public function test_insert_rejects_duplicate_idempotency_key(): void {
		$this->repo->insert( self::payment() );

		$this->expectException( PaymentAlreadyRecordedException::class );

		$this->repo->insert( self::payment() );
	}

	public function test_find_by_idempotency_key_returns_payment(): void {
		$this->repo->insert( self::payment() );

		$loaded = $this->repo->find_by_idempotency_key( 'liqpay:lp-pay-1:pay:success' );

		self::assertNotNull( $loaded );
		self::assertSame( 42, $loaded->order_id );
	}

	public function test_find_by_idempotency_key_returns_null_when_missing(): void {
		self::assertNull( $this->repo->find_by_idempotency_key( 'no-such-key' ) );
	}

	public function test_list_for_order_returns_chronological_order(): void {
		$this->repo->insert(
			self::payment(
				order_id: 1,
				idempotency_key: 'k:1',
				received_at: '2026-05-01 12:30:00'
			)
		);
		$this->repo->insert(
			self::payment(
				order_id: 1,
				idempotency_key: 'k:2',
				received_at: '2026-05-01 12:10:00'
			)
		);
		$this->repo->insert(
			self::payment(
				order_id: 1,
				idempotency_key: 'k:3',
				received_at: '2026-05-01 12:20:00'
			)
		);

		$result = $this->repo->list_for_order( 1 );

		self::assertCount( 3, $result );
		self::assertSame( 'k:2', $result[0]->idempotency_key );
		self::assertSame( 'k:3', $result[1]->idempotency_key );
		self::assertSame( 'k:1', $result[2]->idempotency_key );
	}

	public function test_list_for_order_returns_empty_for_unknown_order(): void {
		self::assertSame( [], $this->repo->list_for_order( 999 ) );
	}

	public function test_list_by_provider_payment_id_filters_provider_and_id(): void {
		$this->repo->insert(
			self::payment(
				idempotency_key: 'k:1',
				provider_payment_id: 'lp-pay-1',
				received_at: '2026-05-01 12:10:00'
			)
		);
		$this->repo->insert(
			self::payment(
				idempotency_key: 'k:2',
				provider_payment_id: 'lp-pay-1',
				received_at: '2026-05-02 12:10:00',
				status: PaymentStatus::REVERSED,
				raw_status: 'reversed',
				transaction_type: PaymentTransactionType::REFUND
			)
		);
		$this->repo->insert(
			self::payment(
				idempotency_key: 'k:3',
				provider_payment_id: 'lp-pay-2',
			)
		);

		$result = $this->repo->list_by_provider_payment_id( 'liqpay', 'lp-pay-1' );

		self::assertCount( 2, $result );
		self::assertSame( PaymentStatus::SUCCESS, $result[0]->status );
		self::assertSame( PaymentStatus::REVERSED, $result[1]->status );
	}

	public function test_list_by_provider_payment_id_skips_null_provider_payment_id(): void {
		$this->repo->insert(
			self::payment(
				idempotency_key: 'k:nullid',
				provider_payment_id: null,
			)
		);

		self::assertSame( [], $this->repo->list_by_provider_payment_id( 'liqpay', 'anything' ) );
	}
}
