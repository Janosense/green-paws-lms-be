<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\LoginGate;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\LoginGate\UnverifiedLoginBlocker;
use WP_Error;

final class UnverifiedLoginBlockerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private UnverifiedLoginBlocker $blocker;

	/** @var array<int, string> */
	private array $verified_meta;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->verified_meta = [];
		$this->blocker       = new UnverifiedLoginBlocker();

		Functions\when( 'get_user_meta' )->alias(
			fn ( int $user_id, string $key, bool $single = false ): string => $this->verified_meta[ $user_id ] ?? ''
		);
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_attaches_wp_authenticate_user_filter(): void {
		Filters\expectAdded( 'wp_authenticate_user' )->once()->with( Mockery::type( 'array' ), 30, 2 );

		$this->blocker->register_hooks();
	}

	public function test_verified_user_passes_through_untouched(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 42;
		$this->verified_meta[42] = '1';

		$result = $this->blocker->block_unverified( $user, 'ignored' );

		self::assertSame( $user, $result );
	}

	public function test_unverified_user_returns_wp_error(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 42;
		$this->verified_meta[42] = '0';

		$result = $this->blocker->block_unverified( $user, 'ignored' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_email_not_verified', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_prior_wp_error_is_forwarded_unchanged(): void {
		$wp_error = new WP_Error( 'incorrect_password', 'wrong.', [ 'status' => 401 ] );

		$result = $this->blocker->block_unverified( $wp_error, 'ignored' );

		self::assertSame( $wp_error, $result );
	}

	public function test_user_with_completely_missing_meta_is_treated_as_unverified(): void {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = 77;

		$result = $this->blocker->block_unverified( $user, 'ignored' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'vl_lms_email_not_verified', $result->get_error_code() );
	}
}
