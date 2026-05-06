<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Assignment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Assignment\GradingResult;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;

final class GradingResultTest extends TestCase {

	public function test_holds_submission_and_passing_flag(): void {
		$submission = new Submission(
			id: 5,
			assignment_id: 100,
			user_id: 8,
			status: SubmissionStatus::GRADED,
			submission_text: 'ok',
			submission_file_url: null,
			submission_file_name: null,
			score: 80,
			feedback: 'Good',
			graded_by: 1,
			submitted_at: '2026-05-06 09:00:00',
			graded_at: '2026-05-06 10:00:00'
		);

		$passing = new GradingResult( $submission, true );
		$failing = new GradingResult( $submission, false );

		self::assertSame( $submission, $passing->submission );
		self::assertTrue( $passing->is_passing );
		self::assertFalse( $failing->is_passing );
	}
}
