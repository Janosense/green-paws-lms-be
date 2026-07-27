<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api\Transformers;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Tests\Unit\Api\TestableQuizAttemptHistoryTransformer;

final class QuizAttemptHistoryTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TestableQuizAttemptHistoryTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transformer                   = new TestableQuizAttemptHistoryTransformer();
		$this->transformer->titles[101]      = 'Анатомія: підсумковий тест';
		$this->transformer->course_slugs[50] = 'anatomiya';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function attempt(
		int $id,
		QuizAttemptStatus $status,
		?int $score,
		?bool $passed,
		string $started_at,
		?string $submitted_at = null,
		int $max_score = 100
	): QuizAttempt {
		$started = new \DateTimeImmutable( $started_at, new \DateTimeZone( 'UTC' ) );
		return new QuizAttempt(
			$id,
			5,
			101,
			50,
			$status,
			$started,
			null === $submitted_at ? null : new \DateTimeImmutable( $submitted_at, new \DateTimeZone( 'UTC' ) ),
			600,
			null === $submitted_at ? null : 240,
			$score,
			$max_score,
			$passed,
			70,
			[ 201 ],
			$started,
			$started
		);
	}

	public function test_empty_history_returns_well_formed_envelope(): void {
		$out = $this->transformer->transform( 101, [] );

		self::assertSame( 101, $out['quiz_id'] );
		self::assertSame( 'Анатомія: підсумковий тест', $out['quiz_title'] );
		self::assertSame( 0, $out['total_attempts'] );
		self::assertSame( 0, $out['graded_attempts'] );
		self::assertSame( [], $out['attempts'] );
		self::assertNull( $out['best_score'] );
		self::assertFalse( $out['passed'] );
		self::assertNull( $out['passed_on_attempt'] );
		// No attempts means no snapshotted course to read off.
		self::assertSame( 0, $out['course_id'] );
		self::assertSame( '', $out['course_slug'] );
	}

	public function test_attempts_are_numbered_from_one_in_listed_order(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::SUBMITTED, 55, false, '2026-05-02 10:00:00', '2026-05-02 10:04:00' ),
				$this->attempt( 3, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-03 10:00:00', '2026-05-03 10:04:00' ),
			]
		);

		self::assertSame( [ 1, 2, 3 ], array_column( $out['attempts'], 'attempt_number' ) );
		self::assertSame( [ 1, 2, 3 ], array_column( $out['attempts'], 'id' ) );
	}

	public function test_passed_on_attempt_reports_the_first_passing_sitting(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::SUBMITTED, 55, false, '2026-05-02 10:00:00', '2026-05-02 10:04:00' ),
				$this->attempt( 3, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-03 10:00:00', '2026-05-03 10:04:00' ),
				$this->attempt( 4, QuizAttemptStatus::SUBMITTED, 95, true, '2026-05-04 10:00:00', '2026-05-04 10:04:00' ),
			]
		);

		self::assertTrue( $out['passed'] );
		// Third sitting cleared it — not the fourth, which scored higher.
		self::assertSame( 3, $out['passed_on_attempt'] );
	}

	public function test_failed_history_reports_no_passing_attempt(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::SUBMITTED, 55, false, '2026-05-02 10:00:00', '2026-05-02 10:04:00' ),
			]
		);

		self::assertFalse( $out['passed'] );
		self::assertNull( $out['passed_on_attempt'] );
		self::assertSame( 55.0, $out['best_score'] );
	}

	public function test_failed_attempts_are_retained_as_rows(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-02 10:00:00', '2026-05-02 10:04:00' ),
			]
		);

		self::assertCount( 2, $out['attempts'] );
		self::assertFalse( $out['attempts'][0]['passed'] );
		self::assertSame( 40, $out['attempts'][0]['score'] );
		self::assertSame( 40.0, $out['attempts'][0]['score_percent'] );
		self::assertTrue( $out['attempts'][1]['passed'] );
	}

	public function test_in_progress_attempt_counts_as_a_sitting_but_not_as_graded(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::IN_PROGRESS, null, null, '2026-05-02 10:00:00' ),
			]
		);

		self::assertSame( 2, $out['total_attempts'] );
		self::assertSame( 1, $out['graded_attempts'] );
		self::assertNull( $out['attempts'][1]['score'] );
		self::assertNull( $out['attempts'][1]['score_percent'] );
		self::assertNull( $out['attempts'][1]['submitted_at'] );
	}

	public function test_expired_attempt_is_graded_but_excluded_from_best_score(): void {
		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::EXPIRED, 90, false, '2026-05-02 10:00:00', '2026-05-02 10:10:00' ),
			]
		);

		self::assertSame( 2, $out['graded_attempts'] );
		// Matches QuizAttemptRepository::best_score_for_user, which filters
		// on SUBMITTED — an expired sitting is scored but not completed.
		self::assertSame( 40.0, $out['best_score'] );
		self::assertSame( 90.0, $out['attempts'][1]['score_percent'] );
	}

	public function test_attempts_remaining_counts_graded_rows_against_the_ceiling(): void {
		$this->transformer->max_attempts_meta[101] = 3;

		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::IN_PROGRESS, null, null, '2026-05-02 10:00:00' ),
			]
		);

		self::assertSame( 3, $out['max_attempts'] );
		// The open attempt has not been spent yet.
		self::assertSame( 2, $out['attempts_remaining'] );
	}

	public function test_attempts_remaining_is_null_when_unlimited(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ) ]
		);

		self::assertSame( 0, $out['max_attempts'] );
		self::assertNull( $out['attempts_remaining'] );
	}

	public function test_attempts_remaining_floors_at_zero(): void {
		$this->transformer->max_attempts_meta[101] = 1;

		$out = $this->transformer->transform(
			101,
			[
				$this->attempt( 1, QuizAttemptStatus::SUBMITTED, 40, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ),
				$this->attempt( 2, QuizAttemptStatus::SUBMITTED, 50, false, '2026-05-02 10:00:00', '2026-05-02 10:04:00' ),
			]
		);

		self::assertSame( 0, $out['attempts_remaining'] );
	}

	public function test_score_percent_is_null_when_the_snapshot_carries_no_points(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 0, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00', 0 ) ]
		);

		self::assertNull( $out['attempts'][0]['score_percent'] );
		self::assertNull( $out['best_score'] );
	}

	public function test_score_percent_rounds_to_two_decimals(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 1, false, '2026-05-01 10:00:00', '2026-05-01 10:04:00', 3 ) ]
		);

		self::assertSame( 33.33, $out['attempts'][0]['score_percent'] );
	}

	public function test_course_context_is_read_off_the_snapshotted_rows(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ) ]
		);

		self::assertSame( 50, $out['course_id'] );
		self::assertSame( 'anatomiya', $out['course_slug'] );
	}

	public function test_rows_carry_iso8601_timestamps_and_snapshotted_threshold(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ) ]
		);

		$row = $out['attempts'][0];
		self::assertSame( '2026-05-01T10:00:00+00:00', $row['started_at'] );
		self::assertSame( '2026-05-01T10:04:00+00:00', $row['submitted_at'] );
		self::assertSame( 70, $row['passing_threshold'] );
		self::assertSame( 240, $row['time_taken_seconds'] );
		self::assertSame( 'submitted', $row['status'] );
	}

	public function test_row_shape_excludes_player_only_fields(): void {
		$out = $this->transformer->transform(
			101,
			[ $this->attempt( 1, QuizAttemptStatus::SUBMITTED, 80, true, '2026-05-01 10:00:00', '2026-05-01 10:04:00' ) ]
		);

		// question_order / time_remaining_seconds belong to the live player
		// envelope; shipping them per history row would be dead weight.
		self::assertArrayNotHasKey( 'question_order', $out['attempts'][0] );
		self::assertArrayNotHasKey( 'time_remaining_seconds', $out['attempts'][0] );
	}
}
