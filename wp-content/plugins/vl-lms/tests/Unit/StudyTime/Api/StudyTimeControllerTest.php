<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\StudyTime\Api\StudyTimeController;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Services\Exception\HeartbeatFailedException;
use VL\LMS\StudyTime\Services\HeartbeatResult;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Services\HeartbeatService;
use VL\LMS\StudyTime\StudyTimeConfig;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class StudyTimeControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&HeartbeatService */
	private $heartbeats;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var list<array{string, array<string, mixed>}> */
	private array $debug_log = [];

	/**
	 * @var Mockery\MockInterface
	 */
	private $reports;

	private StudyTimeController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( mixed $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'get_status' )->andReturn( 200 );
				return $response;
			}
		);

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->heartbeats    = Mockery::mock( HeartbeatService::class );
		$this->reports       = Mockery::mock( StudyTimeReportQuery::class );
		$this->logger        = Mockery::mock( Logger::class );
		$this->logger->shouldReceive( 'debug' )->andReturnUsing(
			function ( string $message, array $context = [] ): void {
				$this->debug_log[] = [ $message, $context ];
			}
		)->byDefault();

		// Deliberately not the defaults: a hardcoded response body fails.
		$this->controller = new StudyTimeController(
			'vl/v1',
			$this->authenticator,
			new StudyTimeConfig( 90, 20, 30 ),
			$this->heartbeats,
			$this->reports,
			$this->logger
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request(): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		assert( $request instanceof WP_REST_Request );
		return $request;
	}

	/**
	 * @param mixed $body What `get_json_params()` returns for this request.
	 */
	private function heartbeat_request( mixed $body ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );
		assert( $request instanceof WP_REST_Request );
		return $request;
	}

	private function user( bool $can_view = true ): WP_User {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 7;
		$user->shouldReceive( 'has_cap' )->with( 'vl_view_lesson' )->andReturn( $can_view )->byDefault();
		assert( $user instanceof WP_User );
		return $user;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function last_debug_context(): array {
		self::assertNotSame( [], $this->debug_log, 'A refused heartbeat must be logged.' );
		return $this->debug_log[ array_key_last( $this->debug_log ) ][1];
	}

	public function test_register_routes_registers_the_config_route_on_the_namespace(): void {
		$captured   = [];
		$namespaces = [];

		// Three routes since Sprint 2 Step 5: config, heartbeat, me.
		Functions\expect( 'register_rest_route' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $rest_namespace, string $route, array $args ) use ( &$captured, &$namespaces ): bool {
					$captured[ $route ] = $args;
					$namespaces[]       = $rest_namespace;
					return true;
				}
			);

		$this->controller->register_routes();

		self::assertSame( [ 'vl/v1', 'vl/v1', 'vl/v1' ], $namespaces );
		$config = $captured['/study-time/config'];
		self::assertSame( 'GET', $config['methods'] );
		self::assertSame( [ $this->controller, 'config' ], $config['callback'] );
		self::assertSame( [ $this->controller, 'permission_callback' ], $config['permission_callback'] );
	}

	public function test_permission_callback_denies_a_caller_the_authenticator_does_not_resolve(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( null );

		$result = $this->controller->permission_callback( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
	}

	public function test_permission_callback_admits_any_logged_in_caller_without_checking_capabilities(): void {
		// The configuration is three integers, not learner data — the
		// capability and enrollment gates belong to the heartbeat (Step 4).
		$user = Mockery::mock( 'WP_User' );
		$user->shouldNotReceive( 'has_cap' );
		assert( $user instanceof WP_User );
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $user );

		self::assertTrue( $this->controller->permission_callback( $this->request() ) );
	}

	public function test_config_answers_the_three_configured_intervals(): void {
		$response = $this->controller->config();

		self::assertSame(
			[
				'idle_seconds'      => 90,
				'heartbeat_seconds' => 20,
				'cap_seconds'       => 30,
			],
			$response->get_data()
		);
	}

	public function test_register_routes_registers_the_heartbeat_route_too(): void {
		$captured = [];

		// Three routes since Sprint 2 Step 5: config, heartbeat, me.
		Functions\expect( 'register_rest_route' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $rest_namespace, string $route, array $args ) use ( &$captured ): bool {
					$captured[ $route ] = $args;
					return true;
				}
			);

		$this->controller->register_routes();

		self::assertSame( [ '/study-time/config', '/study-time/heartbeat', '/study-time/me' ], array_keys( $captured ) );
		self::assertSame( 'POST', $captured['/study-time/heartbeat']['methods'] );
		self::assertSame( [ $this->controller, 'heartbeat' ], $captured['/study-time/heartbeat']['callback'] );
		self::assertSame( [ $this->controller, 'heartbeat_permission_callback' ], $captured['/study-time/heartbeat']['permission_callback'] );
	}

	public function test_heartbeat_permission_callback_denies_a_caller_the_authenticator_does_not_resolve(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( null );

		$result = $this->controller->heartbeat_permission_callback( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
	}

	public function test_heartbeat_permission_callback_denies_a_caller_without_the_view_capability(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user( false ) );

		$result = $this->controller->heartbeat_permission_callback( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_forbidden', $result->get_error_code() );
		self::assertSame( [ 'status' => 403 ], $result->get_error_data() );
	}

	public function test_heartbeat_permission_callback_admits_a_learner_with_the_view_capability(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );

		self::assertTrue( $this->controller->heartbeat_permission_callback( $this->request() ) );
	}

	/**
	 * @return array<string, array{mixed, string, int}>
	 */
	public static function invalid_bodies(): array {
		return [
			'empty body'             => [ [], 'invalid_payload', 400 ],
			'not an object'          => [ null, 'invalid_payload', 400 ],
			'unknown entity type'    => [
				[
					'entity_type' => 'module',
					'entity_id'   => 101,
					'kind'        => 'video',
				],
				'invalid_entity_type',
				422,
			],
			'missing entity type'    => [
				[
					'entity_id' => 101,
					'kind'      => 'video',
				],
				'invalid_entity_type',
				422,
			],
			'entity id zero'         => [
				[
					'entity_type' => 'lesson',
					'entity_id'   => 0,
					'kind'        => 'video',
				],
				'invalid_payload',
				400,
			],
			'entity id not a number' => [
				[
					'entity_type' => 'lesson',
					'entity_id'   => 'x',
					'kind'        => 'video',
				],
				'invalid_payload',
				400,
			],
			'unknown kind'           => [
				[
					'entity_type' => 'lesson',
					'entity_id'   => 101,
					'kind'        => 'quiz',
				],
				'invalid_kind',
				422,
			],
		];
	}

	/**
	 * @dataProvider invalid_bodies
	 */
	public function test_heartbeat_refuses_an_invalid_body_without_calling_the_service( mixed $body, string $code, int $status ): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );
		$this->heartbeats->shouldNotReceive( 'record' );

		$result = $this->controller->heartbeat( $this->heartbeat_request( $body ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertSame( [ 'status' => $status ], $result->get_error_data() );
	}

	public function test_heartbeat_maps_an_unaddressable_entity_to_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );
		$this->heartbeats->shouldReceive( 'record' )->once()->andThrow(
			new HeartbeatFailedException( HeartbeatFailedException::ENTITY_NOT_FOUND )
		);

		$result = $this->controller->heartbeat( $this->valid_request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'entity_not_found', $result->get_error_code() );
		self::assertSame( [ 'status' => 404 ], $result->get_error_data() );
	}

	public function test_heartbeat_maps_a_learner_without_access_to_403(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );
		$this->heartbeats->shouldReceive( 'record' )->once()->andThrow(
			new HeartbeatFailedException( HeartbeatFailedException::NOT_ENROLLED )
		);

		$result = $this->controller->heartbeat( $this->valid_request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( [ 'status' => 403 ], $result->get_error_data() );
	}

	public function test_heartbeat_passes_the_parsed_body_to_the_service_and_answers_its_totals(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );
		$this->heartbeats->shouldReceive( 'record' )
			->once()
			->with( 7, 'topic', 205, StudyKind::READING, Mockery::type( \DateTimeImmutable::class ) )
			->andReturn( new HeartbeatResult( 30, 75 ) );

		$response = $this->controller->heartbeat(
			$this->heartbeat_request(
				[
					'entity_type' => 'topic',
					'entity_id'   => 205,
					'kind'        => 'reading',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame(
			[
				'active_seconds'        => 30,
				'course_active_seconds' => 75,
			],
			$response->get_data()
		);
	}

	public function test_a_refused_heartbeat_is_logged_with_its_reason_and_no_secret(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->once()->andReturn( $this->user() );
		$this->heartbeats->shouldReceive( 'record' )->once()->andThrow(
			new HeartbeatFailedException( HeartbeatFailedException::NOT_ENROLLED )
		);

		$this->controller->heartbeat( $this->valid_request() );

		$context = $this->last_debug_context();
		self::assertSame( 'not_enrolled', $context['reason'] );
		self::assertSame( 403, $context['status'] );
		self::assertSame( 7, $context['user_id'] );
		self::assertSame( 'lesson', $context['entity_type'] );
		self::assertSame( 101, $context['entity_id'] );
		// Nothing that could carry a credential ever reaches the log.
		foreach ( [ 'token', 'authorization', 'Authorization', 'body', 'headers' ] as $forbidden ) {
			self::assertArrayNotHasKey( $forbidden, $context );
		}
	}

	private function valid_request(): WP_REST_Request {
		return $this->heartbeat_request(
			[
				'entity_type' => 'lesson',
				'entity_id'   => 101,
				'kind'        => 'video',
			]
		);
	}

	public function test_register_routes_registers_the_me_route_too(): void {
		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$routes ): void {
				$routes[ $route ] = $args;
			}
		);

		$this->controller->register_routes();

		self::assertArrayHasKey( StudyTimeController::ME_ROUTE, $routes );
		self::assertSame( 'GET', $routes[ StudyTimeController::ME_ROUTE ]['methods'] );
		self::assertSame(
			[ $this->controller, 'permission_callback' ],
			$routes[ StudyTimeController::ME_ROUTE ]['permission_callback'],
			'being signed in is the whole gate: the route answers about the caller only'
		);
	}

	public function test_me_refuses_a_caller_the_authenticator_does_not_resolve(): void {
		$request = Mockery::mock( WP_REST_Request::class );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->me( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'rest_not_logged_in', $result->get_error_code() );
	}

	public function test_me_answers_a_bare_object_with_one_entry_per_enrolled_course(): void {
		$this->authenticate_as( 7 );
		$this->stub_course_slugs(
			[
				101 => 'anesthesia',
				202 => 'c-section',
			]
		);
		$this->reports->shouldReceive( 'for_user' )->with( 7 )->andReturn(
			[
				101 => [
					'total'   => 4716,
					'video'   => 600,
					'reading' => 300,
					'quiz'    => 480,
					'session' => 3336,
				],
				202 => [
					'total'   => 0,
					'video'   => 0,
					'reading' => 0,
					'quiz'    => 0,
					'session' => 0,
				],
			]
		);

		$data = $this->controller->me( Mockery::mock( WP_REST_Request::class ) )->get_data();

		self::assertArrayNotHasKey( 'success', $data, 'bare object, no envelope' );
		self::assertArrayNotHasKey( 'data', $data );
		self::assertCount( 2, $data['courses'] );
		self::assertSame(
			[
				'course_id'       => 101,
				'course_slug'     => 'anesthesia',
				'total_seconds'   => 4716,
				'video_seconds'   => 600,
				'reading_seconds' => 300,
				'quiz_seconds'    => 480,
				'session_seconds' => 3336,
			],
			$data['courses'][0]
		);
		self::assertSame( 0, $data['courses'][1]['total_seconds'], 'an enrolled course with no time is still listed' );
	}

	public function test_me_answers_an_empty_list_rather_than_a_404_for_a_learner_with_no_enrollments(): void {
		$this->authenticate_as( 7 );
		$this->reports->shouldReceive( 'for_user' )->andReturn( [] );

		$response = $this->controller->me( Mockery::mock( WP_REST_Request::class ) );

		self::assertSame( [ 'courses' => [] ], $response->get_data() );
		self::assertSame( 200, $response->get_status() );
	}

	public function test_me_skips_a_course_whose_post_is_gone(): void {
		$this->authenticate_as( 7 );
		$this->stub_course_slugs( [ 101 => 'anesthesia' ] );
		$this->reports->shouldReceive( 'for_user' )->andReturn(
			[
				101 => [
					'total'   => 60,
					'video'   => 60,
					'reading' => 0,
					'quiz'    => 0,
					'session' => 0,
				],
				999 => [
					'total'   => 30,
					'video'   => 30,
					'reading' => 0,
					'quiz'    => 0,
					'session' => 0,
				],
			]
		);

		$data = $this->controller->me( Mockery::mock( WP_REST_Request::class ) )->get_data();

		self::assertSame( [ 101 ], array_column( $data['courses'], 'course_id' ) );
	}

	private function authenticate_as( int $user_id ): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $user_id;
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
	}

	/**
	 * @param array<int, string> $slugs
	 */
	private function stub_course_slugs( array $slugs ): void {
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( $slugs ) {
				if ( ! isset( $slugs[ $id ] ) ) {
					return null;
				}
				$post            = Mockery::mock( 'WP_Post' );
				$post->post_name = $slugs[ $id ];
				return $post;
			}
		);
	}
}
