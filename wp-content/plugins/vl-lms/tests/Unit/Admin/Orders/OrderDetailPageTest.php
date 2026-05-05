<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Orders\OrderDetailPage;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Refunds\Exception\OrderNotFoundForRefundException;
use VL\LMS\Refunds\Exception\OrderNotRefundableException;
use VL\LMS\Refunds\RefundService;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentRepository;

final class OrderDetailPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string SAMPLE_UUID = '00000000-0000-4000-8000-000000000001';

	/** @var OrderRepository&Mockery\MockInterface */
	private $orders;

	/** @var PaymentRepository&Mockery\MockInterface */
	private $payments;

	/** @var RefundService&Mockery\MockInterface */
	private $refunds;

	private OrderDetailPage $page;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn ( string $f, int $ts ): string => gmdate( $f, $ts ) );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, string $url ): string {
				return $url . '?' . http_build_query( is_array( $args ) ? $args : [] );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ) {
				return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test alias.
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_edit_post_link' )->justReturn( null );
		Functions\when( 'get_edit_user_link' )->justReturn( '#' );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->orders   = Mockery::mock( OrderRepository::class );
		$this->payments = Mockery::mock( PaymentRepository::class );
		$this->refunds  = Mockery::mock( RefundService::class );

		$this->page = new OrderDetailPage( $this->orders, $this->payments, $this->refunds );

		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function paid_order( ?\DateTimeImmutable $paid_at = null ): Order {
		return new Order(
			11,
			self::SAMPLE_UUID,
			7,
			OrderStatus::PAID,
			'liqpay',
			'lp-1',
			PurchasableEntityType::COURSE,
			500,
			'sample-course',
			'Sample Course',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' ),
			$paid_at ?? self::utc( '2026-05-01 12:30:00' )
		);
	}

	private function expired_order(): Order {
		return new Order(
			12,
			self::SAMPLE_UUID,
			7,
			OrderStatus::EXPIRED,
			'liqpay',
			null,
			PurchasableEntityType::COURSE,
			500,
			'sample-course',
			'Sample Course',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' )
		);
	}

	public function test_render_with_invalid_uuid_emits_error_notice(): void {
		$_GET['uuid'] = 'not-a-uuid';

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Невірний UUID', $out );
	}

	public function test_render_with_unknown_uuid_emits_not_found(): void {
		$_GET['uuid'] = self::SAMPLE_UUID;

		$this->orders->shouldReceive( 'find_by_uuid' )->once()->with( self::SAMPLE_UUID )->andReturnNull();

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Замовлення не знайдено', $out );
	}

	public function test_render_paid_order_includes_refund_button(): void {
		$_GET['uuid'] = self::SAMPLE_UUID;

		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Sample Course', $out );
		self::assertStringContainsString( 'Відшкодувати', $out );
		self::assertStringContainsString( 'vl-refund-form', $out );
	}

	public function test_render_non_paid_order_hides_refund_button_and_shows_notice(): void {
		$_GET['uuid'] = self::SAMPLE_UUID;

		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->expired_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'не може бути відшкодоване', $out );
		self::assertStringNotContainsString( 'vl-refund-form', $out );
	}

	public function test_render_paid_order_without_cap_hides_refund_button(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_GET['uuid'] = self::SAMPLE_UUID;

		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringNotContainsString( 'vl-refund-form', $out );
	}

	public function test_refund_post_happy_path_invokes_service_and_shows_success(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()->with( self::SAMPLE_UUID )->andReturn( $this->paid_order() );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Замовлення повернено', $out );
		self::assertStringContainsString( 'notice notice-success', $out );
	}

	public function test_refund_post_not_found_exception_maps_to_notice(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()
			->andThrow( new OrderNotFoundForRefundException( 'gone' ) );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Замовлення не знайдено', $out );
	}

	public function test_refund_post_not_refundable_exception_maps_to_notice(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()
			->andThrow( new OrderNotRefundableException( OrderStatus::EXPIRED ) );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->expired_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'не може бути відшкодоване', $out );
		self::assertStringContainsString( 'Прострочено', $out );
	}

	public function test_refund_post_provider_unavailable_maps_to_notice(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()
			->andThrow( new PaymentProviderUnavailableException( 'no creds' ) );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'LiqPay не налаштовано', $out );
	}

	public function test_refund_post_http_exception_maps_to_notice(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()
			->andThrow( new PaymentProviderHttpException( 'timeout', 502, 'gateway' ) );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Помилка зв\'язку з LiqPay', $out );
	}

	public function test_refund_post_rejected_maps_to_notice(): void {
		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->refunds->shouldReceive( 'refund_order' )->once()
			->andThrow( new PaymentProviderRejectedException( 'reject', 'failure', 'err_402' ) );
		$this->orders->shouldReceive( 'find_by_uuid' )->once()->andReturn( $this->paid_order() );
		$this->payments->shouldReceive( 'list_for_order' )->once()->andReturn( [] );

		ob_start();
		$this->page->render();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'LiqPay відхилив', $out );
		self::assertStringContainsString( 'err_402', $out );
	}

	public function test_refund_post_without_cap_calls_wp_die(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_GET['uuid']              = self::SAMPLE_UUID;
		$_GET['action']            = 'refund';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$this->page->render();
	}
}
