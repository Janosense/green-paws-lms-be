<?php

declare(strict_types=1);

namespace VL\LMS\Support;

/**
 * Shared frontend-base-URL resolver used by transactional mailers and the
 * Phase 8.1 LiqPay payload builder.
 *
 * Mirrors `Auth\Mail\VerificationMailer::app_url()` semantics — the
 * `VL_LMS_APP_URL` PHP constant points at the frontend's deployed origin,
 * with a `home_url()` fallback (and a warning log) for misconfigured
 * environments.
 *
 * @author Tymofii Synianskyi
 */
class AppUrlResolver {

	public function __construct(
		private readonly Logger $logger
	) {
	}

	public function base_url(): string {
		if ( defined( 'VL_LMS_APP_URL' ) && '' !== (string) constant( 'VL_LMS_APP_URL' ) ) {
			return (string) constant( 'VL_LMS_APP_URL' );
		}
		$this->logger->warning(
			'VL_LMS_APP_URL is not defined; transactional email links will point at home_url().'
		);
		return (string) home_url();
	}

	public function path( string $path ): string {
		$base    = rtrim( $this->base_url(), '/' );
		$cleaned = '/' . ltrim( $path, '/' );
		return $base . $cleaned;
	}
}
