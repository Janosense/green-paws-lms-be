<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Exception\ZoomAuthException;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

/**
 * Caches a Zoom S2S OAuth access token in a WP transient and refreshes
 * it on miss / expiry. The 60-second skew on
 * {@see AccessToken::is_expired()} keeps us from racing the wire.
 *
 * The {@see HttpZoomClient} 401-retry path calls {@see self::invalidate()}
 * to bust the transient before its single retry; that's the only
 * external code that needs to invalidate the cached token.
 *
 * Throws {@see ZoomAuthException} on missing credentials and on every
 * underlying HTTP failure — there is no retry on token fetch (token is
 * already a hot path; if Zoom is down, fail fast).
 *
 * @author Tymofii Synianskyi
 */
class TokenProvider {

	private const string TRANSIENT_KEY = 'vl_lms_zoom_access_token';

	private const int CACHE_SKEW_SECONDS = 60;

	private ZoomSettingsProvider $settings;

	private TokenHttpClient $http;

	/** @var callable():\DateTimeImmutable */
	private $clock;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct(
		ZoomSettingsProvider $settings,
		TokenHttpClient $http,
		?callable $clock = null
	) {
		$this->settings = $settings;
		$this->http     = $http;
		$this->clock    = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns a valid access token. Reads from the transient cache when
	 * possible; on miss or expiry, requests a fresh token, writes the
	 * transient with TTL = `expires_in - 60s`, and returns it.
	 *
	 * @throws ZoomAuthException When credentials are missing or the token endpoint fails.
	 */
	public function get_token(): string {
		$cached = $this->read_transient();
		if ( null !== $cached && ! $cached->is_expired( ( $this->clock )(), self::CACHE_SKEW_SECONDS ) ) {
			return $cached->token;
		}

		$creds = $this->settings->get_credentials();
		if ( ! $creds->is_configured() ) {
			throw new ZoomAuthException( 'Zoom integration is not configured: missing credentials.' );
		}

		$response = $this->http->request_token( $creds );

		$token       = isset( $response['access_token'] ) && is_string( $response['access_token'] ) ? $response['access_token'] : '';
		$expires_in  = isset( $response['expires_in'] ) && is_numeric( $response['expires_in'] ) ? (int) $response['expires_in'] : 0;

		if ( '' === $token || $expires_in <= 0 ) {
			throw new ZoomAuthException( 'Zoom token response missing access_token or expires_in.' );
		}

		$now        = ( $this->clock )();
		$expires_at = $now->modify( '+' . $expires_in . ' seconds' );

		$this->write_transient( $token, $expires_at, $expires_in );

		return $token;
	}

	public function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	private function read_transient(): ?AccessToken {
		$raw = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( ! isset( $raw['token'], $raw['expires_at_iso'] ) ) {
			return null;
		}

		try {
			$expires_at = new \DateTimeImmutable( (string) $raw['expires_at_iso'], new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}

		return new AccessToken( (string) $raw['token'], $expires_at );
	}

	private function write_transient( string $token, \DateTimeImmutable $expires_at, int $expires_in ): void {
		$ttl = max( 1, $expires_in - self::CACHE_SKEW_SECONDS );

		set_transient(
			self::TRANSIENT_KEY,
			[
				'token'          => $token,
				'expires_at_iso' => $expires_at->format( 'Y-m-d\\TH:i:s\\Z' ),
			],
			$ttl
		);
	}
}
