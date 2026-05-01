<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Settings;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;

final class ZoomCredentialsTest extends TestCase {

	public function test_is_configured_true_when_all_fields_present(): void {
		$creds = new ZoomCredentials( 'a', 'b', 'c', 'd' );
		self::assertTrue( $creds->is_configured() );
	}

	public function test_is_configured_false_when_account_id_empty(): void {
		$creds = new ZoomCredentials( '', 'b', 'c', 'd' );
		self::assertFalse( $creds->is_configured() );
	}

	public function test_is_configured_false_when_client_id_empty(): void {
		$creds = new ZoomCredentials( 'a', '', 'c', 'd' );
		self::assertFalse( $creds->is_configured() );
	}

	public function test_is_configured_false_when_client_secret_empty(): void {
		$creds = new ZoomCredentials( 'a', 'b', '', 'd' );
		self::assertFalse( $creds->is_configured() );
	}

	public function test_is_configured_false_when_webhook_secret_empty(): void {
		$creds = new ZoomCredentials( 'a', 'b', 'c', '' );
		self::assertFalse( $creds->is_configured() );
	}

	public function test_is_configured_false_when_all_empty(): void {
		$creds = new ZoomCredentials( '', '', '', '' );
		self::assertFalse( $creds->is_configured() );
	}
}
