<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Api;

use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 9.2 — preview-info REST endpoint backing the "Переглянути"
 * button on the Instructor Dashboard.
 *
 *   GET /vl/v1/admin/courses/{slug}/preview-info
 *
 * Returns the first published lesson slug under the first published
 * module of the course, plus the absolute frontend URL with
 * `?preview=1`. There are no preview tokens — the frontend's `learn`
 * middleware pairs `?preview=1` with the user's role to bypass the
 * enrollment gate, and `LessonAccessGate` already short-circuits for
 * users with `edit_post` capability on the lesson.
 *
 * Not declared `final` — unit tests subclass to bypass `get_page_by_path`.
 *
 * @author Tymofii Synianskyi
 */
class AdminPreviewController {

	public const string PREVIEW_ROUTE = '/admin/courses/(?P<slug>[a-z0-9\-]+)/preview-info';
	public const string CAP           = 'edit_posts';

	public function __construct( private readonly string $rest_namespace ) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::PREVIEW_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'preview_info' ],
				'permission_callback' => [ $this, 'permission_preview' ],
			]
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_preview() {
		if ( ! current_user_can( self::CAP ) ) {
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
	public function preview_info( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );

		$course = $this->find_post( $slug, 'vl_course' );
		if ( ! $course instanceof WP_Post ) {
			return new WP_Error(
				'course_not_found',
				__( 'Курс не знайдено.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}

		$module = $this->first_published_child( (int) $course->ID, 'vl_module' );
		$lesson = $module instanceof WP_Post
			? $this->first_published_child( (int) $module->ID, 'vl_lesson' )
			: null;

		if ( ! $lesson instanceof WP_Post ) {
			return new WP_Error(
				'no_lessons',
				__( 'Курс ще не містить опублікованих уроків.', 'vl-lms' ),
				[ 'status' => 422 ]
			);
		}

		$lesson_slug = (string) $lesson->post_name;
		$preview_url = InstructorDashboardPage::frontend_base_url() . '/learn/' . $lesson_slug . '?preview=1';

		return rest_ensure_response(
			[
				'first_lesson_slug' => $lesson_slug,
				'preview_url'       => $preview_url,
			]
		);
	}

	protected function find_post( string $slug, string $post_type ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}
		$post = get_page_by_path( $slug, OBJECT, $post_type );
		return $post instanceof WP_Post ? $post : null;
	}

	protected function first_published_child( int $parent_id, string $post_type ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post_parent'    => $parent_id,
				'posts_per_page' => 1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			]
		);
		if ( ! is_array( $posts ) || [] === $posts ) {
			return null;
		}
		$first = $posts[0];
		return $first instanceof WP_Post ? $first : null;
	}
}
