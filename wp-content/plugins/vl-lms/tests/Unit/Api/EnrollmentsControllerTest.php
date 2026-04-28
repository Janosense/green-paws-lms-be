<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\EnrollmentRecordTransformer;
use VL\LMS\Api\EnrollmentsController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class EnrollmentsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&EnrollmentRepository */
	private $repository;

	/** @var Mockery\MockInterface&EnrollmentRecordTransformer */
	private $transformer;

	private EnrollmentService $service;

	private EnrollmentsController $controller;

	/** @var array<int, WP_Post|null> */
	private array $posts = [];

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	private int $now_utc = 1_730_000_000;

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
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'time' )->alias(
			fn (): int => $this->now_utc
		);
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->repository    = Mockery::mock( EnrollmentRepository::class );
		$this->transformer   = Mockery::mock( EnrollmentRecordTransformer::class );

		// EnrollmentService is final and cannot be mocked. Use the real
		// implementation backed by the mocked repository — its outputs are
		// deterministic for our needs (`has_active_access` reads through
		// `find_for_user_and_course`, `enroll` writes via `insert` /
		// `update`).
		$this->service = new EnrollmentService( $this->repository );

		$this->controller = new EnrollmentsController(
			'vl/v1',
			$this->authenticator,
			$this->service,
			$this->repository,
			$this->transformer
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_post_and_get_endpoints(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/enrollments', $calls[0]['route'] );
		self::assertSame( 'POST', $calls[0]['args']['methods'] );
		self::assertTrue( $calls[0]['args']['args']['course_id']['required'] );
		self::assertSame( '/enrollments/me', $calls[1]['route'] );
		self::assertSame( 'GET', $calls[1]['args']['methods'] );
	}

	// ---------------------------------------------------------------------
	// POST — permission and validation
	// ---------------------------------------------------------------------

	public function test_post_returns_401_when_not_authenticated(): void {
		$this->stage_user( null );

		$result = $this->controller->permission_create( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_post_returns_403_when_user_lacks_enroll_capability(): void {
		$this->stage_user( $this->user( 5, has_enroll_cap: false ) );

		$result = $this->controller->permission_create( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_post_permission_callback_returns_true_when_authed_and_capable(): void {
		$this->stage_user( $this->user( 5, has_enroll_cap: true ) );

		self::assertTrue( $this->controller->permission_create( $this->request() ) );
	}

	public function test_post_returns_400_for_missing_course_id(): void {
		$this->stage_user( $this->user( 5 ) );

		$result = $this->controller->create( $this->request( [] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'invalid_course_id', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_post_returns_400_for_non_positive_course_id(): void {
		$this->stage_user( $this->user( 5 ) );

		$result = $this->controller->create( $this->request( [ 'course_id' => 0 ] ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'invalid_course_id', $result->get_error_code() );

		$result = $this->controller->create( $this->request( [ 'course_id' => -3 ] ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'invalid_course_id', $result->get_error_code() );

		$result = $this->controller->create( $this->request( [ 'course_id' => 'abc' ] ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'invalid_course_id', $result->get_error_code() );
	}

	public function test_post_returns_404_for_unknown_course_id(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->stub_no_existing_enrollment( 5, 999 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 999 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'course_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_post_returns_404_for_non_course_post_type(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->post( 10, 'spring-cardio', 'vl_webinar', 'publish' );
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'course_not_found', $result->get_error_code() );
	}

	public function test_post_returns_404_for_unpublished_course(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->post( 10, 'draft-course', 'vl_course', 'draft' );
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'course_not_found', $result->get_error_code() );
	}

	public function test_post_returns_402_for_paid_course(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 1500.0 ],
			'_vl_course_enrollment_open' => [ 10 => '1' ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'payment_required', $result->get_error_code() );
		self::assertSame( 402, $result->get_error_data()['status'] );
	}

	public function test_post_returns_422_when_enrollment_open_flag_is_false(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 0 ],
			'_vl_course_enrollment_open' => [ 10 => '' ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'enrollment_closed', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_post_returns_422_when_now_is_before_opens_at(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'               => [ 10 => 0 ],
			'_vl_course_enrollment_open'     => [ 10 => '1' ],
			'_vl_course_enrollment_opens_at' => [ 10 => gmdate( 'Y-m-d\TH:i:s\Z', $this->now_utc + 3600 ) ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'enrollment_not_open', $result->get_error_code() );
	}

	public function test_post_returns_422_when_now_is_after_closes_at(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'                => [ 10 => 0 ],
			'_vl_course_enrollment_open'      => [ 10 => '1' ],
			'_vl_course_enrollment_closes_at' => [ 10 => gmdate( 'Y-m-d\TH:i:s\Z', $this->now_utc - 3600 ) ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'enrollment_closed', $result->get_error_code() );
	}

	public function test_post_returns_422_when_capacity_reached(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 0 ],
			'_vl_course_enrollment_open' => [ 10 => '1' ],
			'_vl_course_max_students'    => [ 10 => 50 ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );
		$this->repository->shouldReceive( 'count_for_course' )
			->once()
			->with( 10, EnrollmentStatus::ACTIVE )
			->andReturn( 50 );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'enrollment_full', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_post_skips_window_checks_when_dates_are_unparseable(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'                => [ 10 => 0 ],
			'_vl_course_enrollment_open'      => [ 10 => '1' ],
			'_vl_course_enrollment_opens_at'  => [ 10 => 'this is not a date' ],
			'_vl_course_enrollment_closes_at' => [ 10 => 'definitely not iso' ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );
		$persisted = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE );
		$this->stub_successful_insert( $persisted );
		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( Mockery::type( Enrollment::class ), $this->posts[10] )
			->andReturn( [ 'id' => 100 ] );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 201, $result->get_status() );
	}

	public function test_post_skips_capacity_check_when_max_students_is_zero(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 0 ],
			'_vl_course_enrollment_open' => [ 10 => '1' ],
			'_vl_course_max_students'    => [ 10 => 0 ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );
		$this->repository->shouldNotReceive( 'count_for_course' );
		$this->stub_successful_insert( $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE ) );
		$this->transformer->shouldReceive( 'transform' )
			->once()
			->andReturn( [ 'id' => 100 ] );

		$result = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 201, $result->get_status() );
	}

	public function test_post_returns_201_with_record_when_enroll_succeeds(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 0 ],
			'_vl_course_enrollment_open' => [ 10 => '1' ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$enrollment = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE );
		$this->stub_successful_insert( $enrollment );
		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( Mockery::type( Enrollment::class ), $this->posts[10] )
			->andReturn( [ 'id' => 100 ] );

		$response = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( 100, $response->get_data()['data']['id'] );
	}

	public function test_post_returns_200_with_existing_record_when_already_enrolled(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );

		// `has_active_access` reads through the repository; an ACTIVE row
		// short-circuits the pipeline. The handler then re-fetches the same
		// row to project it through the transformer.
		$existing = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE );
		$this->repository->shouldReceive( 'find_for_user_and_course' )
			->with( 5, 10 )
			->andReturn( $existing );
		$this->repository->shouldNotReceive( 'insert' );
		$this->repository->shouldNotReceive( 'update' );

		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( $existing, $this->posts[10] )
			->andReturn( [ 'id' => 100 ] );

		$response = $this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 100, $response->get_data()['data']['id'] );
	}

	public function test_post_calls_service_with_self_signup_source(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );
		$this->meta      = [
			'_vl_course_price'           => [ 10 => 0 ],
			'_vl_course_enrollment_open' => [ 10 => '1' ],
		];
		$this->stub_no_existing_enrollment( 5, 10 );

		$captured_data = null;
		$this->repository->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				static function ( array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 100;
				}
			);
		// `EnrollmentService::enroll` reloads the row by id after insert.
		$this->repository->shouldReceive( 'find_by_id' )
			->with( 100 )
			->andReturn( $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE ) );
		$this->transformer->shouldReceive( 'transform' )->andReturn( [ 'id' => 100 ] );

		$this->controller->create( $this->request( [ 'course_id' => 10 ] ) );

		self::assertSame( EnrollmentSource::SELF_SIGNUP->value, $captured_data['source'] );
	}

	// ---------------------------------------------------------------------
	// GET /enrollments/me
	// ---------------------------------------------------------------------

	public function test_get_returns_401_when_not_authenticated(): void {
		$this->stage_user( null );

		$result = $this->controller->permission_list_mine( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_get_returns_empty_list_when_user_has_no_enrollments(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->repository->shouldReceive( 'list_for_user_in_statuses' )
			->once()
			->with( 5, [ EnrollmentStatus::ACTIVE, EnrollmentStatus::COMPLETED ] )
			->andReturn( [] );

		$response = $this->controller->list_mine( $this->request() );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( [], $response->get_data()['data']['items'] );
	}

	public function test_get_returns_active_and_completed_only_excludes_revoked_and_expired(): void {
		$this->stage_user( $this->user( 5 ) );

		$captured_statuses = null;
		$this->repository->shouldReceive( 'list_for_user_in_statuses' )
			->once()
			->andReturnUsing(
				static function ( int $user_id, array $statuses ) use ( &$captured_statuses ): array {
					$captured_statuses = $statuses;
					return [];
				}
			);

		$this->controller->list_mine( $this->request() );

		self::assertSame(
			[ EnrollmentStatus::ACTIVE, EnrollmentStatus::COMPLETED ],
			$captured_statuses
		);
	}

	public function test_get_orders_by_enrolled_at_desc(): void {
		// Repository contract is to return rows in the desired order; the
		// controller preserves that order. Here we feed mock results in
		// `enrolled_at DESC` order and assert the response items match.
		$this->stage_user( $this->user( 5 ) );

		$newest = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE, '2026-04-20 10:00:00' );
		$older  = $this->enrollment( 101, 5, 11, EnrollmentStatus::ACTIVE, '2026-04-10 10:00:00' );

		$this->posts[10] = $this->course_post( 10 );
		$this->posts[11] = $this->course_post( 11 );

		$this->repository->shouldReceive( 'list_for_user_in_statuses' )
			->once()
			->andReturn( [ $newest, $older ] );

		$this->transformer->shouldReceive( 'transform' )
			->with( $newest, $this->posts[10] )
			->andReturn( [ 'id' => 100 ] );
		$this->transformer->shouldReceive( 'transform' )
			->with( $older, $this->posts[11] )
			->andReturn( [ 'id' => 101 ] );

		$response = $this->controller->list_mine( $this->request() );

		$items = $response->get_data()['data']['items'];
		self::assertSame( 100, $items[0]['id'] );
		self::assertSame( 101, $items[1]['id'] );
	}

	public function test_get_includes_course_summary_with_cover(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );

		$enrollment = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE );
		$this->repository->shouldReceive( 'list_for_user_in_statuses' )
			->once()
			->andReturn( [ $enrollment ] );

		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( $enrollment, $this->posts[10] )
			->andReturn(
				[
					'id'     => 100,
					'course' => [
						'id'    => 10,
						'slug'  => 'free-course',
						'title' => 'Free Course',
						'cover' => [ 'card' => [ 'url' => 'https://t/card.jpg' ] ],
					],
				]
			);

		$response = $this->controller->list_mine( $this->request() );
		$item     = $response->get_data()['data']['items'][0];

		self::assertSame( 10, $item['course']['id'] );
		self::assertSame( 'free-course', $item['course']['slug'] );
		self::assertSame( 'https://t/card.jpg', $item['course']['cover']['card']['url'] );
	}

	public function test_get_returns_null_cover_when_course_has_no_cover_image(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->posts[10] = $this->course_post( 10 );

		$enrollment = $this->enrollment( 100, 5, 10, EnrollmentStatus::ACTIVE );
		$this->repository->shouldReceive( 'list_for_user_in_statuses' )
			->once()
			->andReturn( [ $enrollment ] );

		$this->transformer->shouldReceive( 'transform' )
			->once()
			->andReturn(
				[
					'id'     => 100,
					'course' => [
						'id'    => 10,
						'slug'  => 'free-course',
						'title' => 'Free Course',
						'cover' => null,
					],
				]
			);

		$response = $this->controller->list_mine( $this->request() );
		$item     = $response->get_data()['data']['items'][0];

		self::assertNull( $item['course']['cover'] );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function stage_user( ?\WP_User $user ): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
	}

	/**
	 * Tells the repository "no row exists for (user_id, course_id)" — both
	 * for the idempotency probe (`has_active_access`) and any subsequent
	 * lookups inside the same handler call.
	 */
	private function stub_no_existing_enrollment( int $user_id, int $course_id ): void {
		$this->repository->shouldReceive( 'find_for_user_and_course' )
			->with( $user_id, $course_id )
			->andReturn( null );
	}

	/**
	 * Stub the insert + reload pair that `EnrollmentService::enroll()` runs
	 * for a brand-new enrollment.
	 */
	private function stub_successful_insert( Enrollment $persisted ): void {
		$this->repository->shouldReceive( 'insert' )->andReturn( $persisted->id );
		$this->repository->shouldReceive( 'find_by_id' )
			->with( $persisted->id )
			->andReturn( $persisted );
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

	private function user( int $id, bool $has_enroll_cap = true ): \WP_User {
		$user        = Mockery::mock( 'WP_User' );
		$user->ID    = $id;
		$user->roles = [ 'student' ];
		$user->shouldReceive( 'has_cap' )
			->with( EnrollmentsController::ENROLL_CAPABILITY )
			->andReturn( $has_enroll_cap );
		return $user;
	}

	private function post( int $id, string $slug, string $type, string $status ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_name   = $slug;
		$post->post_title  = ucwords( str_replace( '-', ' ', $slug ) );
		$post->post_type   = $type;
		$post->post_status = $status;
		return $post;
	}

	private function course_post( int $id ): WP_Post {
		return $this->post( $id, 'free-course-' . $id, 'vl_course', 'publish' );
	}

	private function enrollment(
		int $id,
		int $user_id,
		int $course_id,
		EnrollmentStatus $status,
		string $enrolled_at = '2026-04-15 12:00:00'
	): Enrollment {
		return new Enrollment(
			id: $id,
			user_id: $user_id,
			course_id: $course_id,
			status: $status,
			source: EnrollmentSource::SELF_SIGNUP,
			source_group_id: null,
			source_order_id: null,
			enrolled_at: $enrolled_at,
			started_at: null,
			completed_at: null,
			expires_at: null,
			revoked_at: null,
			revoked_by: null,
			revoke_reason: null,
			progress_pct: 0,
			created_at: $enrolled_at,
			updated_at: $enrolled_at,
		);
	}
}
