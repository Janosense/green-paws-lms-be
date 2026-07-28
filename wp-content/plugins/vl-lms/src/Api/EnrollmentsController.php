<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Enrollment\EnrollmentStatsService;
use VL\LMS\Services\Progress\ProgressResetService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for `/vl/v1/enrollments` and `/vl/v1/enrollments/me`.
 *
 * - `POST   /vl/v1/enrollments`    — enroll the authed caller into a free
 *                                    course (Phase 4.1).
 * - `GET    /vl/v1/enrollments/me` — list the authed caller's enrollments,
 *                                    active + completed only (Phase 4.1).
 * - `DELETE /vl/v1/enrollments/me/{course_slug}` — self-revoke a free-tier
 *                                    enrollment (Phase 8.3).
 * - `DELETE /vl/v1/enrollments/me/{course_slug}/progress` — self-service
 *                                    progress reset (Phase 11).
 *
 * Authentication is delegated to {@see RestAuthenticator} (production wires
 * to `\VLJwtAuth\Auth::user_from_request()`) so the JWT contract stays in
 * `vl-jwt-auth`. Permission callbacks return a `WP_Error` with
 * `rest_not_logged_in` / `rest_forbidden` codes — the frontend keys i18n
 * off these stable codes.
 *
 * The handlers are thin REST adapters: validation lives here, all state
 * mutation flows through {@see EnrollmentService}.
 *
 * @author Tymofii Synianskyi
 */
final class EnrollmentsController {

	public const string ENROLLMENTS_ROUTE                  = '/enrollments';
	public const string ENROLLMENTS_ME_ROUTE               = '/enrollments/me';
	public const string ENROLLMENTS_ME_ITEM_ROUTE          = '/enrollments/me/(?P<course_slug>[a-z0-9][a-z0-9-]*)';
	public const string ENROLLMENTS_ME_ITEM_PROGRESS_ROUTE = '/enrollments/me/(?P<course_slug>[a-z0-9][a-z0-9-]*)/progress';

