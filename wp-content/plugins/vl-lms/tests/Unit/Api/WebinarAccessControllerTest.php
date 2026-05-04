<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\WebinarAccessController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Services\Webinars\WebinarAccessDecision;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarAccessReason;
use VL\LMS\Services\Webinars\WebinarLookup;
use VL\LMS\Tests\Fixtures\TestableWebinarAccessController;
use WP_Error;
use WP_Post;

final class WebinarAccessControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&WebinarLookup */
	private $lookup;

	/** @var Mockery\MockInterface&WebinarAccessGate */
	private $gate;

	private TestableWebinarAccessController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->lookup        = Mockery::mock( WebinarLookup::class );
		$this->gate          = Mockery::mock( WebinarAccessGate::class );

		$this->controller = new TestableWebinarAccessController(
			'vl/v1',
			$this->authenticator,
			$this->lookup,
			$this->gate
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_join_and_recording(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertStringContainsString( '/join', $calls[0]['route'] );
		self::assertStringContainsString( '/recording', $calls[1]['route'] );
	}

	// Permission

	public function test_permission_join_returns_401_when_anonymous(): void {
		$this->stage_user( null );
		$result = $this->controller->permission_join( $this->request() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_permission_join_returns_403_when_missing_capability(): void {
		$this->stage_user( $this->user( 5, has_cap: false ) );
		$result = $this->controller->permission_join( $this->request() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_recording_uses_recording_capability(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 5;
		$user->shouldReceive( 'has_cap' )
			->with( WebinarAccessController::RECORDING_CAPABILITY )
			->andReturn( true );
		$this->stage_user( $user );

		self::assertTrue( $this->controller->permission_recording( $this->request() ) );
	}

	// JOIN

	public function test_join_redirects_when_gate_allows(): void {
		$this->stage_user( $this->user( 5 ) );
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			WebinarAccessDecision::allow( 'https://zoom.us/j/x' )
		);

		$this->controller->join( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'https://zoom.us/j/x', $this->controller->last_redirect );
	}

	public function test_join_returns_403_when_not_registered(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_registered', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_join_returns_409_with_opens_at_when_window_not_open(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			WebinarAccessDecision::deny(
				WebinarAccessReason::JOIN_WINDOW_NOT_OPEN,
				[ 'opens_at' => '2026-05-15T17:45:00+00:00' ]
			)
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'join_window_not_open', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( '2026-05-15T17:45:00+00:00', $result->get_error_data()['opens_at'] );
	}

	public function test_join_returns_410_when_window_closed(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			WebinarAccessDecision::deny( WebinarAccessReason::JOIN_WINDOW_CLOSED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'join_window_closed', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
	}

	public function test_join_returns_503_with_retry_after_for_meeting_not_provisioned(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_join' )->andReturn(
			WebinarAccessDecision::deny( WebinarAccessReason::MEETING_NOT_PROVISIONED )
		);

		$result = $this->controller->join( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'meeting_not_provisioned', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
		self::assertSame( 60, $result->get_error_data()['retry_after'] );
	}

	public function test_join_returns_404_when_webinar_not_found(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( null );

		$result = $this->controller->join( $this->request( [ 'slug' => 'missing' ] ) );

		self::assertSame( 'webinar_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	// RECORDING

	public function test_recording_redirects_when_gate_allows(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			WebinarAccessDecision::allow( 'https://zoom.us/r/x' )
		);

		$this->controller->recording( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'https://zoom.us/r/x', $this->controller->last_redirect );
	}

	public function test_recording_returns_403_when_not_registered(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED )
		);

		$result = $this->controller->recording( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'not_registered', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_recording_returns_404_for_recording_not_available(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE )
		);

		$result = $this->controller->recording( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'recording_not_available', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_recording_returns_410_with_expired_at_when_window_expired(): void {
		$this->stage_user( $this->user( 5 ) );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $this->webinar( 100 ) );
		$this->gate->shouldReceive( 'can_view_recording' )->andReturn(
			WebinarAccessDecision::deny(
				WebinarAccessReason::RECORDING_WINDOW_EXPIRED,
				[ 'expired_at' => '2026-06-15T00:00:00+00:00' ]
			)
		);

		$result = $this->controller->recording( $this->request( [ 'slug' => 'vet-may' ] ) );

		self::assertSame( 'recording_window_expired', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
		self::assertSame( '2026-06-15T00:00:00+00:00', $result->get_error_data()['expired_at'] );
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
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $id;
		$user->shouldReceive( 'has_cap' )->andReturn( $has_cap );
		return $user;
	}

	private function webinar( int $id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = 'vet-may';
		$p->post_type   = 'vl_webinar';
		$p->post_status = 'publish';
		return $p;
	}
}
