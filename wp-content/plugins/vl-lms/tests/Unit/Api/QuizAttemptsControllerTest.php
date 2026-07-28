<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\QuizAttemptsController;
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
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class QuizAttemptsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&QuizAttemptService */
	private $service;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private InMemoryQuizAttemptRepository $repo;

	private InMemoryEnrollmentRepository $enrollments;

	private TestableQuizAttemptStateTransformer $transformer;

	private TestableQuizAttemptHistoryTransformer $history_transformer;

	private QuizAttemptsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				$response->status = 200;
				return $response;
			}
		);

		$this->service       = Mockery::mock( QuizAttemptService::class );
		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->logger        = Mockery::mock( Logger::class );
		$this->repo          = new InMemoryQuizAttemptRepository();
		$this->enrollments   = new InMemoryEnrollmentRepository();
		$this->transformer   = new TestableQuizAttemptStateTransformer( $this->repo );

		$this->history_transformer = new TestableQuizAttemptHistoryTransformer();

		$this->controller = new QuizAttemptsController(
			'vl/v1',
			$this->service,
			$this->authenticator,
			$this->logger,
			$this->transformer,
			$this->history_transformer,
			$this->enrollments
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function user( int $id ): WP_User {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $id;
		assert( $user instanceof WP_User );
		return $user;
	}

	private function request( array $params = [], array $body = [] ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => $params[ $name ] ?? null
		);
		$req->shouldReceive( 'get_json_params' )->andReturn( $body );
		assert( $req instanceof WP_REST_Request );
		return $req;
	}

	private function quiz( int $id ): \WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz';
		$post->post_status = 'publish';
		assert( $post instanceof \WP_Post );
		return $post;
	}

	private function attempt(
		int $id = 17,
		QuizAttemptStatus $status = QuizAttemptStatus::IN_PROGRESS,
		?bool $passed = null,
		?int $score = null,
		string $started_at = '2026-04-29 10:00:00'
	): QuizAttempt {
		$started = new \DateTimeImmutable( $started_at, new \DateTimeZone( 'UTC' ) );
		return new QuizAttempt(
			$id,
			5,
			101,
			50,
			$status,
			$started,
			QuizAttemptStatus::IN_PROGRESS === $status ? null : $started,
			0,
			null,
			$score,
			100,
			$passed,
			70,
			[ 201, 202 ],
			$started,
			$started
		);
	}

	public function test_handle_start_unauthenticated_returns_401(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'unauthenticated', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_handle_start_quiz_not_found_returns_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [] );

		$result = $this->controller->handle_start( $this->request( [ 'slug' => 'unknown' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'quiz_not_found', $result->get_error_code() );
	}

	public function test_handle_start_returns_201_for_fresh_attempt(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$state = new AttemptStateResult( $this->attempt(), [], [], true );
		$this->service->shouldReceive( 'start' )->once()->andReturn( $state );

		$response = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->status );
	}

	public function test_handle_start_returns_200_for_idempotent_resume(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$state = new AttemptStateResult( $this->attempt(), [], [], false );
		$this->service->shouldReceive( 'start' )->once()->andReturn( $state );

		$response = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		self::assertSame( 200, $response->status );
	}

	public function test_handle_start_maps_attempts_exhausted_to_409(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->service->shouldReceive( 'start' )
			->andThrow( new QuizAttemptException( 'attempts_exhausted' ) );

		$result = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'attempts_exhausted', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_handle_fetch_returns_200_with_attempt_envelope(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$state = new AttemptStateResult( $this->attempt( 17 ), [], [], false );
		$this->service->shouldReceive( 'fetch_state' )->with( 5, 17 )->andReturn( $state );

		$response = $this->controller->handle_fetch( $this->request( [ 'id' => 17 ] ) );

		self::assertSame( 200, $response->status );
		$body = $response->get_data();
		self::assertTrue( $body['success'] );
		self::assertSame( 17, $body['data']['attempt']['id'] );
	}

	public function test_handle_fetch_attempt_not_found_returns_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$this->service->shouldReceive( 'fetch_state' )
			->andThrow( new QuizAttemptException( 'attempt_not_found' ) );

		$result = $this->controller->handle_fetch( $this->request( [ 'id' => 9999 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'attempt_not_found', $result->get_error_code() );
	}

	public function test_handle_save_answer_invalid_body_returns_422(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$result = $this->controller->handle_save_answer(
			$this->request(
				[
					'id'          => 17,
					'question_id' => 201,
				],
				[ 'wat' => 1 ]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'invalid_answer_data', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_handle_save_answer_happy_path_returns_envelope(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$now    = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );
		$answer = new QuizAnswer( 1, 17, 201, [ 'answer_id' => 'b' ], null, null, $now );
		$result = new SaveAnswerResult( $this->attempt(), $answer, false );

		$this->service->shouldReceive( 'save_answer' )
			->with( 5, 17, 201, [ 'answer_id' => 'b' ] )
			->andReturn( $result );

		$response = $this->controller->handle_save_answer(
			$this->request(
				[
					'id'          => 17,
					'question_id' => 201,
				],
				[ 'answer_data' => [ 'answer_id' => 'b' ] ]
			)
		);

		self::assertSame( 200, $response->status );
		$body = $response->get_data();
		self::assertFalse( $body['data']['expired'] );
		self::assertSame( 201, $body['data']['answer']['question_id'] );
	}

	public function test_handle_save_answer_attempt_expired_returns_409_with_attempt_payload(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$expired_attempt = $this->attempt( 17, QuizAttemptStatus::EXPIRED, false, 0 );

		$this->service->shouldReceive( 'save_answer' )
			->andThrow( new QuizAttemptException( 'attempt_expired', '', $expired_attempt ) );

		$result = $this->controller->handle_save_answer(
			$this->request(
				[
					'id'          => 17,
					'question_id' => 201,
				],
				[ 'answer_data' => [ 'answer_id' => 'b' ] ]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'attempt_expired', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertArrayHasKey( 'attempt', $data );
		self::assertSame( 'expired', $data['attempt']['status'] );
	}

	public function test_handle_submit_returns_200_with_finalized_attempt(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$state = new AttemptStateResult(
			$this->attempt( 17, QuizAttemptStatus::SUBMITTED, true, 90 ),
			[],
			[],
			false
		);
		$this->service->shouldReceive( 'submit' )->with( 5, 17 )->andReturn( $state );

		$response = $this->controller->handle_submit( $this->request( [ 'id' => 17 ] ) );

		self::assertSame( 200, $response->status );
		$body = $response->get_data();
		self::assertSame( 'submitted', $body['data']['attempt']['status'] );
		self::assertTrue( $body['data']['attempt']['passed'] );
	}

	public function test_handle_submit_forbidden_returns_403(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$this->service->shouldReceive( 'submit' )
			->andThrow( new QuizAttemptException( 'forbidden' ) );

		$result = $this->controller->handle_submit( $this->request( [ 'id' => 17 ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_callback_denies_when_user_lacks_cap(): void {
		$user = $this->user( 5 );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		Functions\when( 'user_can' )->alias(
			static fn ( WP_User $u, string $cap ): bool => 'vl_submit_quiz' !== $cap
		);

		self::assertFalse( $this->controller->permission_callback( $this->request() ) );
	}

	public function test_permission_callback_allows_authed_user_with_cap(): void {
		$user = $this->user( 5 );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		Functions\when( 'user_can' )->justReturn( true );

		self::assertTrue( $this->controller->permission_callback( $this->request() ) );
	}

	public function test_permission_callback_denies_when_unauthenticated(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		self::assertFalse( $this->controller->permission_callback( $this->request() ) );
	}

	public function test_response_includes_quiz_title_attempts_remaining_best_score(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->transformer->titles            = [ 101 => 'Підсумкова атестація' ];
		$this->transformer->max_attempts_meta = [ 101 => 3 ];

		$now = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );
		$this->repo->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::SUBMITTED,
				$now,
				$now,
				0,
				null,
				75,
				100,
				true,
				70,
				[ 201 ],
				$now,
				$now
			)
		);

		$state = new AttemptStateResult( $this->attempt(), [], [], true );
		$this->service->shouldReceive( 'start' )->once()->andReturn( $state );

		$response = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$body = $response->get_data();
		self::assertSame( 'Підсумкова атестація', $body['data']['attempt']['quiz_title'] );
		self::assertSame( 2, $body['data']['attempt']['attempts_remaining'] );
		self::assertSame( 75.0, $body['data']['attempt']['best_score'] );
	}

	public function test_attempts_remaining_is_null_when_unlimited(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->transformer->titles            = [ 101 => 'Тест без ліміту' ];
		$this->transformer->max_attempts_meta = [ 101 => 0 ];

		$state = new AttemptStateResult( $this->attempt(), [], [], true );
		$this->service->shouldReceive( 'start' )->once()->andReturn( $state );

		$response = $this->controller->handle_start( $this->request( [ 'slug' => 'q' ] ) );

		$body = $response->get_data();
		self::assertNull( $body['data']['attempt']['attempts_remaining'] );
		self::assertNull( $body['data']['attempt']['best_score'] );
	}

	// ------------------------------------------------------------------
	// handle_history
	// ------------------------------------------------------------------

	public function test_handle_history_returns_200_with_attempt_log(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->history_transformer->titles = [ 101 => 'Підсумковий тест' ];

		$this->service->shouldReceive( 'history' )
			->once()
			->with( 5, Mockery::type( \WP_Post::class ) )
			->andReturn(
				[
					$this->attempt( 11, QuizAttemptStatus::SUBMITTED, false, 40 ),
					$this->attempt( 12, QuizAttemptStatus::SUBMITTED, true, 80 ),
				]
			);

		$response = $this->controller->handle_history( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->status );

		$body = $response->get_data();
		self::assertTrue( $body['success'] );
		self::assertSame( 'Підсумковий тест', $body['data']['quiz_title'] );
		self::assertSame( 2, $body['data']['total_attempts'] );
		self::assertTrue( $body['data']['passed'] );
		self::assertSame( 2, $body['data']['passed_on_attempt'] );
		self::assertSame( [ 11, 12 ], array_column( $body['data']['attempts'], 'id' ) );
		self::assertSame( [ 1, 2 ], array_column( $body['data']['attempts'], 'attempt_number' ) );
	}

	public function test_handle_history_passes_progress_reset_epoch_to_summary(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		// A pass before the reset, a fail after it. Attempts snapshot
		// course 50; the enrollment row carries the epoch between the two.
		$this->enrollments->seed(
			[
				'user_id'           => 5,
				'course_id'         => 50,
				'progress_reset_at' => '2026-04-29 00:00:00',
			]
		);
		$this->service->shouldReceive( 'history' )
			->once()
			->andReturn(
				[
					$this->attempt( 11, QuizAttemptStatus::SUBMITTED, true, 90, '2026-04-28 10:00:00' ),
					$this->attempt( 12, QuizAttemptStatus::SUBMITTED, false, 40, '2026-04-30 10:00:00' ),
				]
			);

		$response = $this->controller->handle_history( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data()['data'];

		// Rows stay all-time with all-time numbering…
		self::assertSame( [ 11, 12 ], array_column( $data['attempts'], 'id' ) );
		self::assertSame( [ 1, 2 ], array_column( $data['attempts'], 'attempt_number' ) );
		self::assertSame( 2, $data['total_attempts'] );
		// …but the summary counts post-reset sittings only: the pre-reset
		// pass no longer passes the quiz, and best_score is the post-reset 40.
		self::assertFalse( $data['passed'] );
		self::assertNull( $data['passed_on_attempt'] );
		self::assertSame( 1, $data['graded_attempts'] );
		self::assertSame( 40.0, $data['best_score'] );
	}

	public function test_handle_history_returns_200_with_empty_log_when_never_attempted(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->service->shouldReceive( 'history' )->once()->andReturn( [] );

		$response = $this->controller->handle_history( $this->request( [ 'slug' => 'q' ] ) );

		// "I have never sat this quiz" is an answer, not a 404.
		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->status );
		self::assertSame( [], $response->get_data()['data']['attempts'] );
	}

	public function test_handle_history_returns_401_when_unauthenticated(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->handle_history( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'unauthenticated', $result->get_error_code() );
	}

	public function test_handle_history_returns_404_for_unknown_quiz(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [] );

		$result = $this->controller->handle_history( $this->request( [ 'slug' => 'unknown' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'quiz_not_found', $result->get_error_code() );
	}

	public function test_handle_history_maps_service_denial_to_403(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		Functions\when( 'get_posts' )->justReturn( [ $this->quiz( 101 ) ] );

		$this->service->shouldReceive( 'history' )
			->once()
			->andThrow( new QuizAttemptException( 'not_enrolled' ) );

		$result = $this->controller->handle_history( $this->request( [ 'slug' => 'q' ] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}
}