	public const string ENROLL_CAPABILITY  = 'vl_enroll_in_course';
	public const string SELF_REVOKE_REASON = 'user_self_revoke';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly EnrollmentService $service,
		private readonly EnrollmentRepository $repository,
		private readonly EnrollmentRecordTransformer $transformer,
		private readonly ProgressResetService $reset_service,
		private readonly EnrollmentStatsService $stats,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::ENROLLMENTS_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'permission_create' ],
				'args'                => [
					'course_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ENROLLMENTS_ME_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_mine' ],
				'permission_callback' => [ $this, 'permission_list_mine' ],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ENROLLMENTS_ME_ITEM_ROUTE,
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'self_revoke' ],
				'permission_callback' => [ $this, 'permission_list_mine' ],
				'args'                => [
					'course_slug' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ENROLLMENTS_ME_ITEM_PROGRESS_ROUTE,
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'reset_progress' ],
				'permission_callback' => [ $this, 'permission_list_mine' ],
				'args'                => [
					'course_slug' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);
	}

	/**
	 * Permission callback for `POST /enrollments`.
	 *
	 * Requires (a) a valid JWT and (b) the `vl_enroll_in_course` capability.
	 * Subscribers (the WP fallback for unverified accounts) hit the 403
	 * branch — they have a token but no enrollment cap.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_create( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::ENROLL_CAPABILITY ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Permission callback for `GET /enrollments/me`.
	 *
	 * Authentication only — no capability check. A subscriber asking for
	 * their own enrollments gets an empty list, not a 403.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		return true;
	}

	/**
	 * `POST /vl/v1/enrollments`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			// Defensive — the permission callback already gates this. Keep
			// the branch so a future routing-config bug doesn't land us in
			// the handler with an anonymous request.
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$course_id = $this->parse_course_id( $request->get_param( 'course_id' ) );
		if ( null === $course_id ) {
			return new WP_Error(
				'invalid_course_id',
				__( 'A positive integer course_id is required.', 'vl-lms' ),
				[ 'status' => 400 ]
			);
		}

		// Idempotency: an active enrollment short-circuits validation. A
		// previously revoked / expired row falls through to `enroll()`,
		// which decides whether to resurrect it.
		if ( $this->service->has_active_access( $user_id, $course_id ) ) {
			$existing = $this->repository->find_for_user_and_course( $user_id, $course_id );
			$course   = get_post( $course_id );
			if ( null === $existing || ! $course instanceof WP_Post ) {
				return $this->course_not_found();
			}
			return rest_ensure_response(
				[
					'success' => true,
					'data'    => $this->transformer->transform(
						$existing,
						$course,
						$this->stats->for_user_in_course( $user_id, (int) $course->ID )
					),
				]
			);
		}

		$course = $this->require_published_course( $course_id );
		if ( ! $course instanceof WP_Post ) {
			return $this->course_not_found();
		}

		$gate = $this->validate_enrollment_window( $course_id );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$enrollment = $this->service->enroll( $user_id, $course_id, EnrollmentSource::SELF_SIGNUP );

		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->transformer->transform(
					$enrollment,
					$course,
					$this->stats->for_user_in_course( $user_id, (int) $course->ID )
				),
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * `GET /vl/v1/enrollments/me`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}

		$enrollments = $this->repository->list_for_user_in_statuses(
			(int) $user->ID,
			[ EnrollmentStatus::ACTIVE, EnrollmentStatus::COMPLETED ]
		);

		$resolved = [];
		foreach ( $enrollments as $enrollment ) {
			$course = get_post( $enrollment->course_id );
			if ( ! $course instanceof WP_Post ) {
				// Course was deleted out from under the enrollment row.
				// Skip rather than 500 — the dashboard renders what we have.
				continue;
			}
			$resolved[] = [ $enrollment, $course ];
		}

		$stats = $this->stats->for_user_in_courses(
			(int) $user->ID,
			array_map( static fn ( array $pair ): int => (int) $pair[1]->ID, $resolved )
		);

		$items = [];
		foreach ( $resolved as [ $enrollment, $course ] ) {
			$items[] = $this->transformer->transform( $enrollment, $course, $stats[ (int) $course->ID ] );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'items' => $items,
				],
			]
		);
	}

	/**
	 * Phase 8.3 — `DELETE /vl/v1/enrollments/me/{course_slug}`.
	 *
	 * Self-revoke for free-tier (non-PURCHASE) enrollments. PURCHASE-source
	 * enrollments require admin refund-flow; the user is told to contact
	 * support.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function self_revoke( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$slug = (string) $request->get_param( 'course_slug' );
		if ( '' === $slug ) {
			return $this->course_not_found();
		}

		$course = get_page_by_path( $slug, OBJECT, 'vl_course' );
		if ( ! $course instanceof WP_Post ) {
			return $this->course_not_found();
		}

		$enrollment = $this->service->find_for_user_and_course( $user_id, (int) $course->ID );
		if ( ! $enrollment instanceof Enrollment ) {
			return $this->enrollment_not_found();
		}
		if ( $enrollment->user_id !== $user_id ) {
			// Defensive — find_for_user_and_course is keyed on user_id, so
			// reaching this branch is unreachable in practice. Mirrors the
			// "mask other-user's enrollment as 404" instruction in the spec.
			return $this->enrollment_not_found();
		}
		if ( EnrollmentStatus::ACTIVE !== $enrollment->status && EnrollmentStatus::COMPLETED !== $enrollment->status ) {
			return $this->enrollment_not_found();
		}
		if ( EnrollmentSource::PURCHASE === $enrollment->source ) {
			return new WP_Error(
				'purchase_enrollment_requires_refund',
				__( 'Платні записи можна відкликати лише через процедуру повернення коштів.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}

		$revoked = $this->service->revoke( $enrollment->id, $user_id, self::SELF_REVOKE_REASON );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->transformer->transform(
					$revoked,
					$course,
					$this->stats->for_user_in_course( $user_id, (int) $course->ID )
				),
			]
		);
	}

	/**
	 * `DELETE /vl/v1/enrollments/me/{course_slug}/progress` — self-service
	 * progress reset ("fresh start with preserved history"; see
	 * {@see ProgressResetService} for what is wiped vs kept).
	 *
	 * Unlike {@see self::self_revoke()}, there is deliberately no
	 * PURCHASE-source gate: a reset never touches access, so a paying
	 * learner may restart their course without a refund flow.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_progress( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$slug = (string) $request->get_param( 'course_slug' );
		if ( '' === $slug ) {
			return $this->course_not_found();
		}

		$course = get_page_by_path( $slug, OBJECT, 'vl_course' );
		if ( ! $course instanceof WP_Post ) {
			return $this->course_not_found();
		}

		$enrollment = $this->service->find_for_user_and_course( $user_id, (int) $course->ID );
		if ( ! $enrollment instanceof Enrollment ) {
			return $this->enrollment_not_found();
		}
		if ( EnrollmentStatus::ACTIVE !== $enrollment->status && EnrollmentStatus::COMPLETED !== $enrollment->status ) {
			// Same masking as self_revoke: a revoked / refunded / expired
			// enrollment has nothing the learner may reset, and the row's
			// existence is not theirs to probe.
			return $this->enrollment_not_found();
		}

		$reset = $this->reset_service->reset( $user_id, (int) $course->ID );
		if ( ! $reset instanceof Enrollment ) {
			return new WP_Error(
				'progress_reset_failed',
				__( 'Не вдалося скинути прогрес.', 'vl-lms' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				// Stats are computed after the wipe, so completed / passed
				// arrive already zeroed alongside the reset progress_pct.
				'data'    => $this->transformer->transform(
					$reset,
					$course,
					$this->stats->for_user_in_course( $user_id, (int) $course->ID )
				),
			]
		);
	}

	private function enrollment_not_found(): WP_Error {
		return new WP_Error(
			'enrollment_not_found',
			__( 'Запис на курс не знайдено.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Validates checks 3–7 of the enrollment pipeline.
	 *
	 * Returns `null` on success or a `WP_Error` with the documented code /
	 * status on failure. Pulled out of the create handler so the happy path
	 * reads as a sequence of guard clauses.
	 *
	 * Bad-meta resilience: an unparseable opens-at / closes-at string is
	 * treated as missing rather than a 500. Authors get a soft fail instead
	 * of a noisy crash, and the smoke recipes can exercise the bad-string
	 * branch without a fixture cleanup.
	 */
	private function validate_enrollment_window( int $course_id ): ?WP_Error {
		$price = (float) get_post_meta( $course_id, '_vl_course_price', true );
		if ( $price > 0.0 ) {
			return new WP_Error(
				'payment_required',
				__( 'Course requires payment.', 'vl-lms' ),
				[ 'status' => 402 ]
			);
		}

		$enrollment_open = get_post_meta( $course_id, '_vl_course_enrollment_open', true );
		if ( ! $this->is_truthy( $enrollment_open ) ) {
			return new WP_Error(
				'enrollment_closed',
				__( 'Enrollment is closed.', 'vl-lms' ),
				[ 'status' => 422 ]
			);
		}

		// `time()` is unconditionally UTC; the WPCS sniff against
		// `current_time('timestamp', true)` is the reason we use the PHP
		// built-in directly (see WP_Core ticket #40378).
		$now = time();

		$opens_at = (string) get_post_meta( $course_id, '_vl_course_enrollment_opens_at', true );
		if ( '' !== $opens_at ) {
			$opens_ts = strtotime( $opens_at );
			if ( false !== $opens_ts && $now < $opens_ts ) {
				return new WP_Error(
					'enrollment_not_open',
					__( 'Enrollment has not opened yet.', 'vl-lms' ),
					[ 'status' => 422 ]
				);
			}
		}

		$closes_at = (string) get_post_meta( $course_id, '_vl_course_enrollment_closes_at', true );
		if ( '' !== $closes_at ) {
			$closes_ts = strtotime( $closes_at );
			if ( false !== $closes_ts && $now > $closes_ts ) {
				return new WP_Error(
					'enrollment_closed',
					__( 'Enrollment has closed.', 'vl-lms' ),
					[ 'status' => 422 ]
				);
			}
		}

		$max_students = (int) get_post_meta( $course_id, '_vl_course_max_students', true );
		if ( $max_students > 0 ) {
			$active_count = $this->repository->count_for_course( $course_id, EnrollmentStatus::ACTIVE );
			if ( $active_count >= $max_students ) {
				return new WP_Error(
					'enrollment_full',
					__( 'Course has reached capacity.', 'vl-lms' ),
					[ 'status' => 422 ]
				);
			}
		}

		return null;
	}

	private function require_published_course( int $course_id ): ?WP_Post {
		$post = get_post( $course_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( 'vl_course' !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}

	private function parse_course_id( mixed $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_numeric( $raw ) ) {
			$value = (int) $raw;
			return $value > 0 ? $value : null;
		}
		return null;
	}

	/**
	 * Loose truthiness check for boolean-shaped post meta. WP serializes
	 * checkbox-style booleans as `'1'` / `''` (or sometimes `'0'`); a
	 * registered meta with a `boolean` type can also surface as PHP `true`.
	 */
	private function is_truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			return '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
		}
		return false;
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
			__( 'You do not have permission to enroll.', 'vl-lms' ),
			[ 'status' => 403 ]
		);
	}

	private function course_not_found(): WP_Error {
		return new WP_Error(
			'course_not_found',
			__( 'Course not found.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}
}
