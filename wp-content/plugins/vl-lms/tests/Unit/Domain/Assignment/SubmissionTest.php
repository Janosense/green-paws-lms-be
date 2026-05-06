<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Assignment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;

final class SubmissionTest extends TestCase {

	public function test_with_grade_returns_new_instance_with_graded_status(): void {
		$pending = new Submission(
			id: 17,
			assignment_id: 200,
			user_id: 9,
			status: SubmissionStatus::PENDING,
			submission_text: 'My answer.',
			submission_file_url: null,
			submission_file_name: null,
			score: null,
			feedback: null,
			graded_by: null,
			submitted_at: '2026-05-06 10:00:00',
			graded_at: null
		);

		$graded = $pending->with_grade( 85, 'Гарна робота', 42, '2026-05-06 12:30:00' );

		// Original instance untouched.
		self::assertSame( SubmissionStatus::PENDING, $pending->status );
		self::assertNull( $pending->score );
		self::assertNull( $pending->graded_by );
		self::assertNull( $pending->graded_at );

		// New instance carries grade fields.
		self::assertNotSame( $pending, $graded );
		self::assertSame( SubmissionStatus::GRADED, $graded->status );
		self::assertSame( 85, $graded->score );
		self::assertSame( 'Гарна робота', $graded->feedback );
		self::assertSame( 42, $graded->graded_by );
		self::assertSame( '2026-05-06 12:30:00', $graded->graded_at );
		// Identity columns preserved.
		self::assertSame( 17, $graded->id );
		self::assertSame( 200, $graded->assignment_id );
		self::assertSame( 9, $graded->user_id );
		self::assertSame( 'My answer.', $graded->submission_text );
		self::assertSame( '2026-05-06 10:00:00', $graded->submitted_at );
	}
}
