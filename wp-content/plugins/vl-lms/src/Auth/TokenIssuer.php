<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

use WP_REST_Request;
use WP_User;

/**
 * Contract for "issue a JWT access + refresh pair for this user".
 *
 * Exists to decouple {@see \VL\LMS\Api\AuthController} from the concrete
 * `vl-jwt-auth` plumbing. The production implementation
 * ({@see JwtBridgeTokenIssuer}) delegates into `vl-jwt-auth`'s internal
 * services; tests substitute an in-memory fake.
 *
 * The return shape matches `vl-jwt-auth`'s `/token` response so the
 * frontend contract for `POST /vl/v1/auth/verify-email` looks identical
 * to a regular login.
 *
 * @author Tymofii Synianskyi
 */
interface TokenIssuer {

	/**
	 * Issue tokens for `$user` and set the refresh cookie on the
	 * outgoing response.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int}
	 */
	public function issue_for( WP_User $user, WP_REST_Request $request ): array;
}
