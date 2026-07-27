<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\QuizRef;
use VL\LMS\Quiz\Access\QuizAccessGate;
use VL\LMS\Quiz\QuizCourseResolver;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use VL\LMS\Tests\Fixtures\StubProgressionGate;
use WP_Post;

final class QuizAccessGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollment_repo;
	private EnrollmentService $enrollments;
	private InMemoryQuizAttemptRepository $attempts;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	private ?int $resolved_course_id = 50;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );
		$this->attempts        = new InMemoryQuizAttemptRepository();
		$this->meta            = [];

		$meta = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$meta ): mixed {
				return $meta[ $id ][ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function gate( ?StubProgressionGate $progression = null ): QuizAccessGate {
		$resolver_course_id = &$this->resolved_course_id;
		$resolver           = new class( $resolver_course_id ) extends QuizCourseResolver {

			/** @var int|null */
			private $course_id_ref;

			public function __construct( ?int &$course_id ) {
				$this->course_id_ref = &$course_id;
			}

			public function find_course_id_for_quiz( int $quiz_id ): ?int {
				return $this->course_id_ref;
			}
		};

		return new QuizAccessGate(
			$this->enrollments,
			$this->attempts,
			$resolver,
			$progression ?? new StubProgressionGate()
		);
	}

	private function quiz_post( int $id, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz';
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function attempt(
		int $id = 17,
		int $user_id = 5,
		int $course_id = 50
	): QuizAttempt {
		$now = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new QuizAttempt(
			$id,
			$user_id,
			101,
			$course_id,
			QuizAttemptStatus::IN_PROGRESS,
			$now,
			null,
			0,
			null,
			null,
			100,
			null,
			0,
			[],
			$now,
			$now
		);
	}

	private function set_meta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $post_id ][ $key ] = $value;
	}

	public function test_start_denies_a_locked_quiz_and_carries_the_lock(): void {
		$this->seed_active_enrollment( 5, 50 );

		$lock        = LockState::progression( new QuizRef( 77, 'module-1-test', 'Тест до модуля 1' ) );
		$progression = new StubProgressionGate( $lock );

		$decision = $this->gate( $progression )->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'progression_locked', $decision->reason );
		self::assertSame( 50, $decision->course_id );
		self::assertSame( $lock, $decision->lock );
		self::assertSame(
			[
				[
					'user_id'   => 5,
					'course_id' => 50,
					'kind'      => CurriculumStop::KIND_QUIZ,
					'entity_id' => 101,
				],
			],
			$progression->calls
		);
	}

	public function test_start_denies_with_the_course_prerequisites_reason(): void {
		$this->seed_active_enrollment( 5, 50 );

		$progression = new StubProgressionGate( LockState::course_quizzes_incomplete( 3 ) );

		$decision = $this->gate( $progression )->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'course_quizzes_incomplete', $decision->reason );
		self::assertSame( 3, $decision->lock?->remaining_quiz_count );
		self::assertNull( $decision->lock?->blocking_quiz );
	}

	/**
	 * "You cannot open this yet" is more useful than "you have used all
	 * your attempts" for a quiz the learner was never allowed to begin, so
	 * the lock is evaluated ahead of the max-attempts ceiling.
	 */
	public function test_start_reports_the_lock_before_the_attempts_ceiling(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '1' );
		$this->seed_attempts( 5, 101, 50, 1 );

		$progression = new StubProgressionGate(
			LockState::progression( new QuizRef( 77, 'blocker', 'Blocker' ) )
		);

		$decision = $this->gate( $progression )->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertSame( 'progression_locked', $decision->reason );
	}

	/**
	 * Attempt history is a record of work the learner already did. An
	 * editor switching on a gate upstream must not retroactively 403 it
	 * away, so the read path never consults the progression gate.
	 */
	public function test_read_never_consults_the_progression_gate(): void {
		$this->seed_active_enrollment( 5, 50 );

		$progression = new StubProgressionGate(
			LockState::progression( new QuizRef( 77, 'blocker', 'Blocker' ) )
		);

		$decision = $this->gate( $progression )->evaluate_for_read( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
		self::assertFalse( $progression->was_called() );
	}

	/**
	 * Locking an in-flight attempt would strand it — `save` and `submit`
	 * would both start failing on an attempt the server itself issued.
	 */
	public function test_attempt_action_never_consults_the_progression_gate(): void {
		$this->seed_active_enrollment( 5, 50 );

		$progression = new StubProgressionGate(
			LockState::progression( new QuizRef( 77, 'blocker', 'Blocker' ) )
		);

		$decision = $this->gate( $progression )->evaluate_for_attempt_action( 5, $this->attempt( 1, 5, 50 ) );

		self::assertTrue( $decision->allowed );
		self::assertFalse( $progression->was_called() );
	}

	public function test_start_allows_when_no_lock_applies(): void {
		$this->seed_active_enrollment( 5, 50 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
		self::assertNull( $decision->lock );
	}

	public function test_start_denies_when_quiz_unpublished(): void {
		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101, 'draft' ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_published', $decision->reason );
	}

	public function test_start_denies_when_resolver_returns_null(): void {
		$this->resolved_course_id = null;

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_in_course', $decision->reason );
	}

	public function test_start_denies_when_not_enrolled(): void {
		// No enrollment seeded → has_active_access returns false.
		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_start_denies_when_max_attempts_reached(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '2' );
		$this->seed_attempts( 5, 101, 50, 2 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'attempts_exhausted', $decision->reason );
	}

	public function test_start_allows_when_under_max_attempts(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '3' );
		$this->seed_attempts( 5, 101, 50, 1 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_start_treats_zero_max_attempts_as_unlimited(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '0' );
		// Seed many attempts — gate must not consult the count.
		$this->seed_attempts( 5, 101, 50, 99 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
	}

	public function test_attempt_action_denies_when_user_mismatch(): void {
		$decision = $this->gate()->evaluate_for_attempt_action( 99, $this->attempt() );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'forbidden', $decision->reason );
	}

	public function test_attempt_action_denies_when_not_enrolled(): void {
		$decision = $this->gate()->evaluate_for_attempt_action( 5, $this->attempt() );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
	}

	public function test_attempt_action_allows_when_owner_and_enrolled(): void {
		$this->seed_active_enrollment( 5, 50 );

		$decision = $this->gate()->evaluate_for_attempt_action( 5, $this->attempt() );

		self::assertTrue( $decision->allowed );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_read_denies_when_quiz_unpublished(): void {
		$decision = $this->gate()->evaluate_for_read( 5, 101, $this->quiz_post( 101, 'draft' ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_published', $decision->reason );
	}

	public function test_read_denies_when_resolver_returns_null(): void {
		$this->resolved_course_id = null;

		$decision = $this->gate()->evaluate_for_read( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_in_course', $decision->reason );
	}

	public function test_read_denies_when_not_enrolled(): void {
		$decision = $this->gate()->evaluate_for_read( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_read_allows_when_enrolled(): void {
		$this->seed_active_enrollment( 5, 50 );

		$decision = $this->gate()->evaluate_for_read( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
		self::assertSame( 50, $decision->course_id );
	}

	/**
	 * The whole reason `evaluate_for_read` exists: a learner who has spent
	 * every attempt is precisely who wants to look at the record of them, so
	 * the read path must not inherit the start path's ceiling check.
	 */
	public function test_read_allows_even_when_attempts_are_exhausted(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '2' );
		$this->seed_attempts( 5, 101, 50, 2 );

		$start = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );
		$read  = $this->gate()->evaluate_for_read( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $start->allowed );
		self::assertSame( 'attempts_exhausted', $start->reason );
		self::assertTrue( $read->allowed );
		self::assertSame( 50, $read->course_id );
	}

	private function seed_active_enrollment( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
	}

	private function seed_attempts( int $user_id, int $quiz_id, int $course_id, int $count ): void {
		$now = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->attempts->insert(
				new QuizAttempt(
					0,
					$user_id,
					$quiz_id,
					$course_id,
					QuizAttemptStatus::SUBMITTED,
					$now,
					$now,
					0,
					null,
					0,
					100,
					false,
					70,
					[],
					$now,
					$now
				)
			);
		}
	}
}
