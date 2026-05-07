<?php

declare(strict_types=1);

namespace VL\LMS\Auth\LoginGate;

use WP_Error;
use WP_User;

/**
 * Rejects `wp_authenticate()` when the matched user has not verified
 * their email address. Administrators bypass this gate so site owners
 * are never locked out of `wp-login.php` if their verification meta is
 * missing or stale.
 *
 * Hooks the WP-core `wp_authenticate_user` filter at priority 30 — after
 * WP's built-in validators (priorities 10–20 for password match, spam
 * checks, etc.) so this runs only on a user that would otherwise succeed.
 *
 * Known limitation: `vl-jwt-auth`'s `/vl-auth/v1/token` handler collapses
 * every `WP_Error` from `wp_authenticate()` into `invalid_credentials`.
 * The distinct `vl_lms_email_not_verified` code reaches
 * `wp-login.php` and any future native caller, but NOT
 * `/vl-auth/v1/token` today. See PHASE-2-AUDIT.md §4 for the recommended
 * follow-up in `vl-jwt-auth`.
 *
 * @author Tymofii Synianskyi
 */
final class UnverifiedLoginBlocker {

	public function register_hooks(): void {
		add_filter( 'wp_authenticate_user', [ $this, 'block_unverified' ], 30, 2 );
	}

	/**
	 * `wp_authenticate_user` filter callback.
	 *
	 * @param WP_User|WP_Error $user     The resolved user, or an error from a prior filter.
	 * @param string           $password Cleartext password (unused here).
	 * @return WP_User|WP_Error
	 */
	public function block_unverified( $user, string $password ) {
		unset( $password );

		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return $user;
		}

		$verified = '1' === (string) get_user_meta( (int) $user->ID, '_vl_email_verified', true );
		if ( $verified ) {
			return $user;
		}

		return new WP_Error(
			'vl_lms_email_not_verified',
			__( 'Please verify your email address before signing in.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}
}
