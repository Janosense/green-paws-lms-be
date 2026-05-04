<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Resolves LiqPay merchant credentials and the sandbox toggle.
 *
 * Resolution per field:
 *   1. PHP constant — `VL_LMS_LIQPAY_PUBLIC_KEY`, `VL_LMS_LIQPAY_PRIVATE_KEY`,
 *      `VL_LMS_LIQPAY_SANDBOX`.
 *   2. WP option — `vl_lms_liqpay_public_key`, `vl_lms_liqpay_private_key`,
 *      `vl_lms_liqpay_sandbox`.
 *   3. For credentials: empty string (callers detect via {@see is_configured()}).
 *      For sandbox: derived from `wp_get_environment_type()` —
 *      sandbox is `true` for everything except `'production'`.
 *
 * Missing credentials never throw here. The {@see LiqPayClient::prepare_payment}
 * call-site surfaces "provider unconfigured" as a
 * {@see \VL\LMS\Payments\Exception\PaymentProviderUnavailableException}.
 *
 * @author Tymofii Synianskyi
 */
class Settings {

	private const string CONST_PUBLIC_KEY  = 'VL_LMS_LIQPAY_PUBLIC_KEY';
	private const string CONST_PRIVATE_KEY = 'VL_LMS_LIQPAY_PRIVATE_KEY';
	private const string CONST_SANDBOX     = 'VL_LMS_LIQPAY_SANDBOX';

	private const string OPTION_PUBLIC_KEY  = 'vl_lms_liqpay_public_key';
	private const string OPTION_PRIVATE_KEY = 'vl_lms_liqpay_private_key';
	private const string OPTION_SANDBOX     = 'vl_lms_liqpay_sandbox';

	public function public_key(): string {
		return $this->resolve_credential( self::CONST_PUBLIC_KEY, self::OPTION_PUBLIC_KEY );
	}

	public function private_key(): string {
		return $this->resolve_credential( self::CONST_PRIVATE_KEY, self::OPTION_PRIVATE_KEY );
	}

	public function is_configured(): bool {
		return '' !== $this->public_key() && '' !== $this->private_key();
	}

	public function is_sandbox(): bool {
		$constant_value = $this->read_constant( self::CONST_SANDBOX );
		if ( null !== $constant_value ) {
			return (bool) $constant_value;
		}
		$option_value = $this->read_option( self::OPTION_SANDBOX );
		if ( null !== $option_value && '' !== $option_value ) {
			return '1' === $option_value;
		}
		return 'production' !== $this->environment_type();
	}

	private function resolve_credential( string $constant_name, string $option_name ): string {
		$constant_value = $this->read_constant( $constant_name );
		if ( is_string( $constant_value ) && '' !== $constant_value ) {
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
	 * at the live PHP constant table. Returns the raw constant value
	 * (any scalar) so the sandbox-flag path can interpret booleans.
	 */
	protected function read_constant( string $name ): mixed {
		if ( ! defined( $name ) ) {
			return null;
		}
		return constant( $name );
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

	/**
	 * Indirected so unit tests can subclass and override without
	 * touching the WordPress environment-type globals.
	 */
	protected function environment_type(): string {
		return (string) wp_get_environment_type();
	}
}
