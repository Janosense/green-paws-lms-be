<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\Settings as LiqPaySettings;

final class LiqPaySettingsSectionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&LiqPaySettings */
	private $settings;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'home_url' )->alias(
			static fn ( string $p = '' ): string => 'http://test.local' . $p
		);
		Functions\when( 'get_option' )->justReturn( '' );

		$this->settings = Mockery::mock( LiqPaySettings::class );
		$this->settings->shouldReceive( 'is_configured' )->andReturn( false )->byDefault();
		$this->settings->shouldReceive( 'is_sandbox' )->andReturn( true )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_shows_constant_lock_when_defined(): void {
		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [ 'VL_LMS_LIQPAY_PRIVATE_KEY' ];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Визначено константою', $html );
		self::assertStringNotContainsString( 'name="vl_lms_liqpay_private_key"', $html );
	}

	public function test_render_shows_password_input_for_private_key(): void {
		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'type="password"', $html );
		self::assertStringContainsString( 'name="vl_lms_liqpay_private_key"', $html );
	}

	public function test_render_shows_sandbox_checkbox(): void {
		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringContainsString( 'name="vl_lms_liqpay_sandbox"', $html );
	}

	public function test_render_status_shows_callback_url(): void {
		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '/wp-json/vl/v1/payments/liqpay/callback', $html );
	}

	public function test_render_status_indicates_active_when_configured(): void {
		$this->settings = Mockery::mock( LiqPaySettings::class );
		$this->settings->shouldReceive( 'is_configured' )->andReturn( true );
		$this->settings->shouldReceive( 'is_sandbox' )->andReturn( true );

		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Підключення активне', $html );
	}

	public function test_render_status_indicates_missing_when_unconfigured(): void {
		$section                    = new TestableLiqPaySettingsSection( $this->settings );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Налаштуйте Public Key і Private Key', $html );
	}
}
