<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\Registration;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\Mail\VerificationMailer;
use VL\LMS\Auth\Registration\RegistrationException;
use VL\LMS\Auth\Registration\RegistrationOutcome;
use VL\LMS\Auth\Registration\RegistrationRequest;
use VL\LMS\Auth\Registration\RegistrationService;
use WP_Error;

final class RegistrationServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string>> */
	private array $user_meta;

	/** @var Mockery\MockInterface&VerificationMailer */
	private $mailer;

	private RegistrationService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->user_meta = [];

		Functions\when( 'is_email' )->alias( static fn ( string $email ): bool => str_contains( $email, '@' ) );
		Functions\when( 'sanitize_user' )->returnArg( 1 );
		Functions\when( 'wp_generate_password' )->justReturn( 'GENERATED_TOKEN_PLAIN' );

		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single = false ) {
				return $this->user_meta[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = (string) $value;
				return true;
			}
		);

		$this->mailer  = Mockery::mock( VerificationMailer::class );
		$this->service = new RegistrationService( $this->mailer );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_creates_new_user_with_student_role_and_sends_email(): void {
		Functions\when( 'get_user_by' )->alias(
			function ( string $field, $value ) {
				if ( 'email' === $field ) {
					return false;
				}
				$user      = Mockery::mock( 'WP_User' );
				$user->ID  = 42;
				$user->first_name = 'Alice';
				$user->user_login = 'alice';
				return $user;
			}
		);
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_insert_user' )->justReturn( 42 );

		$this->mailer->shouldReceive( 'send' )
			->once()
			->withArgs( static fn ( $user, string $plain ): bool => 'GENERATED_TOKEN_PLAIN' === $plain )
			->andReturnTrue();

		$result = $this->service->register(
			new RegistrationRequest(
				email: 'alice@example.test',
				password: 'hunter2hunter2',
				first_name: 'Alice',
				last_name: 'Smith'
			)
		);

		self::assertSame( 42, $result->user_id );
		self::assertSame( RegistrationOutcome::CREATED, $result->outcome );
		self::assertSame( 'GENERATED_TOKEN_PLAIN', $result->plain_verification_token );
		self::assertSame( '0', $this->user_meta[42]['_vl_email_verified'] );
		self::assertSame( 'student', $this->user_meta[42]['_vl_account_kind'] );
		self::assertSame(
			hash( 'sha256', 'GENERATED_TOKEN_PLAIN' ),
			$this->user_meta[42]['_vl_verification_token_hash']
		);
	}

	public function test_register_passes_student_role_to_wp_insert_user(): void {
		Functions\when( 'get_user_by' )->alias(
			function ( string $field, $value ) {
				if ( 'email' === $field ) {
					return false;
				}
				$user      = Mockery::mock( 'WP_User' );
				$user->ID  = 42;
				return $user;
			}
		);
		Functions\when( 'username_exists' )->justReturn( false );

		$captured_args = null;
		Functions\expect( 'wp_insert_user' )
			->once()
			->andReturnUsing(
				static function ( array $args ) use ( &$captured_args ): int {
					$captured_args = $args;
					return 42;
				}
			);

		$this->mailer->shouldReceive( 'send' )->andReturnTrue();

		$this->service->register(
			new RegistrationRequest(
				email: 'alice@example.test',
				password: 'hunter2hunter2',
				first_name: 'Alice',
				last_name: 'Smith'
			)
		);

		self::assertSame( 'student', $captured_args['role'] );
		self::assertSame( 'alice@example.test', $captured_args['user_email'] );
		self::assertSame( 'Alice Smith', $captured_args['display_name'] );
	}

	public function test_register_silently_succeeds_for_already_verified_email(): void {
		$existing     = Mockery::mock( 'WP_User' );
		$existing->ID = 99;
		$this->user_meta[99] = [ '_vl_email_verified' => '1' ];

		Functions\when( 'get_user_by' )->justReturn( $existing );

		$this->mailer->shouldNotReceive( 'send' );

		$result = $this->service->register(
			new RegistrationRequest(
				email: 'verified@example.test',
				password: 'hunter2hunter2',
				first_name: 'Verified',
				last_name: 'User'
			)
		);

		self::assertSame( 99, $result->user_id );
		self::assertSame( RegistrationOutcome::ALREADY_VERIFIED, $result->outcome );
		self::assertNull( $result->plain_verification_token );
	}

	public function test_register_resends_verification_for_existing_unverified_user(): void {
		$existing     = Mockery::mock( 'WP_User' );
		$existing->ID = 77;
		$this->user_meta[77] = [ '_vl_email_verified' => '0' ];

		Functions\when( 'get_user_by' )->justReturn( $existing );

		$this->mailer->shouldReceive( 'send' )
			->once()
			->with( $existing, 'GENERATED_TOKEN_PLAIN' )
			->andReturnTrue();

		$result = $this->service->register(
			new RegistrationRequest(
				email: 'unverified@example.test',
				password: 'hunter2hunter2',
				first_name: 'Unverified',
				last_name: 'User'
			)
		);

		self::assertSame( RegistrationOutcome::RESENT, $result->outcome );
		self::assertSame( 'GENERATED_TOKEN_PLAIN', $result->plain_verification_token );
		self::assertSame(
			hash( 'sha256', 'GENERATED_TOKEN_PLAIN' ),
			$this->user_meta[77]['_vl_verification_token_hash']
		);
	}

	public function test_register_rejects_weak_password(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->mailer->shouldNotReceive( 'send' );

		try {
			$this->service->register(
				new RegistrationRequest(
					email: 'weak@example.test',
					password: 'short',
					first_name: 'Weak',
					last_name: 'User'
				)
			);
			self::fail( 'Expected RegistrationException for weak password.' );
		} catch ( RegistrationException $e ) {
			self::assertSame( 'vl_lms_weak_password', $e->error_code() );
		}
	}

	public function test_register_rejects_invalid_email(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->mailer->shouldNotReceive( 'send' );

		try {
			$this->service->register(
				new RegistrationRequest(
					email: 'not-an-email',
					password: 'hunter2hunter2',
					first_name: 'Alice',
					last_name: 'Smith'
				)
			);
			self::fail( 'Expected RegistrationException for invalid email.' );
		} catch ( RegistrationException $e ) {
			self::assertSame( 'vl_lms_invalid_email', $e->error_code() );
		}
	}

	public function test_register_propagates_wp_insert_user_failure(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );

		$wp_error = Mockery::mock( WP_Error::class );
		$wp_error->shouldReceive( 'get_error_message' )->andReturn( 'A user with that email already exists.' );
		Functions\when( 'wp_insert_user' )->justReturn( $wp_error );

		$this->mailer->shouldNotReceive( 'send' );

		try {
			$this->service->register(
				new RegistrationRequest(
					email: 'dup@example.test',
					password: 'hunter2hunter2',
					first_name: 'Dup',
					last_name: 'User'
				)
			);
			self::fail( 'Expected RegistrationException when wp_insert_user returns WP_Error.' );
		} catch ( RegistrationException $e ) {
			self::assertSame( 'vl_lms_registration_failed', $e->error_code() );
			self::assertSame( 500, $e->status_code() );
		}
	}

	public function test_register_respects_min_password_length_filter(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		Filters\expectApplied( 'vl_lms_min_password_length' )
			->with( RegistrationService::DEFAULT_MIN_PASSWORD_LENGTH )
			->andReturn( 12 );
		Filters\expectApplied( 'vl_lms_verification_token_ttl' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_verification_email_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_verification_email_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->mailer->shouldNotReceive( 'send' );

		try {
			$this->service->register(
				new RegistrationRequest(
					email: 'ok@example.test',
					password: 'eightchr_', // 9 chars, below the 12-char override
					first_name: 'Ok',
					last_name: 'User'
				)
			);
			self::fail( 'Expected RegistrationException when password is below filtered minimum.' );
		} catch ( RegistrationException $e ) {
			self::assertSame( 'vl_lms_weak_password', $e->error_code() );
		}
	}
}
