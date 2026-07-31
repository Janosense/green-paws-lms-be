<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Progression\CompletedSet;
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

	private function lesson( int $id, bool $has_topics = false ): CurriculumStop {
		return new CurriculumStop(
			CurriculumStop::KIND_LESSON,
			$id,
			'lesson-' . $id,
			'Lesson ' . $id,
			has_topics: $has_topics
		);
	}

	private function topic( int $id ): CurriculumStop {
		return new CurriculumStop( CurriculumStop::KIND_TOPIC, $id, 'topic-' . $id, 'Topic ' . $id );
	}

	/**
	 * @param list<string> $keys
	 */
	private function completed( array $keys ): CompletedSet {
		return CompletedSet::fromKeys( $keys );
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
				'blocking_entity'      => null,
			],
			$map->to_node_value( CurriculumStop::KIND_LESSON, 2 )
		);
	}

	// --- Sequential completion mode ---

	public function test_sequential_locks_every_stop_after_the_frontier_with_previous_incomplete(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 20 ),
			$this->lesson( 3, has_topics: true ),
			$this->topic( 4 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		// The frontier itself stays open — the learner must reach it to clear it.
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );

		foreach ( [ [ CurriculumStop::KIND_QUIZ, 20 ], [ CurriculumStop::KIND_LESSON, 3 ], [ CurriculumStop::KIND_TOPIC, 4 ] ] as [ $kind, $id ] ) {
			$lock = $map->for_entity( $kind, $id );
			self::assertSame( LockState::REASON_PREVIOUS_INCOMPLETE, $lock?->reason, "{$kind}:{$id}" );
			self::assertNull( $lock?->blocking_quiz );
			self::assertSame( 'lesson', $lock?->blocking_entity?->kind );
			self::assertSame( 1, $lock?->blocking_entity?->id );
			self::assertSame( 'lesson-1', $lock?->blocking_entity?->slug );
			self::assertSame( 'Lesson 1', $lock?->blocking_entity?->title );
		}
	}

	public function test_sequential_completed_prefix_advances_the_frontier(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->lesson( 2 ),
			$this->lesson( 3 ),
			$this->lesson( 4 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [ 'lesson:1', 'lesson:2' ] )
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 2 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 3 ) );
		self::assertSame( 3, $map->for_entity( CurriculumStop::KIND_LESSON, 4 )?->blocking_entity?->id );
	}

	/**
	 * A lesson-with-topics stop precedes its own topics in canonical order,
	 * but its progress row only completes after all of them do — treating it
	 * as a blocker would deadlock its first topic forever.
	 */
	public function test_sequential_lesson_with_topics_is_transparent_but_lockable(): void {
		$stops = [
			$this->lesson( 1, has_topics: true ),
			$this->topic( 2 ),
			$this->topic( 3 ),
			$this->lesson( 4, has_topics: true ),
			$this->topic( 5 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		// Not a frontier candidate: its first topic is the frontier instead.
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_TOPIC, 2 ) );
		self::assertSame( 2, $map->for_entity( CurriculumStop::KIND_TOPIC, 3 )?->blocking_entity?->id );
		// A later lesson-with-topics is still an ordinary lockable stop.
		self::assertSame( 2, $map->for_entity( CurriculumStop::KIND_LESSON, 4 )?->blocking_entity?->id );
		self::assertSame( 2, $map->for_entity( CurriculumStop::KIND_TOPIC, 5 )?->blocking_entity?->id );
	}

	/**
	 * Quiz gating stays opt-in per quiz — sequential mode must not silently
	 * turn every quiz into a gate.
	 */
	public function test_unflagged_quiz_never_defines_the_sequential_frontier(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 20 ),
			$this->lesson( 3 ),
			$this->lesson( 4 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [ 'lesson:1' ] )
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 20 ) );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 3 ) );
		$lock = $map->for_entity( CurriculumStop::KIND_LESSON, 4 );
		self::assertSame( LockState::REASON_PREVIOUS_INCOMPLETE, $lock?->reason );
		self::assertSame( 3, $lock?->blocking_entity?->id );
	}

	public function test_earlier_quiz_frontier_wins_over_later_sequential_frontier(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 20, blocks: true ),
			$this->lesson( 3 ),
			$this->lesson( 4 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [ 20 => 'failed' ] ),
			sequential: true,
			completed: $this->completed( [ 'lesson:1' ] )
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 20 ) );
		// Even the sequential frontier stop itself sits behind the quiz gate.
		$lock = $map->for_entity( CurriculumStop::KIND_LESSON, 3 );
		self::assertSame( LockState::REASON_PROGRESSION, $lock?->reason );
		self::assertSame( 20, $lock?->blocking_quiz?->id );
		self::assertSame( 20, $map->for_entity( CurriculumStop::KIND_LESSON, 4 )?->blocking_quiz?->id );
	}

	public function test_earlier_sequential_frontier_wins_over_later_quiz_frontier(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 20, blocks: true ),
			$this->lesson( 3 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [ 20 => 'failed' ] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );
		// The blocking quiz loses its "frontier stays open" privilege when an
		// earlier lesson is the actual next step.
		$lock = $map->for_entity( CurriculumStop::KIND_QUIZ, 20 );
		self::assertSame( LockState::REASON_PREVIOUS_INCOMPLETE, $lock?->reason );
		self::assertSame( 1, $lock?->blocking_entity?->id );
		self::assertSame( 1, $map->for_entity( CurriculumStop::KIND_LESSON, 3 )?->blocking_entity?->id );
	}

	public function test_sequential_sessions_are_never_locked_but_do_not_clear_the_frontier(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->session( 40 ),
			$this->quiz( 41 ),
			$this->lesson( 5 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		self::assertNull( $map->for_entity( CurriculumStop::KIND_SESSION, 40 ) );
		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_QUIZ, 41 ) );
		self::assertNotNull( $map->for_entity( CurriculumStop::KIND_LESSON, 5 ) );
	}

	public function test_sequential_fully_completed_course_locks_nothing(): void {
		$stops = [
			$this->lesson( 1 ),
			$this->quiz( 20 ),
			$this->lesson( 3, has_topics: true ),
			$this->topic( 4 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [ 'lesson:1', 'topic:4' ] )
		);

		self::assertTrue( $map->is_empty() );
	}

	public function test_requires_all_still_applies_before_the_sequential_frontier(): void {
		$stops = [
			$this->quiz( 99, requires_all: true, is_final: true ),
			$this->quiz( 10 ),
			$this->lesson( 1 ),
		];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [ 10 => 'failed' ] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		$lock = $map->for_entity( CurriculumStop::KIND_QUIZ, 99 );
		self::assertSame( LockState::REASON_COURSE_INCOMPLETE, $lock?->reason );
		self::assertSame( 1, $lock?->remaining_quiz_count );
		self::assertNull( $map->for_entity( CurriculumStop::KIND_LESSON, 1 ) );
	}

	/**
	 * Defensive contract: the flag without the set must fail open, never
	 * lock a course on a caller mistake.
	 */
	public function test_sequential_without_a_completed_set_locks_nothing(): void {
		$stops = [ $this->lesson( 1 ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate( $stops, $this->overlay( [] ), sequential: true );

		self::assertTrue( $map->is_empty() );
	}

	public function test_previous_incomplete_serializes_to_the_wire_shape(): void {
		$stops = [ $this->lesson( 1 ), $this->lesson( 2 ) ];

		$map = $this->policy->evaluate(
			$stops,
			$this->overlay( [] ),
			sequential: true,
			completed: $this->completed( [] )
		);

		self::assertSame(
			[
				'reason'               => 'previous_incomplete',
				'blocking_quiz'        => null,
				'remaining_quiz_count' => 0,
				'blocking_entity'      => [
					'kind'  => 'lesson',
					'id'    => 1,
					'slug'  => 'lesson-1',
					'title' => 'Lesson 1',
				],
			],
			$map->to_node_value( CurriculumStop::KIND_LESSON, 2 )
		);
	}
}
