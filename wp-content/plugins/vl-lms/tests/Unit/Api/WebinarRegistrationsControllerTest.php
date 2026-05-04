<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\Transformers\WebinarRegistrationTransformer;
use VL\LMS\Api\WebinarRegistrationsController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Webinars\WebinarLookup;
use VL\LMS\Services\Webinars\WebinarRegistrationDecision;
use VL\LMS\Services\Webinars\WebinarRegistrationDecisionType;
use VL\LMS\Services\Webinars\WebinarRegistrationError;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class WebinarRegistrationsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&WebinarRegistrationService */
	private $service;

	/** @var Mockery\MockInterface&WebinarLookup */
	private $lookup;

	/** @var Mockery\MockInterface&WebinarRegistrationRepository */
	private $repository;

	/** @var Mockery\MockInterface&WebinarRegistrationTransformer */
	private $transformer;

	private WebinarRegistrationsController $controller;

	private \DateTimeImmutable $now;

	/** @var array<int, WP_Post|null> */
	private array $posts = [];

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

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
		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->service       = Mockery::mock( WebinarRegistrationService::class );
		$this->lookup        = Mockery::mock( WebinarLookup::class );
		$this->repository    = Mockery::mock( WebinarRegistrationRepository::class );
		$this->transformer   = Mockery::mock( WebinarRegistrationTransformer::class );
		$this->now           = new \DateTimeImmutable( '2026-05-10T00:00:00Z' );

		$this->controller = new WebinarRegistrationsController(
			'vl/v1',
			$this->authenticator,
			$this->service,
			$this->lookup,
			$this->repository,
			$this->transformer,
			fn (): \DateTimeImmutable => $this->now
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_post_delete_and_me_routes(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertStringContainsString( 'webinars/(?P<slug>', $calls[0]['route'] );
		self::assertSame( 'POST', $calls[0]['args'][0]['methods'] );
		self::assertSame( 'DELETE', $calls[0]['args'][1]['methods'] );
		self::assertSame( '/webinars/me', $calls[1]['route'] );
		self::assertSame( 'GET', $calls[1]['args']['methods'] );
	}

	// Permission

	public function test_permission_register_returns_401_when_not_authenticated(): void {
		$this->stage_user( null );

		$result = $this->controller->permission_register( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_permission_register_returns_403_when_user_lacks_capability(): void {
		$this->stage_user( $this->user( 5, has_cap: false ) );

		$result = $this->controller->permission_register( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_register_returns_true_for_authed_user_with_cap(): void {
		$this->stage_user( $this->user( 5 ) );

		self::assertTrue( $this->controller->permission_register( $this->request() ) );
	}

	// POST

	public function test_post_returns_201_on_fresh_registration(): void {
		$this->stage_user( $this->user( 5 ) );
		$webinar = $this->webinar( 100, 'vet-may' );
		$reg     = $this->registration( 9, 100, 5 );

		$this->service->shouldReceive( 'register' )->once()->andReturn(
			WebinarRegistrationDecision::success( WebinarRegistrationDecisionType::REGISTERED, $reg )
		);
		$this->lookup->shouldReceive( 'find_by_slug' )->with( 'vet-may' )->andReturn( $webinar );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 9 ] );

		$response = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );
		self::assertTrue( $response->get_data()['success'] );
	}

	public function test_post_returns_200_on_re_register(): void {
		$this->stage_user( $this->user( 5 ) );
		$webinar = $this->webinar( 100, 'vet-may' );
		$reg     = $this->registration( 9, 100, 5 );

		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::success( WebinarRegistrationDecisionType::RE_REGISTERED, $reg )
		);
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $webinar );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 9 ] );

		$response = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_post_returns_200_idempotent_for_already_active(): void {
		$this->stage_user( $this->user( 5 ) );
		$webinar = $this->webinar( 100, 'vet-may' );
		$reg     = $this->registration( 9, 100, 5 );

		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::success( WebinarRegistrationDecisionType::ALREADY_ACTIVE, $reg )
		);
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $webinar );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 9 ] );

		$response = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['idempotent'] );
	}

	public function test_post_returns_404_for_webinar_not_found(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::failure( WebinarRegistrationError::WEBINAR_NOT_FOUND )
		);

		$result = $this->controller->register( $this->request( [ 'slug' => 'missing' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webinar_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_post_returns_409_for_registration_not_open_yet_with_opens_at(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::failure(
				WebinarRegistrationError::REGISTRATION_NOT_OPEN_YET,
				[ 'opens_at' => '2026-06-01T00:00:00Z' ]
			)
		);

		$result = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'registration_not_open_yet', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( '2026-06-01T00:00:00Z', $result->get_error_data()['opens_at'] );
	}

	public function test_post_returns_409_for_registration_closed(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::failure(
				WebinarRegistrationError::REGISTRATION_CLOSED,
				[ 'closes_at' => '2026-04-01T00:00:00Z' ]
			)
		);

		$result = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'registration_closed', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_post_returns_402_for_paid_webinar_with_price_block(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::failure(
				WebinarRegistrationError::PAYMENT_REQUIRED,
				[
					'price' => [
						'amount'   => 499.0,
						'currency' => 'UAH',
					],
				]
			)
		);

		$result = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'payment_required', $result->get_error_code() );
		self::assertSame( 402, $result->get_error_data()['status'] );
		self::assertSame( 499.0, $result->get_error_data()['price']['amount'] );
		self::assertSame( 'UAH', $result->get_error_data()['price']['currency'] );
	}

	public function test_post_returns_409_for_capacity_reached(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'register' )->andReturn(
			WebinarRegistrationDecision::failure(
				WebinarRegistrationError::CAPACITY_REACHED,
				[ 'capacity' => 200 ]
			)
		);

		$result = $this->controller->register( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'capacity_reached', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( 200, $result->get_error_data()['capacity'] );
	}

	// DELETE

	public function test_delete_returns_200_on_cancellation(): void {
		$this->stage_user( $this->user( 5 ) );
		$webinar = $this->webinar( 100, 'vet-may' );
		$reg     = $this->registration( 9, 100, 5, WebinarRegistrationStatus::CANCELLED );

		$this->service->shouldReceive( 'cancel' )->once()->andReturn(
			WebinarRegistrationDecision::success( WebinarRegistrationDecisionType::CANCELLED, $reg )
		);
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $webinar );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 9 ] );

		$response = $this->controller->cancel( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertTrue( $response->get_data()['success'] );
	}

	public function test_delete_returns_idempotent_for_already_cancelled(): void {
		$this->stage_user( $this->user( 5 ) );
		$webinar = $this->webinar( 100, 'vet-may' );
		$reg     = $this->registration( 9, 100, 5, WebinarRegistrationStatus::CANCELLED );

		$this->service->shouldReceive( 'cancel' )->andReturn(
			WebinarRegistrationDecision::success( WebinarRegistrationDecisionType::ALREADY_CANCELLED, $reg )
		);
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $webinar );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 9 ] );

		$response = $this->controller->cancel( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertTrue( $response->get_data()['idempotent'] );
	}

	public function test_delete_returns_409_for_not_registered(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'cancel' )->andReturn(
			WebinarRegistrationDecision::failure( WebinarRegistrationError::NOT_REGISTERED )
		);

		$result = $this->controller->cancel( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_registered', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_delete_returns_404_for_webinar_not_found(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->service->shouldReceive( 'cancel' )->andReturn(
			WebinarRegistrationDecision::failure( WebinarRegistrationError::WEBINAR_NOT_FOUND )
		);

		$result = $this->controller->cancel( $this->request( [ 'slug' => 'missing' ] ) );

		self::assertSame( 'webinar_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	// GET /me

	public function test_get_me_returns_empty_list_when_no_registrations(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->repository->shouldReceive( 'list_for_user' )
			->with( 5, WebinarRegistrationStatus::ACTIVE )
			->andReturn( [] );

		$response = $this->controller->list_mine( $this->request() );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( [], $response->get_data()['registrations'] );
	}

	public function test_get_me_filters_to_upcoming_by_default(): void {
		$this->stage_user( $this->user( 5 ) );

		$past_webinar     = $this->webinar( 100, 'past-vet' );
		$upcoming_webinar = $this->webinar( 200, 'future-vet' );
		$this->posts[100] = $past_webinar;
		$this->posts[200] = $upcoming_webinar;
		$this->meta       = [
			'_vl_webinar_scheduled_start' => [
				100 => '2026-04-01T18:00:00Z',
				200 => '2026-06-01T18:00:00Z',
			],
			'_vl_webinar_scheduled_end'   => [
				100 => '2026-04-01T19:00:00Z',
				200 => '2026-06-01T19:00:00Z',
			],
		];

		$reg_past     = $this->registration( 1, 100, 5 );
		$reg_upcoming = $this->registration( 2, 200, 5 );
		$this->repository->shouldReceive( 'list_for_user' )->andReturn( [ $reg_past, $reg_upcoming ] );

		$captured_ids = [];
		$this->transformer->shouldReceive( 'transform' )->andReturnUsing(
			static function ( WebinarRegistration $r, WP_Post $w ) use ( &$captured_ids ): array {
				$captured_ids[] = $r->id;
				return [ 'id' => $r->id ];
			}
		);

		$response = $this->controller->list_mine( $this->request() );

		$items = $response->get_data()['registrations'];
		self::assertCount( 1, $items );
		self::assertSame( 2, $items[0]['id'] );
	}

	public function test_get_me_returns_past_in_descending_order_when_time_filter_past(): void {
		$this->stage_user( $this->user( 5 ) );

		$older            = $this->webinar( 100, 'old' );
		$newer            = $this->webinar( 200, 'new' );
		$this->posts[100] = $older;
		$this->posts[200] = $newer;
		$this->meta       = [
			'_vl_webinar_scheduled_start' => [
				100 => '2026-01-01T18:00:00Z',
				200 => '2026-04-01T18:00:00Z',
			],
			'_vl_webinar_scheduled_end'   => [
				100 => '2026-01-01T19:00:00Z',
				200 => '2026-04-01T19:00:00Z',
			],
		];

		$reg_old = $this->registration( 1, 100, 5 );
		$reg_new = $this->registration( 2, 200, 5 );
		$this->repository->shouldReceive( 'list_for_user' )->andReturn( [ $reg_old, $reg_new ] );

		$this->transformer->shouldReceive( 'transform' )->andReturnUsing(
			static fn ( WebinarRegistration $r ): array => [ 'id' => $r->id ]
		);

		$response = $this->controller->list_mine(
			$this->request(
				[
					'time_filter' => 'past',
					'status'      => 'all',
				]
			)
		);

		$items = $response->get_data()['registrations'];
		self::assertCount( 2, $items );
		self::assertSame( 2, $items[0]['id'] );
		self::assertSame( 1, $items[1]['id'] );
	}

	public function test_get_me_uses_status_cancelled_when_requested(): void {
		$this->stage_user( $this->user( 5 ) );

		$captured = null;
		$this->repository->shouldReceive( 'list_for_user' )
			->andReturnUsing(
				static function ( int $user_id, $status ) use ( &$captured ): array {
					$captured = $status;
					return [];
				}
			);

		$this->controller->list_mine( $this->request( [ 'status' => 'cancelled' ] ) );

		self::assertSame( WebinarRegistrationStatus::CANCELLED, $captured );
	}

	public function test_get_me_skips_registrations_whose_webinar_post_is_missing(): void {
		$this->stage_user( $this->user( 5 ) );

		$reg_missing = $this->registration( 1, 999, 5 );
		$this->repository->shouldReceive( 'list_for_user' )->andReturn( [ $reg_missing ] );

		$response = $this->controller->list_mine( $this->request( [ 'time_filter' => 'all' ] ) );

		self::assertSame( [], $response->get_data()['registrations'] );
	}

	// Helpers

	private function stage_user( ?\WP_User $user ): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( array $params = [] ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => $params[ $name ] ?? null
		);
		$request->shouldReceive( 'get_header' )->andReturn( '' );
		return $request;
	}

	private function user( int $id, bool $has_cap = true ): \WP_User {
		$user        = Mockery::mock( 'WP_User' );
		$user->ID    = $id;
		$user->roles = [ 'student' ];
		$user->shouldReceive( 'has_cap' )->andReturn( $has_cap );
		return $user;
	}

	private function webinar( int $id, string $slug ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_title  = ucwords( str_replace( '-', ' ', $slug ) );
		$p->post_type   = 'vl_webinar';
		$p->post_status = 'publish';
		return $p;
	}

	private function registration(
		int $id,
		int $webinar_id,
		int $user_id,
		WebinarRegistrationStatus $status = WebinarRegistrationStatus::ACTIVE
	): WebinarRegistration {
		return new WebinarRegistration(
			id: $id,
			webinar_id: $webinar_id,
			user_id: $user_id,
			status: $status,
			source: WebinarRegistrationSource::SELF_SIGNUP,
			registered_at: '2026-05-01 10:30:00',
			cancelled_at: WebinarRegistrationStatus::CANCELLED === $status ? '2026-05-02 12:00:00' : null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-05-01 10:30:00',
			updated_at: '2026-05-01 10:30:00'
		);
	}
}
