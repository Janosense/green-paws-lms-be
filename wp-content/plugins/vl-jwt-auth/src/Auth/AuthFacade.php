<?php

declare(strict_types=1);

namespace VLJwtAuth\Auth;

use VLJwtAuth\Api\Middleware;
use VLJwtAuth\Exception\TokenException;
use VLJwtAuth\Support\Settings;
use WP_REST_Request;
use WP_User;

/**
 * Public static API used by other plugins to consume vl-jwt-auth.
 *
 * Exposed also as `\VLJwtAuth\Auth` via a class_alias registered in the
 * plugin bootstrap — so downstream code can write
 * `\VLJwtAuth\Auth::require_role(...)` without knowing the internal FQCN.
 *
 * All resolution methods are non-throwing by default:
 * `current_user()` and `user_from_request()` return null when
 * authentication fails. `decode_access_token()` is the single method that
 * intentionally surfaces failures via {@see TokenException}.
 */
final class AuthFacade {

	private static ?TokenService $token_service = null;

	/**
	 * Resolve the user from the current HTTP request, looking at the
	 * Authorization header on `$_SERVER`. Usable from any hook, not just
	 * REST handlers.
	 */
	public static function current_user(): ?WP_User {
		$token = self::bearer_from_globals();
		if ( null === $token ) {
			return null;
		}
		return self::user_from_token( $token );
	}

	public static function user_from_request( WP_REST_Request $request ): ?WP_User {
		$header = (string) $request->get_header( 'authorization' );
		$token  = self::parse_bearer( $header );
		if ( null === $token ) {
			return null;
		}
		return self::user_from_token( $token );
	}

	/**
	 * @return callable(WP_REST_Request):(bool|\WP_Error)
	 */
	public static function require_authenticated(): callable {
		return Middleware::authenticated();
	}

	/**
	 * @return callable(WP_REST_Request):(bool|\WP_Error)
	 */
	public static function require_role( string ...$roles ): callable {
		return Middleware::role( ...$roles );
	}

	/**
	 * @return callable(WP_REST_Request):(bool|\WP_Error)
	 */
	public static function require_capability( string $capability ): callable {
		return Middleware::capability( $capability );
	}

	/**
	 * Decode + verify an access JWT. Throws on any failure.
	 *
	 * @return array<string, mixed>
	 * @throws TokenException
	 */
	public static function decode_access_token( string $jwt ): array {
		return self::service()->decode_access( $jwt );
	}

	private static function user_from_token( string $token ): ?WP_User {
		try {
			$claims = self::service()->decode_access( $token );
		} catch ( TokenException ) {
			return null;
		}

		$user = get_user_by( 'id', (int) ( $claims['user_id'] ?? 0 ) );
		return $user instanceof WP_User ? $user : null;
	}

	private static function service(): TokenService {
		return self::$token_service ??= new TokenService(
			(string) VL_JWT_AUTH_SECRET_KEY,
			new ClaimsBuilder( new Settings() )
		);
	}

	private static function parse_bearer( string $header ): ?string {
		if ( '' === $header ) {
			return null;
		}
		return preg_match( '/^Bearer\s+(\S+)/i', $header, $matches ) ? $matches[1] : null;
	}

	private static function bearer_from_globals(): ?string {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// Apache under mod_rewrite/CGI moves Authorization here.
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = (array) apache_request_headers();
			foreach ( [ 'Authorization', 'authorization' ] as $key ) {
				if ( isset( $headers[ $key ] ) ) {
					$header = (string) $headers[ $key ];
					break;
				}
			}
		}

		return self::parse_bearer( $header );
	}
}
