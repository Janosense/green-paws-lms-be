<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\Verification;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\Mail\VerificationMailer;
use VL\LMS\Auth\Verification\EmailVerificationService;
use VL\LMS\Auth\Verification\VerificationException;
use VLJwtAuth\Support\RateLimiter;

final class EmailVerificationServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string>> */
	private array $user_meta;

	/** @var Mockery\MockInterface&VerificationMailer */
	private $mailer;

	/** @var Mockery\MockInterface&RateLimiter */
	private $rate_limiter;

	private EmailVerificationService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->user_meta = [];

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
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ): bool {
				unset( $this->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn ( string $email ): bool => str_contains( $email, '@' ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'NEW_TOKEN_PLAIN' );

		$this->mailer       = Mockery::mock( VerificationMailer::class );
		$this->rate_limiter = Mockery::mock( RateLimiter::class );
		$this->service      = new EmailVerificationService( $this->mailer, $this->rate_limiter );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_user(
		int $user_id,
		string $plain_token,
		int $expires_at,
		string $verified = '0'
	): object {
		$this->user_meta[ $user_id ] = [
			'_vl_verification_token_hash'    => hash( 'sha256', $plain_token ),
			'_vl_verification_token_expires' => (string) $expires_at,
			'_vl_email_verified'             => $verified,
		];

		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $user_id;

		Functions\when( 'get_users' )->alias(
			function ( array $args ) use ( $user ) {
				$hash = $args['meta_value'] ?? '';
				if ( hash( 'sha256', 'NO_MATCH' ) === $hash ) {
					return [];
				}
				if ( $this->user_meta[ $user->ID ]['_vl_verification_token_hash'] === $hash ) {
					return [ $user ];
				}
				return [];
			}
		);

		return $user;
	}

	public function test_verify_happy_path_marks_verified_and_clears_token(): void {
		$this->seed_user( 42, 'VALID_TOKEN', time() + 3600 );

		$user_id = $this->service->verify( 'VALID_TOKEN' );

		self::assertSame( 42, $user_id );
		self::assertSame( '1', $this->user_meta[42]['_vl_email_verified'] );
		self::assertArrayNotHasKey( '_vl_verification_token_hash', $this->user_meta[42] );
		self::assertArrayNotHasKey( '_vl_verification_token_expires', $this->user_meta[42] );
	}

	public function test_verify_rejects_empty_token_without_querying_users(): void {
		Functions\when( 'get_users' )->justReturn( [] );

		$this->expectException( VerificationException::class );

		try {
			$this->service->verify( '  ' );
		} catch ( VerificationException $e ) {
			self::assertSame( 'vl_lms_verification_invalid', $e->error_code() );
			throw $e;
		}
	}

	public function test_verify_throws_invalid_for_unknown_token(): void {
		Functions\when( 'get_users' )->justReturn( [] );

		try {
			$this->service->verify( 'NO_MATCH' );
			self::fail( 'Expected VerificationException.' );
		} catch ( VerificationException $e ) {
			self::assertSame( 'vl_lms_verification_invalid', $e->error_code() );
		}
	}

	public function test_verify_throws_expired_for_past_expiry(): void {
		$this->seed_user( 42, 'EXPIRED_TOKEN', time() - 10 );

		try {
			$this->service->verify( 'EXPIRED_TOKEN' );
			self::fail( 'Expected VerificationException.' );
		} catch ( VerificationException $e ) {
			self::assertSame( 'vl_lms_verification_expired', $e->error_code() );
		}
	}

	public function test_verify_throws_already_verified_when_user_already_marked(): void {
		$this->seed_user( 42, 'TOKEN', time() + 3600, verified: '1' );

		try {
			$this->service->verify( 'TOKEN' );
			self::fail( 'Expected VerificationException.' );
		} catch ( VerificationException $e ) {
			self::assertSame( 'vl_lms_verification_already_verified', $e->error_code() );
		}
	}

	public function test_verify_is_single_use_and_second_call_with_same_token_fails(): void {
		$user = $this->seed_user( 42, 'ONCE_ONLY', time() + 3600 );
		unset( $user );

		$this->service->verify( 'ONCE_ONLY' );

		Functions\when( 'get_users' )->justReturn( [] );

		try {
			$this->service->verify( 'ONCE_ONLY' );
			self::fail( 'Expected VerificationException on second use.' );
		} catch ( VerificationException $e ) {
			self::assertSame( 'vl_lms_verification_invalid', $e->error_code() );
		}
	}

	public function test_resend_is_silent_and_sends_email_for_unverified_user(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 77;
		$this->user_meta[77] = [ '_vl_email_verified' => '0' ];

		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->rate_limiter->shouldReceive( 'check' )->once()->andReturnTrue();
		$this->mailer->shouldReceive( 'send' )
			->once()
			->with( $user, 'NEW_TOKEN_PLAIN' )
			->andReturnTrue();

		$this->service->resend( 'someone@example.test' );

		self::assertSame(
			hash( 'sha256', 'NEW_TOKEN_PLAIN' ),
			$this->user_meta[77]['_vl_verification_token_hash']
		);
	}

	public function test_resend_is_silent_for_already_verified_user(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 77;
		$this->user_meta[77] = [ '_vl_email_verified' => '1' ];

		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->rate_limiter->shouldReceive( 'check' )->once()->andReturnTrue();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->resend( 'someone@example.test' );
	}

	public function test_resend_is_silent_for_unknown_email(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->rate_limiter->shouldReceive( 'check' )->once()->andReturnTrue();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->resend( 'nobody@example.test' );
	}

	public function test_resend_is_silent_for_malformed_email_without_rate_limit_hit(): void {
		$this->rate_limiter->shouldNotReceive( 'check' );
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->resend( 'not-an-email' );
	}

	public function test_resend_is_silent_when_rate_limited(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->rate_limiter->shouldReceive( 'check' )->once()->andReturnFalse();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->resend( 'rate@example.test' );
	}

	public function test_resend_invalidates_previous_token(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 77;
		$this->user_meta[77] = [
			'_vl_email_verified'             => '0',
			'_vl_verification_token_hash'    => hash( 'sha256', 'PREVIOUS_TOKEN' ),
			'_vl_verification_token_expires' => (string) ( time() + 3600 ),
		];

		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->rate_limiter->shouldReceive( 'check' )->once()->andReturnTrue();
		$this->mailer->shouldReceive( 'send' )->once()->andReturnTrue();

		$this->service->resend( 'user@example.test' );

		self::assertSame(
			hash( 'sha256', 'NEW_TOKEN_PLAIN' ),
			$this->user_meta[77]['_vl_verification_token_hash'],
			'New token hash must overwrite the previous one.'
		);
	}
}
