<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Webhook\UrlValidationResponder;

final class UrlValidationResponderTest extends TestCase {

	public function test_response_matches_hmac_recipe(): void {
		$plain    = 'qgg8vlvZRS6UYdXTYgbloA';
		$secret   = 'verysecret';
		$expected = hash_hmac( 'sha256', $plain, $secret );

		$response = ( new UrlValidationResponder() )->respond( $plain, $secret );

		self::assertSame( $plain, $response['plainToken'] );
		self::assertSame( $expected, $response['encryptedToken'] );
	}

	public function test_different_secrets_produce_different_tokens(): void {
		$plain = 'token';
		$one   = ( new UrlValidationResponder() )->respond( $plain, 'a' );
		$two   = ( new UrlValidationResponder() )->respond( $plain, 'b' );

		self::assertNotSame( $one['encryptedToken'], $two['encryptedToken'] );
	}
}
