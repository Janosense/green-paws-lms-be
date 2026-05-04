<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Builds the LiqPay request signature per the v3 spec.
 *
 *   signature = base64( sha1( private_key || base64_data || private_key, raw ) )
 *
 * Pure local computation — no HTTP, no I/O. Phase 8.1 only signs outbound
 * checkout payloads; Phase 8.2 will reuse this on the verification side
 * (callback authenticity), at which point a `SignatureVerifier` peer
 * lands.
 *
 * @author Tymofii Synianskyi
 */
class SignatureBuilder {

	public function build( string $private_key, string $base64_data ): string {
		return base64_encode( hash( 'sha1', $private_key . $base64_data . $private_key, true ) );
	}
}
