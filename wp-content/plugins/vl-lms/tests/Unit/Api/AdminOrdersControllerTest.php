<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\AdminOrdersController;
use VL\LMS\Api\OrderTransformer;
use VL\LMS\Auth\RestAuthenticator;
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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminOrdersControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&RefundService */
	private $refunds;

	/** @var Mockery\MockInterface&OrderTransformer */
	private $transformer;

	private AdminOrdersController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response         = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->status = 200;
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_status' )->andReturnUsing(
					static fn (): int => $response->status
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				return $response;
			}
		);

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->refunds       = Mockery::mock( RefundService::class );
		$this->transformer   = Mockery::mock( OrderTransformer::class );

		$this->controller = new AdminOrdersController(
			'vl/v1',
			$this->authenticator,
			$this->refunds,
			$this->transformer
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_post_endpoint_with_cap(): void {
		$captured = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->andReturnUsing(
				static function ( $namespace, $route, $args ) use ( &$captured ): bool {
					$captured = compact( 'namespace', 'route', 'args' );
					return true;
				}
			);

		$this->controller->register_routes();

		self::assertSame( 'vl/v1', $captured['namespace'] );
		self::assertStringContainsString( '/admin/orders/', $captured['route'] );
		self::assertStringContainsString( '/refund', $captured['route'] );
		self::assertSame( 'POST', $captured['args']['methods'] );
	}

	public function test_refund_returns_401_for_unauthenticated(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->permission_refund( $this->request( 'aaa' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_refund_returns_403_when_user_lacks_cap(): void {
		$user = $this->user( 1, has_refund_cap: false );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$result = $this->controller->permission_refund( $this->request( 'aaa' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_refund_returns_200_with_order_on_success(): void {
		$user = $this->user( 1, has_refund_cap: true );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$order = $this->refunded_order();
		$this->refunds->shouldReceive( 'refund_order' )
			->once()
			->with( 'aaa-uuid' )
			->andReturn( $order );

		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( $order )
			->andReturn(
				[
					'uuid'   => 'aaa-uuid',
					'status' => 'refunded',
				]
			);

		$response = $this->controller->refund( $this->request( 'aaa-uuid' ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'refunded', $response->get_data()['status'] );
	}

	public function test_refund_maps_not_found_to_404(): void {
		$this->stage_authed();
		$this->refunds->shouldReceive( 'refund_order' )
			->andThrow( new OrderNotFoundForRefundException( 'no-such' ) );

		$result = $this->controller->refund( $this->request( 'aaa' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'order_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_refund_maps_not_refundable_to_409_with_current_status(): void {
		$this->stage_authed();
		$this->refunds->shouldReceive( 'refund_order' )
			->andThrow( new OrderNotRefundableException( OrderStatus::CANCELLED ) );

		$result = $this->controller->refund( $this->request( 'aaa' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'order_not_refundable', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( OrderStatus::CANCELLED->value, $result->get_error_data()['current_status'] );
	}

	public function test_refund_maps_unavailable_to_503(): void {
		$this->stage_authed();
		$this->refunds->shouldReceive( 'refund_order' )
			->andThrow( new PaymentProviderUnavailableException( 'creds missing' ) );

		$result = $this->controller->refund( $this->request( 'aaa' ) );

		self::assertSame( 'payment_provider_unavailable', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_refund_maps_http_failure_to_502_with_reason_http(): void {
		$this->stage_authed();
		$this->refunds->shouldReceive( 'refund_order' )
			->andThrow( new PaymentProviderHttpException( 'timeout', 504, 'gateway' ) );

		$result = $this->controller->refund( $this->request( 'aaa' ) );

		self::assertSame( 'payment_provider_error', $result->get_error_code() );
		self::assertSame( 502, $result->get_error_data()['status'] );
		self::assertSame( 'http', $result->get_error_data()['reason'] );
	}

	public function test_refund_maps_rejection_to_502_with_provider_err_code(): void {
		$this->stage_authed();
		$this->refunds->shouldReceive( 'refund_order' )
			->andThrow( new PaymentProviderRejectedException( 'rejected', 'failure', 'err_amount' ) );

		$result = $this->controller->refund( $this->request( 'aaa' ) );

		self::assertSame( 'payment_provider_error', $result->get_error_code() );
		self::assertSame( 502, $result->get_error_data()['status'] );
		self::assertSame( 'rejected', $result->get_error_data()['reason'] );
		self::assertSame( 'err_amount', $result->get_error_data()['provider_err_code'] );
	}

	private function stage_authed(): void {
		$user = $this->user( 1, has_refund_cap: true );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
	}

	private function request( string $uuid ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => 'uuid' === $name ? $uuid : null
		);
		return $req;
	}

	private function user( int $id, bool $has_refund_cap ): \WP_User {
		$user        = Mockery::mock( 'WP_User' );
		$user->ID    = $id;
		$user->roles = [ 'administrator' ];
		$user->shouldReceive( 'has_cap' )
			->with( AdminOrdersController::CAP_REFUND )
			->andReturn( $has_refund_cap );
		return $user;
	}

	private function refunded_order(): Order {
		return new Order(
			id: 1,
			uuid: 'aaa-uuid',
			user_id: 7,
			status: OrderStatus::REFUNDED,
			payment_provider: 'liqpay',
			liqpay_order_id: 'aaa-uuid',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'course',
			entity_title_snapshot: 'Course',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01T10:00:00Z' ),
			expires_at: new \DateTimeImmutable( '2026-05-02T10:00:00Z' ),
			paid_at: new \DateTimeImmutable( '2026-05-01T11:00:00Z' ),
			refunded_at: new \DateTimeImmutable( '2026-05-04T12:00:00Z' )
		);
	}
}
