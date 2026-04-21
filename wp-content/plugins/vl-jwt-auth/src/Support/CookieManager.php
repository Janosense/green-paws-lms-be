<?php

declare(strict_types=1);

namespace VLJwtAuth\Support;

/**
 * Manages the refresh-token cookie.
 *
 * Cookie is always:
 * - HttpOnly
 * - Secure (forced when SameSite=None to satisfy browser requirements)
 * - SameSite per plugin option (default None for fully cross-origin SPA)
 * - Path scoped to the plugin's REST namespace, so it is never sent on
 *   ordinary frontend or wp-admin requests.
 *
 * The Domain attribute comes from plugin settings; leaving it blank uses
 * the current host (strict-subdomain-only scope).
 */
final class CookieManager {

	public const COOKIE_NAME = 'vl_refresh_token';

	public function __construct(
		private Settings $settings
	) {
	}

	public function set( string $token, int $expires_at ): void {
		setcookie( self::COOKIE_NAME, $token, $this->options( $expires_at ) );
	}

	public function clear(): void {
		setcookie( self::COOKIE_NAME, '', $this->options( time() - HOUR_IN_SECONDS ) );
	}

	public function read(): ?string {
		$raw = $_COOKIE[ self::COOKIE_NAME ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * @return array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
	 */
	private function options( int $expires ): array {
		$samesite = $this->settings->cookie_samesite();

		return [
			'expires'  => $expires,
			'path'     => $this->cookie_path(),
			'domain'   => $this->settings->cookie_domain(),
			'secure'   => is_ssl() || 'None' === $samesite,
			'httponly' => true,
			'samesite' => $samesite,
		];
	}

	/**
	 * Derive the URL path the cookie is scoped to — `/wp-json/vl-auth/v1/`
	 * under a root install, prefixed with the subdirectory otherwise.
	 */
	private function cookie_path(): string {
		$path = wp_parse_url( rest_url( 'vl-auth/v1/' ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/wp-json/vl-auth/v1/';
	}
}
