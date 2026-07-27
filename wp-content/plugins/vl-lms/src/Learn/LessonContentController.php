<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Learn\Access\LessonAccessGate;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the two authed read endpoints introduced in 5.1b:
 *
 * - `GET /vl/v1/learn/lessons/{slug}` — lesson detail + topic summaries.
 * - `GET /vl/v1/learn/topics/{slug}` — topic detail.
 *
 * Permission flow: cap-check happens in {@see self::permission_callback()}
 * (logged-in + `vl_view_lesson`). Domain access — enrollment, publish
 * status, preview bypass — flows through {@see LessonAccessGate} inside
 * the route handler so the gate's reason codes can be mapped to stable
 * HTTP-aware `WP_Error` shapes.
 *
 * Response envelope follows the existing `{ success: true, data: {...} }`
 * convention used elsewhere in the plugin.
 *
 * @author Tymofii Synianskyi
 */
final class LessonContentController {

	public const string LESSON_ROUTE = '/learn/lessons/(?P<slug>[a-z0-9\-]+)';
	public const string TOPIC_ROUTE  = '/learn/topics/(?P<slug>[a-z0-9\-]+)';

	public const string VIEW_CAPABILITY = 'vl_view_lesson';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly LessonAccessGate $gate,
		private readonly LessonContentTransformer $lesson_transformer,
		private readonly TopicContentTransformer $topic_transformer
	) {
	}

	public function register_routes(): void {
		$slug_args = [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
		];

		register_rest_route(
			$this->rest_namespace,
			self::LESSON_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_lesson' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => $slug_args,
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::TOPIC_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_topic' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => $slug_args,
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
	public function handle_lesson( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->unauthorized();
		}

		$slug   = (string) $request->get_param( 'slug' );
		$lesson = $this->find_published_post( 'vl_lesson', $slug );
		if ( ! $lesson instanceof WP_Post ) {
			return new WP_Error(
				'lesson_not_found',
				__( 'Lesson not found.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}

		$decision = $this->gate->check( (int) $user->ID, $lesson );
		if ( ! $decision->allowed ) {
			return $this->map_denied_decision( $decision );
		}

		$payload = $this->lesson_transformer->transform( $lesson, (int) $user->ID, $decision );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $payload,
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_topic( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->unauthorized();
		}

		$slug  = (string) $request->get_param( 'slug' );
		$topic = $this->find_published_post( 'vl_topic', $slug );
		if ( ! $topic instanceof WP_Post ) {
			return new WP_Error(
				'topic_not_found',
				__( 'Topic not found.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}

		$decision = $this->gate->check( (int) $user->ID, $topic );
		if ( ! $decision->allowed ) {
			return $this->map_denied_decision( $decision );
		}

		$payload = $this->topic_transformer->transform( $topic, (int) $user->ID, $decision );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $payload,
			]
		);
	}

	private function find_published_post( string $post_type, string $slug ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'              => $post_type,
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

	/**
	 * Note the progression arm is **403, not 404**. A 404 would land in
	 * the frontend's `NOT_FOUND_CODES` set and render "lesson not found"
	 * to an enrolled learner looking at a lesson that plainly exists in
	 * their curriculum rail. 403 matches `not_enrolled`, which is the
	 * same authorization shape, and carries the lock payload so the
	 * client can name the quiz that opens it.
	 */
	private function map_denied_decision( AccessDecision $decision ): WP_Error {
		return match ( $decision->reason ) {
			'parent_not_found'          => new WP_Error(
				'parent_not_found',
				__( 'Parent not found.', 'vl-lms' ),
				[ 'status' => 404 ]
			),
			'course_unpublished'        => new WP_Error(
				'course_unpublished',
				__( 'Course is not published.', 'vl-lms' ),
				[ 'status' => 404 ]
			),
			'not_enrolled'              => new WP_Error(
				'not_enrolled',
				__( 'You must be enrolled to view this content.', 'vl-lms' ),
				[ 'status' => 403 ]
			),
			'progression_locked'        => new WP_Error(
				'progression_locked',
				__( 'This part of the course is not available yet.', 'vl-lms' ),
				[
					'status' => 403,
					'lock'   => $decision->lock?->to_array(),
				]
			),
			'course_quizzes_incomplete' => new WP_Error(
				'course_quizzes_incomplete',
				__( 'Pass the remaining course quizzes to unlock this.', 'vl-lms' ),
				[
					'status' => 403,
					'lock'   => $decision->lock?->to_array(),
				]
			),
			default                     => new WP_Error(
				'access_denied',
				__( 'Access denied.', 'vl-lms' ),
				[ 'status' => 403 ]
			),
		};
	}

	private function unauthorized(): WP_Error {
		return new WP_Error(
			'unauthorized',
			__( 'Authentication required.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}
}
