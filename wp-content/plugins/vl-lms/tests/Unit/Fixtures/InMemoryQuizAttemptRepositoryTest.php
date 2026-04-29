<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;

final class InMemoryQuizAttemptRepositoryTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function attempt(
		QuizAttemptStatus $status = QuizAttemptStatus::IN_PROGRESS,
		int $user_id = 5,
		int $quiz_id = 101,
		int $course_id = 7,
		?int $score = null,
		?bool $passed = null,
		string $started_at = '2026-04-28 10:00:00',
		?string $submitted_at = null
	): QuizAttempt {
		return new QuizAttempt(
			0,
			$user_id,
			$quiz_id,
			$course_id,
			$status,
			self::utc( $started_at ),
			null === $submitted_at ? null : self::utc( $submitted_at ),
			600,
			null,
			$score,
			100,
			$passed,
			70,
			[ 201, 202, 203 ],
			self::utc( '2026-04-28 10:00:00' ),
			self::utc( '2026-04-28 10:00:00' )
		);
	}

	public function test_insert_and_find_round_trip(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$id   = $repo->insert( self::attempt() );

		self::assertSame( 1, $id );
		$found = $repo->find( 1 );
		self::assertNotNull( $found );
		self::assertSame( 5, $found->user_id );
		self::assertSame( 101, $found->quiz_id );
	}

	public function test_count_for_user_in_quiz_only_counts_matching_pair(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt() );
		$repo->insert( self::attempt() );
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS, 5, 999 ) );
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS, 6, 101 ) );

		self::assertSame( 2, $repo->count_for_user_in_quiz( 5, 101 ) );
	}

	public function test_find_active_returns_most_recent_in_progress(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS, 5, 101, 7, null, null, '2026-04-28 09:00:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS, 5, 101, 7, null, null, '2026-04-28 10:00:00' ) );

		$active = $repo->find_active_for_user_in_quiz( 5, 101 );
		self::assertNotNull( $active );
		self::assertSame( '2026-04-28 10:00:00', $active->started_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_find_active_skips_submitted(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$id   = $repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 90, true ) );

		self::assertNull( $repo->find_active_for_user_in_quiz( 5, 101 ) );
		self::assertNotNull( $repo->find( $id ) );
	}

	public function test_find_best_score_picks_highest_submitted(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 60, false, '2026-04-28 09:00:00', '2026-04-28 09:30:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 90, true, '2026-04-28 10:00:00', '2026-04-28 10:30:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 75, true, '2026-04-28 11:00:00', '2026-04-28 11:30:00' ) );

		$best = $repo->find_best_score_for_user_in_quiz( 5, 101 );
		self::assertNotNull( $best );
		self::assertSame( 90, $best->score );
	}

	public function test_find_best_score_excludes_in_progress(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS ) );

		self::assertNull( $repo->find_best_score_for_user_in_quiz( 5, 101 ) );
	}

	public function test_list_passed_for_user_in_course_filters_passed_and_submitted(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 90, true, '2026-04-28 09:00:00', '2026-04-28 09:30:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 102, 7, 50, false, '2026-04-28 10:00:00', '2026-04-28 10:30:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 103, 8, 99, true, '2026-04-28 11:00:00', '2026-04-28 11:30:00' ) );

		$rows = $repo->list_passed_for_user_in_course( 5, 7 );
		self::assertCount( 1, $rows );
		self::assertSame( 101, $rows[0]->quiz_id );
	}

	public function test_find_passed_final_exam_returns_targeted_match(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 90, true, '2026-04-28 09:00:00', '2026-04-28 09:30:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 999, 7, 95, true, '2026-04-28 10:00:00', '2026-04-28 10:30:00' ) );

		$result = $repo->find_passed_final_exam_for_user_in_course( 5, 7, 999 );
		self::assertNotNull( $result );
		self::assertSame( 999, $result->quiz_id );
		self::assertSame( 95, $result->score );
	}

	public function test_update_final_writes_all_columns_immutably(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$id   = $repo->insert( self::attempt() );

		$ok = $repo->update_final(
			$id,
			85,
			true,
			1800,
			self::utc( '2026-04-28 10:30:00' ),
			QuizAttemptStatus::SUBMITTED
		);
		self::assertTrue( $ok );

		$row = $repo->find( $id );
		self::assertNotNull( $row );
		self::assertSame( 85, $row->score );
		self::assertTrue( $row->passed );
		self::assertSame( 1800, $row->time_taken_seconds );
		self::assertSame( QuizAttemptStatus::SUBMITTED, $row->status );
		self::assertSame( '2026-04-28 10:30:00', $row->submitted_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_update_status_returns_false_for_unknown_id(): void {
		$repo = new InMemoryQuizAttemptRepository();
		self::assertFalse( $repo->update_status( 999, QuizAttemptStatus::ABANDONED ) );
	}

	public function test_list_for_user_in_quiz_returns_all_attempts_ordered(): void {
		$repo = new InMemoryQuizAttemptRepository();
		$repo->insert( self::attempt( QuizAttemptStatus::IN_PROGRESS, 5, 101, 7, null, null, '2026-04-28 09:00:00' ) );
		$repo->insert( self::attempt( QuizAttemptStatus::SUBMITTED, 5, 101, 7, 60, false, '2026-04-28 10:00:00', '2026-04-28 10:30:00' ) );

		$rows = $repo->list_for_user_in_quiz( 5, 101 );
		self::assertCount( 2, $rows );
		self::assertSame( '2026-04-28 10:00:00', $rows[0]->started_at->format( 'Y-m-d H:i:s' ) );
	}
}
