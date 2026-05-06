<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Settings\ZoomSettingsSection;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

final class ZoomSettingsSectionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&ZoomSettingsProvider */
	private $provider;

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

		$this->provider = Mockery::mock( ZoomSettingsProvider::class );
		$this->provider->shouldReceive( 'get_credentials' )
			->andReturn( new ZoomCredentials( '', '', '', '' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_shows_constant_lock_when_defined(): void {
		$section                    = new TestableZoomSettingsSection( $this->provider );
		$section->defined_constants = [ 'VL_ZOOM_CLIENT_ID' ];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Визначено константою', $html );
		self::assertStringNotContainsString( 'name="vl_lms_zoom_client_id"', $html );
	}

	public function test_render_shows_password_input_when_constant_undefined(): void {
		$section                    = new TestableZoomSettingsSection( $this->provider );
		$section->defined_constants = [];

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'type="password"', $html );
		self::assertStringContainsString( 'name="vl_lms_zoom_client_secret"', $html );
	}
}
