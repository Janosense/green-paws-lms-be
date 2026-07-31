<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAttemptRepository;

final class ProgressionGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @param list<CurriculumStop> $stops
	 */
	private function order( array $stops, bool $sequential = false, ?int &$build_count = null ): CurriculumOrder {
		return new class( $stops, $sequential, $build_count ) extends CurriculumOrder {

			/** @param list<CurriculumStop> $stops */
			public function __construct(
				private array $stops,
				private bool $sequential,
				private ?int &$build_count
			) {
			}

			public function for_course( int $course_id ): array {
				if ( null !== $this->build_count ) {
					++$this->build_count;
				}
				return $this->stops;
			}

			public function is_sequential_course( int $course_id ): bool {
				return $this->sequential;
			}
		};
	}

	/**
	 * @param list<CurriculumStop> $stops
	 */
	private function gate(
		array $stops,
		QuizAttemptRepository $attempts,
		?ProgressRepository $progress = null,
		bool $can_edit = false,
		bool $sequential = false,
		?int &$build_count = null
	): ProgressionGate {
		$progress ??= Mockery::mock( ProgressRepository::class );
		assert( $progress instanceof ProgressRepository );
		$order = $this->order( $stops, $sequential, $build_count );

		return new class( $order, $attempts, $progress, $can_edit ) extends ProgressionGate {

			public function __construct(
				CurriculumOrder $order,
				QuizAttemptRepository $attempts,
				ProgressRepository $progress,
				private bool $can_edit
			) {
				parent::__construct( $order, $attempts, $progress );
			}

			protected function can_edit_course( int $user_id, int $course_id ): bool {
				return $this->can_edit;
			}
		};
	}

	private function blocking_quiz( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_QUIZ, $id, 'quiz-' . $id, 'Quiz ' . $id, true );
	}

	private function lesson( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_LESSON, $id, 'lesson-' . $id, 'Lesson ' . $id );
	}

	private function completed_row( EntityType $type, int $entity_id ): Progress {
		$now = new \DateTimeImmutable( '2026-07-31T00:00:00Z' );
		return new Progress(
			1,
			5,
			$type,
			$entity_id,
			1,
			ProgressStatus::COMPLETED,
			null,
			$now,
			$now,
			$now,
			$now
		);
	}

	/**
	 * The point of the ungated fast path: no per-learner aggregation of any
	 * kind is issued for a course that uses neither gating feature.
	 */
	public function test_ungated_course_never_touches_the_attempt_repository(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldNotReceive( 'status_map_for_user_in_course' );
		$progress = Mockery::mock( ProgressRepository::class );
		$progress->shouldNotReceive( 'list_for_user_in_course' );
		assert( $progress instanceof ProgressRepository );

		$stops = [
			new CurriculumStop( CurriculumStop::KIND_LESSON, 1 ),
			new CurriculumStop( CurriculumStop::KIND_QUIZ, 10, 'quiz-10', 'Quiz 10' ),
		];

		$map = $this->gate( $stops, $attempts, $progress )->lock_map( 5, 1 );

		self::assertTrue( $map->is_empty() );
	}

	/**
	 * Each per-learner read is tied to the rule that consumes it: a
	 * sequential course with no gated quiz reads progress but never
	 * aggregates quiz attempts.
	 */
	public function test_sequential_ungated_course_skips_the_attempt_repository_but_reads_progress(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldNotReceive( 'status_map_for_user_in_course' );
		$progress = Mockery::mock( ProgressRepository::class );
		$progress->shouldReceive( 'list_for_user_in_course' )->once()->with( 5, 1 )->andReturn( [] );
		assert( $progress instanceof ProgressRepository );

		$gate = $this->gate( [ $this->lesson( 1 ), $this->lesson( 2 ) ], $attempts, $progress, sequential: true );

		$lock = $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 );

		self::assertSame( LockState::REASON_PREVIOUS_INCOMPLETE, $lock?->reason );
		self::assertSame( 1, $lock?->blocking_entity?->id );
	}

	public function test_free_gated_course_never_touches_the_progress_repository(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldReceive( 'status_map_for_user_in_course' )->once()->andReturn( [] );
		$progress = Mockery::mock( ProgressRepository::class );
		$progress->shouldNotReceive( 'list_for_user_in_course' );
		assert( $progress instanceof ProgressRepository );

		$gate = $this->gate( [ $this->blocking_quiz( 10 ), $this->lesson( 2 ) ], $attempts, $progress );

		$lock = $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 );

		self::assertSame( LockState::REASON_PROGRESSION, $lock?->reason );
	}

	public function test_sequential_course_resolves_the_frontier_from_completed_rows(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldNotReceive( 'status_map_for_user_in_course' );
		$progress = Mockery::mock( ProgressRepository::class );
		$progress->shouldReceive( 'list_for_user_in_course' )->andReturn(
			[
				$this->completed_row( EntityType::LESSON, 1 ),
				// Module and session rows never feed the frontier.
				$this->completed_row( EntityType::MODULE, 999 ),
			]
		);
		assert( $progress instanceof ProgressRepository );

		$gate = $this->gate(
			[ $this->lesson( 1 ), $this->lesson( 2 ), $this->lesson( 3 ) ],
			$attempts,
			$progress,
			sequential: true
		);

		self::assertNull( $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 1 ) );
		self::assertNull( $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 ) );
		self::assertSame( 2, $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 3 )?->blocking_entity?->id );
	}

	/**
	 * Instructors walk their own courses through `?preview=1` and must reach
	 * every entity without first passing its quizzes. Checked before any
	 * query runs, so previewing costs nothing.
	 */
	public function test_editor_bypass_short_circuits_before_building_the_order(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldNotReceive( 'status_map_for_user_in_course' );

		$build_count = 0;
		$gate        = $this->gate(
			[ $this->blocking_quiz( 10 ), new CurriculumStop( CurriculumStop::KIND_LESSON, 2 ) ],
			$attempts,
			can_edit: true,
			build_count: $build_count
		);

		$map = $gate->lock_map( 5, 1 );

		self::assertTrue( $map->is_empty() );
		self::assertSame( 0, $build_count );
	}

	public function test_gated_course_resolves_locks_from_the_overlay(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldReceive( 'status_map_for_user_in_course' )
			->once()
			->with( 5, 1 )
			->andReturn(
				[
					10 => [
						'passed'          => false,
						'in_progress'     => false,
						'submitted_count' => 1,
						'best_pct'        => 40.0,
					],
				]
			);

		$gate = $this->gate(
			[ $this->blocking_quiz( 10 ), new CurriculumStop( CurriculumStop::KIND_LESSON, 2 ) ],
			$attempts
		);

		$lock = $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 );

		self::assertSame( LockState::REASON_PROGRESSION, $lock?->reason );
		self::assertSame( 10, $lock?->blocking_quiz?->id );
	}

	public function test_lock_map_is_memoised_per_user_and_course(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		// `once()` is the assertion: a second lookup must not re-query.
		$attempts->shouldReceive( 'status_map_for_user_in_course' )->once()->andReturn( [] );

		$gate = $this->gate(
			[ $this->blocking_quiz( 10 ), new CurriculumStop( CurriculumStop::KIND_LESSON, 2 ) ],
			$attempts
		);

		$gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 );
		$gate->check( 5, 1, CurriculumStop::KIND_QUIZ, 10 );
		$gate->lock_map( 5, 1 );

		self::assertTrue( true );
	}

	public function test_check_returns_null_for_an_open_entity(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldReceive( 'status_map_for_user_in_course' )->andReturn(
			[
				10 => [
					'passed'          => true,
					'in_progress'     => false,
					'submitted_count' => 1,
					'best_pct'        => 100.0,
				],
			]
		);

		$gate = $this->gate(
			[ $this->blocking_quiz( 10 ), new CurriculumStop( CurriculumStop::KIND_LESSON, 2 ) ],
			$attempts
		);

		self::assertNull( $gate->check( 5, 1, CurriculumStop::KIND_LESSON, 2 ) );
	}
}
