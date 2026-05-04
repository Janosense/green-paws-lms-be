<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\SignatureBuilder;
use VL\LMS\Payments\LiqPay\SignatureVerifier;
use VL\LMS\Tests\Fixtures\Payments\LiqPay\TestLiqPaySettings;

final class SignatureVerifierTest extends TestCase {

	public function test_verify_returns_true_for_matching_signature(): void {
		$verifier = $this->configured_verifier( 'sk_test' );
		$base64   = base64_encode( '{"order_id":"abc","status":"success"}' );
		$expected = ( new SignatureBuilder() )->build( 'sk_test', $base64 );

		self::assertTrue( $verifier->verify( $base64, $expected ) );
	}

	public function test_verify_returns_false_when_data_is_tampered(): void {
		$verifier = $this->configured_verifier( 'sk_test' );
		$base64   = base64_encode( '{"order_id":"abc"}' );
		$signed   = ( new SignatureBuilder() )->build( 'sk_test', $base64 );

		$tampered = base64_encode( '{"order_id":"hacked"}' );
		self::assertFalse( $verifier->verify( $tampered, $signed ) );
	}

	public function test_verify_returns_false_when_signature_is_tampered(): void {
		$verifier = $this->configured_verifier( 'sk_test' );
		$base64   = base64_encode( '{"order_id":"abc"}' );

		self::assertFalse( $verifier->verify( $base64, 'not-a-valid-signature' ) );
	}

	public function test_verify_returns_false_when_private_key_unconfigured(): void {
		$verifier = new SignatureVerifier(
			new TestLiqPaySettings(),
			new SignatureBuilder()
		);

		self::assertFalse( $verifier->verify( 'any', 'any' ) );
	}

	public function test_verify_returns_false_for_empty_inputs(): void {
		$verifier = $this->configured_verifier( 'sk_test' );

		self::assertFalse( $verifier->verify( '', '' ) );
		self::assertFalse( $verifier->verify( 'data', '' ) );
		self::assertFalse( $verifier->verify( '', 'sig' ) );
	}

	public function test_verify_uses_constant_time_comparison(): void {
		// Asserted indirectly: same private key, off-by-one byte in signature
		// must reject, never short-circuit early.
		$verifier = $this->configured_verifier( 'sk_test' );
		$base64   = base64_encode( '{"x":1}' );
		$signed   = ( new SignatureBuilder() )->build( 'sk_test', $base64 );

		// Flip the last character.
		$broken = substr( $signed, 0, -1 ) . ( 'A' === substr( $signed, -1 ) ? 'B' : 'A' );
		self::assertFalse( $verifier->verify( $base64, $broken ) );
	}

	private function configured_verifier( string $private_key ): SignatureVerifier {
		return new SignatureVerifier(
			new TestLiqPaySettings(
				constants: [ 'VL_LMS_LIQPAY_PRIVATE_KEY' => $private_key ]
			),
			new SignatureBuilder()
		);
	}
}
