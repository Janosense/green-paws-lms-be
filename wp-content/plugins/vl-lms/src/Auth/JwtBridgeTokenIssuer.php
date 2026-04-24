<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

use VLJwtAuth\Auth\ClaimsBuilder;
use VLJwtAuth\Auth\TokenService;
use VLJwtAuth\Repository\RefreshTokenRepository;
use VLJwtAuth\Support\CookieManager;
use VLJwtAuth\Support\Settings;
use WP_REST_Request;
use WP_User;

/**
 * {@see TokenIssuer} implementation backed by `vl-jwt-auth`'s internals.
 *
 * NOTE on coupling: `vl-jwt-auth`'s public facade
 * ({@see \VLJwtAuth\Auth\AuthFacade}) does not expose token issuance —
 * only decoding. The cleanest long-term fix is an explicit facade method
 * (see PHASE-2-AUDIT.md §4 → "Pre-login gating" and §6.1). Until that
 * lands, this class wires up the same concrete services that
 * `vl-jwt-auth`'s own REST controller uses:
 * {@see TokenService}, {@see RefreshTokenRepository}, {@see CookieManager}.
 *
 * The coupling is intentionally isolated to this one class — every
 * other consumer inside `vl-lms` depends on the {@see TokenIssuer}
 * interface instead.
 *
 * @author Tymofii Synianskyi
 */
final class JwtBridgeTokenIssuer implements TokenIssuer {

	/**
	 * @return array{access_token: string, token_type: string, expires_in: int}
	 */
	public function issue_for( WP_User $user, WP_REST_Request $request ): array {
		$settings       = new Settings();
		$claims_builder = new ClaimsBuilder( $settings );
		$token_service  = new TokenService( (string) VL_JWT_AUTH_SECRET_KEY, $claims_builder );
		$repo           = new RefreshTokenRepository();
		$cookies        = new CookieManager( $settings );

		$access  = $token_service->issue( $user, 'access' );
		$refresh = $token_service->issue( $user, 'refresh' );
		$family  = wp_generate_uuid4();

		$repo->create(
			user_id: (int) $user->ID,
			token: $refresh['token'],
			token_family: $family,
			expires_at_utc: gmdate( 'Y-m-d H:i:s', $refresh['expires_at'] ),
			device_name: self::device_name( $request ),
			ip_address: self::client_ip(),
			user_agent: self::user_agent( $request ),
		);

		$cookies->set( $refresh['token'], $refresh['expires_at'] );

		/**
		 * Mirror `vl-jwt-auth`'s own login/refresh side effect: fire the
		 * `vl_jwt_auth_user_authenticated` action so downstream listeners
		 * (audit logs etc.) see the verification-login just like a
		 * normal login. Hook name is owned by the sibling plugin.
		 */
		do_action( 'vl_jwt_auth_user_authenticated', $user, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return [
			'access_token' => (string) $access['token'],
			'token_type'   => 'Bearer',
			'expires_in'   => max( 0, (int) $access['expires_at'] - time() ),
		];
	}

	private static function user_agent( WP_REST_Request $request ): ?string {
		$ua = (string) $request->get_header( 'user_agent' );
		return '' !== $ua ? substr( $ua, 0, 500 ) : null;
	}

	private static function device_name( WP_REST_Request $request ): ?string {
		$ua = self::user_agent( $request );
		return null === $ua ? null : substr( $ua, 0, 191 );
	}

	private static function client_ip(): ?string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		/** This filter is documented in `vl-jwt-auth/src/Api/RestController.php`. */
		$ip = (string) apply_filters( 'vl_jwt_auth_client_ip', $ip ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}
}
