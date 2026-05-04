<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;

final class QuizAttemptTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'                 => '17',
			'user_id'            => '5',
			'quiz_id'            => '101',
			'course_id'          => '7',
			'status'             => 'in_progress',
			'started_at'         => '2026-04-28 10:00:00',
			'submitted_at'       => null,
			'time_limit_seconds' => '600',
			'time_taken_seconds' => null,
			'score'              => null,
			'max_score'          => '100',
			'passed'             => null,
			'passing_threshold'  => '70',
			'question_order'     => '[201, 202, 203]',
			'created_at'         => '2026-04-28 10:00:00',
			'updated_at'         => '2026-04-28 10:00:00',
		];
	}

	public function test_constructor_assigns_every_property(): void {
		$started = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );

		$attempt = new QuizAttempt(
			17,
			5,
			101,
			7,
			QuizAttemptStatus::IN_PROGRESS,
			$started,
			null,
			600,
			null,
			null,
			100,
			null,
			70,
			[ 201, 202, 203 ],
			$started,
			$started
		);

		self::assertSame( 17, $attempt->id );
		self::assertSame( 5, $attempt->user_id );
		self::assertSame( 101, $attempt->quiz_id );
		self::assertSame( 7, $attempt->course_id );
		self::assertSame( QuizAttemptStatus::IN_PROGRESS, $attempt->status );
		self::assertSame( 600, $attempt->time_limit_seconds );
		self::assertSame( 100, $attempt->max_score );
		self::assertSame( 70, $attempt->passing_threshold );
		self::assertSame( [ 201, 202, 203 ], $attempt->question_order );
		self::assertNull( $attempt->submitted_at );
		self::assertNull( $attempt->time_taken_seconds );
		self::assertNull( $attempt->score );
		self::assertNull( $attempt->passed );
	}

	public function test_from_array_coerces_numeric_strings_and_decodes_question_order(): void {
		$attempt = QuizAttempt::from_array( self::sample_row() );

		self::assertIsInt( $attempt->id );
		self::assertSame( 17, $attempt->id );
		self::assertIsInt( $attempt->time_limit_seconds );
		self::assertSame( 600, $attempt->time_limit_seconds );
		self::assertSame( [ 201, 202, 203 ], $attempt->question_order );
		self::assertSame( 'UTC', $attempt->started_at->getTimezone()->getName() );
	}

	public function test_from_array_decodes_passed_when_set(): void {
		$row                       = self::sample_row();
		$row['status']             = 'submitted';
		$row['submitted_at']       = '2026-04-28 10:30:00';
		$row['score']              = '85';
		$row['passed']             = '1';
		$row['time_taken_seconds'] = '1800';

		$attempt = QuizAttempt::from_array( $row );

		self::assertSame( QuizAttemptStatus::SUBMITTED, $attempt->status );
		self::assertSame( 85, $attempt->score );
		self::assertTrue( $attempt->passed );
		self::assertSame( 1800, $attempt->time_taken_seconds );
		self::assertSame( '2026-04-28 10:30:00', $attempt->submitted_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_from_array_to_array_round_trip_preserves_payload(): void {
		$attempt    = QuizAttempt::from_array( self::sample_row() );
		$rehydrated = QuizAttempt::from_array( $attempt->to_array() );

		self::assertSame( $attempt->id, $rehydrated->id );
		self::assertSame( $attempt->user_id, $rehydrated->user_id );
		self::assertSame( $attempt->quiz_id, $rehydrated->quiz_id );
		self::assertSame( $attempt->course_id, $rehydrated->course_id );
		self::assertSame( $attempt->status, $rehydrated->status );
		self::assertSame( $attempt->time_limit_seconds, $rehydrated->time_limit_seconds );
		self::assertSame( $attempt->max_score, $rehydrated->max_score );
		self::assertSame( $attempt->passing_threshold, $rehydrated->passing_threshold );
		self::assertSame( $attempt->question_order, $rehydrated->question_order );
	}

	public function test_to_array_emits_passed_as_int_for_db(): void {
		$row           = self::sample_row();
		$row['status'] = 'submitted';
		$row['passed'] = '1';

		$attempt = QuizAttempt::from_array( $row );
		$out     = $attempt->to_array();

		self::assertSame( 1, $out['passed'] );
		self::assertSame( 'submitted', $out['status'] );
	}

	public function test_is_completed_true_for_submitted_and_expired(): void {
		$row           = self::sample_row();
		$row['status'] = 'submitted';
		self::assertTrue( QuizAttempt::from_array( $row )->is_completed() );

		$row['status'] = 'expired';
		self::assertTrue( QuizAttempt::from_array( $row )->is_completed() );

		$row['status'] = 'in_progress';
		self::assertFalse( QuizAttempt::from_array( $row )->is_completed() );
	}

	public function test_is_in_progress_true_only_for_in_progress(): void {
		$row = self::sample_row();
		self::assertTrue( QuizAttempt::from_array( $row )->is_in_progress() );

		$row['status'] = 'submitted';
		self::assertFalse( QuizAttempt::from_array( $row )->is_in_progress() );
	}

	public function test_score_pct_returns_null_when_not_scored(): void {
		$attempt = QuizAttempt::from_array( self::sample_row() );
		self::assertNull( $attempt->score_pct() );
	}

	public function test_score_pct_returns_zero_when_max_score_zero(): void {
		$row              = self::sample_row();
		$row['score']     = '0';
		$row['max_score'] = '0';

		self::assertSame( 0, QuizAttempt::from_array( $row )->score_pct() );
	}

	public function test_score_pct_rounds_half_up(): void {
		$row              = self::sample_row();
		$row['score']     = '50';
		$row['max_score'] = '100';
		self::assertSame( 50, QuizAttempt::from_array( $row )->score_pct() );

		$row['score'] = '85';
		self::assertSame( 85, QuizAttempt::from_array( $row )->score_pct() );

		// 1/3 → 33.33… → 33
		$row['score']     = '1';
		$row['max_score'] = '3';
		self::assertSame( 33, QuizAttempt::from_array( $row )->score_pct() );

		// 5/8 = 62.5 → 63 (half-up)
		$row['score']     = '5';
		$row['max_score'] = '8';
		self::assertSame( 63, QuizAttempt::from_array( $row )->score_pct() );
	}

	public function test_score_pct_returns_100_when_full_score(): void {
		$row              = self::sample_row();
		$row['score']     = '100';
		$row['max_score'] = '100';
		self::assertSame( 100, QuizAttempt::from_array( $row )->score_pct() );
	}

	public function test_from_array_rejects_unknown_status(): void {
		$row           = self::sample_row();
		$row['status'] = 'graded';

		$this->expectException( \InvalidArgumentException::class );
		QuizAttempt::from_array( $row );
	}

	public function test_properties_are_readonly(): void {
		$attempt = QuizAttempt::from_array( self::sample_row() );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Property.ReadOnlyAssignNotInScope
		$attempt->status = QuizAttemptStatus::SUBMITTED;
	}
}
