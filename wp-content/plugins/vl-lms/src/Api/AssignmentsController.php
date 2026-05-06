<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Api\Transformers\SubmissionTransformer;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\AssignmentSubmissionFailedException;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Phase 9.4 — student-facing REST controller for assignment submissions.
 *
 * Two endpoints:
 *  - `POST /vl/v1/assignments/{slug}/submissions` — create or idempotently
 *    update a pending submission.
 *  - `GET /vl/v1/assignments/{slug}/submissions/me` — fetch the caller's
 *    own row.
 *
 * Permission shape mirrors the quiz-attempt controller: JWT-authenticated
 * + `vl_view_lesson` capability. The owning-course enrollment gate lives
 * inside the service layer, so a non-enrolled user gets 403
 * `not_enrolled` from {@see AssignmentSubmissionService::submit()}.
 *
 * Not declared `final` — tests subclass to bypass `get_posts()`.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentsController {

	public const string VIEW_CAPABILITY = 'vl_view_lesson';
	public const string SUBMIT_ROUTE    = '/assignments/(?P<slug>[a-z0-9\-]+)/submissions';
	public const string FETCH_ROUTE     = '/assignments/(?P<slug>[a-z0-9\-]+)/submissions/me';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly AssignmentSubmissionService $service,
		private readonly AssignmentSubmissionRepository $repository,
		private readonly SubmissionTransformer $transformer,
		private readonly RestAuthenticator $authenticator
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::SUBMIT_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_submit' ],
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

		register_rest_route(
			$this->rest_namespace,
			self::FETCH_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_fetch_mine' ],
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

	public function permission_callback( WP_REST_Request $request ): bool {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return user_can( $user, self::VIEW_CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'unauthenticated', 'Authentication required.', [ 'status' => 401 ] );
		}

		$slug       = (string) $request->get_param( 'slug' );
		$assignment = $this->find_published_assignment( $slug );
		if ( null === $assignment ) {
			return new WP_Error( 'assignment_not_found', 'Assignment not found.', [ 'status' => 404 ] );
		}

		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : [];
		$text      = isset( $body['submission_text'] ) ? (string) $body['submission_text'] : null;
		$file_url  = isset( $body['submission_file_url'] ) ? (string) $body['submission_file_url'] : null;
		$file_name = isset( $body['submission_file_name'] ) ? (string) $body['submission_file_name'] : null;

		$existed_before = null !== $this->find_existing_for_user( (int) $assignment->ID, (int) $user->ID );

		try {
			$submission = $this->service->submit(
				(int) $assignment->ID,
				(int) $user->ID,
				$text,
				$file_url,
				$file_name
			);
		} catch ( AssignmentSubmissionFailedException $e ) {
			return $this->map_failure( $e );
		}

		$status = $existed_before ? 200 : 201;
		return $this->success( $this->transformer->transform( $submission ), $status );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_fetch_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'unauthenticated', 'Authentication required.', [ 'status' => 401 ] );
		}

		$slug       = (string) $request->get_param( 'slug' );
		$assignment = $this->find_published_assignment( $slug );
		if ( null === $assignment ) {
			return new WP_Error( 'assignment_not_found', 'Assignment not found.', [ 'status' => 404 ] );
		}

		$submission = $this->find_existing_for_user( (int) $assignment->ID, (int) $user->ID );
		if ( null === $submission ) {
			return new WP_Error( 'no_submission', 'No submission exists for this assignment.', [ 'status' => 404 ] );
		}

		return $this->success( $this->transformer->transform( $submission ), 200 );
	}

	protected function find_published_assignment( string $slug ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'              => 'vl_assignment',
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
		return $posts[0] instanceof WP_Post ? $posts[0] : null;
	}

	protected function find_existing_for_user( int $assignment_id, int $user_id ): ?Submission {
		return $this->repository->find_by_assignment_user( $assignment_id, $user_id );
	}

	private function map_failure( AssignmentSubmissionFailedException $e ): WP_Error {
		$status = match ( $e->error_code ) {
			AssignmentSubmissionFailedException::NOT_ENROLLED          => 403,
			AssignmentSubmissionFailedException::INVALID_SUBMISSION    => 422,
			AssignmentSubmissionFailedException::SUBMISSION_LOCKED     => 409,
			AssignmentSubmissionFailedException::COURSE_NOT_RESOLVABLE => 422,
			default                                                    => 500,
		};
		return new WP_Error( $e->error_code, $e->getMessage(), [ 'status' => $status ] );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function success( array $data, int $status ): WP_REST_Response {
		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => $data,
			]
		);
		$response->set_status( $status );
		return $response;
	}
}
