<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers the `vl/v1` REST namespace.
 *
 * Phase 0 exposes a single public health probe. Future domain endpoints
 * will be registered alongside — or in dedicated sub-controllers dispatched
 * from here — and will delegate authentication to \VLJwtAuth\Auth.
 *
 * Intended pattern for auth-protected routes (to be used in later phases):
 *
 *     register_rest_route(
 *         $this->rest_namespace,
 *         '/enrollments',
 *         [
 *             'methods'             => 'POST',
 *             'callback'            => [ $this, 'create_enrollment' ],
 *             'permission_callback' => static fn (): bool =>
 *                 \VLJwtAuth\Auth::require_role( 'veterinarian' ),
 *         ]
 *     );
 */
final class RestController {

	public function __construct(
		private readonly string $rest_namespace,
		private readonly string $version,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			'/healthz',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'healthz' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function healthz( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return rest_ensure_response(
			[
				'status'    => 'ok',
				'version'   => $this->version,
				'timestamp' => time(),
			]
		);
	}
}
