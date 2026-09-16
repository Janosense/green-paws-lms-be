<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\StudyTime\StudyTimeConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST surface of the `study-time` feature.
 *
 *   GET /vl/v1/study-time/config
 *
 * Serves the three intervals the client needs to run its heartbeat loop
 * (`docs/features/study-time/FEATURE.md` → Interfaces). The server stays the
 * source of these values, and the cap is enforced server-side whatever a
 * client sends.
 *
 * Auth: the caller is resolved through {@see RestAuthenticator}, never
 * through WP's cookie helpers (`docs/TECH-STACK.md` → ANTI-PATTERNS; the
 * reasoning is spelled out on `Api\ProgressController::permission_callback`).
 * Any logged-in caller may read the configuration — it is three integers, not
 * learner data. The capability and enrollment gates belong to the heartbeat.
 *
 * Concrete (not final), like the `core` controllers: Mockery-mockable in unit
 * tests.
 *
 * @author Tymofii Synianskyi
 */
class StudyTimeController {

	public const string CONFIG_ROUTE = '/study-time/config';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly StudyTimeConfig $config
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::CONFIG_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'config' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [],
			]
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function permission_callback( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'vl-lms' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	public function config(): WP_REST_Response {
		return rest_ensure_response(
			[
				'idle_seconds'      => $this->config->idle_seconds,
				'heartbeat_seconds' => $this->config->heartbeat_seconds,
				'cap_seconds'       => $this->config->cap_seconds,
			]
		);
	}
}
