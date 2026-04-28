<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

use WP_REST_Request;
use WP_User;

/**
 * Resolves the authenticated user for an incoming REST request.
 *
 * Single-method boundary against `vl-jwt-auth`: production wires this to
 * {@see \VLJwtAuth\Auth::user_from_request()}; unit tests substitute a
 * stub that returns a staged `WP_User` (or `null` for guest cases).
 *
 * Keeping the contract typed at this layer means controllers depend on a
 * vl-lms-owned interface rather than a static call into a sibling plugin's
 * facade — easier to mock, easier to evolve if the JWT contract gains a
 * second mode (refresh-cookie, signed-URL, etc.) later.
 */
interface RestAuthenticator {

	public function user_from_request( WP_REST_Request $request ): ?WP_User;
}
