<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Progress\ProgressEventRequest;
use VL\LMS\Services\Progress\ProgressEventResult;
use VL\LMS\Services\Progress\ProgressService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for `POST /vl/v1/progress`.
 *
 * One write endpoint: accepts a single lesson-player event, writes one row
 * to `vl_lesson_views`, upserts `vl_progress`, and (on `event_type=complete`)
 * runs synchronous fan-up through {@see ProgressService}.
 *
 * permission_callback gates by `is_user_logged_in()` AND
 * `current_user_can('vl_view_lesson')` — the same capability used for read
 * endpoints in 5.1b/5.2 (no separate write cap). The handler then resolves
 * the authenticated user via {@see RestAuthenticator} for identity, and
 * pre-validates entity + course + enrollment so each failure surfaces with
 * its own error code rather than a generic 500.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressController {

	public const string PROGRESS_ROUTE = '/progress';

	public const string VIEW_CAPABILITY = 'vl_view_lesson';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly EnrollmentService $enrollments,
		private readonly EntityHierarchy $hierarchy,
		private readonly ProgressService $service,
		private readonly ProgressionGate $progression
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::PROGRESS_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [],
			]
		);
	}

	/**
	 * Resolve the caller through the JWT bearer-auth path rather than WP's
	 * cookie auth. `is_user_logged_in()` and `current_user_can()` only see
	 * the user when `wp_set_current_user()` has been called — and
	 * `vl-jwt-auth` deliberately doesn't pollute global user state when it
	 * decodes a bearer token (that's why it exposes the explicit
	 * `AuthFacade::user_from_request()` call). Using cookie-auth helpers
	 * here would 401 every JWT request — including the lesson player's
	 * `useProgressTracker` heartbeats and "Mark as Complete" clicks.
	 *
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
		if ( ! $user->has_cap( self::VIEW_CAPABILITY ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'unauthorized',
				__( 'Authentication required.', 'vl-lms' ),
				[ 'status' => 401 ]
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || [] === $body ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Request body must be a non-empty JSON object.', 'vl-lms' ),
				[ 'status' => 400 ]
			);
		}

		try {
			$dto = ProgressEventRequest::fromArray( $body );
		} catch ( \InvalidArgumentException $e ) {
			return $this->map_validation_error( $e );
		}

		$post = get_post( $dto->entity_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return $this->entity_not_found();
		}
		$expected_type = EntityType::LESSON === $dto->entity_type ? 'vl_lesson' : 'vl_topic';
		if ( $post->post_type !== $expected_type ) {
			return $this->entity_not_found();
		}

		$course = $this->hierarchy->resolveCourse( $post );
		if ( ! $course instanceof WP_Post ) {
			return $this->entity_not_found();
		}

		if ( ! $this->enrollments->has_active_access( (int) $user->ID, (int) $course->ID ) ) {
			return new WP_Error(
				'not_enrolled',
				__( 'You are not enrolled in this course.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}

		// A locked entity must accumulate no progress or view rows. The
		// content read already 403s, so the player is never legitimately
		// open on one — this refusal is what makes progression locks (and
		// sequential mode's "mark complete to advance" in particular)
		// enforceable against a crafted POST rather than advisory.
		$lock = $this->progression->check(
			(int) $user->ID,
			(int) $course->ID,
			$dto->entity_type->value,
			$dto->entity_id
		);
		if ( null !== $lock ) {
			return new WP_Error(
				$lock->reason,
				__( 'This part of the course is not available yet.', 'vl-lms' ),
				[
					'status' => 403,
					'lock'   => $lock->to_array(),
				]
			);
		}

		try {
			$result = $this->service->record( (int) $user->ID, $dto );
		} catch ( \RuntimeException $e ) {
			$code = $e->getMessage();
			if ( 'entity_not_found' === $code || 'hierarchy_failure' === $code ) {
				return $this->entity_not_found();
			}
			throw $e;
		}

		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->envelope( $result ),
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	private function map_validation_error( \InvalidArgumentException $e ): WP_Error {
		$code = $e->getMessage();
		if ( 'payload_too_large' === $code ) {
			return new WP_Error(
				'payload_too_large',
				__( 'Event payload exceeds the 4 KB limit.', 'vl-lms' ),
				[ 'status' => 413 ]
			);
		}
		return new WP_Error(
			'invalid_payload',
			$this->validation_message( $code ),
			[ 'status' => 400 ]
		);
	}

	private function validation_message( string $code ): string {
		if ( str_starts_with( $code, 'missing_field:' ) ) {
			$field = substr( $code, strlen( 'missing_field:' ) );
			return sprintf(
				/* translators: %s: name of the missing field. */
				__( "Field '%s' is required.", 'vl-lms' ),
				$field
			);
		}
		if ( str_starts_with( $code, 'invalid_type:' ) ) {
			$field = substr( $code, strlen( 'invalid_type:' ) );
			return sprintf(
				/* translators: %s: name of the field whose type is wrong. */
				__( "Field '%s' has an invalid type.", 'vl-lms' ),
				$field
			);
		}
		if ( str_starts_with( $code, 'invalid_range:' ) ) {
			$field = substr( $code, strlen( 'invalid_range:' ) );
			return sprintf(
				/* translators: %s: name of the field whose value is out of range. */
				__( "Field '%s' is out of range.", 'vl-lms' ),
				$field
			);
		}
		return match ( $code ) {
			'invalid_uuid'        => __( 'Field session_uuid is not a valid UUID.', 'vl-lms' ),
			'invalid_entity_type' => __( 'Field entity_type must be lesson or topic.', 'vl-lms' ),
			'invalid_event_type'  => __( 'Field event_type is not a recognized value.', 'vl-lms' ),
			default               => __( 'Request body failed validation.', 'vl-lms' ),
		};
	}

	private function entity_not_found(): WP_Error {
		return new WP_Error(
			'entity_not_found',
			__( 'The entity could not be found.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function envelope( ProgressEventResult $result ): array {
		$progress = $result->progress;

		return [
			'view_id'  => $result->view_id,
			'progress' => [
				'entity_type'      => $progress->entity_type->value,
				'entity_id'        => $progress->entity_id,
				'course_id'        => $progress->course_id,
				'status'           => $progress->status->value,
				'position_seconds' => $progress->position_seconds,
				'completed_at'     => null === $progress->completed_at
					? null
					: $progress->completed_at->format( \DateTimeInterface::ATOM ),
				'last_seen_at'     => null === $progress->last_seen_at
					? null
					: $progress->last_seen_at->format( \DateTimeInterface::ATOM ),
			],
			'fanup'    => [
				'lesson_completed'    => $result->lesson_completed,
				'module_completed'    => $result->module_completed,
				'course_progress_pct' => $result->course_progress_pct,
				'course_completed'    => $result->course_completed,
			],
		];
	}
}
