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

	private static ?TokenService $token_service          = null;
	private static ?QueryTokenAllowlist $query_allowlist = null;

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
			$token = self::bearer_from_body( $request );
		}

		if ( null === $token ) {
			$token = self::bearer_from_query( $request );
		}

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

	/**
	 * Fall back to a `__bearer` token in the JSON request body when no
	 * Authorization header is present. POST-only by design — supports
	 * `navigator.sendBeacon` unload events (which cannot set custom
	 * headers) from the lesson-player progress tracker.
	 *
	 * The field is pruned via `set_param('__bearer', null)` so downstream
	 * REST controllers that read params do not see the token leak.
	 */
	private static function bearer_from_body( WP_REST_Request $request ): ?string {
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return null;
		}

		$content_type = (array) $request->get_content_type();
		$media        = isset( $content_type['value'] ) ? strtolower( (string) $content_type['value'] ) : '';
		if ( 'application/json' !== $media ) {
			return null;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! isset( $params['__bearer'] ) ) {
			return null;
		}

		$token = $params['__bearer'];
		// Always strip the field so downstream consumers don't see it.
		$request->set_param( '__bearer', null );

		if ( ! is_string( $token ) || '' === $token ) {
			return null;
		}

		return $token;
	}

	/**
	 * Fall back to a `token` query parameter on GET requests for a small set
	 * of redirect-style routes (see {@see QueryTokenAllowlist}). Frontend
	 * invokes those routes via top-level browser navigation
	 * (`window.location.assign`), which cannot attach an Authorization header
	 * — the query token is the narrowest workable carrier.
	 *
	 * Header always wins; the body `__bearer` path runs first; this only
	 * fires when both are absent. After consumption the parameter is
	 * pruned via `set_param('token', null)` so downstream controllers
	 * never see the token leak through `WP_REST_Request::get_param()`.
	 */
	private static function bearer_from_query( WP_REST_Request $request ): ?string {
		if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
			return null;
		}

		$path = (string) $request->get_route();
		if ( ! self::query_allowlist()->matches( $path ) ) {
			return null;
		}

		$raw = $request->get_param( 'token' );
		// Always prune so the token never leaks downstream, even when the
		// value is malformed or empty.
		$request->set_param( 'token', null );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		return $raw;
	}

	private static function query_allowlist(): QueryTokenAllowlist {
		return self::$query_allowlist ??= QueryTokenAllowlist::default();
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
