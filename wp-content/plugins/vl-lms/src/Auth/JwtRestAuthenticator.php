<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

use VLJwtAuth\Auth\AuthFacade;
use WP_REST_Request;
use WP_User;

/**
 * Production {@see RestAuthenticator} that delegates to vl-jwt-auth's
 * facade.
 *
 * Note on naming: vl-jwt-auth registers a `class_alias` so the facade is
 * also reachable as `\VLJwtAuth\Auth`. We import the canonical FQCN
 * ({@see AuthFacade}) here so static analysis (PHPStan can't follow
 * `class_alias` to the alias) keeps working.
 *
 * The facade owns token decoding, signature verification, and user lookup.
 * Keeping that logic on the vl-jwt-auth side means vl-lms never imports
 * `firebase/jwt` or any TokenService internals — the same boundary that
 * `\VL\LMS\Auth\TokenIssuer` enforces for the issuance direction.
 */
final class JwtRestAuthenticator implements RestAuthenticator {

	public function user_from_request( WP_REST_Request $request ): ?WP_User {
		return AuthFacade::user_from_request( $request );
	}
}
