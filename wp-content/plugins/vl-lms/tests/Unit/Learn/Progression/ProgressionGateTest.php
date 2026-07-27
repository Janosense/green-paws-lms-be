<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Repositories\QuizAttemptRepository;

final class ProgressionGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @param list<CurriculumStop> $stops
	 */
	private function order( array $stops, ?int &$build_count = null ): CurriculumOrder {
		return new class( $stops, $build_count ) extends CurriculumOrder {

			/** @param list<CurriculumStop> $stops */
			public function __construct(
				private array $stops,
				private ?int &$build_count
			) {
			}

			public function for_course( int $course_id ): array {
				if ( null !== $this->build_count ) {
					++$this->build_count;
				}
				return $this->stops;
			}
		};
	}

	/**
	 * @param list<CurriculumStop> $stops
	 */
	private function gate(
		array $stops,
		QuizAttemptRepository $attempts,
		bool $can_edit = false,
		?int &$build_count = null
	): ProgressionGate {
		return new class( $this->order( $stops, $build_count ), $attempts, $can_edit ) extends ProgressionGate {

			public function __construct(
				CurriculumOrder $order,
				QuizAttemptRepository $attempts,
				private bool $can_edit
			) {
				parent::__construct( $order, $attempts );
			}

			protected function can_edit_course( int $user_id, int $course_id ): bool {
				return $this->can_edit;
			}
		};
	}

	private function blocking_quiz( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_QUIZ, $id, 'quiz-' . $id, 'Quiz ' . $id, true );
	}

	/**
	 * The point of the ungated fast path: no per-learner attempt aggregation
	 * is issued for a course that does not use the feature.
	 */
	public function test_ungated_course_never_touches_the_attempt_repository(): void {
		$attempts = Mockery::mock( QuizAttemptRepository::class );
		$attempts->shouldNotReceive( 'status_map_for_user_in_course' );

		$stops = [
			new CurriculumStop( CurriculumStop::KIND_LESSON, 1 ),
			new CurriculumStop( CurriculumStop::KIND_QUIZ, 10, 'quiz-10', 'Quiz 10' ),
		];

		$map = $this->gate( $stops, $attempts )->lock_map( 5, 1 );

		self::assertTrue( $map->is_empty() );
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
