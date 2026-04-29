<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Services\Progress\CourseProgressCalculator;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use WP_Post;

final class CompletionPropagatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryProgressRepository $progress;
	private InMemoryEnrollmentRepository $enrollments;
	private \DateTimeImmutable $now;

	/** @var Mockery\MockInterface&CourseProgressCalculator */
	private $calculator;

	private int $recompute_pct = 0;

	private int $recompute_calls = 0;

	private bool $has_final_exam = false;

	/** @var array<int, list<WP_Post>> */
	private array $sibling_topics = [];

	/** @var array<int, list<WP_Post>> */
	private array $sibling_lessons = [];

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->now         = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress    = new InMemoryProgressRepository( fn (): \DateTimeImmutable => $this->now );
		$this->enrollments = new InMemoryEnrollmentRepository();

		$this->sibling_topics  = [];
		$this->sibling_lessons = [];
		$this->recompute_pct   = 0;
		$this->recompute_calls = 0;
		$this->has_final_exam  = false;

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
			$this->sibling_topics,
			$this->sibling_lessons,
			$this->has_final_exam,
			$this->now
		) extends CompletionPropagator {

			/**
			 * @param array<int, list<WP_Post>> $sibling_topics
			 * @param array<int, list<WP_Post>> $sibling_lessons
			 */
			public function __construct(
				InMemoryProgressRepository $progress,
				EntityHierarchy $hierarchy,
				CourseProgressCalculator $calc,
				EnrollmentRepository $enrollments,
				private array $sibling_topics,
				private array $sibling_lessons,
				private bool $has_final_exam,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $progress, $hierarchy, $calc, $enrollments );
			}

			protected function query_sibling_topics( int $lesson_id ): array {
				return $this->sibling_topics[ $lesson_id ] ?? [];
			}

			protected function query_sibling_lessons( int $parent_id ): array {
				return $this->sibling_lessons[ $parent_id ] ?? [];
			}

			protected function course_has_final_exam( int $course_id ): bool {
				return $this->has_final_exam;
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

		$this->recompute_pct  = 100;
		$this->has_final_exam = false;

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

		$this->recompute_pct  = 100;
		$this->has_final_exam = true;

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
}
