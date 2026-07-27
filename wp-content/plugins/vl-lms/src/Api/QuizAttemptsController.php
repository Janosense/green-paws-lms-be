<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Api\Transformers\QuizAttemptHistoryTransformer;
use VL\LMS\Api\Transformers\QuizAttemptStateTransformer;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Quiz\AttemptStateResult;
use VL\LMS\Quiz\QuizAttemptException;
use VL\LMS\Quiz\QuizAttemptService;
use VL\LMS\Quiz\SaveAnswerResult;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the quiz-attempt lifecycle (Phase 6.1).
 *
 * Five endpoints share one service ({@see QuizAttemptService}) and one
 * permission callback (`vl_submit_quiz` cap + auth). Ownership and
 * enrollment are enforced inside the service via
 * {@see \VL\LMS\Quiz\Access\QuizAccessGate}, never duplicated here.
 *
 * The collection path `/quizzes/{slug}/attempts` carries two methods:
 * POST starts (or resumes) an attempt, GET returns the caller's attempt
 * history. History is read through
 * {@see QuizAttemptHistoryTransformer} rather than mapping the player's
 * {@see QuizAttemptStateTransformer} over the list — see that class for
 * why.
 *
 * Error mapping: every code in the §7.3 table from the phase spec maps
 * to a `WP_Error` with the appropriate HTTP status. The two soft-error
 * codes (`attempt_expired`, `attempt_already_finalized`) carry the
 * finalized attempt in `data.attempt` so the client can pivot to the
 * results screen without a re-fetch.
 *
 * @author Tymofii Synianskyi
 */
final class QuizAttemptsController {

