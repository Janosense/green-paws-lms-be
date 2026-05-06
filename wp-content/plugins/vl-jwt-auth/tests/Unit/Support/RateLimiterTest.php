<?php

declare(strict_types=1);

namespace VLJwtAuth\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VLJwtAuth\Support\RateLimiter;

final class RateLimiterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_check_allows_calls_under_the_limit(): void {
		Filters\expectApplied( 'vl_jwt_auth_rate_limit_bypass' )->andReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$limiter = new RateLimiter();
		$this->assertTrue( $limiter->check( 'login:1.2.3.4', 5, 900 ) );
	}

	public function test_check_blocks_calls_over_the_limit(): void {
		Filters\expectApplied( 'vl_jwt_auth_rate_limit_bypass' )->andReturn( false );
		Functions\when( 'get_transient' )->justReturn(
			[
				'count' => 5,
				'reset' => time() + 600,
			]
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$limiter = new RateLimiter();
		$this->assertFalse( $limiter->check( 'login:1.2.3.4', 5, 900 ) );
	}

	public function test_bypass_filter_short_circuits_the_check(): void {
		Filters\expectApplied( 'vl_jwt_auth_rate_limit_bypass' )
			->once()
			->with( false, 'login:1.2.3.4', 5, 900 )
			->andReturn( true );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$limiter = new RateLimiter();
		$this->assertTrue( $limiter->check( 'login:1.2.3.4', 5, 900 ) );
	}
}
