<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Settings;

use PHPUnit\Framework\TestCase;
use VL\LMS\Tests\Fixtures\Zoom\TestZoomSettingsProvider;

final class ZoomSettingsProviderTest extends TestCase {

	public function test_returns_empty_credentials_when_nothing_configured(): void {
		$provider = new TestZoomSettingsProvider();

		$creds = $provider->get_credentials();

		self::assertSame( '', $creds->account_id );
		self::assertSame( '', $creds->client_id );
		self::assertSame( '', $creds->client_secret );
		self::assertSame( '', $creds->webhook_secret );
		self::assertFalse( $creds->is_configured() );
	}

	public function test_resolves_all_from_constants_when_present(): void {
		$provider = new TestZoomSettingsProvider(
			constants: [
				'VL_ZOOM_ACCOUNT_ID'     => 'account-from-const',
				'VL_ZOOM_CLIENT_ID'      => 'client-id-const',
				'VL_ZOOM_CLIENT_SECRET'  => 'client-secret-const',
				'VL_ZOOM_WEBHOOK_SECRET' => 'webhook-const',
			]
		);

		$creds = $provider->get_credentials();

		self::assertSame( 'account-from-const', $creds->account_id );
		self::assertSame( 'client-id-const', $creds->client_id );
		self::assertSame( 'client-secret-const', $creds->client_secret );
		self::assertSame( 'webhook-const', $creds->webhook_secret );
		self::assertTrue( $creds->is_configured() );
	}

	public function test_resolves_all_from_options_when_constants_absent(): void {
		$provider = new TestZoomSettingsProvider(
			options: [
				'vl_lms_zoom_account_id'     => 'account-from-option',
				'vl_lms_zoom_client_id'      => 'client-id-option',
				'vl_lms_zoom_client_secret'  => 'client-secret-option',
				'vl_lms_zoom_webhook_secret' => 'webhook-option',
			]
		);

		$creds = $provider->get_credentials();

		self::assertSame( 'account-from-option', $creds->account_id );
		self::assertSame( 'client-id-option', $creds->client_id );
		self::assertTrue( $creds->is_configured() );
	}

	public function test_constants_take_precedence_over_options(): void {
		$provider = new TestZoomSettingsProvider(
			constants: [
				'VL_ZOOM_ACCOUNT_ID' => 'const-wins',
			],
			options: [
				'vl_lms_zoom_account_id' => 'option-loses',
			]
		);

		$creds = $provider->get_credentials();

		self::assertSame( 'const-wins', $creds->account_id );
	}

	public function test_partial_constants_fall_through_to_options(): void {
		$provider = new TestZoomSettingsProvider(
			constants: [
				'VL_ZOOM_ACCOUNT_ID' => 'const-account',
				'VL_ZOOM_CLIENT_ID'  => 'const-client',
			],
			options: [
				'vl_lms_zoom_client_secret'  => 'opt-secret',
				'vl_lms_zoom_webhook_secret' => 'opt-webhook',
			]
		);

		$creds = $provider->get_credentials();

		self::assertSame( 'const-account', $creds->account_id );
		self::assertSame( 'const-client', $creds->client_id );
		self::assertSame( 'opt-secret', $creds->client_secret );
		self::assertSame( 'opt-webhook', $creds->webhook_secret );
		self::assertTrue( $creds->is_configured() );
	}

	public function test_empty_string_constant_falls_through_to_option(): void {
		$provider = new TestZoomSettingsProvider(
			constants: [
				'VL_ZOOM_ACCOUNT_ID' => '',
			],
			options: [
				'vl_lms_zoom_account_id' => 'opt-account',
			]
		);

		$creds = $provider->get_credentials();

		self::assertSame( 'opt-account', $creds->account_id );
	}
}
