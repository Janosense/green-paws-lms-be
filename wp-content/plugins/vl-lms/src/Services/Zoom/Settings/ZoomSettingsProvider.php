<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Settings;

/**
 * Resolves the four Zoom credentials from `wp-config.php` constants with
 * a WP-options fallback for a future admin UI (Phase 9 scope).
 *
 * Resolution per field, in order:
 *   1. PHP constant — `VL_ZOOM_ACCOUNT_ID`, `VL_ZOOM_CLIENT_ID`,
 *      `VL_ZOOM_CLIENT_SECRET`, `VL_ZOOM_WEBHOOK_SECRET`.
 *   2. WP option — `vl_lms_zoom_account_id`, `vl_lms_zoom_client_id`,
 *      `vl_lms_zoom_client_secret`, `vl_lms_zoom_webhook_secret`.
 *   3. Empty string.
 *
 * Missing credentials never throw — they yield an empty
 * {@see ZoomCredentials} VO whose `is_configured()` returns `false`. The
 * {@see \VL\LMS\Services\Zoom\TokenProvider} is the call-site that
 * surfaces "Zoom not configured" as a {@see \VL\LMS\Services\Zoom\Exception\ZoomAuthException}.
 *
 * @author Tymofii Synianskyi
 */
class ZoomSettingsProvider {

	private const string CONST_ACCOUNT_ID     = 'VL_ZOOM_ACCOUNT_ID';
	private const string CONST_CLIENT_ID      = 'VL_ZOOM_CLIENT_ID';
	private const string CONST_CLIENT_SECRET  = 'VL_ZOOM_CLIENT_SECRET';
	private const string CONST_WEBHOOK_SECRET = 'VL_ZOOM_WEBHOOK_SECRET';

	private const string OPTION_ACCOUNT_ID     = 'vl_lms_zoom_account_id';
	private const string OPTION_CLIENT_ID      = 'vl_lms_zoom_client_id';
	private const string OPTION_CLIENT_SECRET  = 'vl_lms_zoom_client_secret';
	private const string OPTION_WEBHOOK_SECRET = 'vl_lms_zoom_webhook_secret';

	public function get_credentials(): ZoomCredentials {
		return new ZoomCredentials(
			$this->resolve( self::CONST_ACCOUNT_ID, self::OPTION_ACCOUNT_ID ),
			$this->resolve( self::CONST_CLIENT_ID, self::OPTION_CLIENT_ID ),
			$this->resolve( self::CONST_CLIENT_SECRET, self::OPTION_CLIENT_SECRET ),
			$this->resolve( self::CONST_WEBHOOK_SECRET, self::OPTION_WEBHOOK_SECRET )
		);
	}

	private function resolve( string $constant_name, string $option_name ): string {
		$constant_value = $this->read_constant( $constant_name );
		if ( null !== $constant_value && '' !== $constant_value ) {
			return $constant_value;
		}
		$option_value = $this->read_option( $option_name );
		if ( null !== $option_value && '' !== $option_value ) {
			return $option_value;
		}
		return '';
	}

	/**
	 * Indirected so unit tests can subclass and override without poking
	 * at the live PHP constant table.
	 */
	protected function read_constant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );
		if ( ! is_string( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Indirected so unit tests can subclass and override without
	 * round-tripping through `get_option`.
	 */
	protected function read_option( string $name ): ?string {
		$value = get_option( $name, null );
		if ( null === $value || false === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		return $value;
	}
}
