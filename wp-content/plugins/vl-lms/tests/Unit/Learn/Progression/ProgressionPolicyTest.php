<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\ProgressionPolicy;
use VL\LMS\Learn\QuizStatusOverlay;

final class ProgressionPolicyTest extends TestCase {

	private ProgressionPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new ProgressionPolicy();
	}

	private function lesson( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_LESSON, $id );
	}

	private function topic( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_TOPIC, $id );
	}

	private function session( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_SESSION, $id );
	}

	private function quiz(
		int $id,
		bool $blocks = false,
		bool $requires_all = false,
		bool $is_final = false
	): CurriculumStop {
		return new CurriculumStop(
			CurriculumStop::KIND_QUIZ,
			$id,
			'quiz-' . $id,
			'Quiz ' . $id,
			$blocks,
			$requires_all,
			$is_final
		);
	}

	/**
	 * @param array<int, string> $status_by_quiz
	 */
	private function overlay( array $status_by_quiz ): QuizStatusOverlay {
		$map = [];
		foreach ( $status_by_quiz as $quiz_id => $status ) {
			$map[ $quiz_id ] = [
				'passed'          => 'passed' === $status,
				'in_progress'     => 'in_progress' === $status,
				'submitted_count' => 'failed' === $status ? 1 : 0,
				'best_pct'        => null,
			];
		}
		return QuizStatusOverlay::fromMap( $map );
	}

	public function test_nothing_locks_when_no_quiz_carries_a_flag(): void {
		$stops = [ $this->lesson( 1 ), $this->quiz( 10 ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [] ) );

		self::assertTrue( $map->is_empty() );
	}

	public function test_unpassed_blocking_quiz_locks_everything_after_it(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 10, blocks: true ),
			$this->lesson( 2 ),
			$this->topic( 3 ),
		];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'failed' ] ) );

		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 10 ) );

		$lock = $map->for_entity( CurriculumStop::KIND_LESSON, 2 );
		self::assertSame( LockState::REASON_PROGRESSION, $lock?->reason );
		self::assertSame( 10, $lock?->blocking_quiz?->id );
		self::assertSame( 'quiz-10', $lock?->blocking_quiz?->slug );
		self::assertSame( 'Quiz 10', $lock?->blocking_quiz?->title );

		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_TOPIC, 3 ) );
	}

	/**
	 * The gate itself must stay reachable, otherwise the learner has no way
	 * to clear it.
	 */
	public function test_the_blocking_quiz_itself_is_never_locked(): void {
		$stops = [ $this->quiz( 10, blocks: true ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'failed' ] ) );

		self::assertNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 10 ) );
	}

	public function test_passing_the_blocking_quiz_opens_everything_after_it(): void {
		$stops = [ $this->quiz( 10, blocks: true ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'passed' ] ) );

		self::assertTrue( $map->is_empty() );
	}

	public function test_only_the_earliest_unpassed_blocking_quiz_defines_the_frontier(): void {
		$stops = [
			$this->quiz( 10, blocks: true ),
			$this->lesson( 2 ),
			$this->quiz( 20, blocks: true ),
			$this->lesson( 3 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay(
				[
					10 => 'failed',
					20 => 'failed',
				]
			)
		);

		// Both later stops name quiz 10 — the nearer, actionable gate.
		self::assertSame( 10, $map->for_entity( CurriculumStop::KIND_LESSON, 2 )?->blocking_quiz?->id );
		self::assertSame( 10, $map->for_entity( CurriculumStop::KIND_LESSON, 3 )?->blocking_quiz?->id );
		// Quiz 20 sits after the frontier, so it is locked too.
		self::assertSame( 10, $map->for_entity( CurriculumStop::KIND_QUIZ, 20 )?->blocking_quiz?->id );
	}

	public function test_frontier_advances_once_the_first_gate_is_cleared(): void {
		$stops = [
			$this->quiz( 10, blocks: true ),
			$this->lesson( 2 ),
			$this->quiz( 20, blocks: true ),
			$this->lesson( 3 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay(
				[
					10 => 'passed',
					20 => 'failed',
				]
			)
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 2 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 20 ) );
		self::assertSame( 20, $map->for_entity( CurriculumStop::KIND_LESSON, 3 )?->blocking_quiz?->id );
	}

	/**
	 * A cohort session is a scheduled call. Locking one strands a learner
	 * outside an event that will not be repeated — but it must not clear the
	 * frontier either, or attaching a session would silently defeat the gate.
	 */
	public function test_sessions_are_never_locked_but_do_not_clear_the_frontier(): void {
		$stops = [
			$this->quiz( 10, blocks: true ),
			$this->session( 40 ),
			$this->quiz( 41 ),
			$this->lesson( 5 ),
		];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'failed' ] ) );

		self::assertNull( $map->for_entity( CurriculumStop::KIND_SESSION, 40 ) );
		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 41 ) );
		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_LESSON, 5 ) );
	}

	public function test_in_progress_blocking_quiz_still_blocks(): void {
		$stops = [ $this->quiz( 10, blocks: true ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'in_progress' ] ) );

		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_LESSON, 2 ) );
	}

	public function test_requires_all_locks_until_every_other_non_final_quiz_is_passed(): void {
		$stops = [
			$this->quiz( 10 ),
			$this->quiz( 11 ),
			$this->quiz( 99, requires_all: true, is_final: true ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay(
				[
					10 => 'passed',
					11 => 'failed',
				]
			)
		);

		$lock = $map->for_entity( CurriculumStop::KIND_QUIZ, 99 );
		self::assertSame( LockState::REASON_COURSE_INCOMPLETE, $lock?->reason );
		self::assertSame( 1, $lock?->remaining_quiz_count );
		self::assertNull( $lock?->blocking_quiz );
	}

	public function test_requires_all_opens_once_every_prerequisite_is_passed(): void {
		$stops = [
			$this->quiz( 10 ),
			$this->quiz( 11 ),
			$this->quiz( 99, requires_all: true, is_final: true ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay(
				[
					10 => 'passed',
					11 => 'passed',
				]
			)
		);

		self::assertTrue( $map->is_empty() );
	}

	/**
	 * Two final exams both carrying the flag would otherwise deadlock each
	 * other, so final exams are excluded from the prerequisite set.
	 */
	public function test_requires_all_ignores_other_final_exams(): void {
		$stops = [
			$this->quiz( 98, requires_all: true, is_final: true ),
			$this->quiz( 99, requires_all: true, is_final: true ),
		];

		$map = $this->policy->evaluate( $stops, $this->overlay( [] ) );

		self::assertTrue( $map->is_empty() );
	}

	/**
	 * An empty universal quantifier is satisfied — the flag is inert on a
	 * course with no other quizzes rather than making that quiz unreachable.
	 */
	public function test_requires_all_is_inert_when_there_are_no_other_quizzes(): void {
		$stops = [ $this->lesson( 1 ), $this->quiz( 99, requires_all: true, is_final: true ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [] ) );

		self::assertTrue( $map->is_empty() );
	}

	public function test_requires_all_never_counts_the_gated_quiz_against_itself(): void {
		$stops = [ $this->quiz( 99, requires_all: true ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 99 => 'failed' ] ) );

		self::assertTrue( $map->is_empty() );
	}

	/**
	 * The frontier is the nearer gate, and naming one quiz to go pass beats
	 * "finish everything else".
	 */
	public function test_frontier_outranks_the_course_prerequisite_reason(): void {
		$stops = [
			$this->quiz( 10, blocks: true ),
			$this->quiz( 11 ),
			$this->quiz( 99, requires_all: true, is_final: true ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay(
				[
					10 => 'failed',
					11 => 'failed',
				]
			)
		);

		$lock = $map->for_entity( CurriculumStop::KIND_QUIZ, 99 );
		self::assertSame( LockState::REASON_PROGRESSION, $lock?->reason );
		self::assertSame( 10, $lock?->blocking_quiz?->id );
	}

	/**
	 * A final exam placed before other quizzes still gates correctly — the
	 * prerequisite rule is position-independent.
	 */
	public function test_requires_all_applies_even_when_the_quiz_comes_first(): void {
		$stops = [
			$this->quiz( 99, requires_all: true, is_final: true ),
			$this->quiz( 10 ),
			$this->quiz( 11 ),
		];

		$map = $this->policy->evaluate( $stops, $this->overlay( [] ) );

		self::assertSame( 2, $map->for_entity( CurriculumStop::KIND_QUIZ, 99 )?->remaining_quiz_count );
	}

	public function test_lock_serializes_to_the_wire_shape(): void {
		$stops = [ $this->quiz( 10, blocks: true ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [ 10 => 'failed' ] ) );

		self::assertSame(
			[
				'reason'               => 'progression_locked',
				'blocking_quiz'        => [
					'id'    => 10,
					'slug'  => 'quiz-10',
					'title' => 'Quiz 10',
				],
				'remaining_quiz_count' => 0,
			],
			$map->to_node_value( CurriculumStop::KIND_LESSON, 2 )
		);
	}
}
