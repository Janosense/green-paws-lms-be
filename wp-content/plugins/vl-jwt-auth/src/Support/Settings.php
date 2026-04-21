<?php

declare(strict_types=1);

namespace VLJwtAuth\Support;

/**
 * Typed accessor for the plugin's stored options.
 *
 * Reads the `vl_jwt_auth_settings` option, merges it over hard defaults,
 * and applies public filters so other plugins can override TTLs at runtime.
 *
 * @phpstan-type SettingsShape array{
 *     access_token_ttl: int,
 *     refresh_token_ttl: int,
 *     cookie_domain: string,
 *     cookie_samesite: string,
 *     rate_limit_login: int,
 *     rate_limit_window: int,
 *     allowed_origins: list<string>
 * }
 */
final class Settings {

	public const OPTION_KEY = 'vl_jwt_auth_settings';

	/** @var SettingsShape */
	private array $values;

	public function __construct() {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		/** @var SettingsShape $values */
		$values       = array_merge( self::defaults(), $stored );
		$this->values = $values;
	}

	public function access_ttl(): int {
		/** @var int $ttl */
		$ttl = apply_filters( 'vl_jwt_auth_access_ttl', (int) $this->values['access_token_ttl'] );
		return max( 60, (int) $ttl );
	}

	public function refresh_ttl(): int {
		/** @var int $ttl */
		$ttl = apply_filters( 'vl_jwt_auth_refresh_ttl', (int) $this->values['refresh_token_ttl'] );
		return max( 60, (int) $ttl );
	}

	public function cookie_domain(): string {
		return (string) $this->values['cookie_domain'];
	}

	public function cookie_samesite(): string {
		$value = (string) $this->values['cookie_samesite'];
		return in_array( $value, [ 'None', 'Lax', 'Strict' ], true ) ? $value : 'None';
	}

	public function rate_limit_login(): int {
		return max( 1, (int) $this->values['rate_limit_login'] );
	}

	public function rate_limit_window(): int {
		return max( 60, (int) $this->values['rate_limit_window'] );
	}

	/**
	 * @return list<string>
	 */
	public function allowed_origins(): array {
		$raw = $this->values['allowed_origins'];
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$origins = [];
		foreach ( $raw as $origin ) {
			if ( is_string( $origin ) && '' !== $origin ) {
				$origins[] = rtrim( $origin, '/' );
			}
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * @return SettingsShape
	 */
	public static function defaults(): array {
		return [
			'access_token_ttl'  => 1800,
			'refresh_token_ttl' => 1209600,
			'cookie_domain'     => '',
			'cookie_samesite'   => 'None',
			'rate_limit_login'  => 5,
			'rate_limit_window' => 900,
			'allowed_origins'   => [],
		];
	}
}
