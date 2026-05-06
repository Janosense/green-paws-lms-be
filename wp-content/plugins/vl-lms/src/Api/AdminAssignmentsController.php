<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Api\Transformers\SubmissionTransformer;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\InvalidScoreException;
use VL\LMS\Services\Assignments\Exception\SubmissionNotFoundException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 9.4 — instructor-facing REST controller for grading assignment submissions.
 *
 * Three endpoints:
 *  - `GET  /vl/v1/admin/submissions?status=pending&page=1` — list paginated.
 *  - `POST /vl/v1/admin/submissions/{id}/grade`            — score + feedback.
 *  - `POST /vl/v1/admin/submissions/{id}/reject`           — reject + feedback.
 *
 * Cap-gated on `edit_posts` (the same cap the instructor dashboard +
 * grading-queue page use). The grading-queue admin page also routes through
 * the same service via `admin-post.php` form handlers, but the REST surface
 * lets future instructor SPAs consume the queue without screen scraping.
 *
 * Not declared `final` — tests subclass to bypass `current_user_can()`.
 *
 * @author Tymofii Synianskyi
 */
class AdminAssignmentsController {

	public const string LIST_ROUTE   = '/admin/submissions';
	public const string GRADE_ROUTE  = '/admin/submissions/(?P<id>\d+)/grade';
	public const string REJECT_ROUTE = '/admin/submissions/(?P<id>\d+)/reject';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly AssignmentSubmissionService $service,
		private readonly AssignmentSubmissionRepository $repository,
		private readonly SubmissionTransformer $transformer
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::LIST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_list' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::GRADE_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_grade' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::REJECT_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_reject' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);
	}

	public function permission_callback(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_list( WP_REST_Request $request ) {
		$status = (string) ( $request->get_param( 'status' ) ?? 'pending' );
		$page   = (int) ( $request->get_param( 'page' ) ?? 1 );
		if ( $page < 1 ) {
			$page = 1;
		}
		$per_page = 20;

		$items = $this->repository->list_by_status( $status, $page, $per_page );
		$total = $this->repository->count_by_status( $status );

		$transformed = array_map(
			fn ( $submission ): array => $this->transformer->transform( $submission ),
			$items
		);

		return $this->success(
			[
				'items'    => $transformed,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			],
			200
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_grade( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		if ( ! isset( $body['score'] ) || ! is_numeric( $body['score'] ) ) {
			return new WP_Error( 'invalid_score', 'Score is required.', [ 'status' => 422 ] );
		}
		$score    = (int) $body['score'];
		$feedback = isset( $body['feedback'] ) ? (string) $body['feedback'] : null;

		$grader = get_current_user_id();

		try {
			$result = $this->service->grade( $id, $score, $feedback, (int) $grader );
		} catch ( SubmissionNotFoundException $e ) {
			return new WP_Error( 'submission_not_found', $e->getMessage(), [ 'status' => 404 ] );
		} catch ( InvalidScoreException $e ) {
			return new WP_Error( 'invalid_score', $e->getMessage(), [ 'status' => 422 ] );
		}

		return $this->success( $this->transformer->transform( $result->submission ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reject( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		$feedback = isset( $body['feedback'] ) ? trim( (string) $body['feedback'] ) : '';
		if ( '' === $feedback ) {
			return new WP_Error( 'invalid_feedback', 'Feedback is required for rejection.', [ 'status' => 422 ] );
		}

		$grader = get_current_user_id();

		try {
			$submission = $this->service->reject( $id, $feedback, (int) $grader );
		} catch ( SubmissionNotFoundException $e ) {
			return new WP_Error( 'submission_not_found', $e->getMessage(), [ 'status' => 404 ] );
		}

		return $this->success( $this->transformer->transform( $submission ), 200 );
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
