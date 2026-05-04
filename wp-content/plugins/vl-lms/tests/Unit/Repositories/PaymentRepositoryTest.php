<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Repositories\PaymentRepository;

final class PaymentRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private PaymentRepository $repo;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new PaymentRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                  => '1',
				'order_id'            => '42',
				'provider'            => 'liqpay',
				'provider_payment_id' => 'lp-pay-1',
				'provider_action'     => 'pay',
				'provider_status'     => 'success',
				'transaction_type'    => 'charge',
				'amount'              => '1500.00',
				'currency'            => 'UAH',
				'raw_payload'         => '{"status":"success"}',
				'received_at'         => '2026-05-01 12:10:00',
				'idempotency_key'     => 'liqpay:lp-pay-1:pay:success',
			],
			$overrides
		);
	}

	private static function payment( string $idempotency_key = 'liqpay:lp-pay-1:pay:success' ): Payment {
		return new Payment(
			null,
			42,
			PaymentProvider::LIQPAY,
			'lp-pay-1',
			'pay',
			PaymentStatus::SUCCESS,
			'success',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{"status":"success"}',
			self::utc( '2026-05-01 12:10:00' ),
			$idempotency_key
		);
	}

	public function test_insert_writes_row_when_no_collision(): void {
		$captured = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured ): int {
					$captured = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 7;

		$id = $this->repo->insert( self::payment() );

		self::assertSame( 7, $id );
		self::assertSame( 42, $captured['order_id'] );
		self::assertSame( '1500.00', $captured['amount'] );
		self::assertSame( 'liqpay:lp-pay-1:pay:success', $captured['idempotency_key'] );
		self::assertArrayNotHasKey( 'id', $captured, 'insert payload must omit id' );
	}

	public function test_insert_throws_when_payment_already_persisted(): void {
		$payment = self::payment()->with_id( 99 );

		$this->expectException( \DomainException::class );

		$this->repo->insert( $payment );
	}

	public function test_insert_throws_when_idempotency_key_already_present(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->expectException( PaymentAlreadyRecordedException::class );

		$this->repo->insert( self::payment() );
	}

	public function test_insert_throws_when_wpdb_insert_returns_false(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->expectException( PaymentAlreadyRecordedException::class );

		$this->repo->insert( self::payment() );
	}

	public function test_find_by_id_returns_payment_when_row_exists(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$payment = $this->repo->find_by_id( 1 );

		self::assertNotNull( $payment );
		self::assertSame( 1, $payment->id );
		self::assertSame( PaymentStatus::SUCCESS, $payment->status );
		self::assertSame( 'success', $payment->raw_provider_status );
		self::assertSame( '1500.00', $payment->amount->to_major_decimal() );
	}

	public function test_find_by_id_returns_null_when_row_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull( $this->repo->find_by_id( 999 ) );
	}

	public function test_find_by_idempotency_key_returns_payment(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$payment = $this->repo->find_by_idempotency_key( 'liqpay:lp-pay-1:pay:success' );

		self::assertNotNull( $payment );
		self::assertSame( 'liqpay:lp-pay-1:pay:success', $payment->idempotency_key );
	}

	public function test_find_by_idempotency_key_returns_null_when_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull( $this->repo->find_by_idempotency_key( 'no-such-key' ) );
	}

	public function test_list_for_order_hydrates_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					self::row( [ 'id' => '1' ] ),
					self::row(
						[
							'id'              => '2',
							'idempotency_key' => 'k:2',
						]
					),
				]
			);

		$result = $this->repo->list_for_order( 42 );

		self::assertCount( 2, $result );
	}

	public function test_list_for_order_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( [], $this->repo->list_for_order( 42 ) );
	}

	public function test_list_by_provider_payment_id_filters_results(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( [ 'id' => '1' ] ) ] );

		$result = $this->repo->list_by_provider_payment_id( 'liqpay', 'lp-pay-1' );

		self::assertCount( 1, $result );
		self::assertSame( 'lp-pay-1', $result[0]->provider_payment_id );
	}
}
