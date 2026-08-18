<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Services\Progress\CourseProgressCalculator;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use WP_Post;

final class CompletionPropagatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryProgressRepository $progress;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryQuizAttemptRepository $quiz_attempts;
	private \DateTimeImmutable $now;

	/** @var Mockery\MockInterface&CourseProgressCalculator */
	private $calculator;

	private int $recompute_pct = 0;

	private int $recompute_calls = 0;

	/** @var list<int> */
	private array $final_exam_quiz_ids = [];

	/** @var array<int, list<WP_Post>> */
	private array $sibling_topics = [];

	/** @var array<int, list<WP_Post>> */
	private array $sibling_lessons = [];

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	/** @var array<int, WP_Post> */
	private array $post_index = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->post_index = [];

		$index = &$this->post_index;
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( &$index ): ?WP_Post {
				return $index[ $id ] ?? null;
			}
		);

		$this->now           = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress      = new InMemoryProgressRepository( fn (): \DateTimeImmutable => $this->now );
		$this->enrollments   = new InMemoryEnrollmentRepository();
		$this->quiz_attempts = new InMemoryQuizAttemptRepository();

		$this->sibling_topics      = [];
		$this->sibling_lessons     = [];
		$this->recompute_pct       = 0;
		$this->recompute_calls     = 0;
		$this->final_exam_quiz_ids = [];

		$this->calculator = Mockery::mock( CourseProgressCalculator::class );
		$pct_ref          = &$this->recompute_pct;
		$calls_ref        = &$this->recompute_calls;
		$this->calculator->shouldReceive( 'recompute' )
			->andReturnUsing(
				static function () use ( &$pct_ref, &$calls_ref ): int {
					++$calls_ref;
					return $pct_ref;
				}
			);

		$this->hierarchy = Mockery::mock( EntityHierarchy::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function propagator(): CompletionPropagator {
		return new class(
			$this->progress,
			$this->hierarchy,
			$this->calculator,
			$this->enrollments,
			$this->quiz_attempts,
			$this->sibling_topics,
			$this->sibling_lessons,
			$this->final_exam_quiz_ids,
			$this->now
		) extends CompletionPropagator {

			/**
			 * @param array<int, list<WP_Post>> $sibling_topics
			 * @param array<int, list<WP_Post>> $sibling_lessons
			 * @param list<int>                 $final_exam_quiz_ids
			 */
			public function __construct(
				InMemoryProgressRepository $progress,
				EntityHierarchy $hierarchy,
				CourseProgressCalculator $calc,
				EnrollmentRepository $enrollments,
				InMemoryQuizAttemptRepository $quiz_attempts,
				private array $sibling_topics,
				private array $sibling_lessons,
				private array $final_exam_quiz_ids,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $progress, $hierarchy, $calc, $enrollments, $quiz_attempts );
			}

			protected function query_sibling_topics( int $lesson_id ): array {
				return $this->sibling_topics[ $lesson_id ] ?? [];
			}

			protected function query_sibling_lessons( int $parent_id ): array {
				return $this->sibling_lessons[ $parent_id ] ?? [];
			}

			protected function find_final_exam_quiz_ids_in_course( int $course_id ): array {
				return $this->final_exam_quiz_ids;
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock_now;
			}
		};
	}

	/**
	 * Propagator wired to a REAL `EntityHierarchy` (backed by the `get_post`
	 * post index) so the `resolveCourse()` filter inside
	 * `find_final_exam_quiz_ids_in_course()` is actually exercised — only the
	 * `WP_Query` seam supplying the flagged quiz posts is overridden.
	 *
	 * @param list<WP_Post> $final_exam_posts
	 */
	private function propagator_with_real_hierarchy( array $final_exam_posts ): CompletionPropagator {
		return new class(
			$this->progress,
			new EntityHierarchy(),
			$this->calculator,
			$this->enrollments,
			$this->quiz_attempts,
			$this->sibling_topics,
			$this->sibling_lessons,
			$final_exam_posts,
			$this->now
		) extends CompletionPropagator {

			/**
			 * @param array<int, list<WP_Post>> $sibling_topics
			 * @param array<int, list<WP_Post>> $sibling_lessons
			 * @param list<WP_Post>             $final_exam_posts
			 */
			public function __construct(
				InMemoryProgressRepository $progress,
				EntityHierarchy $hierarchy,
				CourseProgressCalculator $calc,
				EnrollmentRepository $enrollments,
				InMemoryQuizAttemptRepository $quiz_attempts,
				private array $sibling_topics,
				private array $sibling_lessons,
				private array $final_exam_posts,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $progress, $hierarchy, $calc, $enrollments, $quiz_attempts );
			}

			protected function query_sibling_topics( int $lesson_id ): array {
				return $this->sibling_topics[ $lesson_id ] ?? [];
			}

			protected function query_sibling_lessons( int $parent_id ): array {
				return $this->sibling_lessons[ $parent_id ] ?? [];
			}

			protected function query_final_exam_quiz_posts(): array {
				return $this->final_exam_posts;
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock_now;
			}
		};
	}

	private function post( int $id, string $type ): WP_Post {
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = $id;
		$post->post_type = $type;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function indexed_post( int $id, string $type, int $parent_id = 0, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_parent = $parent_id;
		$post->post_status = $status;

		assert( $post instanceof WP_Post );
		$this->post_index[ $id ] = $post;
		return $post;
	}

	private function seed_completed( int $user_id, int $course_id, EntityType $type, int $entity_id ): void {
		$this->progress->upsert(
			$user_id,
			$type,
			$entity_id,
			$course_id,
			ProgressStatus::COMPLETED,
			null,
			$this->now,
			$this->now
		);
	}

	public function test_topic_completion_with_incomplete_siblings_does_not_promote_lesson(): void {
		$lesson  = $this->post( 200, 'vl_lesson' );
		$topic_a = $this->post( 300, 'vl_topic' );
		$topic_b = $this->post( 301, 'vl_topic' );

		$this->hierarchy->shouldReceive( 'resolveLesson' )->with( $topic_a )->andReturn( $lesson );

		$this->sibling_topics[200] = [ $topic_a, $topic_b ];

		$this->seed_completed( 1, 100, EntityType::TOPIC, 300 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $topic_a );

		self::assertFalse( $result->lesson_completed );
		self::assertFalse( $result->module_completed );
		self::assertFalse( $result->course_completed );
		self::assertNull( $this->progress->find( 1, EntityType::LESSON, 200 ) );
	}

	public function test_topic_completion_with_all_siblings_complete_promotes_lesson(): void {
		$lesson  = $this->post( 200, 'vl_lesson' );
		$topic_a = $this->post( 300, 'vl_topic' );
		$topic_b = $this->post( 301, 'vl_topic' );

		$this->hierarchy->shouldReceive( 'resolveLesson' )->with( $topic_a )->andReturn( $lesson );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->sibling_topics[200] = [ $topic_a, $topic_b ];

		$this->seed_completed( 1, 100, EntityType::TOPIC, 300 );
		$this->seed_completed( 1, 100, EntityType::TOPIC, 301 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $topic_a );

		self::assertTrue( $result->lesson_completed );
		self::assertFalse( $result->module_completed );

		$lesson_progress = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $lesson_progress );
		self::assertSame( ProgressStatus::COMPLETED, $lesson_progress->status );
	}

	public function test_lesson_completion_with_incomplete_sibling_lessons_does_not_promote_module(): void {
		$module   = $this->post( 110, 'vl_module' );
		$lesson_a = $this->post( 200, 'vl_lesson' );
		$lesson_b = $this->post( 201, 'vl_lesson' );

		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson_a )->andReturn( $module );

		$this->sibling_lessons[110] = [ $lesson_a, $lesson_b ];

		$this->seed_completed( 1, 100, EntityType::LESSON, 200 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson_a );

		self::assertFalse( $result->module_completed );
		self::assertNull( $this->progress->find( 1, EntityType::MODULE, 110 ) );
	}

	public function test_lesson_completion_with_all_siblings_complete_promotes_module(): void {
		$module   = $this->post( 110, 'vl_module' );
		$lesson_a = $this->post( 200, 'vl_lesson' );
		$lesson_b = $this->post( 201, 'vl_lesson' );

		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson_a )->andReturn( $module );

		$this->sibling_lessons[110] = [ $lesson_a, $lesson_b ];

		$this->seed_completed( 1, 100, EntityType::LESSON, 200 );
		$this->seed_completed( 1, 100, EntityType::LESSON, 201 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson_a );

		self::assertTrue( $result->module_completed );
		$module_progress = $this->progress->find( 1, EntityType::MODULE, 110 );
		self::assertNotNull( $module_progress );
		self::assertSame( ProgressStatus::COMPLETED, $module_progress->status );
	}

	public function test_propagate_throws_logic_exception_when_invoked_with_session_post(): void {
		// Phase 7.4: sessions are leaves with no fan-up chain. Attendance
		// flows through SessionAttendanceProgressListener, not propagate().
		$session = $this->post( 200, 'vl_session' );

		$this->expectException( \LogicException::class );
		$this->propagator()->propagate( 1, 100, $session );
	}

	public function test_course_direct_lesson_skips_module_step(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson );

		self::assertFalse( $result->module_completed );
	}

	public function test_course_completion_when_no_final_exam_and_progress_pct_is_100(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [];

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson );

		self::assertTrue( $result->course_completed );
		self::assertSame( 100, $result->course_progress_pct );

		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::COMPLETED, $row->status );
		self::assertSame( '2026-04-28 12:00:00', $row->completed_at );
	}

	public function test_course_with_final_exam_keeps_enrollment_active_at_100(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson );

		self::assertFalse( $result->course_completed );
		self::assertSame( 100, $result->course_progress_pct );

		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::ACTIVE, $row->status );
	}

	public function test_recompute_called_exactly_once(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$this->propagator()->propagate( 1, 100, $lesson );

		self::assertSame( 1, $this->recompute_calls );
	}

	public function test_reevaluate_flips_when_both_arms_pass(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		$this->seed_passed_attempt( 1, 100, 999 );
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertTrue( $flipped );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::COMPLETED, $row->status );
	}

	public function test_reevaluate_does_not_flip_when_lesson_progress_below_100(): void {
		$this->recompute_pct       = 80;
		$this->final_exam_quiz_ids = [ 999 ];

		$this->seed_passed_attempt( 1, 100, 999 );
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertFalse( $flipped );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::ACTIVE, $row->status );
	}

	public function test_reevaluate_does_not_flip_when_no_passed_final_exam(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		// No passed attempt seeded — final-exam arm fails.
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertFalse( $flipped );
	}

	public function test_reevaluate_ignores_final_exam_pass_from_before_progress_reset(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		// The learner passed the exam, then reset progress: the pass
		// pre-dates the epoch, so the final-exam arm must re-arm and the
		// course must not re-complete on lesson progress alone.
		$this->seed_passed_attempt( 1, 100, 999 );
		$this->quiz_attempts->set_progress_reset_at( 1, 100, $this->now->modify( '+1 day' ) );
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertFalse( $flipped );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::ACTIVE, $row->status );
	}

	public function test_reevaluate_flips_on_final_exam_pass_after_progress_reset(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		$this->seed_passed_attempt( 1, 100, 999 );
		$this->quiz_attempts->set_progress_reset_at( 1, 100, $this->now->modify( '-1 hour' ) );
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertTrue( $flipped );
	}

	public function test_propagate_at_100_pct_with_final_exam_does_not_flip(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveModule' )->with( $lesson )->andReturn( null );

		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [ 999 ];

		// Simulate a student who finished all lessons but never took
		// (or never passed) the final exam.
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$result = $this->propagator()->propagate( 1, 100, $lesson );

		self::assertFalse( $result->course_completed );
		self::assertSame( 100, $result->course_progress_pct );
	}

	public function test_reevaluate_fires_course_completed_action_when_flip_succeeds(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [];

		$enrollment_id = $this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		Actions\expectDone( 'vl_lms_course_completed' )
			->once()
			->with( 1, 100, $enrollment_id );

		$flipped = $this->propagator()->reevaluate_course_completion( 1, 100 );

		self::assertTrue( $flipped );
	}

	public function test_reevaluate_does_not_fire_action_when_already_completed(): void {
		$this->recompute_pct       = 100;
		$this->final_exam_quiz_ids = [];

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::COMPLETED->value,
			]
		);

		Actions\expectDone( 'vl_lms_course_completed' )->never();

		$result = $this->propagator()->reevaluate_course_completion( 1, 100 );
		self::assertTrue( $result );
	}

	public function test_reevaluate_does_not_fire_action_when_no_op(): void {
		$this->recompute_pct       = 80;
		$this->final_exam_quiz_ids = [];

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		Actions\expectDone( 'vl_lms_course_completed' )->never();

		self::assertFalse( $this->propagator()->reevaluate_course_completion( 1, 100 ) );
	}

	public function test_final_exam_discovery_resolves_quiz_chain_and_blocks_completion(): void {
		// Regression pin: `resolveCourse()` used to have no `vl_quiz` arm,
		// so discovery always came back empty and the final-exam arm
		// auto-passed — completing the course (and issuing the certificate)
		// on lesson progress alone.
		$this->indexed_post( 100, 'vl_course' );
		$this->indexed_post( 110, 'vl_module', 100 );
		$this->indexed_post( 200, 'vl_lesson', 110 );
		$quiz = $this->indexed_post( 999, 'vl_quiz', 200 );

		$this->recompute_pct = 100;

		// No passed attempt seeded — the exam is untaken.
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		Actions\expectDone( 'vl_lms_course_completed' )->never();

		$flipped = $this->propagator_with_real_hierarchy( [ $quiz ] )->reevaluate_course_completion( 1, 100 );

		self::assertFalse( $flipped );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::ACTIVE, $row->status );
	}

	public function test_final_exam_discovery_ignores_exams_resolving_to_other_courses(): void {
		$this->indexed_post( 100, 'vl_course' );
		$this->indexed_post( 500, 'vl_course' );
		$quiz = $this->indexed_post( 999, 'vl_quiz', 500 );

		$this->recompute_pct = 100;

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		// The only flagged exam belongs to course 500, so course 100 has no
		// final exam and the arm passes trivially.
		$flipped = $this->propagator_with_real_hierarchy( [ $quiz ] )->reevaluate_course_completion( 1, 100 );

		self::assertTrue( $flipped );
	}

	public function test_final_exam_discovery_pass_allows_completion(): void {
		$this->indexed_post( 100, 'vl_course' );
		$this->indexed_post( 110, 'vl_module', 100 );
		$this->indexed_post( 200, 'vl_lesson', 110 );
		$quiz = $this->indexed_post( 999, 'vl_quiz', 200 );

		$this->recompute_pct = 100;

		$this->seed_passed_attempt( 1, 100, 999 );
		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$flipped = $this->propagator_with_real_hierarchy( [ $quiz ] )->reevaluate_course_completion( 1, 100 );

		self::assertTrue( $flipped );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( EnrollmentStatus::COMPLETED, $row->status );
	}

	private function seed_passed_attempt( int $user_id, int $course_id, int $quiz_id ): void {
		$now = $this->now;
		$this->quiz_attempts->insert(
			new QuizAttempt(
				0,
				$user_id,
				$quiz_id,
				$course_id,
				QuizAttemptStatus::SUBMITTED,
				$now,
				$now,
				0,
				60,
				90,
				100,
				true,
				70,
				[ 201 ],
				$now,
				$now
			)
		);
	}
}
