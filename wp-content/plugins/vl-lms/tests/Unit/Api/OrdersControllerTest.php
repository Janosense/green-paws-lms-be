<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\OrdersController;
use VL\LMS\Api\OrderTransformer;
use VL\LMS\Api\PreparedPaymentTransformer;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Orders\Exception\AlreadyEnrolledException;
use VL\LMS\Orders\Exception\AlreadyRegisteredException;
use VL\LMS\Orders\Exception\EntityNotFoundException;
use VL\LMS\Orders\Exception\EntityNotPurchasableException;
use VL\LMS\Orders\Exception\OrderNotCancellableException;
use VL\LMS\Orders\Exception\OrderNotFoundException;
use VL\LMS\Orders\Exception\OrderNotOwnedException;
use VL\LMS\Orders\Exception\WebinarFullException;
use VL\LMS\Orders\OrderCreationResult;
use VL\LMS\Orders\OrderService;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class OrdersControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&OrderService */
	private $service;

	private OrdersController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( mixed $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$status = 200;
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					static function ( int $code ) use ( $response, &$status ): WP_REST_Response {
						$status = $code;
						return $response;
					}
				);
				$response->shouldReceive( 'get_status' )->andReturnUsing(
					static function () use ( &$status ): int {
						return $status;
					}
				);
				return $response;
			}
		);

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->service       = Mockery::mock( OrderService::class );

		$this->controller = new OrdersController(
			'vl/v1',
			$this->authenticator,
			$this->service,
			new OrderTransformer(),
			new PreparedPaymentTransformer()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_four_routes(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 4, $calls );
		self::assertSame( '/orders', $calls[0]['route'] );
		self::assertSame( 'POST', $calls[0]['args']['methods'] );
		self::assertSame( '/orders/me', $calls[1]['route'] );
		self::assertSame( 'GET', $calls[1]['args']['methods'] );
		self::assertStringContainsString( '/orders/(?P<uuid>', $calls[2]['route'] );
		self::assertSame( 'GET', $calls[2]['args']['methods'] );
		self::assertStringContainsString( '/cancel', $calls[3]['route'] );
		self::assertSame( 'POST', $calls[3]['args']['methods'] );
	}

	public function test_create_returns_201_with_order_and_payment_form(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$order    = $this->order( OrderStatus::PENDING );
		$prepared = new PreparedPayment(
			'https://liqpay.ua',
			'POST',
			[
				'data'      => 'd',
				'signature' => 's',
				'version'   => '3',
			]
		);
		$this->service->shouldReceive( 'create_for_purchase' )
			->with( 7, PurchasableEntityType::COURSE, 'web-design' )
			->andReturn( new OrderCreationResult( $order, $prepared ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'course',
					'slug'        => 'web-design',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		self::assertArrayHasKey( 'order', $body );
		self::assertArrayHasKey( 'payment_form', $body );
		self::assertSame( 'cafebabe-cafe-4cab-8cab-cafebabecafe', $body['order']['uuid'] );
		self::assertSame( 'https://liqpay.ua', $body['payment_form']['action_url'] );
	}

	public function test_create_without_login_returns_401(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->permission_create( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_create_without_capability_returns_403(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => false ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$result = $this->controller->permission_create( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_create_maps_invalid_entity_type_to_400(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'tutorial',
					'slug'        => 'x',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_entity_type', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_create_maps_entity_not_found_to_404(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new EntityNotFoundException( 'x' ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'course',
					'slug'        => 'x',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'entity_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_create_maps_entity_not_purchasable_to_409(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new EntityNotPurchasableException( 'x' ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'course',
					'slug'        => 'x',
				]
			)
		);

		self::assertSame( 'entity_not_purchasable', $response->get_error_code() );
		self::assertSame( 409, $response->get_error_data()['status'] );
	}

	public function test_create_maps_already_enrolled_to_409_with_enrollment_id(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new AlreadyEnrolledException( 42 ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'course',
					'slug'        => 'x',
				]
			)
		);

		self::assertSame( 'already_enrolled', $response->get_error_code() );
		$data = $response->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( 42, $data['enrollment_id'] );
	}

	public function test_create_maps_already_registered_to_409_with_registration_id(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new AlreadyRegisteredException( 99 ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'webinar',
					'slug'        => 'x',
				]
			)
		);

		self::assertSame( 'already_registered', $response->get_error_code() );
		$data = $response->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( 99, $data['registration_id'] );
	}

	public function test_create_maps_webinar_full_to_409(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new WebinarFullException( 'x' ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'webinar',
					'slug'        => 'x',
				]
			)
		);

		self::assertSame( 'webinar_full', $response->get_error_code() );
		self::assertSame( 409, $response->get_error_data()['status'] );
	}

	public function test_create_maps_provider_unavailable_to_503(): void {
		$user = $this->user_with_caps( 7, [ 'vl_purchase_course' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'create_for_purchase' )->andThrow( new PaymentProviderUnavailableException( 'down' ) );

		$response = $this->controller->create(
			$this->request(
				[
					'entity_type' => 'course',
					'slug'        => 'x',
				]
			)
		);

		self::assertSame( 'payment_provider_unavailable', $response->get_error_code() );
		self::assertSame( 503, $response->get_error_data()['status'] );
	}

	public function test_list_mine_returns_paginated_envelope(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$order = $this->order( OrderStatus::PAID );
		$this->service->shouldReceive( 'list_for_user' )
			->with( 7, null, 1, 20 )
			->andReturn(
				[
					'items' => [ $order ],
					'total' => 1,
				]
			);

		$response = $this->controller->list_mine( $this->request() );

		$body = $response->get_data();
		self::assertCount( 1, $body['items'] );
		self::assertSame( 1, $body['total'] );
		self::assertSame( 1, $body['page'] );
		self::assertSame( 20, $body['per_page'] );
	}

	public function test_list_mine_parses_status_filter(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$captured = null;
		$this->service->shouldReceive( 'list_for_user' )
			->andReturnUsing(
				static function ( int $u, ?array $s, int $p, int $pp ) use ( &$captured ): array {
					$captured = $s;
					return [
						'items' => [],
						'total' => 0,
					];
				}
			);

		$this->controller->list_mine( $this->request( [ 'status' => 'pending,paid' ] ) );

		self::assertEquals( [ OrderStatus::PENDING, OrderStatus::PAID ], $captured );
	}

	public function test_list_mine_invalid_status_returns_400(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$response = $this->controller->list_mine( $this->request( [ 'status' => 'bogus' ] ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_status', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_find_returns_transformed_order(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'find_for_user' )
			->with( 7, 'cafebabe-cafe-4cab-8cab-cafebabecafe' )
			->andReturn( $this->order( OrderStatus::PENDING ) );

		$response = $this->controller->find( $this->request( [ 'uuid' => 'cafebabe-cafe-4cab-8cab-cafebabecafe' ] ) );

		$body = $response->get_data();
		self::assertSame( 'cafebabe-cafe-4cab-8cab-cafebabecafe', $body['uuid'] );
	}

	public function test_find_returns_404_for_unknown_uuid(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'find_for_user' )->andThrow( new OrderNotFoundException( 'no' ) );

		$response = $this->controller->find( $this->request( [ 'uuid' => 'nope' ] ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'order_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_find_masks_owner_mismatch_as_404(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'find_for_user' )->andThrow( new OrderNotOwnedException( 'no' ) );

		$response = $this->controller->find( $this->request( [ 'uuid' => 'cafebabe-cafe-4cab-8cab-cafebabecafe' ] ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'order_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_cancel_returns_transformed_cancelled_order(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'cancel' )
			->with( 7, 'cafebabe-cafe-4cab-8cab-cafebabecafe' )
			->andReturn( $this->order( OrderStatus::CANCELLED ) );

		$response = $this->controller->cancel( $this->request( [ 'uuid' => 'cafebabe-cafe-4cab-8cab-cafebabecafe' ] ) );

		$body = $response->get_data();
		self::assertSame( 'cancelled', $body['status'] );
	}

	public function test_cancel_maps_not_cancellable_to_409_with_current_status(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'cancel' )->andThrow( new OrderNotCancellableException( OrderStatus::PAID ) );

		$response = $this->controller->cancel( $this->request( [ 'uuid' => 'cafebabe-cafe-4cab-8cab-cafebabecafe' ] ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'order_not_cancellable', $response->get_error_code() );
		$data = $response->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'paid', $data['current_status'] );
	}

	public function test_cancel_masks_owner_mismatch_as_404(): void {
		$user = $this->user_with_caps( 7, [ 'vl_view_own_orders' => true ] );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
		$this->service->shouldReceive( 'cancel' )->andThrow( new OrderNotOwnedException( 'x' ) );

		$response = $this->controller->cancel( $this->request( [ 'uuid' => 'cafebabe-cafe-4cab-8cab-cafebabecafe' ] ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'order_not_found', $response->get_error_code() );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( array $params = [] ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $key ): mixed => $params[ $key ] ?? null
		);
		return $request;
	}

	/**
	 * @param array<string, bool> $caps
	 */
	private function user_with_caps( int $id, array $caps ): WP_User {
		$user     = Mockery::mock( WP_User::class );
		$user->ID = $id;
		$user->shouldReceive( 'has_cap' )->andReturnUsing(
			static fn ( string $cap ): bool => $caps[ $cap ] ?? false
		);
		return $user;
	}

	private function order( OrderStatus $status ): Order {
		return new Order(
			id: 11,
			uuid: 'cafebabe-cafe-4cab-8cab-cafebabecafe',
			user_id: 7,
			status: $status,
			payment_provider: 'liqpay',
			liqpay_order_id: 'cafebabe-cafe-4cab-8cab-cafebabecafe',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'web-design',
			entity_title_snapshot: 'Web Design',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 12:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 12:00:00', new \DateTimeZone( 'UTC' ) ),
			cancelled_at: OrderStatus::CANCELLED === $status
				? new \DateTimeImmutable( '2026-05-01 13:00:00', new \DateTimeZone( 'UTC' ) )
				: null,
		);
	}
}
