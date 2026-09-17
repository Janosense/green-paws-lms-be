<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Services\Exception\HeartbeatFailedException;
use VL\LMS\StudyTime\Services\HeartbeatService;
use VL\LMS\StudyTime\StudyTimeConfig;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST surface of the `study-time` feature.
 *
 *   GET  /vl/v1/study-time/config
 *   POST /vl/v1/study-time/heartbeat
 *
 * The config route serves the three intervals the client needs to run its
 * heartbeat loop; the heartbeat route turns one client signal into ledger
 * seconds (`docs/features/study-time/FEATURE.md` → Interfaces). Both answer
 * bare objects, without the `{success, data}` envelope
 * (`docs/DECISIONS.md` 2026-09-16).
 *
 * Auth: the caller is resolved through {@see RestAuthenticator}, never
 * through WP's cookie helpers (`docs/TECH-STACK.md` → ANTI-PATTERNS; the
 * reasoning is spelled out on `Api\ProgressController::permission_callback`).
 * The config is readable by any logged-in caller — it is three integers, not
 * learner data. The heartbeat additionally requires `vl_view_lesson`, and
 * {@see HeartbeatService} checks the enrollment: the gate `POST /vl/v1/progress`
 * uses, minus the progression lock (`docs/DECISIONS.md` 2026-09-15 —
 * feature-owned ledger).
 *
 * Concrete (not final), like the `core` controllers: Mockery-mockable in unit
 * tests.
 *
 * @author Tymofii Synianskyi
 */
class StudyTimeController {

	public const string CONFIG_ROUTE = '/study-time/config';

	public const string HEARTBEAT_ROUTE = '/study-time/heartbeat';

	public const string ME_ROUTE = '/study-time/me';

	public const string VIEW_CAPABILITY = 'vl_view_lesson';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly StudyTimeConfig $config,
		private readonly HeartbeatService $heartbeats,
		private readonly StudyTimeReportQuery $reports,
		private readonly Logger $logger
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

		register_rest_route(
			$this->rest_namespace,
			self::HEARTBEAT_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'heartbeat' ],
				'permission_callback' => [ $this, 'heartbeat_permission_callback' ],
				'args'                => [],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ME_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'me' ],
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
			return $this->not_logged_in();
		}
		return true;
	}

	/**
	 * The heartbeat writes a learner's ledger, so it also demands the
	 * capability every learner surface demands.
	 *
	 * @return true|WP_Error
	 */
	public function heartbeat_permission_callback( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::VIEW_CAPABILITY ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'vl-lms' ),
				[ 'status' => 403 ]
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

	/**
	 * The caller's own study time, per course they are enrolled in.
	 *
	 * Answers about the caller and nobody else, which is why being signed in
	 * is the whole gate: there is no other learner's data to protect here.
	 * A learner with no enrollments gets an empty list and a 200 — "you have
	 * studied nothing yet" is an answer, not a missing resource.
	 *
	 * Bare object, like the feature's other responses
	 * (`docs/DECISIONS.md` 2026-09-16).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function me( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}

		$courses = [];
		foreach ( $this->reports->for_user( (int) $user->ID ) as $course_id => $totals ) {
			$post = get_post( $course_id );
			if ( ! $post instanceof \WP_Post ) {
				// The enrollment outlived its course; there is nothing to
				// name on the card, so it is left out rather than shipped
				// with an empty slug.
				continue;
			}

			$courses[] = [
				'course_id'       => $course_id,
				'course_slug'     => (string) $post->post_name,
				'total_seconds'   => $totals['total'],
				'video_seconds'   => $totals['video'],
				'reading_seconds' => $totals['reading'],
				'quiz_seconds'    => $totals['quiz'],
				'session_seconds' => $totals['session'],
			];
		}

		return rest_ensure_response( [ 'courses' => $courses ] );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function heartbeat( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || [] === $body ) {
			return $this->refuse( $user_id, 'invalid_payload', __( 'Request body must be a non-empty JSON object.', 'vl-lms' ), 400, [] );
		}

		$entity_type = is_string( $body['entity_type'] ?? null ) ? $body['entity_type'] : '';
		if ( 'lesson' !== $entity_type && 'topic' !== $entity_type ) {
			return $this->refuse(
				$user_id,
				'invalid_entity_type',
				__( "Field 'entity_type' must be 'lesson' or 'topic'.", 'vl-lms' ),
				422,
				[ 'entity_type' => $entity_type ]
			);
		}

		$entity_id = is_numeric( $body['entity_id'] ?? null ) ? (int) $body['entity_id'] : 0;
		if ( $entity_id <= 0 ) {
			return $this->refuse(
				$user_id,
				'invalid_payload',
				__( "Field 'entity_id' must be a positive integer.", 'vl-lms' ),
				400,
				[ 'entity_type' => $entity_type ]
			);
		}

		$kind = is_string( $body['kind'] ?? null ) ? StudyKind::tryFrom( $body['kind'] ) : null;
		if ( ! $kind instanceof StudyKind ) {
			return $this->refuse(
				$user_id,
				'invalid_kind',
				__( "Field 'kind' must be 'video' or 'reading'.", 'vl-lms' ),
				422,
				[
					'entity_type' => $entity_type,
					'entity_id'   => $entity_id,
				]
			);
		}

		try {
			$result = $this->heartbeats->record(
				$user_id,
				$entity_type,
				$entity_id,
				$kind,
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
			);
		} catch ( HeartbeatFailedException $e ) {
			return $this->map_failure( $user_id, $entity_type, $entity_id, $kind, $e );
		}

		return rest_ensure_response(
			[
				'active_seconds'        => $result->active_seconds,
				'course_active_seconds' => $result->course_active_seconds,
			]
		);
	}

	private function not_logged_in(): WP_Error {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You are not currently logged in.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}

	private function map_failure(
		int $user_id,
		string $entity_type,
		int $entity_id,
		StudyKind $kind,
		HeartbeatFailedException $e
	): WP_Error {
		$status  = HeartbeatFailedException::NOT_ENROLLED === $e->error_code ? 403 : 404;
		$message = 403 === $status
			? __( 'You are not enrolled in this course.', 'vl-lms' )
			: __( 'This lesson or topic does not exist.', 'vl-lms' );

		return $this->refuse(
			$user_id,
			$e->error_code,
			$message,
			$status,
			[
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'kind'        => $kind->value,
			]
		);
	}

	/**
	 * Builds the error and logs it at debug level. The context carries the
	 * reason and what was addressed — never the token, a header or the raw
	 * body (root `CLAUDE.md` core rule 4).
	 *
	 * @param array<string, mixed> $context
	 */
	private function refuse( int $user_id, string $code, string $message, int $status, array $context ): WP_Error {
		$this->logger->debug(
			'Study-time heartbeat refused.',
			array_merge(
				[
					'reason'  => $code,
					'status'  => $status,
					'user_id' => $user_id,
				],
				$context
			)
		);

		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
