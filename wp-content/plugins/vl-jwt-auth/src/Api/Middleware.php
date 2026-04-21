<?php

declare(strict_types=1);

namespace VLJwtAuth\Api;

use VLJwtAuth\Auth\AuthFacade;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * Permission-callback factories for use with register_rest_route().
 *
 * Returns closures, not booleans: WordPress invokes the callback with
 * the incoming request and expects true / false / WP_Error back. We
 * return WP_Error with a stable code + HTTP status so other plugins'
 * error envelopes remain consistent.
 *
 * Exposed publicly through {@see AuthFacade}.
 */
final class Middleware {

	public static function authenticated(): callable {
		return static function ( WP_REST_Request $request ): bool|WP_Error {
			$user = AuthFacade::user_from_request( $request );
			if ( $user instanceof WP_User ) {
				return true;
			}
			return self::unauthenticated();
		};
	}

	/**
	 * Require the caller to hold any one of the given roles.
	 */
	public static function role( string ...$roles ): callable {
		return static function ( WP_REST_Request $request ) use ( $roles ): bool|WP_Error {
			$user = AuthFacade::user_from_request( $request );
			if ( ! $user instanceof WP_User ) {
				return self::unauthenticated();
			}

			$user_roles = array_values( (array) $user->roles );
			foreach ( $roles as $role ) {
				if ( in_array( $role, $user_roles, true ) ) {
					return true;
				}
			}

			return new WP_Error(
				'insufficient_role',
				__( 'Your role does not grant access to this resource.', 'vl-jwt-auth' ),
				[ 'status' => 403 ]
			);
		};
	}

	public static function capability( string $capability ): callable {
		return static function ( WP_REST_Request $request ) use ( $capability ): bool|WP_Error {
			$user = AuthFacade::user_from_request( $request );
			if ( ! $user instanceof WP_User ) {
				return self::unauthenticated();
			}

			if ( $user->has_cap( $capability ) ) {
				return true;
			}

			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to perform this action.', 'vl-jwt-auth' ),
				[ 'status' => 403 ]
			);
		};
	}

	private static function unauthenticated(): WP_Error {
		return new WP_Error(
			'token_invalid',
			__( 'Authentication required.', 'vl-jwt-auth' ),
			[ 'status' => 401 ]
		);
	}
}
