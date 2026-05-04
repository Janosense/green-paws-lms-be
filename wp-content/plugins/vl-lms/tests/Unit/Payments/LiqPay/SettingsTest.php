<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Tests\Fixtures\Payments\LiqPay\TestLiqPaySettings;

final class SettingsTest extends TestCase {

	public function test_returns_empty_credentials_when_nothing_configured(): void {
		$settings = new TestLiqPaySettings();

		self::assertSame( '', $settings->public_key() );
		self::assertSame( '', $settings->private_key() );
		self::assertFalse( $settings->is_configured() );
	}

	public function test_resolves_credentials_from_constants(): void {
		$settings = new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY'  => 'pk_const',
				'VL_LMS_LIQPAY_PRIVATE_KEY' => 'sk_const',
			]
		);

		self::assertSame( 'pk_const', $settings->public_key() );
		self::assertSame( 'sk_const', $settings->private_key() );
		self::assertTrue( $settings->is_configured() );
	}

	public function test_resolves_credentials_from_options_when_constants_absent(): void {
		$settings = new TestLiqPaySettings(
			options: [
				'vl_lms_liqpay_public_key'  => 'pk_option',
				'vl_lms_liqpay_private_key' => 'sk_option',
			]
		);

		self::assertSame( 'pk_option', $settings->public_key() );
		self::assertSame( 'sk_option', $settings->private_key() );
		self::assertTrue( $settings->is_configured() );
	}

	public function test_constants_beat_options_for_credentials(): void {
		$settings = new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY' => 'pk_const',
			],
			options: [
				'vl_lms_liqpay_public_key'  => 'pk_option',
				'vl_lms_liqpay_private_key' => 'sk_option',
			]
		);

		self::assertSame( 'pk_const', $settings->public_key() );
		self::assertSame( 'sk_option', $settings->private_key() );
		self::assertTrue( $settings->is_configured() );
	}

	public function test_empty_constant_falls_through_to_option(): void {
		$settings = new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY' => '',
			],
			options: [
				'vl_lms_liqpay_public_key' => 'pk_option',
			]
		);

		self::assertSame( 'pk_option', $settings->public_key() );
	}

	public function test_is_configured_truth_table(): void {
		$pk_only = new TestLiqPaySettings( constants: [ 'VL_LMS_LIQPAY_PUBLIC_KEY' => 'x' ] );
		$sk_only = new TestLiqPaySettings( constants: [ 'VL_LMS_LIQPAY_PRIVATE_KEY' => 'y' ] );
		$both    = new TestLiqPaySettings(
			constants: [
				'VL_LMS_LIQPAY_PUBLIC_KEY'  => 'x',
				'VL_LMS_LIQPAY_PRIVATE_KEY' => 'y',
			]
		);
		$neither = new TestLiqPaySettings();

		self::assertFalse( $pk_only->is_configured() );
		self::assertFalse( $sk_only->is_configured() );
		self::assertTrue( $both->is_configured() );
		self::assertFalse( $neither->is_configured() );
	}

	public function test_is_sandbox_constant_beats_option(): void {
		$settings = new TestLiqPaySettings(
			constants: [ 'VL_LMS_LIQPAY_SANDBOX' => false ],
			options:   [ 'vl_lms_liqpay_sandbox' => '1' ],
			environment: 'local'
		);

		self::assertFalse( $settings->is_sandbox() );
	}

	public function test_is_sandbox_option_beats_environment(): void {
		$settings = new TestLiqPaySettings(
			options: [ 'vl_lms_liqpay_sandbox' => '0' ],
			environment: 'local'
		);

		self::assertFalse( $settings->is_sandbox() );
	}

	public function test_is_sandbox_falls_back_to_local_environment(): void {
		$settings = new TestLiqPaySettings( environment: 'local' );

		self::assertTrue( $settings->is_sandbox() );
	}

	public function test_is_sandbox_falls_back_to_staging_environment(): void {
		$settings = new TestLiqPaySettings( environment: 'staging' );

		self::assertTrue( $settings->is_sandbox() );
	}

	public function test_is_sandbox_falls_back_to_production_environment(): void {
		$settings = new TestLiqPaySettings( environment: 'production' );

		self::assertFalse( $settings->is_sandbox() );
	}

	public function test_is_sandbox_constant_true_overrides_production_environment(): void {
		$settings = new TestLiqPaySettings(
			constants: [ 'VL_LMS_LIQPAY_SANDBOX' => true ],
			environment: 'production'
		);

		self::assertTrue( $settings->is_sandbox() );
	}

	public function test_is_sandbox_option_one_string_means_true(): void {
		$settings = new TestLiqPaySettings(
			options: [ 'vl_lms_liqpay_sandbox' => '1' ],
			environment: 'production'
		);

		self::assertTrue( $settings->is_sandbox() );
	}
}
