<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

use Closure;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

/**
 * Validates an inbound Zoom webhook against the documented HMAC-SHA256
 * recipe and a 5-minute replay window.
 *
 * Algorithm:
 *   1. `message = "v0:{x-zm-request-timestamp}:{raw_body}"`
 *   2. `expected = "v0=" . hash_hmac('sha256', message, webhook_secret)`
 *   3. `hash_equals(expected, x-zm-signature)` (constant-time compare).
 *
 * Replay protection: if `|now - timestamp| > 300s`, fail with
 * `replay_window` regardless of signature correctness.
 *
 * Concrete (not final) so unit tests can subclass; the clock is injected
 * as a closure so tests can pin "now" without mocking time globally.
 *
 * @author Tymofii Synianskyi
 */
class WebhookSignatureVerifier {

	private const int REPLAY_WINDOW_SECONDS = 300;

	private ZoomSettingsProvider $settings;

	/** @var Closure(): int */
	private Closure $clock;

	/**
	 * @param Closure(): int $clock Returns the current unix timestamp in seconds.
	 */
	public function __construct( ZoomSettingsProvider $settings, Closure $clock ) {
		$this->settings = $settings;
		$this->clock    = $clock;
	}

	public function verify(
		string $raw_body,
		string $signature_header,
		string $timestamp_header
	): VerificationResult {
		if ( '' === $timestamp_header || '' === $signature_header ) {
			return VerificationResult::failed( 'missing_headers' );
		}

		$secret = $this->settings->get_credentials()->webhook_secret;
		if ( '' === $secret ) {
			return VerificationResult::failed( 'missing_secret' );
		}

		if ( ! ctype_digit( $timestamp_header ) ) {
			return VerificationResult::failed( 'missing_headers' );
		}
		$timestamp = (int) $timestamp_header;

		$now   = ( $this->clock )();
		$delta = abs( $now - $timestamp );
		if ( $delta > self::REPLAY_WINDOW_SECONDS ) {
			return VerificationResult::failed( 'replay_window' );
		}

		$message  = 'v0:' . $timestamp_header . ':' . $raw_body;
		$expected = 'v0=' . hash_hmac( 'sha256', $message, $secret );

		if ( ! hash_equals( $expected, $signature_header ) ) {
			return VerificationResult::failed( 'mismatch' );
		}

		return VerificationResult::ok();
	}
}
