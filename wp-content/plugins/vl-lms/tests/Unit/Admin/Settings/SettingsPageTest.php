<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Settings\LiqPaySettingsSection;
use VL\LMS\Admin\Settings\ZoomSettingsSection;
use VL\LMS\Payments\LiqPay\Settings as LiqPaySettings;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

final class SettingsPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'http://test.local/wp-admin/' . ltrim( $p, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build_page(): TestableSettingsPage {
		return new TestableSettingsPage(
			new ZoomSettingsSection( Mockery::mock( ZoomSettingsProvider::class ) ),
			new LiqPaySettingsSection( Mockery::mock( LiqPaySettings::class ) )
		);
	}

	public function test_save_writes_option_when_constant_not_defined(): void {
		$writes = [];
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$writes ): bool {
				$writes[ $key ] = $value;
				return true;
			}
		);

		$_POST = [
			'_vl_lms_settings_nonce'     => 'nonce',
			'vl_lms_zoom_account_id'     => 'abc123',
			'vl_lms_zoom_client_id'      => 'cli',
			'vl_lms_zoom_client_secret'  => 's3cret',
			'vl_lms_zoom_webhook_secret' => 'whk',
			'vl_lms_liqpay_public_key'   => 'sandbox_pub',
			'vl_lms_liqpay_private_key'  => 'sandbox_priv',
			'vl_lms_liqpay_sandbox'      => '1',
		];

		$page                    = $this->build_page();
		$page->defined_constants = [];

		$page->handle_save();

		self::assertSame( 'abc123', $writes['vl_lms_zoom_account_id'] );
		self::assertSame( 'cli', $writes['vl_lms_zoom_client_id'] );
		self::assertSame( 's3cret', $writes['vl_lms_zoom_client_secret'] );
		self::assertSame( 'whk', $writes['vl_lms_zoom_webhook_secret'] );
		self::assertSame( 'sandbox_pub', $writes['vl_lms_liqpay_public_key'] );
		self::assertSame( 'sandbox_priv', $writes['vl_lms_liqpay_private_key'] );
		self::assertSame( '1', $writes['vl_lms_liqpay_sandbox'] );
		self::assertTrue( $page->redirected );

		$_POST = [];
	}

	public function test_save_skips_field_when_constant_is_defined(): void {
		$writes = [];
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$writes ): bool {
				$writes[ $key ] = $value;
				return true;
			}
		);

		$_POST = [
			'_vl_lms_settings_nonce'     => 'nonce',
			'vl_lms_zoom_account_id'     => 'should-not-be-written',
			'vl_lms_zoom_client_id'      => 'cli',
			'vl_lms_zoom_client_secret'  => 's3cret',
			'vl_lms_zoom_webhook_secret' => 'whk',
			'vl_lms_liqpay_public_key'   => 'should-not-be-written',
			'vl_lms_liqpay_private_key'  => 'priv',
		];

		$page                    = $this->build_page();
		$page->defined_constants = [ 'VL_ZOOM_ACCOUNT_ID', 'VL_LMS_LIQPAY_PUBLIC_KEY' ];

		$page->handle_save();

		self::assertArrayNotHasKey( 'vl_lms_zoom_account_id', $writes );
		self::assertArrayNotHasKey( 'vl_lms_liqpay_public_key', $writes );
		self::assertSame( 'cli', $writes['vl_lms_zoom_client_id'] );
		self::assertSame( 'priv', $writes['vl_lms_liqpay_private_key'] );

		$_POST = [];
	}

	public function test_save_persists_unchecked_sandbox_as_empty_string(): void {
		$writes = [];
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$writes ): bool {
				$writes[ $key ] = $value;
				return true;
			}
		);

		$_POST = [
			'_vl_lms_settings_nonce'    => 'nonce',
			'vl_lms_liqpay_public_key'  => 'pub',
			'vl_lms_liqpay_private_key' => 'priv',
			// `vl_lms_liqpay_sandbox` deliberately absent — unchecked checkbox.
		];

		$page                    = $this->build_page();
		$page->defined_constants = [];

		$page->handle_save();

		self::assertSame( '', $writes['vl_lms_liqpay_sandbox'] );

		$_POST = [];
	}
}
