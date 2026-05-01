<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Webhook\WebhookSignatureVerifier;
use VL\LMS\Tests\Fixtures\Zoom\Sync\StubZoomSettingsProvider;

final class WebhookSignatureVerifierTest extends TestCase {

	private const string SECRET = 'super-secret';

	private function settings( string $secret = self::SECRET ): StubZoomSettingsProvider {
		return new StubZoomSettingsProvider(
			new ZoomCredentials( 'a', 'b', 'c', $secret )
		);
	}

	private function clock( int $now ): \Closure {
		return static fn (): int => $now;
	}

	private function expected( string $body, string $timestamp, string $secret = self::SECRET ): string {
		return 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, $secret );
	}

	public function test_happy_path_verifies(): void {
		$body      = '{"event":"meeting.started"}';
		$timestamp = '1714540800';
		$signature = $this->expected( $body, $timestamp );

		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( $body, $signature, $timestamp );

		self::assertTrue( $result->valid );
		self::assertSame( 'ok', $result->reason );
	}

	public function test_missing_signature_header_fails(): void {
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( 'body', '', '1714540800' );

		self::assertFalse( $result->valid );
		self::assertSame( 'missing_headers', $result->reason );
	}

	public function test_missing_timestamp_header_fails(): void {
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( 'body', 'v0=abc', '' );

		self::assertFalse( $result->valid );
		self::assertSame( 'missing_headers', $result->reason );
	}

	public function test_non_numeric_timestamp_fails(): void {
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( 'body', 'v0=abc', 'not-a-number' );

		self::assertFalse( $result->valid );
		self::assertSame( 'missing_headers', $result->reason );
	}

	public function test_missing_secret_fails(): void {
		$verifier = new WebhookSignatureVerifier( $this->settings( '' ), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( 'body', 'v0=abc', '1714540800' );

		self::assertFalse( $result->valid );
		self::assertSame( 'missing_secret', $result->reason );
	}

	public function test_replay_window_exceeded_in_past(): void {
		$body      = 'b';
		$timestamp = '1714540000';
		$signature = $this->expected( $body, $timestamp );

		// 301s after timestamp.
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540301 ) );
		$result   = $verifier->verify( $body, $signature, $timestamp );

		self::assertFalse( $result->valid );
		self::assertSame( 'replay_window', $result->reason );
	}

	public function test_replay_window_exceeded_in_future(): void {
		$body      = 'b';
		$timestamp = '1714540800';
		$signature = $this->expected( $body, $timestamp );

		// 301s before timestamp (clock-skew).
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540499 ) );
		$result   = $verifier->verify( $body, $signature, $timestamp );

		self::assertFalse( $result->valid );
		self::assertSame( 'replay_window', $result->reason );
	}

	public function test_replay_window_exactly_300_seconds_is_accepted(): void {
		$body      = 'b';
		$timestamp = '1714540500';
		$signature = $this->expected( $body, $timestamp );

		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( $body, $signature, $timestamp );

		self::assertTrue( $result->valid );
	}

	public function test_tampered_body_mismatches(): void {
		$body      = 'original';
		$timestamp = '1714540800';
		$signature = $this->expected( $body, $timestamp );

		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( 'tampered', $signature, $timestamp );

		self::assertFalse( $result->valid );
		self::assertSame( 'mismatch', $result->reason );
	}

	public function test_wrong_secret_mismatches(): void {
		$body      = 'b';
		$timestamp = '1714540800';
		// Signature computed with one secret …
		$signature = $this->expected( $body, $timestamp, 'OTHER_SECRET' );

		// … but verifier configured with the canonical secret.
		$verifier = new WebhookSignatureVerifier( $this->settings(), $this->clock( 1714540800 ) );
		$result   = $verifier->verify( $body, $signature, $timestamp );

		self::assertFalse( $result->valid );
		self::assertSame( 'mismatch', $result->reason );
	}
}
