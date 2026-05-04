<?php

declare(strict_types=1);

namespace VLJwtAuth\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VLJwtAuth\Support\Settings;

final class SettingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_defaults_when_option_is_unset(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$settings = new Settings();

		$defaults = Settings::defaults();
		$this->assertSame( $defaults['access_token_ttl'], $settings->access_ttl() );
		$this->assertSame( $defaults['refresh_token_ttl'], $settings->refresh_ttl() );
		$this->assertSame( $defaults['cookie_domain'], $settings->cookie_domain() );
		$this->assertSame( $defaults['cookie_samesite'], $settings->cookie_samesite() );
		$this->assertSame( $defaults['rate_limit_login'], $settings->rate_limit_login() );
		$this->assertSame( $defaults['rate_limit_window'], $settings->rate_limit_window() );
		$this->assertSame( [], $settings->allowed_origins() );
	}

	public function test_returns_defaults_when_option_is_not_an_array(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$settings = new Settings();

		$this->assertSame( Settings::defaults()['access_token_ttl'], $settings->access_ttl() );
	}

	public function test_stored_options_override_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'access_token_ttl'  => 600,
				'refresh_token_ttl' => 86400,
				'cookie_samesite'   => 'Lax',
				'rate_limit_login'  => 10,
				'rate_limit_window' => 1200,
				'allowed_origins'   => [ 'https://app.example.test/', 'https://app.example.test', '' ],
			]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$settings = new Settings();

		$this->assertSame( 600, $settings->access_ttl() );
		$this->assertSame( 86400, $settings->refresh_ttl() );
		$this->assertSame( 'Lax', $settings->cookie_samesite() );
		$this->assertSame( 10, $settings->rate_limit_login() );
		$this->assertSame( 1200, $settings->rate_limit_window() );
		$this->assertSame( [ 'https://app.example.test' ], $settings->allowed_origins() );
	}

	public function test_filters_can_override_token_ttls(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		Filters\expectApplied( 'vl_jwt_auth_access_ttl' )->once()->andReturn( 999 );
		Filters\expectApplied( 'vl_jwt_auth_refresh_ttl' )->once()->andReturn( 7200 );

		$settings = new Settings();

		$this->assertSame( 999, $settings->access_ttl() );
		$this->assertSame( 7200, $settings->refresh_ttl() );
	}

	public function test_invalid_samesite_falls_back_to_none(): void {
		Functions\when( 'get_option' )->justReturn( [ 'cookie_samesite' => 'Garbage' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$settings = new Settings();

		$this->assertSame( 'None', $settings->cookie_samesite() );
	}
}
