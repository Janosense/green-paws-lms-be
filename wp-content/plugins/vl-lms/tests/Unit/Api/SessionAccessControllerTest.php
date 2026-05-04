<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\SessionAccessController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Learn\Access\SessionAccessDecision;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\Access\SessionAccessReason;
use VL\LMS\Learn\SessionContentTransformer;
use VL\LMS\Learn\SessionLookup;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\TestableSessionAccessController;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class SessionAccessControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&SessionLookup */
	private $lookup;

	/** @var Mockery\MockInterface&SessionAccessGate */
	private $gate;

	/** @var Mockery\MockInterface&SessionContentTransformer */
	private $transformer;

	private InMemoryEnrollmentRepository $enrollment_repo;
	private EnrollmentService $enrollments;

	private TestableSessionAccessController $controller;

	/** @var array<int, WP_Post|null> */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( mixed $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'set_status' )->andReturnSelf();
				$response->shouldReceive( 'get_status' )->andReturn( 200 );
				return $response;
			}
		);
		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);

		$this->authenticator   = Mockery::mock( RestAuthenticator::class );
		$this->lookup          = Mockery::mock( SessionLookup::class );
		$this->gate            = Mockery::mock( SessionAccessGate::class );
		$this->transformer     = Mockery::mock( SessionContentTransformer::class );
		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );

		$this->controller = new TestableSessionAccessController(
			'vl/v1',
			$this->authenticator,
			$this->lookup,
			$this->gate,
			$this->transformer,
			$this->enrollments
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stage_user( ?\WP_User $user ): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );
	}

	private function user( int $id, bool $has_cap = true ): \WP_User {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $id;
		$user->shouldReceive( 'has_cap' )->andReturn( $has_cap );
		return $user;
	}

	private function session( int $id, int $course_id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = 'module-3';
		$p->post_type   = 'vl_session';
		$p->post_status = 'publish';
		$p->post_parent = $course_id;
		return $p;
	}

	private function course( int $id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_type   = 'vl_course';
		$p->post_status = 'publish';
		return $p;
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

	private function enroll( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);
	}

	// register_routes

	public function test_register_routes_registers_three_routes(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 3, $calls );
		self::assertStringContainsString( '/learn/sessions/(?P<slug>', $calls[0]['route'] );
		self::assertStringContainsString( '/join', $calls[1]['route'] );
		self::assertStringContainsString( '/recording', $calls[2]['route'] );
	}

	// detail

	public function test_detail_returns_401_when_anonymous(): void {
		$this->stage_user( null );

		$result = $this->controller->detail( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_detail_returns_404_when_session_missing(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( null );

		$result = $this->controller->detail( $this->request( [ 'slug' => 'missing' ] ) );

		self::assertSame( 'session_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_detail_returns_404_when_parent_course_unpublished(): void {
		$this->stage_user( $this->user( 5 ) );
		$session          = $this->session( 100, 200 );
		$this->posts[200] = ( function (): WP_Post {
			$p              = Mockery::mock( 'WP_Post' );
			$p->ID          = 200;
			$p->post_type   = 'vl_course';
			$p->post_status = 'draft';
			return $p;
		} )();
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $session );

		$result = $this->controller->detail( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'session_not_found', $result->get_error_code() );
	}

	public function test_detail_returns_403_when_not_enrolled(): void {
		$this->stage_user( $this->user( 5 ) );
		$session          = $this->session( 100, 200 );
		$this->posts[200] = $this->course( 200 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $session );
		// No enrollment seeded → has_active_access = false

		$result = $this->controller->detail( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_detail_returns_payload_when_enrolled(): void {
		$this->stage_user( $this->user( 5 ) );
		$session          = $this->session( 100, 200 );
		$this->posts[200] = $this->course( 200 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $session );
		$this->enroll( 5, 200 );
		$this->transformer->shouldReceive( 'transform' )->with( $session, 5 )
			->andReturn(
				[
					'session'  => [ 'id' => 100 ],
					'computed' => [],
				]
			);

		$response = $this->controller->detail( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( 100, $response->get_data()['session']['id'] );
	}

	// join

	public function test_join_redirects_when_gate_allows(): void {
		$this->stage_user( $this->user( 5 ) );
		$session = $this->session( 100, 200 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $session );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::allow( 'https://zoom.us/j/x' )
		);

		$this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'https://zoom.us/j/x', $this->controller->last_redirect );
	}

	public function test_join_returns_403_not_enrolled(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::NOT_ENROLLED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_join_returns_410_session_cancelled(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::SESSION_CANCELLED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'session_cancelled', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
	}

	public function test_join_returns_503_with_retry_after_meeting_not_provisioned(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::MEETING_NOT_PROVISIONED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'meeting_not_provisioned', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
		self::assertSame( 60, $result->get_error_data()['retry_after'] );
	}

	public function test_join_returns_409_with_opens_at_when_window_not_open(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny(
				SessionAccessReason::JOIN_WINDOW_NOT_OPEN,
				[ 'opens_at' => '2026-05-15T17:45:00+00:00' ]
			)
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'join_window_not_open', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( '2026-05-15T17:45:00+00:00', $result->get_error_data()['opens_at'] );
	}

	public function test_join_returns_410_when_window_closed(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::JOIN_WINDOW_CLOSED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'join_window_closed', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
	}

	public function test_join_returns_404_when_course_not_found(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 0 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::COURSE_NOT_FOUND )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'session_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	// recording

	public function test_recording_redirects_when_gate_allows(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			SessionAccessDecision::allow( 'https://zoom.us/r/x' )
		);

		$this->controller->recording( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'https://zoom.us/r/x', $this->controller->last_redirect );
	}

	public function test_recording_returns_404_recording_not_available(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			SessionAccessDecision::deny( SessionAccessReason::RECORDING_NOT_AVAILABLE )
		);

		$result = $this->controller->recording( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'recording_not_available', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_recording_returns_410_with_expired_at(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->session( 100, 200 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			SessionAccessDecision::deny(
				SessionAccessReason::RECORDING_WINDOW_EXPIRED,
				[ 'expired_at' => '2026-06-15T00:00:00+00:00' ]
			)
		);

		$result = $this->controller->recording( $this->request( [ 'slug' => 'session-1' ] ) );

		self::assertSame( 'recording_window_expired', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
		self::assertSame( '2026-06-15T00:00:00+00:00', $result->get_error_data()['expired_at'] );
	}

	// permissions

	public function test_permission_view_returns_403_without_capability(): void {
		$this->stage_user( $this->user( 5, has_cap: false ) );

		$result = $this->controller->permission_view( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_recording_uses_recording_capability(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 5;
		$user->shouldReceive( 'has_cap' )
			->with( SessionAccessController::RECORDING_CAPABILITY )
			->andReturn( true );
		$this->stage_user( $user );

		self::assertTrue( $this->controller->permission_recording( $this->request() ) );
	}
}
