<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Assignments;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\AssignmentSubmissionFailedException;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryAssignmentSubmissionRepository;

final class AssignmentSubmissionServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryAssignmentSubmissionRepository $repo;

	/** @var Mockery\MockInterface&EnrollmentService */
	private $enrollment;

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	private TestableAssignmentSubmissionService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repo       = new InMemoryAssignmentSubmissionRepository();
		$this->enrollment = Mockery::mock( EnrollmentService::class );
		$this->hierarchy  = Mockery::mock( EntityHierarchy::class );

		$this->service                     = new TestableAssignmentSubmissionService(
			$this->repo,
			$this->enrollment,
			$this->hierarchy
		);
		$this->service->resolve_course_for = 555;
		$this->service->meta               = [
			'_vl_assignment_submission_type' => 'text',
			'_vl_assignment_text_required'   => false,
			'_vl_assignment_file_required'   => false,
			'_vl_assignment_max_score'       => 100,
			'_vl_assignment_passing_score'   => 60,
		];
		$this->service->now_value          = '2026-05-06 12:00:00';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_submit_inserts_new_pending_row_when_no_existing(): void {
		$this->enrollment->shouldReceive( 'has_active_access' )->with( 9, 555 )->andReturn( true );

		$submission = $this->service->submit( 200, 9, 'Hello', null, null );

		self::assertSame( 1, $submission->id );
		self::assertSame( SubmissionStatus::PENDING, $submission->status );
		self::assertSame( 'Hello', $submission->submission_text );
		self::assertSame( '2026-05-06 12:00:00', $submission->submitted_at );

		$persisted = $this->repo->find_by_assignment_user( 200, 9 );
		self::assertNotNull( $persisted );
		self::assertSame( 1, $persisted->id );
	}

	public function test_submit_updates_existing_pending_row_idempotently(): void {
		$this->enrollment->shouldReceive( 'has_active_access' )->with( 9, 555 )->andReturn( true );
		$this->repo->insert(
			new Submission(
				id: 0,
				assignment_id: 200,
				user_id: 9,
				status: SubmissionStatus::PENDING,
				submission_text: 'old',
				submission_file_url: null,
				submission_file_name: null,
				score: null,
				feedback: null,
				graded_by: null,
				submitted_at: '2026-05-06 09:00:00',
				graded_at: null
			)
		);

		$this->service->now_value = '2026-05-06 14:00:00';
		$updated                  = $this->service->submit( 200, 9, 'updated text', null, null );

		self::assertSame( 1, $updated->id );
		self::assertSame( SubmissionStatus::PENDING, $updated->status );
		self::assertSame( 'updated text', $updated->submission_text );
		self::assertSame( '2026-05-06 14:00:00', $updated->submitted_at );

		// Single row in storage — no duplicate was created.
		self::assertSame( 1, $this->repo->count_pending() );
	}

	public function test_submit_throws_submission_locked_on_graded_existing(): void {
		$this->enrollment->shouldReceive( 'has_active_access' )->with( 9, 555 )->andReturn( true );
		$this->repo->insert(
			new Submission(
				id: 0,
				assignment_id: 200,
				user_id: 9,
				status: SubmissionStatus::GRADED,
				submission_text: 'previous',
				submission_file_url: null,
				submission_file_name: null,
				score: 90,
				feedback: 'great',
				graded_by: 1,
				submitted_at: '2026-05-06 09:00:00',
				graded_at: '2026-05-06 11:00:00'
			)
		);

		try {
			$this->service->submit( 200, 9, 'try again', null, null );
			self::fail( 'Expected submission_locked exception.' );
		} catch ( AssignmentSubmissionFailedException $e ) {
			self::assertSame( AssignmentSubmissionFailedException::SUBMISSION_LOCKED, $e->error_code );
		}
	}

	public function test_submit_throws_not_enrolled_when_no_course_access(): void {
		$this->enrollment->shouldReceive( 'has_active_access' )->with( 9, 555 )->andReturn( false );

		try {
			$this->service->submit( 200, 9, 'hello', null, null );
			self::fail( 'Expected not_enrolled exception.' );
		} catch ( AssignmentSubmissionFailedException $e ) {
			self::assertSame( AssignmentSubmissionFailedException::NOT_ENROLLED, $e->error_code );
		}
	}

	public function test_grade_fires_action_when_score_passes(): void {
		Actions\expectDone( 'vl_lms_assignment_graded' )->once();

		$id = $this->repo->insert(
			new Submission(
				id: 0,
				assignment_id: 200,
				user_id: 9,
				status: SubmissionStatus::PENDING,
				submission_text: 'hi',
				submission_file_url: null,
				submission_file_name: null,
				score: null,
				feedback: null,
				graded_by: null,
				submitted_at: '2026-05-06 09:00:00',
				graded_at: null
			)
		);

		$this->service->meta['_vl_assignment_max_score']     = 100;
		$this->service->meta['_vl_assignment_passing_score'] = 60;

		$result = $this->service->grade( $id, 75, 'good', 1 );

		self::assertTrue( $result->is_passing );
		self::assertSame( SubmissionStatus::GRADED, $result->submission->status );
		self::assertSame( 75, $result->submission->score );
	}
}
