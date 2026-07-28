<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Services\Progress\ProgressResetService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;

final class ProgressResetServiceTest extends TestCase {

	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryProgressRepository $progress;
	private InMemoryQuizAttemptRepository $quiz_attempts;
	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->now           = new \DateTimeImmutable( '2026-05-10 08:00:00', new \DateTimeZone( 'UTC' ) );
		$this->enrollments   = new InMemoryEnrollmentRepository();
		$this->progress      = new InMemoryProgressRepository( fn (): \DateTimeImmutable => $this->now );
		$this->quiz_attempts = new InMemoryQuizAttemptRepository( fn (): \DateTimeImmutable => $this->now );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service(): ProgressResetService {
		return new class(
			$this->enrollments,
			$this->progress,
			$this->quiz_attempts,
			$this->now
		) extends ProgressResetService {

			public function __construct(
				InMemoryEnrollmentRepository $enrollments,
				InMemoryProgressRepository $progress,
				InMemoryQuizAttemptRepository $quiz_attempts,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $enrollments, $progress, $quiz_attempts );
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock_now;
			}
		};
	}

	private function seed_progress_row( int $user_id, int $course_id, int $entity_id ): void {
		$this->progress->upsert(
			$user_id,
			EntityType::LESSON,
			$entity_id,
			$course_id,
			ProgressStatus::COMPLETED,
			null,
			$this->now->modify( '-10 days' ),
			$this->now->modify( '-10 days' )
		);
	}

	private function seed_in_progress_attempt( int $user_id, int $course_id, int $quiz_id ): int {
		$started = $this->now->modify( '-1 hour' );
		return $this->quiz_attempts->insert(
			new QuizAttempt(
				0,
				$user_id,
				$quiz_id,
				$course_id,
				QuizAttemptStatus::IN_PROGRESS,
				$started,
				null,
				600,
				null,
				null,
				100,
				null,
				70,
				[ 201, 202 ],
				$started,
				$started
			)
		);
	}

	public function test_reset_applies_full_sequence_and_fires_action_once(): void {
		$enrollment_id = $this->enrollments->seed(
			[
				'user_id'      => 5,
				'course_id'    => 7,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'completed_at' => '2026-05-01 09:00:00',
				'progress_pct' => 100,
			]
		);
		$this->seed_progress_row( 5, 7, 200 );
		$open_attempt = $this->seed_in_progress_attempt( 5, 7, 101 );

		Actions\expectDone( 'vl_lms_progress_reset' )
			->once()
			->with( 5, 7, $enrollment_id );

		$result = $this->service()->reset( 5, 7 );

		self::assertNotNull( $result );
		self::assertSame( EnrollmentStatus::ACTIVE, $result->status );
		self::assertSame( 0, $result->progress_pct );
		self::assertNull( $result->completed_at );
		self::assertSame( '2026-05-10 08:00:00', $result->progress_reset_at );
		self::assertSame( [], $this->progress->list_for_user_in_course( 5, 7 ) );
		self::assertSame( QuizAttemptStatus::ABANDONED, $this->quiz_attempts->find( $open_attempt )?->status );
	}

	public function test_reset_never_fires_enrollment_revoked(): void {
		// Certificate-safety pin: CertificateRevoker listens on
		// vl_lms_enrollment_revoked, and a reset must keep certificates.
		$this->enrollments->seed(
			[
				'user_id'   => 5,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		Actions\expectDone( 'vl_lms_enrollment_revoked' )->never();
		Actions\expectDone( 'vl_lms_progress_reset' )->once();

		self::assertNotNull( $this->service()->reset( 5, 7 ) );
	}

	public function test_reset_leaves_other_courses_progress_untouched(): void {
		$this->enrollments->seed(
			[
				'user_id'   => 5,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
		$this->seed_progress_row( 5, 7, 200 );
		$this->seed_progress_row( 5, 8, 300 );

		$this->service()->reset( 5, 7 );

		self::assertCount( 1, $this->progress->list_for_user_in_course( 5, 8 ) );
	}

	public function test_reset_returns_null_when_no_enrollment(): void {
		Actions\expectDone( 'vl_lms_progress_reset' )->never();

		self::assertNull( $this->service()->reset( 5, 7 ) );
	}

	public function test_reset_returns_null_for_revoked_enrollment(): void {
		$this->enrollments->seed(
			[
				'user_id'   => 5,
				'course_id' => 7,
				'status'    => EnrollmentStatus::REVOKED->value,
			]
		);
		$this->seed_progress_row( 5, 7, 200 );

		Actions\expectDone( 'vl_lms_progress_reset' )->never();

		self::assertNull( $this->service()->reset( 5, 7 ) );
		// The guard declines before any write: progress survives.
		self::assertCount( 1, $this->progress->list_for_user_in_course( 5, 7 ) );
	}
}
