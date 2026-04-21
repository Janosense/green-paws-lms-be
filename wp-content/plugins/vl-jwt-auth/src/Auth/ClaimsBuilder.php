<?php

declare(strict_types=1);

namespace VLJwtAuth\Auth;

use VLJwtAuth\Support\Settings;
use WP_User;

/**
 * Assembles the claim set for a JWT and applies the public filter
 * so other plugins can inject custom claims.
 *
 * Responsibility stays narrow: build claims. No signing, no persistence.
 */
final class ClaimsBuilder {

	public function __construct(
		private Settings $settings
	) {
	}

	/**
	 * @param 'access'|'refresh' $type
	 * @return array<string, mixed>
	 */
	public function build( WP_User $user, string $type ): array {
		$now = time();
		$ttl = 'refresh' === $type ? $this->settings->refresh_ttl() : $this->settings->access_ttl();

		$claims = [
			'iss'     => get_site_url(),
			'iat'     => $now,
			'nbf'     => $now,
			'exp'     => $now + $ttl,
			'jti'     => wp_generate_uuid4(),
			'user_id' => (int) $user->ID,
			'roles'   => array_values( (array) $user->roles ),
			'type'    => $type,
		];

		/**
		 * Filter the JWT claim set before signing.
		 *
		 * @param array<string, mixed> $claims  The claim set about to be signed.
		 * @param int                  $user_id WordPress user ID the token is issued for.
		 * @param string               $type    'access' or 'refresh'.
		 */
		$claims = apply_filters( 'vl_jwt_auth_token_claims', $claims, (int) $user->ID, $type );

		return is_array( $claims ) ? $claims : [];
	}
}
