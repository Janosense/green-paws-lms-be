<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\AuthController;
use VL\LMS\Auth\PasswordReset\PasswordResetConfirmRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetException;
use VL\LMS\Auth\PasswordReset\PasswordResetRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetService;
use VL\LMS\Auth\Registration\RegistrationException;
use VL\LMS\Auth\Registration\RegistrationOutcome;
use VL\LMS\Auth\Registration\RegistrationResult;
use VL\LMS\Auth\Registration\RegistrationService;
use VL\LMS\Auth\TokenIssuer;
use VL\LMS\Auth\Verification\EmailVerificationService;
use VL\LMS\Auth\Verification\VerificationException;
use WP_Error;
use WP_REST_Response;

final class AuthControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RegistrationService */
	private $registration;

	/** @var Mockery\MockInterface&EmailVerificationService */
	private $verification;

	/** @var Mockery\MockInterface&TokenIssuer */
	private $token_issuer;

	/** @var Mockery\MockInterface&PasswordResetService */
	private $password_reset;

	private AuthController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'set_status' )->andReturnSelf();
				return $response;
			}
		);

		$this->registration   = Mockery::mock( RegistrationService::class );
		$this->verification   = Mockery::mock( EmailVerificationService::class );
		$this->token_issuer   = Mockery::mock( TokenIssuer::class );
		$this->password_reset = Mockery::mock( PasswordResetService::class );

		$this->controller = new AuthController(
			'vl/v1',
			$this->registration,
			$this->verification,
			$this->token_issuer,
			$this->password_reset
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_all_five_endpoints(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = [
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				];
			}
		);

		$this->controller->register_routes();

		self::assertCount( 5, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/auth/register', $calls[0]['route'] );
		self::assertSame( '__return_true', $calls[0]['args']['permission_callback'] );
		self::assertSame( '/auth/verify-email', $calls[1]['route'] );
		self::assertSame( '/auth/resend-verification', $calls[2]['route'] );
		self::assertSame( '/auth/request-password-reset', $calls[3]['route'] );
		self::assertSame( '__return_true', $calls[3]['args']['permission_callback'] );
		self::assertSame( '/auth/reset-password', $calls[4]['route'] );
		self::assertSame( '__return_true', $calls[4]['args']['permission_callback'] );
		self::assertTrue( $calls[4]['args']['args']['token']['required'] );
		self::assertTrue( $calls[4]['args']['args']['password']['required'] );
	}

	public function test_register_endpoint_args_require_email_and_password(): void {
		$captured = null;
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$captured ): void {
				if ( '/auth/register' === $route ) {
					$captured = $args;
				}
			}
		);

		$this->controller->register_routes();

		self::assertTrue( $captured['args']['email']['required'] );
		self::assertTrue( $captured['args']['password']['required'] );
		self::assertFalse( $captured['args']['account_kind']['required'] );
		self::assertSame( [ 'student' ], $captured['args']['account_kind']['enum'] );
	}

	public function test_register_returns_generic_success_body_on_created(): void {
		$this->registration->shouldReceive( 'register' )
			->once()
			->andReturn(
				new RegistrationResult(
					user_id: 42,
					plain_verification_token: 'PLAIN',
					outcome: RegistrationOutcome::CREATED
				)
			);

		$request = $this->make_request(
			[
				'email'      => 'alice@example.test',
				'password'   => 'hunter2hunter2',
				'first_name' => 'Alice',
				'last_name'  => 'Smith',
			]
		);

		$response = $this->controller->register( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertTrue( $data['success'] );
		self::assertArrayHasKey( 'message', $data['data'] );
	}

	public function test_register_returns_same_generic_body_for_already_verified(): void {
		$this->registration->shouldReceive( 'register' )
			->once()
			->andReturn(
				new RegistrationResult(
					user_id: 99,
					plain_verification_token: null,
					outcome: RegistrationOutcome::ALREADY_VERIFIED
				)
			);

		$request  = $this->make_request(
			[
				'email'      => 'verified@example.test',
				'password'   => 'hunter2hunter2',
				'first_name' => 'V',
				'last_name'  => 'User',
			]
		);
		$response = $this->controller->register( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertArrayHasKey( 'message', $response->get_data()['data'] );
	}

	public function test_register_maps_registration_exception_to_wp_error(): void {
		$this->registration->shouldReceive( 'register' )
			->once()
			->andThrow( RegistrationException::invalid_email() );

		$request = $this->make_request(
			[
				'email'      => 'bogus',
				'password'   => 'hunter2hunter2',
				'first_name' => 'X',
				'last_name'  => 'Y',
			]
		);

		$result = $this->controller->register( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_invalid_email', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_register_rejects_empty_required_fields_with_wp_error(): void {
		$request = $this->make_request(
			[
				'email'      => '',
				'password'   => 'hunter2hunter2',
				'first_name' => 'A',
				'last_name'  => 'B',
			]
		);

		$result = $this->controller->register( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_invalid_email', $result->get_error_code() );
	}

	public function test_verify_email_issues_tokens_on_success(): void {
		$this->verification->shouldReceive( 'verify' )->once()->with( 'PLAIN_TOKEN' )->andReturn( 42 );

		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 42;
		$user->user_email   = 'alice@example.test';
		$user->display_name = 'Alice Smith';
		$user->roles        = [ 'student' ];

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->justReturn( 'student' );

		$this->token_issuer->shouldReceive( 'issue_for' )
			->once()
			->with( $user, Mockery::any() )
			->andReturn(
				[
					'access_token' => 'eyJ-fake',
					'token_type'   => 'Bearer',
					'expires_in'   => 1800,
				]
			);

		$request  = $this->make_request( [ 'token' => 'PLAIN_TOKEN' ] );
		$response = $this->controller->verify_email( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertTrue( $data['success'] );
		self::assertSame( 'eyJ-fake', $data['data']['access_token'] );
		self::assertSame( 42, $data['data']['user']['id'] );
		self::assertSame( 'student', $data['data']['user']['account_kind'] );
	}

	public function test_verify_email_maps_verification_exception_to_wp_error(): void {
		$this->verification->shouldReceive( 'verify' )
			->once()
			->andThrow( VerificationException::expired() );

		$this->token_issuer->shouldNotReceive( 'issue_for' );

		$request = $this->make_request( [ 'token' => 'EXPIRED' ] );

		$result = $this->controller->verify_email( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_verification_expired', $result->get_error_code() );
	}

	public function test_verify_email_surfaces_missing_user(): void {
		$this->verification->shouldReceive( 'verify' )->once()->andReturn( 42 );
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->token_issuer->shouldNotReceive( 'issue_for' );

		$result = $this->controller->verify_email( $this->make_request( [ 'token' => 'OK' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_verification_user_missing', $result->get_error_code() );
		self::assertSame( 410, $result->get_error_data()['status'] );
	}

	public function test_resend_verification_returns_generic_body_always(): void {
		$this->verification->shouldReceive( 'resend' )->once()->with( 'nobody@example.test' );

		$response = $this->controller->resend_verification(
			$this->make_request( [ 'email' => 'nobody@example.test' ] )
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertTrue( $response->get_data()['success'] );
		self::assertArrayHasKey( 'message', $response->get_data()['data'] );
	}

	public function test_request_password_reset_returns_generic_body_for_known_email(): void {
		$this->password_reset->shouldReceive( 'request' )
			->once()
			->with( Mockery::type( PasswordResetRequest::class ) );

		$response = $this->controller->request_password_reset(
			$this->make_request( [ 'email' => 'known@example.test' ] )
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertTrue( $data['success'] );
		self::assertArrayHasKey( 'message', $data['data'] );
	}

	public function test_request_password_reset_returns_same_body_for_unknown_email(): void {
		$this->password_reset->shouldReceive( 'request' )
			->once()
			->with( Mockery::type( PasswordResetRequest::class ) );

		$response_known = $this->controller->request_password_reset(
			$this->make_request( [ 'email' => 'known@example.test' ] )
		);

		$this->password_reset->shouldReceive( 'request' )
			->once()
			->with( Mockery::type( PasswordResetRequest::class ) );
		$response_unknown = $this->controller->request_password_reset(
			$this->make_request( [ 'email' => 'unknown@example.test' ] )
		);

		self::assertSame(
			$response_known->get_data(),
			$response_unknown->get_data(),
			'Response bodies must be identical regardless of whether the email matches an account.'
		);
	}

	public function test_request_password_reset_passes_email_through_value_object(): void {
		$captured = null;
		$this->password_reset->shouldReceive( 'request' )
			->once()
			->andReturnUsing(
				static function ( PasswordResetRequest $req ) use ( &$captured ): void {
					$captured = $req;
				}
			);

		$this->controller->request_password_reset(
			$this->make_request( [ 'email' => 'forward@example.test' ] )
		);

		self::assertNotNull( $captured );
		self::assertSame( 'forward@example.test', $captured->email );
	}

	public function test_reset_password_returns_generic_success_on_confirm(): void {
		$this->password_reset->shouldReceive( 'confirm' )
			->once()
			->with( Mockery::type( PasswordResetConfirmRequest::class ) )
			->andReturn( 42 );

		$response = $this->controller->reset_password(
			$this->make_request(
				[
					'token'    => 'VALID',
					'password' => 'brand-new-pass',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertTrue( $data['success'] );
		self::assertArrayHasKey( 'message', $data['data'] );
	}

	public function test_reset_password_maps_invalid_exception_to_wp_error(): void {
		$this->password_reset->shouldReceive( 'confirm' )
			->once()
			->andThrow( PasswordResetException::invalid() );

		$result = $this->controller->reset_password(
			$this->make_request(
				[
					'token'    => 'BAD',
					'password' => 'brand-new-pass',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_password_reset_invalid_token', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_reset_password_maps_expired_exception_to_wp_error(): void {
		$this->password_reset->shouldReceive( 'confirm' )
			->once()
			->andThrow( PasswordResetException::expired() );

		$result = $this->controller->reset_password(
			$this->make_request(
				[
					'token'    => 'STALE',
					'password' => 'brand-new-pass',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_password_reset_token_expired', $result->get_error_code() );
	}

	public function test_reset_password_maps_weak_password_exception_to_wp_error(): void {
		$this->password_reset->shouldReceive( 'confirm' )
			->once()
			->andThrow( PasswordResetException::weak_password( 8 ) );

		$result = $this->controller->reset_password(
			$this->make_request(
				[
					'token'    => 'VALID',
					'password' => 'weak',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_password_reset_weak_password', $result->get_error_code() );
	}

	/**
	 * Build a minimal WP_REST_Request stub whose `get_param` reads from the
	 * provided array. Avoids depending on WordPress's real request class.
	 *
	 * @param array<string, mixed> $params
	 */
	private function make_request( array $params ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => $params[ $name ] ?? null
		);
		$request->shouldReceive( 'get_header' )->andReturn( '' );
		return $request;
	}
}
