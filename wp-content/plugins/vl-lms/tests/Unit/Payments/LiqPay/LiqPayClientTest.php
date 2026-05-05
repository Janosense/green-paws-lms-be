<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\LiqPay\HttpClient;
use VL\LMS\Payments\LiqPay\LiqPayClient;
use VL\LMS\Payments\LiqPay\PayloadBuilder;
use VL\LMS\Payments\LiqPay\RefundResponse;
use VL\LMS\Payments\LiqPay\RefundResponseParser;
use VL\LMS\Payments\LiqPay\SignatureBuilder;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Payments\LiqPay\TestableLiqPayClient;
use VL\LMS\Tests\Fixtures\Payments\LiqPay\TestLiqPaySettings;

final class LiqPayClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->justReturn( 'https://app.example.test' );
		Functions\when( 'rest_url' )
			->alias( static fn ( string $path ): string => 'https://api.example.test/wp-json/' . $path );
		Functions\when( 'wp_json_encode' )
			->alias( static fn ( mixed $value ): string => (string) json_encode( $value ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_prepare_payment_returns_prepared_payment_with_three_fields(): void {
		$client = $this->configured_client();

		$prepared = $client->prepare_payment( $this->order() );

		self::assertInstanceOf( PreparedPayment::class, $prepared );
		self::assertSame( LiqPayClient::CHECKOUT_ACTION_URL, $prepared->action_url );
		self::assertSame( 'POST', $prepared->http_method );
		self::assertArrayHasKey( 'data', $prepared->fields );
		self::assertArrayHasKey( 'signature', $prepared->fields );
		self::assertArrayHasKey( 'version', $prepared->fields );
		self::assertSame( '3', $prepared->fields['version'] );
	}

	public function test_data_field_round_trips_to_expected_payload(): void {
		$client = $this->configured_client();

		$prepared = $client->prepare_payment( $this->order() );

		$decoded = json_decode( base64_decode( $prepared->fields['data'], true ), true );
		self::assertIsArray( $decoded );
		self::assertSame( 'pk_test', $decoded['public_key'] );
		self::assertSame( 'pay', $decoded['action'] );
		self::assertSame( '1500.00', $decoded['amount'] );
		self::assertSame( 'UAH', $decoded['currency'] );
		self::assertSame( 'https://api.example.test/wp-json/vl/v1/payments/liqpay/callback', $decoded['server_url'] );
	}

	public function test_signature_is_deterministic_for_signed_data(): void {
		$client = $this->configured_client();

		$prepared = $client->prepare_payment( $this->order() );

		$signature_builder = new SignatureBuilder();
		$expected          = $signature_builder->build( 'sk_test', $prepared->fields['data'] );

		self::assertSame( $expected, $prepared->fields['signature'] );
	}

	public function test_throws_when_public_key_missing(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$client      = new LiqPayClient(
			new TestLiqPaySettings(
				constants: [ 'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_test' ]
			),
			new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderUnavailableException::class );
		$client->prepare_payment( $this->order() );
	}

	public function test_throws_when_private_key_missing(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$client      = new LiqPayClient(
			new TestLiqPaySettings(
				constants: [ 'VL_LMS_LIQPAY_PUBLIC_KEY' => 'pk_test' ]
			),
			new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderUnavailableException::class );
		$client->prepare_payment( $this->order() );
	}

	public function test_refund_payment_happy_path_returns_reversed_payment(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturn(
				[
					'status_code' => 200,
					'body'        => '{"status":"reversed","payment_id":987654}',
					'headers'     => [],
				]
			);
		$parser = new RefundResponseParser();

		$client = new TestableLiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			$parser
		);
		$client->set_clock( new \DateTimeImmutable( '2026-05-04T10:00:00Z' ) );

		$payment = $client->refund_payment( $this->order() );

		self::assertInstanceOf( Payment::class, $payment );
		self::assertSame( PaymentTransactionType::REFUND, $payment->transaction_type );
		self::assertSame( PaymentStatus::REVERSED, $payment->status );
		self::assertSame( 'reversed', $payment->raw_provider_status );
		self::assertSame( '987654', $payment->provider_payment_id );
		self::assertSame( 'refund', $payment->provider_action );
		self::assertSame( 'liqpay:987654:refund:reversed', $payment->idempotency_key );
		self::assertSame( '1500.00', $payment->amount->to_major_decimal() );
	}

	public function test_refund_payment_throws_unavailable_when_creds_missing(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldNotReceive( 'post' );
		$client = new LiqPayClient(
			new TestLiqPaySettings(),
			new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderUnavailableException::class );
		$client->refund_payment( $this->order() );
	}

	public function test_refund_payment_propagates_http_exception(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andThrow( new PaymentProviderHttpException( 'timeout' ) );

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderHttpException::class );
		$client->refund_payment( $this->order() );
	}

	public function test_refund_payment_throws_rejected_for_failure_status(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturn(
				[
					'status_code' => 200,
					'body'        => '{"status":"failure","err_code":"err_amount","err_description":"bad"}',
					'headers'     => [],
				]
			);

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		try {
			$client->refund_payment( $this->order() );
			self::fail( 'Expected PaymentProviderRejectedException' );
		} catch ( PaymentProviderRejectedException $ex ) {
			self::assertSame( 'failure', $ex->provider_status() );
			self::assertSame( 'err_amount', $ex->provider_err_code() );
		}
	}

	public function test_refund_payment_throws_rejected_for_error_status(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturn(
				[
					'status_code' => 200,
					'body'        => '{"status":"error","err_code":"err_signature"}',
					'headers'     => [],
				]
			);

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderRejectedException::class );
		$client->refund_payment( $this->order() );
	}

	public function test_refund_payment_throws_rejected_for_unexpected_status(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturn(
				[
					'status_code' => 200,
					'body'        => '{"status":"processing"}',
					'headers'     => [],
				]
			);

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		try {
			$client->refund_payment( $this->order() );
			self::fail( 'Expected PaymentProviderRejectedException' );
		} catch ( PaymentProviderRejectedException $ex ) {
			self::assertSame( 'processing', $ex->provider_status() );
		}
	}

	public function test_refund_payment_throws_when_reversed_response_missing_payment_id(): void {
		$http_client = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturn(
				[
					'status_code' => 200,
					'body'        => '{"status":"reversed"}',
					'headers'     => [],
				]
			);

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);

		$this->expectException( PaymentProviderHttpException::class );
		$client->refund_payment( $this->order() );
	}

	public function test_refund_signature_round_trips(): void {
		$captured_data      = null;
		$captured_signature = null;
		$http_client        = Mockery::mock( HttpClient::class );
		$http_client->shouldReceive( 'post' )
			->once()
			->andReturnUsing(
				static function ( string $data, string $sig ) use ( &$captured_data, &$captured_signature ): array {
					$captured_data      = $data;
					$captured_signature = $sig;
					return [
						'status_code' => 200,
						'body'        => '{"status":"reversed","payment_id":1}',
						'headers'     => [],
					];
				}
			);

		$client = new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);
		$client->refund_payment( $this->order() );

		$decoded = json_decode( base64_decode( (string) $captured_data, true ), true );
		self::assertIsArray( $decoded );
		self::assertSame( 'refund', $decoded['action'] );
		self::assertSame( $this->order()->uuid, $decoded['order_id'] );
		$expected_sig = ( new SignatureBuilder() )->build( 'sk_test', (string) $captured_data );
		self::assertSame( $expected_sig, $captured_signature );
	}

	private function configured_client(): LiqPayClient {
		$http_client = Mockery::mock( HttpClient::class );
		return new LiqPayClient(
			$this->configured_settings(),
			new PayloadBuilder( $this->configured_settings(), $this->resolver() ),
			new SignatureBuilder(),
			$http_client,
			new RefundResponseParser()
		);
	}

	private function configured_settings(): TestLiqPaySettings {
		return new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY'  => 'pk_test',
				'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_test',
			]
		);
	}

	private function resolver(): AppUrlResolver {
		$logger = Mockery::mock( Logger::class );
		$logger->shouldIgnoreMissing();
		return new AppUrlResolver( $logger );
	}

	private function order(): Order {
		return new Order(
			id: 1,
			uuid: '11111111-1111-4111-8111-111111111111',
			user_id: 7,
			status: OrderStatus::PAID,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'web-design',
			entity_title_snapshot: 'Web Design',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 10:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 10:00:00', new \DateTimeZone( 'UTC' ) ),
			paid_at: new \DateTimeImmutable( '2026-05-01 11:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}
}
