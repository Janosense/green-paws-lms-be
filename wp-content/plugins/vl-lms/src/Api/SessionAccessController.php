<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Learn\Access\SessionAccessDecision;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\Access\SessionAccessReason;
use VL\LMS\Learn\SessionContentTransformer;
use VL\LMS\Learn\SessionLookup;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the three Phase 7.4 cohort-session endpoints.
 *
 * - `GET /vl/v1/learn/sessions/{slug}`           — detail payload.
 * - `GET /vl/v1/learn/sessions/{slug}/join`      — 302 to Zoom join URL.
 * - `GET /vl/v1/learn/sessions/{slug}/recording` — 302 to recording URL.
 *
 * All three require JWT auth and the `vl_view_lesson` capability (the
 * established cap for in-course content). The recording endpoint also
 * requires `vl_view_session_recording`. Course enrollment is enforced via
 * `EnrollmentService::has_active_access` against the session's parent
 * `vl_course`.
 *
 * Concrete (not final) so tests can subclass and override the redirect
 * seam — same pattern as `WebinarAccessController`.
 *
 * @author Tymofii Synianskyi
 */
class SessionAccessController {

	public const string DETAIL_ROUTE    = '/learn/sessions/(?P<slug>[a-zA-Z0-9-]+)';
	public const string JOIN_ROUTE      = '/learn/sessions/(?P<slug>[a-zA-Z0-9-]+)/join';
	public const string RECORDING_ROUTE = '/learn/sessions/(?P<slug>[a-zA-Z0-9-]+)/recording';

	public const string VIEW_CAPABILITY      = 'vl_view_lesson';
	public const string RECORDING_CAPABILITY = 'vl_view_session_recording';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly SessionLookup $lookup,
		private readonly SessionAccessGate $gate,
		private readonly SessionContentTransformer $transformer,
		private readonly EnrollmentService $enrollments,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::DETAIL_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'detail' ],
				'permission_callback' => [ $this, 'permission_view' ],
			]
		);
		register_rest_route(
			$this->rest_namespace,
			self::JOIN_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'join' ],
				'permission_callback' => [ $this, 'permission_view' ],
			]
		);
		register_rest_route(
			$this->rest_namespace,
			self::RECORDING_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'recording' ],
				'permission_callback' => [ $this, 'permission_recording' ],
			]
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_view( WP_REST_Request $request ) {
		return $this->require_capability( $request, self::VIEW_CAPABILITY );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_recording( WP_REST_Request $request ) {
		return $this->require_capability( $request, self::RECORDING_CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function detail( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$session = $this->lookup->find_by_slug( (string) $request->get_param( 'slug' ) );
		if ( ! $session instanceof WP_Post ) {
			return $this->session_not_found();
		}

		$course_id = $this->resolve_course_id( $session );
		if ( null === $course_id ) {
			return $this->session_not_found();
		}
		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			return new WP_Error(
				'not_enrolled',
				__( 'You are not enrolled in this course.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}

		return rest_ensure_response(
			array_merge(
				[ 'success' => true ],
				$this->transformer->transform( $session, $user_id )
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function join( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$session = $this->lookup->find_by_slug( (string) $request->get_param( 'slug' ) );
		if ( ! $session instanceof WP_Post ) {
			return $this->session_not_found();
		}

		$decision = $this->gate->can_join( $session, (int) $user->ID );
		if ( $decision->allowed && null !== $decision->redirect_url ) {
			$this->send_redirect( $decision->redirect_url );
			return null;
		}
		return $this->map_join_denial( $decision );
	}

	/**
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function recording( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$session = $this->lookup->find_by_slug( (string) $request->get_param( 'slug' ) );
		if ( ! $session instanceof WP_Post ) {
			return $this->session_not_found();
		}

		$decision = $this->gate->can_view_recording( $session, (int) $user->ID );
		if ( $decision->allowed && null !== $decision->redirect_url ) {
			$this->send_redirect( $decision->redirect_url );
			return null;
		}
		return $this->map_recording_denial( $decision );
	}

	/**
	 * Test seam — production performs `wp_redirect` + `exit`.
	 */
	protected function send_redirect( string $url ): void {
		wp_redirect( $url, 302 );
		exit;
	}

	private function resolve_course_id( WP_Post $session ): ?int {
		$parent_id = (int) $session->post_parent;
		if ( $parent_id <= 0 ) {
			return null;
		}
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post ) {
			return null;
		}
		if ( 'vl_course' !== $parent->post_type || 'publish' !== $parent->post_status ) {
			return null;
		}
		return $parent_id;
	}

	/**
	 * @return bool|WP_Error
	 */
	private function require_capability( WP_REST_Request $request, string $capability ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this session.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	private function map_join_denial( SessionAccessDecision $decision ): WP_Error {
		switch ( $decision->reason ) {
			case SessionAccessReason::COURSE_NOT_FOUND:
				return $this->session_not_found();
			case SessionAccessReason::NOT_ENROLLED:
				return new WP_Error(
					'not_enrolled',
					__( 'You are not enrolled in this course.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
			case SessionAccessReason::SESSION_CANCELLED:
				return new WP_Error(
					'session_cancelled',
					__( 'This session has been cancelled.', 'vl-lms' ),
					[ 'status' => 410 ]
				);
			case SessionAccessReason::MEETING_NOT_PROVISIONED:
				return new WP_Error(
					'meeting_not_provisioned',
					__( 'The Zoom meeting is not yet provisioned. Please retry shortly.', 'vl-lms' ),
					[
						'status'      => 503,
						'retry_after' => 60,
					]
				);
			case SessionAccessReason::JOIN_WINDOW_NOT_OPEN:
				return new WP_Error(
					'join_window_not_open',
					__( 'The join window has not opened yet.', 'vl-lms' ),
					array_merge( [ 'status' => 409 ], $decision->context )
				);
			case SessionAccessReason::JOIN_WINDOW_CLOSED:
				return new WP_Error(
					'join_window_closed',
					__( 'The join window has closed.', 'vl-lms' ),
					array_merge( [ 'status' => 410 ], $decision->context )
				);
			default:
				return new WP_Error(
					'session_join_failed',
					__( 'Cannot join the session.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
		}
	}

	private function map_recording_denial( SessionAccessDecision $decision ): WP_Error {
		switch ( $decision->reason ) {
			case SessionAccessReason::COURSE_NOT_FOUND:
				return $this->session_not_found();
			case SessionAccessReason::NOT_ENROLLED:
				return new WP_Error(
					'not_enrolled',
					__( 'You are not enrolled in this course.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
			case SessionAccessReason::RECORDING_NOT_AVAILABLE:
				return new WP_Error(
					'recording_not_available',
					__( 'The recording is not available.', 'vl-lms' ),
					[ 'status' => 404 ]
				);
			case SessionAccessReason::RECORDING_WINDOW_EXPIRED:
				return new WP_Error(
					'recording_window_expired',
					__( 'The recording access window has ended.', 'vl-lms' ),
					array_merge( [ 'status' => 410 ], $decision->context )
				);
			default:
				return new WP_Error(
					'session_recording_failed',
					__( 'Cannot view the recording.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
		}
	}

	private function not_logged_in(): WP_Error {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You are not currently logged in.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}

	private function session_not_found(): WP_Error {
		return new WP_Error(
			'session_not_found',
			__( 'Session not found.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}
}
