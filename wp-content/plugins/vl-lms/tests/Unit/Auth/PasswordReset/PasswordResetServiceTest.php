<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\PasswordReset;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\Mail\PasswordResetMailer;
use VL\LMS\Auth\PasswordPolicy;
use VL\LMS\Auth\PasswordReset\PasswordResetConfirmRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetException;
use VL\LMS\Auth\PasswordReset\PasswordResetRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetService;
use VLJwtAuth\Support\RateLimiter;

final class PasswordResetServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string>> */
	private array $user_meta;

	/** @var Mockery\MockInterface&PasswordResetMailer */
	private $mailer;

	/** @var Mockery\MockInterface&RateLimiter */
	private $rate_limiter;

	private PasswordResetService $service;

	/** @var callable|null */
	private $reset_password_spy;

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
		Functions\when( 'wp_generate_password' )->justReturn( 'NEW_RESET_TOKEN_PLAIN' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );

		$this->reset_password_spy = null;
		Functions\when( 'reset_password' )->alias(
			function ( $user, string $password ): void {
				if ( null !== $this->reset_password_spy ) {
					( $this->reset_password_spy )( $user, $password );
				}
			}
		);
		Functions\when( 'wp_set_password' )->alias(
			static function (): void {
				self::fail( 'wp_set_password() must not be called — password resets must go through reset_password().' );
			}
		);

		$this->mailer       = Mockery::mock( PasswordResetMailer::class );
		$this->rate_limiter = Mockery::mock( RateLimiter::class );
		$this->service      = new PasswordResetService(
			$this->mailer,
			$this->rate_limiter,
			new PasswordPolicy()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_user( int $user_id, string $plain_token, int $expires_at ): object {
		$this->user_meta[ $user_id ] = [
			'_vl_password_reset_token_hash'    => hash( 'sha256', $plain_token ),
			'_vl_password_reset_token_expires' => (string) $expires_at,
		];

		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $user_id;

		Functions\when( 'get_users' )->alias(
			function ( array $args ) use ( $user ) {
				$hash   = $args['meta_value'] ?? '';
				$stored = $this->user_meta[ $user->ID ]['_vl_password_reset_token_hash'] ?? null;
				if ( null !== $stored && $stored === $hash ) {
					return [ $user ];
				}
				return [];
			}
		);

		return $user;
	}

	public function test_request_generates_token_and_sends_email_for_known_user(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 42;

		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->rate_limiter->shouldReceive( 'check' )->twice()->andReturnTrue();
		$this->mailer->shouldReceive( 'send' )
			->once()
			->with( $user, 'NEW_RESET_TOKEN_PLAIN' )
			->andReturnTrue();

		$this->service->request( new PasswordResetRequest( email: 'user@example.test', ip: '203.0.113.5' ) );

		self::assertSame(
			hash( 'sha256', 'NEW_RESET_TOKEN_PLAIN' ),
			$this->user_meta[42]['_vl_password_reset_token_hash']
		);
		self::assertArrayHasKey( '_vl_password_reset_token_expires', $this->user_meta[42] );
	}

	public function test_request_is_silent_for_unknown_email(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->rate_limiter->shouldReceive( 'check' )->twice()->andReturnTrue();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->request( new PasswordResetRequest( email: 'unknown@example.test', ip: '203.0.113.5' ) );
	}

	public function test_request_is_silent_for_malformed_email_without_rate_check(): void {
		$this->rate_limiter->shouldNotReceive( 'check' );
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->request( new PasswordResetRequest( email: 'not-an-email', ip: '203.0.113.5' ) );
	}

	public function test_request_is_silent_when_rate_limited_by_email(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->rate_limiter->shouldReceive( 'check' )
			->once()
			->with( 'vl_lms_password_reset:user@example.test', Mockery::any(), Mockery::any() )
			->andReturnFalse();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->request( new PasswordResetRequest( email: 'user@example.test', ip: '203.0.113.5' ) );
	}

	public function test_request_is_silent_when_rate_limited_by_ip(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->rate_limiter->shouldReceive( 'check' )
			->once()
			->with( 'vl_lms_password_reset:user@example.test', Mockery::any(), Mockery::any() )
			->andReturnTrue();
		$this->rate_limiter->shouldReceive( 'check' )
			->once()
			->with( 'vl_lms_password_reset:203.0.113.5', Mockery::any(), Mockery::any() )
			->andReturnFalse();
		$this->mailer->shouldNotReceive( 'send' );

		$this->service->request( new PasswordResetRequest( email: 'user@example.test', ip: '203.0.113.5' ) );
	}

	public function test_request_second_call_invalidates_first_token(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 42;

		$this->user_meta[42] = [
			'_vl_password_reset_token_hash'    => hash( 'sha256', 'FIRST_TOKEN' ),
			'_vl_password_reset_token_expires' => (string) ( time() + 3600 ),
		];

		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->rate_limiter->shouldReceive( 'check' )->andReturnTrue();
		$this->mailer->shouldReceive( 'send' )->once()->andReturnTrue();

		$this->service->request( new PasswordResetRequest( email: 'user@example.test', ip: '' ) );

		self::assertSame(
			hash( 'sha256', 'NEW_RESET_TOKEN_PLAIN' ),
			$this->user_meta[42]['_vl_password_reset_token_hash'],
			'New reset token hash must overwrite any previously issued one.'
		);
	}

	public function test_confirm_happy_path_calls_reset_password_and_clears_meta(): void {
		$user = $this->seed_user( 42, 'VALID_TOKEN', time() + 1800 );

		$captured                 = null;
		$this->reset_password_spy = static function ( $called_user, string $password ) use ( &$captured ): void {
			$captured = [
				'user'     => $called_user,
				'password' => $password,
			];
		};

		$result = $this->service->confirm( new PasswordResetConfirmRequest( token: 'VALID_TOKEN', password: 'brand-new-pass' ) );

		self::assertSame( 42, $result );
		self::assertNotNull( $captured );
		self::assertSame( $user, $captured['user'] );
		self::assertSame( 'brand-new-pass', $captured['password'] );
		self::assertArrayNotHasKey( '_vl_password_reset_token_hash', $this->user_meta[42] );
		self::assertArrayNotHasKey( '_vl_password_reset_token_expires', $this->user_meta[42] );
		self::assertSame( '1', $this->user_meta[42]['_vl_email_verified'] );
	}

	public function test_confirm_marks_unverified_user_as_verified_on_reset(): void {
		$this->seed_user( 77, 'VALID_TOKEN', time() + 1800 );
		$this->user_meta[77]['_vl_email_verified'] = '0';

		$this->reset_password_spy = static function (): void {};

		$this->service->confirm( new PasswordResetConfirmRequest( token: 'VALID_TOKEN', password: 'brand-new-pass' ) );

		self::assertSame(
			'1',
			$this->user_meta[77]['_vl_email_verified'],
			'Successful password reset must auto-verify the email — see PHASE-2-AUDIT.md §13.'
		);
	}

	public function test_confirm_rejects_empty_token(): void {
		Functions\when( 'get_users' )->justReturn( [] );

		$this->expectException( PasswordResetException::class );

		try {
			$this->service->confirm( new PasswordResetConfirmRequest( token: '   ', password: 'brand-new-pass' ) );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_invalid_token', $e->error_code() );
			throw $e;
		}
	}

	public function test_confirm_throws_invalid_for_unknown_token(): void {
		Functions\when( 'get_users' )->justReturn( [] );

		try {
			$this->service->confirm( new PasswordResetConfirmRequest( token: 'NO_MATCH', password: 'brand-new-pass' ) );
			self::fail( 'Expected PasswordResetException.' );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_invalid_token', $e->error_code() );
		}
	}

	public function test_confirm_throws_expired_when_past_ttl(): void {
		$this->seed_user( 42, 'STALE_TOKEN', time() - 10 );

		try {
			$this->service->confirm( new PasswordResetConfirmRequest( token: 'STALE_TOKEN', password: 'brand-new-pass' ) );
			self::fail( 'Expected PasswordResetException.' );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_token_expired', $e->error_code() );
		}

		self::assertArrayHasKey(
			'_vl_password_reset_token_hash',
			$this->user_meta[42],
			'Expired-token confirmation must not clear the stored token meta.'
		);
	}

	public function test_confirm_throws_weak_password_and_leaves_token_intact(): void {
		$this->seed_user( 42, 'VALID_TOKEN', time() + 1800 );

		$this->reset_password_spy = static function (): void {
			self::fail( 'reset_password() must not be called when the new password fails policy.' );
		};

		try {
			$this->service->confirm( new PasswordResetConfirmRequest( token: 'VALID_TOKEN', password: 'short' ) );
			self::fail( 'Expected PasswordResetException.' );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_weak_password', $e->error_code() );
		}

		self::assertArrayHasKey(
			'_vl_password_reset_token_hash',
			$this->user_meta[42],
			'Weak-password rejection must leave the token valid for retry.'
		);
	}

	public function test_confirm_is_single_use_second_call_fails_invalid(): void {
		$this->seed_user( 42, 'ONCE_ONLY', time() + 1800 );

		$this->reset_password_spy = static function (): void {};

		$this->service->confirm( new PasswordResetConfirmRequest( token: 'ONCE_ONLY', password: 'brand-new-pass' ) );

		Functions\when( 'get_users' )->justReturn( [] );

		try {
			$this->service->confirm( new PasswordResetConfirmRequest( token: 'ONCE_ONLY', password: 'brand-new-pass' ) );
			self::fail( 'Expected PasswordResetException on second use.' );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_invalid_token', $e->error_code() );
		}
	}

	public function test_confirm_respects_password_policy_filter(): void {
		$this->seed_user( 42, 'VALID_TOKEN', time() + 1800 );

		Filters\expectApplied( 'vl_lms_min_password_length' )
			->atLeast()
			->once()
			->andReturn( 20 );

		$this->reset_password_spy = static function (): void {
			self::fail( 'reset_password() must not be called when filtered policy rejects the password.' );
		};

		try {
			// 12 chars, below the filtered 20-char minimum.
			$this->service->confirm(
				new PasswordResetConfirmRequest( token: 'VALID_TOKEN', password: 'twelvecharss' )
			);
			self::fail( 'Expected PasswordResetException.' );
		} catch ( PasswordResetException $e ) {
			self::assertSame( 'vl_lms_password_reset_weak_password', $e->error_code() );
		}
	}

	public function test_is_rate_limited_respects_filter_overrides(): void {
		Filters\expectApplied( 'vl_lms_password_reset_email_limit' )
			->once()
			->with( PasswordResetService::REQUEST_RATE_LIMIT_EMAIL )
			->andReturn( 99 );
		Filters\expectApplied( 'vl_lms_password_reset_ip_limit' )
			->once()
			->with( PasswordResetService::REQUEST_RATE_LIMIT_IP )
			->andReturn( 42 );

		$this->rate_limiter->shouldReceive( 'check' )
			->once()
			->with( 'vl_lms_password_reset:a@example.test', 99, Mockery::any() )
			->andReturnTrue();
		$this->rate_limiter->shouldReceive( 'check' )
			->once()
			->with( 'vl_lms_password_reset:1.2.3.4', 42, Mockery::any() )
			->andReturnTrue();

		self::assertFalse( $this->service->is_rate_limited( 'a@example.test', '1.2.3.4' ) );
	}
}
