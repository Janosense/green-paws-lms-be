<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Quiz\Access\QuizAccessGate;
use VL\LMS\Quiz\AttemptStateResult;
use VL\LMS\Quiz\QuestionDeliveryTransformer;
use VL\LMS\Quiz\QuizAttemptException;
use VL\LMS\Quiz\QuizAttemptService;
use VL\LMS\Quiz\QuizCourseResolver;
use VL\LMS\Quiz\SaveAnswerResult;
use VL\LMS\Quiz\Scoring\QuizScoringEngine;
use VL\LMS\Quiz\Scoring\ScoringResult;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryQuizAnswerRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use WP_Post;

final class QuizAttemptServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&QuizAccessGate */
	private $gate;

	private InMemoryQuizAttemptRepository $attempts;
	private InMemoryQuizAnswerRepository $answers;

	/** @var Mockery\MockInterface&QuizScoringEngine */
	private $scoring;

	/** @var Mockery\MockInterface&QuestionDeliveryTransformer */
	private $delivery;

	/** @var Mockery\MockInterface&QuizCourseResolver */
	private $resolver;

	/** @var Mockery\MockInterface&CompletionPropagator */
	private $propagator;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	/** @var array<int, list<WP_Post>> */
	private array $questions_by_quiz = [];

	/** @var array<int, WP_Post> */
	private array $posts_by_id = [];

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->gate       = Mockery::mock( QuizAccessGate::class );
		$this->attempts   = new InMemoryQuizAttemptRepository();
		$this->answers    = new InMemoryQuizAnswerRepository();
		$this->scoring    = Mockery::mock( QuizScoringEngine::class );
		$this->delivery   = Mockery::mock( QuestionDeliveryTransformer::class );
		$this->resolver   = Mockery::mock( QuizCourseResolver::class );
		$this->propagator = Mockery::mock( CompletionPropagator::class );
		$this->logger     = Mockery::mock( Logger::class );

		$this->meta              = [];
		$this->questions_by_quiz = [];
		$this->posts_by_id       = [];
		$this->now               = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );

		$meta = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$meta ): mixed {
				return $meta[ $id ][ $key ] ?? '';
			}
		);

		// Default delivery just echoes question count.
		$this->delivery->shouldReceive( 'deliver_for_attempt_view' )
			->andReturnUsing(
				static fn ( array $questions ): array => array_map(
					static fn ( WP_Post $q ): array => [ 'id' => (int) $q->ID ],
					$questions
				)
			)
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service( ?\DateTimeImmutable $now = null ): QuizAttemptService {
		$frozen_now = $now ?? $this->now;
		$questions  = &$this->questions_by_quiz;
		$posts      = &$this->posts_by_id;

		return new class(
			$this->gate,
			$this->attempts,
			$this->answers,
			$this->scoring,
			$this->delivery,
			$this->resolver,
			$this->propagator,
			$this->logger,
			$questions,
			$posts,
			$frozen_now
		) extends QuizAttemptService {

			/** @var array<int, list<WP_Post>> */
			private array $questions_by_quiz_ref;

			/** @var array<int, WP_Post> */
			private array $posts_by_id_ref;

			private \DateTimeImmutable $frozen_now;

			public function __construct(
				QuizAccessGate $gate,
				InMemoryQuizAttemptRepository $attempts,
				InMemoryQuizAnswerRepository $answers,
				QuizScoringEngine $scoring,
				QuestionDeliveryTransformer $delivery,
				QuizCourseResolver $resolver,
				CompletionPropagator $propagator,
				Logger $logger,
				array &$questions_by_quiz_ref,
				array &$posts_by_id_ref,
				\DateTimeImmutable $frozen_now
			) {
				parent::__construct( $gate, $attempts, $answers, $scoring, $delivery, $resolver, $propagator, $logger );
				$this->questions_by_quiz_ref = &$questions_by_quiz_ref;
				$this->posts_by_id_ref       = &$posts_by_id_ref;
				$this->frozen_now            = $frozen_now;
			}

			protected function list_quiz_questions( int $quiz_id ): array {
				return $this->questions_by_quiz_ref[ $quiz_id ] ?? [];
			}

			protected function find_quiz_by_id( int $quiz_id ): ?WP_Post {
				return $this->posts_by_id_ref[ $quiz_id ] ?? null;
			}

			protected function current_time_utc(): \DateTimeImmutable {
				return $this->frozen_now;
			}
		};
	}

	private function quiz( int $id, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz';
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		$this->posts_by_id[ $id ] = $post;
		return $post;
	}

	private function question( int $id, string $type, int $points = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz_question';
		$post->post_status = 'publish';
		assert( $post instanceof WP_Post );
		$this->posts_by_id[ $id ] = $post;
		$this->meta[ $id ]        = [
			'_vl_question_type'   => $type,
			'_vl_question_points' => (string) $points,
		];
		return $post;
	}

	private function set_meta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $post_id ][ $key ] = $value;
	}

	public function test_start_returns_existing_in_progress_attempt(): void {
		$quiz    = $this->quiz( 101 );
		$started = $this->now;

		$this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$started,
				null,
				600,
				null,
				null,
				100,
				null,
				70,
				[ 201 ],
				$started,
				$started
			)
		);

		// Gate must NOT be invoked when an active attempt exists.
		$this->gate->shouldNotReceive( 'evaluate_for_start' );

		$result = $this->service()->start( 5, $quiz );

		self::assertInstanceOf( AttemptStateResult::class, $result );
		self::assertSame( QuizAttemptStatus::IN_PROGRESS, $result->attempt->status );
	}

	public function test_start_creates_fresh_attempt_with_snapshot(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_time_limit_seconds', '600' );
		$this->set_meta( 101, '_vl_quiz_passing_threshold', '80' );
		$this->set_meta( 101, '_vl_quiz_shuffle_questions', '0' );

		$this->questions_by_quiz[101] = [
			$this->question( 201, 'single_choice', 5 ),
			$this->question( 202, 'true_false', 3 ),
		];

		$this->gate->shouldReceive( 'evaluate_for_start' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->resolver->shouldReceive( 'find_course_id_for_quiz' )
			->with( 101 )
			->andReturn( 50 );

		$result = $this->service()->start( 5, $quiz );

		self::assertSame( QuizAttemptStatus::IN_PROGRESS, $result->attempt->status );
		self::assertSame( 600, $result->attempt->time_limit_seconds );
		self::assertSame( 80, $result->attempt->passing_threshold );
		self::assertSame( 8, $result->attempt->max_score );
		self::assertSame( [ 201, 202 ], $result->attempt->question_order );
		self::assertSame( 50, $result->attempt->course_id );
	}

	public function test_start_throws_when_gate_denies(): void {
		$quiz = $this->quiz( 101 );

		$this->gate->shouldReceive( 'evaluate_for_start' )
			->andReturn( AccessDecision::deny( 'attempts_exhausted', 50 ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->start( 5, $quiz );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'attempts_exhausted', $e->error_code );
			throw $e;
		}
	}

	public function test_save_answer_upserts_valid_single_choice(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$this->now,
				null,
				0,
				null,
				null,
				2,
				null,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$result = $this->service()->save_answer( 5, $attempt_id, 201, [ 'answer_id' => 'b' ] );

		self::assertInstanceOf( SaveAnswerResult::class, $result );
		self::assertFalse( $result->expired );
		self::assertNotNull( $result->answer );
		self::assertSame( [ 'answer_id' => 'b' ], $result->answer->answer_data );
	}

	public function test_save_answer_auto_finalizes_on_time_overrun(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				600,
				null,
				null,
				2,
				null,
				70,
				[ 201 ],
				$start,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		// "now" is 1 hour after start → far past the 600s limit.
		$now = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->andReturn( [ 201 => new ScoringResult( false, 0 ) ] );

		try {
			$this->service( $now )->save_answer( 5, $attempt_id, 201, [ 'answer_id' => 'b' ] );
			self::fail( 'Expected QuizAttemptException' );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'attempt_expired', $e->error_code );
			self::assertNotNull( $e->attempt );
			self::assertSame( QuizAttemptStatus::EXPIRED, $e->attempt->status );
		}
	}

	public function test_save_answer_rejects_invalid_question_id(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$this->now,
				null,
				0,
				null,
				null,
				2,
				null,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->save_answer( 5, $attempt_id, 999, [ 'answer_id' => 'b' ] );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'invalid_question_id', $e->error_code );
			throw $e;
		}
	}

	public function test_save_answer_rejects_invalid_payload_shape(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$this->now,
				null,
				0,
				null,
				null,
				2,
				null,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->save_answer( 5, $attempt_id, 201, [ 'wat' => 'x' ] );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'invalid_answer_data', $e->error_code );
			throw $e;
		}
	}

	public function test_save_answer_after_finalize_returns_already_finalized(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::SUBMITTED,
				$this->now,
				$this->now,
				0,
				60,
				2,
				2,
				true,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->save_answer( 5, $attempt_id, 201, [ 'answer_id' => 'b' ] );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'attempt_already_finalized', $e->error_code );
			self::assertNotNull( $e->attempt );
			throw $e;
		}
	}

	public function test_submit_scores_and_marks_passed_when_threshold_met(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_is_final_exam', '0' );
		$q1                           = $this->question( 201, 'single_choice', 6 );
		$q2                           = $this->question( 202, 'true_false', 4 );
		$this->questions_by_quiz[101] = [ $q1, $q2 ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				0,
				null,
				null,
				10,
				null,
				70,
				[ 201, 202 ],
				$start,
				$start
			)
		);
		$this->answers->upsert(
			new \VL\LMS\Domain\Quiz\QuizAnswer(
				0,
				$attempt_id,
				201,
				[ 'answer_id' => 'b' ],
				null,
				null,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->once()
			->andReturn(
				[
					201 => new ScoringResult( true, 6 ),
					202 => new ScoringResult( true, 4 ),
				]
			);

		$now    = new \DateTimeImmutable( '2026-04-29 09:30:00', new \DateTimeZone( 'UTC' ) );
		$result = $this->service( $now )->submit( 5, $attempt_id );

		self::assertSame( QuizAttemptStatus::SUBMITTED, $result->attempt->status );
		self::assertSame( 10, $result->attempt->score );
		self::assertTrue( $result->attempt->passed );
	}

	public function test_submit_marks_failed_when_below_threshold(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_is_final_exam', '0' );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 10 ) ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				0,
				null,
				null,
				10,
				null,
				70,
				[ 201 ],
				$start,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->andReturn( [ 201 => new ScoringResult( false, 0 ) ] );

		$result = $this->service()->submit( 5, $attempt_id );

		self::assertFalse( $result->attempt->passed );
		self::assertSame( 0, $result->attempt->score );
	}

	public function test_submit_idempotent_on_already_finalized(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::SUBMITTED,
				$this->now,
				$this->now,
				0,
				60,
				2,
				2,
				true,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		// Scoring engine MUST NOT be invoked on a re-submit.
		$this->scoring->shouldNotReceive( 'score_attempt' );

		$result = $this->service()->submit( 5, $attempt_id );

		self::assertSame( QuizAttemptStatus::SUBMITTED, $result->attempt->status );
	}

	public function test_submit_passing_final_exam_triggers_propagator(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_is_final_exam', '1' );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 10 ) ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				0,
				null,
				null,
				10,
				null,
				70,
				[ 201 ],
				$start,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->andReturn( [ 201 => new ScoringResult( true, 10 ) ] );

		$this->propagator->shouldReceive( 'reevaluate_course_completion' )
			->once()
			->with( 5, 50 )
			->andReturn( true );

		$this->service()->submit( 5, $attempt_id );
	}

	public function test_submit_failing_final_exam_does_not_trigger_propagator(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_is_final_exam', '1' );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 10 ) ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				0,
				null,
				null,
				10,
				null,
				70,
				[ 201 ],
				$start,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->andReturn( [ 201 => new ScoringResult( false, 0 ) ] );

		$this->propagator->shouldNotReceive( 'reevaluate_course_completion' );

		$this->service()->submit( 5, $attempt_id );
	}

	public function test_submit_swallows_propagator_error_and_logs(): void {
		$quiz = $this->quiz( 101 );
		$this->set_meta( 101, '_vl_quiz_is_final_exam', '1' );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 10 ) ];

		$start = new \DateTimeImmutable( '2026-04-29 09:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$start,
				null,
				0,
				null,
				null,
				10,
				null,
				70,
				[ 201 ],
				$start,
				$start
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->scoring->shouldReceive( 'score_attempt' )
			->andReturn( [ 201 => new ScoringResult( true, 10 ) ] );

		$this->propagator->shouldReceive( 'reevaluate_course_completion' )
			->andThrow( new \RuntimeException( 'oops' ) );

		$this->logger->shouldReceive( 'error' )->once();

		$result = $this->service()->submit( 5, $attempt_id );

		self::assertSame( QuizAttemptStatus::SUBMITTED, $result->attempt->status );
		self::assertTrue( $result->attempt->passed );
	}

	public function test_fetch_state_returns_attempt(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$this->now,
				null,
				0,
				null,
				null,
				2,
				null,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::allow( 50, false ) );

		$result = $this->service()->fetch_state( 5, $attempt_id );

		self::assertSame( $attempt_id, $result->attempt->id );
	}

	public function test_fetch_state_throws_attempt_not_found(): void {
		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->fetch_state( 5, 9999 );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'attempt_not_found', $e->error_code );
			throw $e;
		}
	}

	public function test_fetch_state_throws_when_gate_denies(): void {
		$quiz                         = $this->quiz( 101 );
		$this->questions_by_quiz[101] = [ $this->question( 201, 'single_choice', 2 ) ];

		$attempt_id = $this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::IN_PROGRESS,
				$this->now,
				null,
				0,
				null,
				null,
				2,
				null,
				0,
				[ 201 ],
				$this->now,
				$this->now
			)
		);

		$this->gate->shouldReceive( 'evaluate_for_attempt_action' )
			->andReturn( AccessDecision::deny( 'forbidden', 50 ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->fetch_state( 99, $attempt_id );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'forbidden', $e->error_code );
			throw $e;
		}
	}

	// ------------------------------------------------------------------
	// history()
	// ------------------------------------------------------------------

	private function seed_attempt(
		int $user_id,
		int $quiz_id,
		string $started_at,
		QuizAttemptStatus $status = QuizAttemptStatus::SUBMITTED,
		?bool $passed = false
	): int {
		$started = new \DateTimeImmutable( $started_at, new \DateTimeZone( 'UTC' ) );
		return $this->attempts->insert(
			new QuizAttempt(
				0,
				$user_id,
				$quiz_id,
				50,
				$status,
				$started,
				QuizAttemptStatus::IN_PROGRESS === $status ? null : $started,
				600,
				null,
				QuizAttemptStatus::IN_PROGRESS === $status ? null : 50,
				100,
				$passed,
				70,
				[ 201 ],
				$started,
				$started
			)
		);
	}

	public function test_history_returns_attempts_oldest_first(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		$third  = $this->seed_attempt( 5, 101, '2026-05-03 10:00:00' );
		$first  = $this->seed_attempt( 5, 101, '2026-05-01 10:00:00' );
		$second = $this->seed_attempt( 5, 101, '2026-05-02 10:00:00' );

		$out = $this->service()->history( 5, $this->quiz( 101 ) );

		// The repository hands back newest-first; history reverses it so
		// attempt numbering runs forward.
		self::assertSame(
			[ $first, $second, $third ],
			array_map( static fn ( QuizAttempt $a ): int => $a->id, $out )
		);
	}

	public function test_history_breaks_same_second_ties_on_id(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		// `started_at` has second granularity, so two rows can tie.
		$a = $this->seed_attempt( 5, 101, '2026-05-01 10:00:00' );
		$b = $this->seed_attempt( 5, 101, '2026-05-01 10:00:00' );

		$out = $this->service()->history( 5, $this->quiz( 101 ) );

		self::assertSame(
			[ $a, $b ],
			array_map( static fn ( QuizAttempt $x ): int => $x->id, $out )
		);
	}

	public function test_history_retains_failed_and_in_progress_attempts(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->seed_attempt( 5, 101, '2026-05-01 10:00:00', QuizAttemptStatus::SUBMITTED, false );
		$this->seed_attempt( 5, 101, '2026-05-02 10:00:00', QuizAttemptStatus::EXPIRED, false );
		$this->seed_attempt( 5, 101, '2026-05-03 10:00:00', QuizAttemptStatus::SUBMITTED, true );
		$this->seed_attempt( 5, 101, '2026-05-04 10:00:00', QuizAttemptStatus::IN_PROGRESS, null );

		$out = $this->service()->history( 5, $this->quiz( 101 ) );

		self::assertCount( 4, $out );
		self::assertSame(
			[ 'submitted', 'expired', 'submitted', 'in_progress' ],
			array_map( static fn ( QuizAttempt $a ): string => $a->status->value, $out )
		);
	}

	public function test_history_is_scoped_to_the_caller_and_the_quiz(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		$mine = $this->seed_attempt( 5, 101, '2026-05-01 10:00:00' );
		$this->seed_attempt( 6, 101, '2026-05-01 10:00:00' );
		$this->seed_attempt( 5, 102, '2026-05-01 10:00:00' );

		$out = $this->service()->history( 5, $this->quiz( 101 ) );

		self::assertCount( 1, $out );
		self::assertSame( $mine, $out[0]->id );
	}

	public function test_history_returns_empty_list_when_never_attempted(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		self::assertSame( [], $this->service()->history( 5, $this->quiz( 101 ) ) );
	}

	public function test_history_throws_the_gate_reason_on_denial(): void {
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::deny( 'not_enrolled', 50 ) );

		$this->expectException( QuizAttemptException::class );
		try {
			$this->service()->history( 5, $this->quiz( 101 ) );
		} catch ( QuizAttemptException $e ) {
			self::assertSame( 'not_enrolled', $e->error_code );
			throw $e;
		}
	}

	public function test_history_uses_the_read_gate_not_the_start_gate(): void {
		// Reading history must never consume or be blocked by the
		// max-attempts ceiling that guards starting a new one.
		$this->gate->shouldNotReceive( 'evaluate_for_start' );
		$this->gate->shouldReceive( 'evaluate_for_read' )
			->once()
			->andReturn( AccessDecision::allow( 50, false ) );

		$this->service()->history( 5, $this->quiz( 101 ) );
	}
}
