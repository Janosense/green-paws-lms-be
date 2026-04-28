<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the personalised curriculum endpoint introduced in
 * 5.2:
 *
 * - `GET /vl/v1/learn/courses/{slug}/curriculum`
 *
 * Permission flow: cap-check happens in {@see self::permission_callback()}
 * (logged-in + `vl_view_lesson`). Course-level enrollment is enforced
 * inside the handler — this is intentionally simpler than
 * {@see Access\LessonAccessGate}: the curriculum is a navigation map, not
 * a content read, so per-lesson preview / prerequisite logic does not
 * apply here.
 *
 * Response envelope follows the existing `{ success: true, data: {...} }`
 * convention used elsewhere in the plugin.
 *
 * @author Tymofii Synianskyi
 */
final class CurriculumController {

	public const string CURRICULUM_ROUTE = '/learn/courses/(?P<slug>[a-z0-9\-]+)/curriculum';

	public const string VIEW_CAPABILITY = 'vl_view_lesson';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly EnrollmentService $enrollments,
		private readonly CurriculumTransformer $transformer
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::CURRICULUM_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);
	}

	/**
	 * Permission callback: logged in AND has `vl_view_lesson`.
	 *
	 * Returns `false` (not `WP_Error`) so WP REST emits the standard
	 * `rest_not_logged_in` (401) / `rest_forbidden` (403) responses.
	 */
	public function permission_callback( WP_REST_Request $request ): bool {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return $user->has_cap( self::VIEW_CAPABILITY );
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

		$slug   = (string) $request->get_param( 'slug' );
		$course = $this->find_published_course( $slug );
		if ( ! $course instanceof WP_Post ) {
			return new WP_Error(
				'course_not_found',
				__( 'Course not found.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $this->enrollments->has_active_access( (int) $user->ID, (int) $course->ID ) ) {
			return new WP_Error(
				'not_enrolled',
				__( 'You are not enrolled in this course.', 'vl-lms' ),
				[ 'status' => 403 ]
			);
		}

		$payload = $this->transformer->transform( $course, (int) $user->ID );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $payload,
			]
		);
	}

	private function find_published_course( string $slug ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'              => 'vl_course',
				'name'                   => $slug,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		if ( ! is_array( $posts ) || [] === $posts ) {
			return null;
		}
		return $posts[0];
	}
}
