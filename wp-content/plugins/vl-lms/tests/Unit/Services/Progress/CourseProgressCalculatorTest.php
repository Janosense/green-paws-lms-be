<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Progress\CourseProgressCalculator;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use WP_Post;

final class CourseProgressCalculatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryProgressRepository $progress;
	private InMemoryEnrollmentRepository $enrollments;
	private \DateTimeImmutable $now;

	/** @var array<int, list<WP_Post>> */
	private array $lessons_in_course = [];

	/** @var array<int, list<WP_Post>> */
	private array $topics_in_lesson = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->now         = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress    = new InMemoryProgressRepository( fn (): \DateTimeImmutable => $this->now );
		$this->enrollments = new InMemoryEnrollmentRepository();

		$this->lessons_in_course = [];
		$this->topics_in_lesson  = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function calculator(): CourseProgressCalculator {
		return new class( $this->progress, $this->enrollments, $this->lessons_in_course, $this->topics_in_lesson, $this->now ) extends CourseProgressCalculator {

			/**
			 * @param array<int, list<WP_Post>> $lessons_in_course
			 * @param array<int, list<WP_Post>> $topics_in_lesson
			 */
			public function __construct(
				InMemoryProgressRepository $progress,
				EnrollmentRepository $enrollments,
				private array $lessons_in_course,
				private array $topics_in_lesson,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $progress, $enrollments );
			}

			protected function query_lessons_in_course( int $course_id ): array {
				return $this->lessons_in_course[ $course_id ] ?? [];
			}

			protected function query_topics_under_lesson( int $lesson_id ): array {
				return $this->topics_in_lesson[ $lesson_id ] ?? [];
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

	public function test_empty_curriculum_returns_zero(): void {
		$this->enrollments->seed(
			[
				'user_id'      => 1,
				'course_id'    => 100,
				'progress_pct' => 0,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		self::assertSame( 0, $pct );
		$row = $this->enrollments->find_for_user_and_course( 1, 100 );
		self::assertNotNull( $row );
		self::assertSame( 0, $row->progress_pct );
	}

	public function test_all_topic_leaves_completed_yields_100(): void {
		$lesson                       = $this->post( 200, 'vl_lesson' );
		$this->lessons_in_course[100] = [ $lesson ];
		$this->topics_in_lesson[200]  = [ $this->post( 300, 'vl_topic' ), $this->post( 301, 'vl_topic' ) ];

		$this->seed_completed_progress( 1, 100, EntityType::TOPIC, 300 );
		$this->seed_completed_progress( 1, 100, EntityType::TOPIC, 301 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		self::assertSame( 100, $pct );
	}

	public function test_one_of_three_topics_completed_floors_to_33(): void {
		$lesson                       = $this->post( 200, 'vl_lesson' );
		$this->lessons_in_course[100] = [ $lesson ];
		$this->topics_in_lesson[200]  = [
			$this->post( 300, 'vl_topic' ),
			$this->post( 301, 'vl_topic' ),
			$this->post( 302, 'vl_topic' ),
		];

		$this->seed_completed_progress( 1, 100, EntityType::TOPIC, 300 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		self::assertSame( 33, $pct );
	}

	public function test_half_completed_yields_50(): void {
		$this->lessons_in_course[100] = [
			$this->post( 200, 'vl_lesson' ),
			$this->post( 201, 'vl_lesson' ),
		];
		// Both lessons are topic-less → each is its own leaf.

		$this->seed_completed_progress( 1, 100, EntityType::LESSON, 200 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		self::assertSame( 50, $pct );
	}

	public function test_mix_of_lesson_and_topic_leaves(): void {
		$lesson_with_topics       = $this->post( 200, 'vl_lesson' );
		$lesson_without_topics    = $this->post( 201, 'vl_lesson' );
		$another_lesson_no_topics = $this->post( 202, 'vl_lesson' );

		$this->lessons_in_course[100] = [ $lesson_with_topics, $lesson_without_topics, $another_lesson_no_topics ];
		$this->topics_in_lesson[200]  = [ $this->post( 300, 'vl_topic' ), $this->post( 301, 'vl_topic' ) ];
		// Total leaves: 2 topics + 2 topic-less lessons = 4.

		$this->seed_completed_progress( 1, 100, EntityType::TOPIC, 300 );
		$this->seed_completed_progress( 1, 100, EntityType::LESSON, 201 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		self::assertSame( 50, $pct );
	}

	public function test_persistence_calls_update_progress_state_with_computed_pct(): void {
		$this->lessons_in_course[100] = [
			$this->post( 200, 'vl_lesson' ),
			$this->post( 201, 'vl_lesson' ),
		];
		$this->seed_completed_progress( 1, 100, EntityType::LESSON, 200 );

		$enrollment_id = $this->enrollments->seed(
			[
				'user_id'      => 1,
				'course_id'    => 100,
				'progress_pct' => 0,
				'status'       => EnrollmentStatus::ACTIVE->value,
			]
		);

		$this->calculator()->recompute( 1, 100 );

		$row = $this->enrollments->find_by_id( $enrollment_id );
		self::assertNotNull( $row );
		self::assertSame( 50, $row->progress_pct );
		self::assertSame( EnrollmentStatus::ACTIVE, $row->status );
	}

	public function test_lesson_status_overrides_completed_topics(): void {
		// Even if a lesson row is `completed`, the lesson with topics
		// counts each TOPIC as a leaf — the lesson itself is not in the
		// leaf set. So a `completed` lesson row matters only when the
		// lesson has no topics.
		$lesson                       = $this->post( 200, 'vl_lesson' );
		$this->lessons_in_course[100] = [ $lesson ];
		$this->topics_in_lesson[200]  = [ $this->post( 300, 'vl_topic' ) ];

		$this->seed_completed_progress( 1, 100, EntityType::LESSON, 200 );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$pct = $this->calculator()->recompute( 1, 100 );

		// Lesson-level completion does NOT count toward a topic-bearing
		// lesson's leaf set; the only leaf (topic 300) is incomplete → 0%.
		self::assertSame( 0, $pct );
	}

	private function seed_completed_progress(
		int $user_id,
		int $course_id,
		EntityType $entity_type,
		int $entity_id
	): void {
		$this->progress->upsert(
			$user_id,
			$entity_type,
			$entity_id,
			$course_id,
			ProgressStatus::COMPLETED,
			null,
			$this->now,
			$this->now
		);
	}
}
