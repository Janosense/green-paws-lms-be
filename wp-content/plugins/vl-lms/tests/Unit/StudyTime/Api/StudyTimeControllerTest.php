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
use VL\LMS\StudyTime\StudyTimeConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class StudyTimeControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

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
		// Deliberately not the defaults: a hardcoded response body fails.
		$this->controller = new StudyTimeController(
			'vl/v1',
			$this->authenticator,
			new StudyTimeConfig( 90, 20, 30 )
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

	public function test_register_routes_registers_the_config_route_on_the_namespace(): void {
		$captured = [];

		Functions\expect( 'register_rest_route' )
			->once()
			->andReturnUsing(
				static function ( string $rest_namespace, string $route, array $args ) use ( &$captured ): bool {
					$captured = [ $rest_namespace, $route, $args ];
					return true;
				}
			);

		$this->controller->register_routes();

		[ $rest_namespace, $route, $args ] = $captured;
		self::assertSame( 'vl/v1', $rest_namespace );
		self::assertSame( '/study-time/config', $route );
		self::assertSame( 'GET', $args['methods'] );
		self::assertSame( [ $this->controller, 'config' ], $args['callback'] );
		self::assertSame( [ $this->controller, 'permission_callback' ], $args['permission_callback'] );
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
}
