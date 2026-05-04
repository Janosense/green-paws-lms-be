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
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\LiqPay\LiqPayClient;
use VL\LMS\Payments\LiqPay\PayloadBuilder;
use VL\LMS\Payments\LiqPay\SignatureBuilder;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
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
		$client = new LiqPayClient(
			new TestLiqPaySettings(
				constants: [ 'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_test' ]
			),
			new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() ),
			new SignatureBuilder()
		);

		$this->expectException( PaymentProviderUnavailableException::class );
		$client->prepare_payment( $this->order() );
	}

	public function test_throws_when_private_key_missing(): void {
		$client = new LiqPayClient(
			new TestLiqPaySettings(
				constants: [ 'VL_LMS_LIQPAY_PUBLIC_KEY' => 'pk_test' ]
			),
			new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() ),
			new SignatureBuilder()
		);

		$this->expectException( PaymentProviderUnavailableException::class );
		$client->prepare_payment( $this->order() );
	}

	private function configured_client(): LiqPayClient {
		$settings = new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY'  => 'pk_test',
				'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_test',
			]
		);
		return new LiqPayClient(
			$settings,
			new PayloadBuilder( $settings, $this->resolver() ),
			new SignatureBuilder()
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
			status: OrderStatus::PENDING,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'web-design',
			entity_title_snapshot: 'Web Design',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 10:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}
}
