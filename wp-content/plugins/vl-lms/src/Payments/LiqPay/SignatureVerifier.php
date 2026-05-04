<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Verifies the inbound LiqPay callback `(data, signature)` pair.
 *
 * Phase 8.2 — peer to {@see SignatureBuilder}, which signs outbound
 * checkout payloads. Verification recomputes the v3 signature locally
 * and compares with `hash_equals` so a timing side-channel cannot leak
 * one byte at a time.
 *
 * Returns `false` (never throws) for every failure mode — including the
 * "private key unconfigured" case — so the upstream `CallbackParser` and
 * `CallbackHandler` have a single uniform "failed verification" branch
 * to handle.
 *
 * @author Tymofii Synianskyi
 */
class SignatureVerifier {

	public function __construct(
		private readonly Settings $settings,
		private readonly SignatureBuilder $signature_builder
	) {
	}

	public function verify( string $base64_data, string $signature ): bool {
		$private_key = $this->settings->private_key();
		if ( '' === $private_key ) {
			return false;
		}
		if ( '' === $base64_data || '' === $signature ) {
			return false;
		}

		$expected = $this->signature_builder->build( $private_key, $base64_data );
		return hash_equals( $expected, $signature );
	}
}