	public const string SUBMIT_CAPABILITY = 'vl_submit_quiz';
	public const string START_ROUTE       = '/quizzes/(?P<slug>[a-z0-9\-]+)/attempts';
	public const string FETCH_ROUTE       = '/quizzes/attempts/(?P<id>\d+)';
	public const string SAVE_ANSWER_ROUTE = '/quizzes/attempts/(?P<id>\d+)/answers/(?P<question_id>\d+)';
	public const string SUBMIT_ROUTE      = '/quizzes/attempts/(?P<id>\d+)/submit';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly QuizAttemptService $service,
		private readonly RestAuthenticator $authenticator,
		private readonly Logger $logger,
		private readonly QuizAttemptStateTransformer $attempt_transformer,
		private readonly QuizAttemptHistoryTransformer $history_transformer
	) {
	}

	public function register_routes(): void {
		// POST (start) and GET (history) share one collection path, so they
		// are registered as two endpoints in a single call. Two separate
		// `register_rest_route()` calls on the same route would also merge,
		// but only as a side effect of WP_REST_Server's array_merge — being
		// explicit keeps the pair visible as one surface.
		register_rest_route(
			$this->rest_namespace,
			self::START_ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle_start' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'slug' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						],
					],
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'handle_history' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'slug' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::FETCH_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_fetch' ],
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
			self::SAVE_ANSWER_ROUTE,
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'handle_save_answer' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id'          => [
						'required' => true,
						'type'     => 'integer',
					],
					'question_id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::SUBMIT_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_submit' ],
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

	public function permission_callback( WP_REST_Request $request ): bool {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return user_can( $user, self::SUBMIT_CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_start( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401, __( 'Authentication required.', 'vl-lms' ) );
		}

		$slug = (string) $request->get_param( 'slug' );
		$quiz = $this->find_published_quiz( $slug );
		if ( null === $quiz ) {
			return $this->error( 'quiz_not_found', 404, __( 'Quiz not found.', 'vl-lms' ) );
		}

		try {
			$result = $this->service->start( (int) $user->ID, $quiz );
		} catch ( QuizAttemptException $e ) {
			return $this->map_exception( $e );
		}

		$status = $result->created ? 201 : 200;

		return $this->success( $this->envelope_full_state( $result ), $status );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_fetch( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401, __( 'Authentication required.', 'vl-lms' ) );
		}

		$id = (int) $request->get_param( 'id' );
		try {
			$result = $this->service->fetch_state( (int) $user->ID, $id );
		} catch ( QuizAttemptException $e ) {
			return $this->map_exception( $e );
		}

		return $this->success( $this->envelope_full_state( $result ), 200 );
	}

	/**
	 * `GET /quizzes/{slug}/attempts` — the caller's own attempt log.
	 *
	 * Always 200 with a well-formed envelope, including for a learner who
	 * has never sat the quiz: an empty `attempts` array is the honest
	 * answer to "show me my attempts", not a 404. Only access failures
	 * (unknown quiz, not enrolled) are errors.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_history( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401, __( 'Authentication required.', 'vl-lms' ) );
		}

		$slug = (string) $request->get_param( 'slug' );
		$quiz = $this->find_published_quiz( $slug );
		if ( null === $quiz ) {
			return $this->error( 'quiz_not_found', 404, __( 'Тест не знайдено.', 'vl-lms' ) );
		}

		try {
			$attempts = $this->service->history( (int) $user->ID, $quiz );
		} catch ( QuizAttemptException $e ) {
			return $this->map_exception( $e );
		}

		return $this->success(
			$this->history_transformer->transform( (int) $quiz->ID, $attempts ),
			200
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_save_answer( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401, __( 'Authentication required.', 'vl-lms' ) );
		}

		$id          = (int) $request->get_param( 'id' );
		$question_id = (int) $request->get_param( 'question_id' );

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! array_key_exists( 'answer_data', $body ) || ! is_array( $body['answer_data'] ) ) {
			return $this->error(
				'invalid_answer_data',
				422,
				__( 'Поле "answer_data" обовʼязкове і має бути обʼєктом.', 'vl-lms' )
			);
		}

		try {
			$result = $this->service->save_answer( (int) $user->ID, $id, $question_id, $body['answer_data'] );
		} catch ( QuizAttemptException $e ) {
			return $this->map_exception( $e );
		}

		return $this->success( $this->envelope_save( $result ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401, __( 'Authentication required.', 'vl-lms' ) );
		}

		$id = (int) $request->get_param( 'id' );
		try {
			$result = $this->service->submit( (int) $user->ID, $id );
		} catch ( QuizAttemptException $e ) {
			return $this->map_exception( $e );
		}

		return $this->success( $this->envelope_full_state( $result ), 200 );
	}

	private function find_published_quiz( string $slug ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'              => 'vl_quiz',
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
	 * @return array<string, mixed>
	 */
	private function envelope_full_state( AttemptStateResult $result ): array {
		return [
			'attempt'       => $this->envelope_attempt( $result->attempt ),
			'questions'     => $result->questions,
			'saved_answers' => array_map(
				fn ( QuizAnswer $a ): array => $this->envelope_answer( $a, $result->attempt ),
				$result->saved_answers
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function envelope_save( SaveAnswerResult $result ): array {
		$out = [
			'expired' => $result->expired,
			'answer'  => null === $result->answer
				? null
				: $this->envelope_answer( $result->answer, $result->attempt ),
		];
		if ( $result->expired ) {
			$out['attempt'] = $this->envelope_attempt( $result->attempt );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function envelope_attempt( QuizAttempt $attempt ): array {
		return $this->attempt_transformer->transform_attempt( $attempt );
	}

	/**
	 * Wire shape for one persisted answer. Scoring fields surface only on
	 * a finalized attempt; in-progress responses return only the payload
	 * + timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private function envelope_answer( QuizAnswer $answer, QuizAttempt $attempt ): array {
		$out = [
			'question_id' => $answer->question_id,
			'answer_data' => $answer->answer_data,
			'answered_at' => $answer->answered_at->format( \DateTimeInterface::ATOM ),
		];

		// Only include scoring on finalized attempts. The reveal policy
		// (NEVER / AFTER_SUBMIT / AFTER_PASS) is already applied to the
		// `questions` payload by QuestionDeliveryTransformer; per-answer
		// scoring is included alongside on submitted/expired attempts so
		// the client can render results without a second pass through
		// the questions array. Strict reveal-gating of `is_correct` for
		// AFTER_PASS happens at the per-question level — clients are
		// expected to align by question_id.
		if ( QuizAttemptStatus::IN_PROGRESS !== $attempt->status ) {
			$out['is_correct']     = $answer->is_correct;
			$out['points_awarded'] = $answer->points_awarded;
		}

		return $out;
	}

	private function map_exception( QuizAttemptException $e ): WP_Error {
		$code = $e->error_code;

		if ( 'internal_error' === $code ) {
			$this->logger->error(
				'QuizAttemptService internal error',
				[ 'message' => $e->getMessage() ]
			);
		}

		// Soft-error pair: client gets the finalized attempt body alongside
		// the error so it can pivot to results without a re-fetch.
		if ( ( 'attempt_expired' === $code || 'attempt_already_finalized' === $code ) && null !== $e->attempt ) {
			return new WP_Error(
				$code,
				$this->message_for( $code ),
				[
					'status'  => 409,
					'attempt' => $this->envelope_attempt( $e->attempt ),
				]
			);
		}

		[ $status, $message ] = $this->status_and_message_for( $code );
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * @return array{0: int, 1: string}
	 */
	private function status_and_message_for( string $code ): array {
		return match ( $code ) {
			'unauthenticated'           => [ 401, __( 'Authentication required.', 'vl-lms' ) ],
			'forbidden'                 => [ 403, __( 'Доступ заборонено.', 'vl-lms' ) ],
			'not_enrolled'              => [ 403, __( 'Ви не зараховані на цей курс.', 'vl-lms' ) ],
			'quiz_not_found'            => [ 404, __( 'Тест не знайдено.', 'vl-lms' ) ],
			'quiz_not_published'        => [ 404, __( 'Тест не знайдено.', 'vl-lms' ) ],
			'quiz_not_in_course'        => [ 404, __( 'Тест не належить жодному курсу.', 'vl-lms' ) ],
			'attempts_exhausted'        => [ 409, __( 'Ліміт спроб вичерпано.', 'vl-lms' ) ],
			'attempt_not_found'         => [ 404, __( 'Спробу не знайдено.', 'vl-lms' ) ],
			'attempt_expired'           => [ 409, __( 'Час спроби вичерпано.', 'vl-lms' ) ],
			'attempt_already_finalized' => [ 409, __( 'Спробу вже завершено.', 'vl-lms' ) ],
			'invalid_question_id'       => [ 422, __( 'Невірний ідентифікатор питання.', 'vl-lms' ) ],
			'invalid_answer_data'       => [ 422, __( 'Дані відповіді не пройшли валідацію.', 'vl-lms' ) ],
			default                     => [ 500, __( 'Внутрішня помилка.', 'vl-lms' ) ],
		};
	}

	private function message_for( string $code ): string {
		return $this->status_and_message_for( $code )[1];
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

	private function error( string $code, int $status, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
