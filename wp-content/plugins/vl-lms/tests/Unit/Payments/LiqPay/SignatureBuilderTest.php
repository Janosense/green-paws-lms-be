<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\SignatureBuilder;

final class SignatureBuilderTest extends TestCase {

	public function test_known_input_produces_known_output(): void {
		// Canonical fixture: private_key='priv', base64_data='dGVzdC1kYXRh' (b64 'test-data').
		// signature = base64( sha1( 'priv' . 'dGVzdC1kYXRh' . 'priv', raw ) )
		$builder = new SignatureBuilder();

		$signature = $builder->build( 'priv', 'dGVzdC1kYXRh' );

		self::assertSame( 'if5+38LS1vUSJ/gGnF6mCmsyvYc=', $signature );
	}

	public function test_different_private_key_produces_different_signature(): void {
		$builder = new SignatureBuilder();

		$a = $builder->build( 'priv-a', 'dGVzdC1kYXRh' );
		$b = $builder->build( 'priv-b', 'dGVzdC1kYXRh' );

		self::assertNotSame( $a, $b );
	}

	public function test_different_data_produces_different_signature(): void {
		$builder = new SignatureBuilder();

		$a = $builder->build( 'priv', 'YWFh' );
		$b = $builder->build( 'priv', 'YmJi' );

		self::assertNotSame( $a, $b );
	}

	public function test_empty_data_does_not_throw(): void {
		$builder = new SignatureBuilder();

		$signature = $builder->build( 'priv', '' );

		self::assertNotSame( '', $signature );
	}
}
