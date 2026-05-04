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
use VL\LMS\Payments\LiqPay\PayloadBuilder;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Payments\LiqPay\TestLiqPaySettings;

final class PayloadBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->justReturn( 'https://app.example.test' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_course_order_produces_expected_payload(): void {
		$order = $this->course_order( 'web-design', 'Web Design Mastery', '1500.00' );

		$builder = new PayloadBuilder(
			new TestLiqPaySettings(
				constants: [
					'VL_LMS_LIQPAY_PUBLIC_KEY'  => 'pk_live',
					'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_live',
					'VL_LMS_LIQPAY_SANDBOX'     => false,
				],
				environment: 'production'
			),
			$this->resolver()
		);

		$payload = $builder->build( $order, 'https://api.example.test/wp-json/vl/v1/payments/liqpay/callback' );

		self::assertSame( 3, $payload['version'] );
		self::assertSame( 'pk_live', $payload['public_key'] );
		self::assertSame( 'pay', $payload['action'] );
		self::assertSame( '1500.00', $payload['amount'] );
		self::assertSame( 'UAH', $payload['currency'] );
		self::assertSame( 'Курс: Web Design Mastery', $payload['description'] );
		self::assertSame( $order->uuid, $payload['order_id'] );
		self::assertSame( 'https://app.example.test/orders/' . $order->uuid . '/result', $payload['result_url'] );
		self::assertSame( 'https://api.example.test/wp-json/vl/v1/payments/liqpay/callback', $payload['server_url'] );
		self::assertSame( 0, $payload['sandbox'] );
		self::assertSame( 'uk', $payload['language'] );
	}

	public function test_webinar_order_uses_webinar_prefix(): void {
		$order = $this->webinar_order( 'Live AMA Session' );

		$builder = new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() );

		$payload = $builder->build( $order, 'https://x' );

		self::assertSame( 'Вебінар: Live AMA Session', $payload['description'] );
	}

	public function test_sandbox_flag_flows_from_settings(): void {
		$order = $this->course_order( 'a', 'A', '10.00' );

		$builder = new PayloadBuilder(
			new TestLiqPaySettings( environment: 'local' ),
			$this->resolver()
		);

		$payload = $builder->build( $order, 'https://x' );

		self::assertSame( 1, $payload['sandbox'] );
	}

	public function test_currency_flows_from_order(): void {
		$order = $this->course_order( 'a', 'A', '99.50' );

		$builder = new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() );

		$payload = $builder->build( $order, 'https://x' );

		self::assertSame( 'UAH', $payload['currency'] );
		self::assertSame( '99.50', $payload['amount'] );
	}

	public function test_long_title_is_truncated(): void {
		$title = str_repeat( 'А', 250 );
		$order = $this->course_order( 'long', $title, '100.00' );

		$builder = new PayloadBuilder( new TestLiqPaySettings(), $this->resolver() );

		$payload = $builder->build( $order, 'https://x' );

		self::assertSame( 'Курс: ' . str_repeat( 'А', 200 ), $payload['description'] );
	}

	private function resolver(): AppUrlResolver {
		$logger = Mockery::mock( Logger::class );
		$logger->shouldIgnoreMissing();
		return new AppUrlResolver( $logger );
	}

	private function course_order( string $slug, string $title, string $amount ): Order {
		return new Order(
			id: 1,
			uuid: '11111111-1111-4111-8111-111111111111',
			user_id: 7,
			status: OrderStatus::PENDING,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: $slug,
			entity_title_snapshot: $title,
			amount: Money::from_major_decimal( $amount, 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 10:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	private function webinar_order( string $title ): Order {
		return new Order(
			id: 2,
			uuid: '22222222-2222-4222-8222-222222222222',
			user_id: 7,
			status: OrderStatus::PENDING,
			payment_provider: 'liqpay',
			liqpay_order_id: '22222222-2222-4222-8222-222222222222',
			entity_type: PurchasableEntityType::WEBINAR,
			entity_id: 200,
			entity_slug: 'live-ama',
			entity_title_snapshot: $title,
			amount: Money::from_major_decimal( '500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 10:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}
}
