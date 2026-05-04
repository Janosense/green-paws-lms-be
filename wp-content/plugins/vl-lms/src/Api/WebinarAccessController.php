<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Services\Webinars\WebinarAccessDecision;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarAccessReason;
use VL\LMS\Services\Webinars\WebinarLookup;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the two Phase 7.3 redirect-gated endpoints.
 *
 * - `GET /vl/v1/webinars/{slug}/join`      — 302 to the Zoom join URL.
 * - `GET /vl/v1/webinars/{slug}/recording` — 302 to the recording URL.
 *
 * On a clean gate, the controller responds with a 302 redirect via the
 * {@see self::send_redirect()} seam (overridable by tests so PHPUnit can
 * assert on the call without `exit`-ing the runner). On a denial, it
 * returns a `WP_Error` with the documented status / code.
 *
 * @author Tymofii Synianskyi
 */
class WebinarAccessController {

	public const string JOIN_ROUTE      = '/webinars/(?P<slug>[a-zA-Z0-9-]+)/join';
	public const string RECORDING_ROUTE = '/webinars/(?P<slug>[a-zA-Z0-9-]+)/recording';

	public const string JOIN_CAPABILITY      = 'vl_register_for_webinar';
	public const string RECORDING_CAPABILITY = 'vl_view_webinar_recording';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly WebinarLookup $lookup,
		private readonly WebinarAccessGate $gate,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::JOIN_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'join' ],
				'permission_callback' => [ $this, 'permission_join' ],
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
	public function permission_join( WP_REST_Request $request ) {
		return $this->require_capability( $request, self::JOIN_CAPABILITY );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_recording( WP_REST_Request $request ) {
		return $this->require_capability( $request, self::RECORDING_CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function join( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$slug    = (string) $request->get_param( 'slug' );
		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post ) {
			return $this->webinar_not_found();
		}

		$decision = $this->gate->can_join( $webinar, (int) $user->ID );
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
		$slug    = (string) $request->get_param( 'slug' );
		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post ) {
			return $this->webinar_not_found();
		}

		$decision = $this->gate->can_view_recording( $webinar, (int) $user->ID );
		if ( $decision->allowed && null !== $decision->redirect_url ) {
			$this->send_redirect( $decision->redirect_url );
			return null;
		}
		return $this->map_recording_denial( $decision );
	}

	/**
	 * Test seam — production performs `wp_redirect` + `exit`. PHPUnit
	 * subclasses override this method so the assertion can read the URL
	 * without halting the runner.
	 */
	protected function send_redirect( string $url ): void {
		wp_redirect( $url, 302 );
		exit;
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
			return $this->forbidden();
		}
		return true;
	}

	private function map_join_denial( WebinarAccessDecision $decision ): WP_Error {
		switch ( $decision->reason ) {
			case WebinarAccessReason::NOT_REGISTERED:
				return new WP_Error(
					'not_registered',
					__( 'You are not registered for this webinar.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
			case WebinarAccessReason::JOIN_WINDOW_NOT_OPEN:
				return new WP_Error(
					'join_window_not_open',
					__( 'The join window has not opened yet.', 'vl-lms' ),
					array_merge( [ 'status' => 409 ], $decision->context )
				);
			case WebinarAccessReason::JOIN_WINDOW_CLOSED:
				return new WP_Error(
					'join_window_closed',
					__( 'The join window has closed.', 'vl-lms' ),
					array_merge( [ 'status' => 410 ], $decision->context )
				);
			case WebinarAccessReason::MEETING_NOT_PROVISIONED:
				return new WP_Error(
					'meeting_not_provisioned',
					__( 'The Zoom meeting is not yet provisioned. Please retry shortly.', 'vl-lms' ),
					[
						'status'      => 503,
						'retry_after' => 60,
					]
				);
			default:
				return new WP_Error(
					'webinar_join_failed',
					__( 'Cannot join the webinar.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
		}
	}

	private function map_recording_denial( WebinarAccessDecision $decision ): WP_Error {
		switch ( $decision->reason ) {
			case WebinarAccessReason::NOT_REGISTERED:
				return new WP_Error(
					'not_registered',
					__( 'You are not registered for this webinar.', 'vl-lms' ),
					[ 'status' => 403 ]
				);
			case WebinarAccessReason::RECORDING_NOT_AVAILABLE:
				return new WP_Error(
					'recording_not_available',
					__( 'The recording is not available.', 'vl-lms' ),
					[ 'status' => 404 ]
				);
			case WebinarAccessReason::RECORDING_WINDOW_EXPIRED:
				return new WP_Error(
					'recording_window_expired',
					__( 'The recording access window has ended.', 'vl-lms' ),
					array_merge( [ 'status' => 410 ], $decision->context )
				);
			default:
				return new WP_Error(
					'webinar_recording_failed',
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

	private function forbidden(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this webinar.', 'vl-lms' ),
			[ 'status' => 403 ]
		);
	}

	private function webinar_not_found(): WP_Error {
		return new WP_Error(
			'webinar_not_found',
			__( 'Webinar not found.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}
}
