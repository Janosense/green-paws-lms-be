<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;

final class QuizAttemptStatusTest extends TestCase {

	public function test_cases_match_database_values(): void {
		self::assertSame( 'in_progress', QuizAttemptStatus::IN_PROGRESS->value );
		self::assertSame( 'submitted', QuizAttemptStatus::SUBMITTED->value );
		self::assertSame( 'expired', QuizAttemptStatus::EXPIRED->value );
		self::assertSame( 'abandoned', QuizAttemptStatus::ABANDONED->value );
	}

	public function test_from_string_round_trips_known_values(): void {
		self::assertSame( QuizAttemptStatus::IN_PROGRESS, QuizAttemptStatus::from_string( 'in_progress' ) );
		self::assertSame( QuizAttemptStatus::SUBMITTED, QuizAttemptStatus::from_string( 'submitted' ) );
		self::assertSame( QuizAttemptStatus::EXPIRED, QuizAttemptStatus::from_string( 'expired' ) );
		self::assertSame( QuizAttemptStatus::ABANDONED, QuizAttemptStatus::from_string( 'abandoned' ) );
	}

	public function test_from_string_rejects_unknown(): void {
		$this->expectException( \InvalidArgumentException::class );
		QuizAttemptStatus::from_string( 'graded' );
	}
}
