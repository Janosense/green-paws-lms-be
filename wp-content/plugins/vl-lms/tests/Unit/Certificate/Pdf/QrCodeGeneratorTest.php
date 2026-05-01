<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate\Pdf;

use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\Pdf\QrCodeGenerator;

final class QrCodeGeneratorTest extends TestCase {

	public function test_returns_data_uri_with_png_payload(): void {
		$gen = new QrCodeGenerator();
		$out = $gen->generate_for_url( 'https://example.test/certificates/abc-uuid' );

		self::assertNotSame( '', $out );
		self::assertStringStartsWith( 'data:image/png;base64,', $out );

		// Decode the base64 payload and verify it begins with the PNG
		// magic header (0x89 0x50 0x4E 0x47).
		$payload = substr( $out, strlen( 'data:image/png;base64,' ) );
		$bytes   = base64_decode( $payload, true );
		self::assertIsString( $bytes );
		self::assertStringStartsWith( "\x89PNG\r\n\x1a\n", (string) $bytes );
	}

	public function test_distinct_urls_yield_distinct_qr_codes(): void {
		$gen = new QrCodeGenerator();
		$a   = $gen->generate_for_url( 'https://example.test/a' );
		$b   = $gen->generate_for_url( 'https://example.test/b' );

		self::assertNotSame( $a, $b );
	}
}
