<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Assignments;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Assignments\AssignmentCompletionListener;
use VL\LMS\Services\Progress\CompletionPropagator;

final class AssignmentCompletionListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_handle_calls_completion_propagator(): void {
		$hierarchy  = Mockery::mock( EntityHierarchy::class );
		$propagator = Mockery::mock( CompletionPropagator::class );

		$propagator->shouldReceive( 'reevaluate_course_completion' )
			->once()
			->with( 9, 777 );

		$listener                     = new TestableAssignmentCompletionListener( $hierarchy, $propagator );
		$listener->resolve_course_for = 777;

		$submission = new Submission(
			id: 1,
			assignment_id: 200,
			user_id: 9,
			status: SubmissionStatus::GRADED,
			submission_text: 'ok',
			submission_file_url: null,
			submission_file_name: null,
			score: 90,
			feedback: 'great',
			graded_by: 1,
			submitted_at: '2026-05-06 09:00:00',
			graded_at: '2026-05-06 11:00:00'
		);

		$listener->handle( $submission, 90 );
	}
}
