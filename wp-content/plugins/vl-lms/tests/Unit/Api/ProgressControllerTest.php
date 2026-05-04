<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\ProgressController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Progress\ProgressEventResult;
use VL\LMS\Services\Progress\ProgressService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class ProgressControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string VALID_UUID = '8c7e9f2a-2c1d-4d2c-9e89-3f5d2a3b4c5d';

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	/** @var Mockery\MockInterface&ProgressService */
	private $service;

	private InMemoryEnrollmentRepository $enroll_repo;

	private EnrollmentService $enroll_service;

	private ProgressController $controller;

	/** @var array<int, WP_Post> */
	private array $posts = [];

	private bool $logged_in = true;

	private bool $has_cap = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'register_rest_route' )->justReturn( null );
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
		Functions\when( 'is_user_logged_in' )->alias( fn (): bool => $this->logged_in );
		Functions\when( 'current_user_can' )->alias( fn (): bool => $this->has_cap );

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->hierarchy     = Mockery::mock( EntityHierarchy::class );
		$this->service       = Mockery::mock( ProgressService::class );

		$this->enroll_repo    = new InMemoryEnrollmentRepository();
		$this->enroll_service = new EnrollmentService( $this->enroll_repo );

		$this->controller = new ProgressController(
			'vl/v1',
			$this->authenticator,
			$this->enroll_service,
			$this->hierarchy,
			$this->service
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id, string $type, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		$this->posts[ $id ] = $post;
		return $post;
	}

	private function user( int $id = 7 ): WP_User {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $id;
		assert( $user instanceof WP_User );
		return $user;
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request( ?array $body ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );
		return $request;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function valid_body( array $overrides = [] ): array {
		return array_merge(
			[
				'entity_type'      => 'lesson',
				'entity_id'        => 200,
				'session_uuid'     => self::VALID_UUID,
				'event_type'       => 'progress',
				'position_seconds' => 60,
				'payload'          => null,
			],
			$overrides
		);
	}

	private function progress_row(): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: 1,
			user_id: 7,
			entity_type: EntityType::LESSON,
			entity_id: 200,
			course_id: 100,
			status: ProgressStatus::IN_PROGRESS,
			position_seconds: 60,
			completed_at: null,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now
		);
	}

	public function test_register_routes_registers_post_endpoint(): void {
		$captured = [];
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames -- Stub mirrors the WP signature; we capture all three args even when one isn't asserted on directly.
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$captured ): void {
				$captured[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl/v1', $captured[0]['namespace'] );
		self::assertSame( '/progress', $captured[0]['route'] );
		self::assertSame( 'POST', $captured[0]['args']['methods'] );
	}

	public function test_permission_callback_false_when_logged_out(): void {
		$this->logged_in = false;

		self::assertFalse(
			$this->controller->permission_callback( $this->request( null ) )
		);
	}

	public function test_permission_callback_false_when_missing_cap(): void {
		$this->logged_in = true;
		$this->has_cap   = false;

		self::assertFalse(
			$this->controller->permission_callback( $this->request( null ) )
		);
	}

	public function test_permission_callback_true_when_logged_in_and_capable(): void {
		$this->logged_in = true;
		$this->has_cap   = true;

		self::assertTrue(
			$this->controller->permission_callback( $this->request( null ) )
		);
	}

	public function test_unauthorized_when_authenticator_returns_null(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'unauthorized', $response->get_error_code() );
		self::assertSame( 401, $response->get_error_data()['status'] );
	}

	public function test_invalid_payload_when_body_is_null(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle( $this->request( null ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_invalid_payload_when_field_missing(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$body = self::valid_body();
		unset( $body['entity_type'] );

		$response = $this->controller->handle( $this->request( $body ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
	}

	public function test_invalid_payload_when_uuid_malformed(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle(
			$this->request( self::valid_body( [ 'session_uuid' => 'not-a-uuid' ] ) )
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
	}

	public function test_invalid_payload_when_entity_type_is_module(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle(
			$this->request( self::valid_body( [ 'entity_type' => 'module' ] ) )
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
	}

	public function test_invalid_payload_when_entity_type_is_session(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		// Sessions go through the Zoom attendance pipeline (Phase 7.4),
		// not the lesson-player progress endpoint.
		$response = $this->controller->handle(
			$this->request( self::valid_body( [ 'entity_type' => 'session' ] ) )
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
	}

	public function test_invalid_payload_when_entity_id_negative(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle(
			$this->request( self::valid_body( [ 'entity_id' => -1 ] ) )
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_payload', $response->get_error_code() );
	}

	public function test_payload_too_large(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle(
			$this->request(
				self::valid_body( [ 'payload' => [ 'blob' => str_repeat( 'a', 5000 ) ] ] )
			)
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'payload_too_large', $response->get_error_code() );
		self::assertSame( 413, $response->get_error_data()['status'] );
	}

	public function test_entity_not_found_when_post_missing(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'entity_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_entity_not_found_when_post_unpublished(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$this->post( 200, 'vl_lesson', 'draft' );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'entity_not_found', $response->get_error_code() );
	}

	public function test_entity_not_found_when_post_type_mismatch(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$this->post( 200, 'vl_topic' );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'entity_not_found', $response->get_error_code() );
	}

	public function test_not_enrolled_when_no_active_enrollment(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $lesson )->andReturn( $course );

		// No enrollment seeded.

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'not_enrolled', $response->get_error_code() );
		self::assertSame( 403, $response->get_error_data()['status'] );
	}

	public function test_success_returns_201_envelope(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $lesson )->andReturn( $course );

		$this->enroll_repo->seed(
			[
				'user_id'   => 7,
				'course_id' => 100,
			]
		);

		$result = new ProgressEventResult(
			view_id: 4567,
			progress: $this->progress_row(),
			lesson_completed: false,
			module_completed: false,
			course_progress_pct: 42,
			course_completed: false
		);

		$this->service->shouldReceive( 'record' )->once()->with( 7, Mockery::any() )->andReturn( $result );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['success'] );
		self::assertSame( 4567, $data['data']['view_id'] );
		self::assertSame( 'lesson', $data['data']['progress']['entity_type'] );
		self::assertSame( 200, $data['data']['progress']['entity_id'] );
		self::assertSame( 100, $data['data']['progress']['course_id'] );
		self::assertSame( 'in_progress', $data['data']['progress']['status'] );
		self::assertSame( 60, $data['data']['progress']['position_seconds'] );
		self::assertNull( $data['data']['progress']['completed_at'] );
		self::assertSame( 42, $data['data']['fanup']['course_progress_pct'] );
		self::assertFalse( $data['data']['fanup']['lesson_completed'] );
	}

	public function test_service_runtime_error_maps_to_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user() );
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $lesson )->andReturn( $course );

		$this->enroll_repo->seed(
			[
				'user_id'   => 7,
				'course_id' => 100,
			]
		);

		$this->service->shouldReceive( 'record' )->andThrow( new \RuntimeException( 'hierarchy_failure' ) );

		$response = $this->controller->handle( $this->request( self::valid_body() ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'entity_not_found', $response->get_error_code() );
	}
}
