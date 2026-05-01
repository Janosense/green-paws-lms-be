<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Certificate\IssueResult;
use VL\LMS\Certificate\SnapshotBuilder;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryCertificateRepository;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;

final class CertificateServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryCertificateRepository $certs;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryQuizAttemptRepository $attempts;

	/** @var Mockery\MockInterface&SnapshotBuilder */
	private $snapshot;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private \DateTimeImmutable $now;

	/** @var array<int, string> */
	private array $course_meta = [];

	/** @var list<int> */
	private array $final_exam_quiz_ids = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->now         = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		$this->certs       = new InMemoryCertificateRepository( fn () => $this->now );
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->attempts    = new InMemoryQuizAttemptRepository();
		$this->snapshot    = Mockery::mock( SnapshotBuilder::class );
		$this->logger      = Mockery::mock( Logger::class )->shouldIgnoreMissing();

		$meta = &$this->course_meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$meta ): mixed {
				if ( '_vl_course_certificate_enabled' === $key ) {
					return $meta[ $id ] ?? '';
				}
				return '';
			}
		);

		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-3333-4444-555555555555' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service(): CertificateService {
		$ids = &$this->final_exam_quiz_ids;
		return new class(
			$this->certs,
			$this->enrollments,
			$this->snapshot,
			$this->attempts,
			$this->logger,
			$ids,
			$this->now
		) extends CertificateService {
			/** @var list<int> */
			private array $final_exam_ids;
			private \DateTimeImmutable $frozen_now;

			public function __construct(
				CertificateRepository $c,
				EnrollmentRepository $e,
				SnapshotBuilder $s,
				QuizAttemptRepository $q,
				Logger $l,
				array &$final_exam_ids,
				\DateTimeImmutable $frozen_now
			) {
				parent::__construct( $c, $e, $s, $q, $l );
				$this->final_exam_ids = &$final_exam_ids;
				$this->frozen_now     = $frozen_now;
			}

			protected function find_final_exam_quiz_ids_in_course( int $course_id ): array {
				return $this->final_exam_ids;
			}

			protected function current_time_utc(): \DateTimeImmutable {
				return $this->frozen_now;
			}
		};
	}

	private function seed_completed_enrollment( int $user_id = 5, int $course_id = 50 ): int {
		return $this->enrollments->seed(
			[
				'user_id'      => $user_id,
				'course_id'    => $course_id,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'progress_pct' => 100,
				'completed_at' => '2026-04-29 10:00:00',
			]
		);
	}

	private function default_snapshot(): array {
		return [
			'course_title'         => 'Course X',
			'course_slug'          => 'course-x',
			'learner_full_name'    => 'Богдан Коваль',
			'learner_display_name' => 'Богдан К.',
			'instructor_names'     => [ 'Олена Ш.' ],
			'issuer_name'          => 'Green Paws LMS',
			'issued_at_iso'        => '2026-04-29T10:00:00+00:00',
			'final_score_pct'      => 92,
			'template_version'     => 'v1',
		];
	}

	public function test_issue_returns_failure_when_enrollment_not_found(): void {
		$result = $this->service()->issue_for_enrollment( 9999 );

		self::assertFalse( $result->success );
		self::assertSame( 'enrollment_not_found', $result->error_code );
	}

	public function test_issue_returns_failure_when_enrollment_not_completed(): void {
		$id = $this->enrollments->seed(
			[
				'user_id'   => 5,
				'course_id' => 50,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertFalse( $result->success );
		self::assertSame( 'enrollment_not_completed', $result->error_code );
	}

	public function test_issue_returns_skipped_when_certificates_disabled(): void {
		$id                    = $this->seed_completed_enrollment();
		$this->course_meta[50] = '0';

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertFalse( $result->success );
		self::assertTrue( $result->skipped );
		self::assertSame( 'certificates_disabled', $result->error_code );
	}

	public function test_issue_creates_new_certificate_when_no_existing(): void {
		$id                    = $this->seed_completed_enrollment();
		$this->course_meta[50] = '1';

		$this->snapshot->shouldReceive( 'build_snapshot' )
			->with( 5, 50, null, null, $this->now )
			->andReturn( $this->default_snapshot() );

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertTrue( $result->success );
		self::assertFalse( $result->idempotent );
		self::assertNotNull( $result->certificate );
		self::assertSame( 5, $result->certificate->user_id );
		self::assertSame( 50, $result->certificate->course_id );
		self::assertSame( '11111111-2222-3333-4444-555555555555', $result->certificate->uuid );
		self::assertSame( 'Course X', $result->certificate->snapshot_data['course_title'] );
	}

	public function test_issue_is_idempotent_when_active_certificate_exists(): void {
		$id                    = $this->seed_completed_enrollment();
		$this->course_meta[50] = '1';

		// Pre-existing certificate.
		$this->certs->insert(
			new Certificate(
				0,
				'pre-existing-uuid',
				5,
				50,
				$id,
				$this->now,
				null,
				80,
				100,
				$this->default_snapshot(),
				null,
				$this->now,
				$this->now
			)
		);

		$this->snapshot->shouldNotReceive( 'build_snapshot' );

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertTrue( $result->success );
		self::assertTrue( $result->idempotent );
		self::assertNotNull( $result->certificate );
		self::assertSame( 'pre-existing-uuid', $result->certificate->uuid );
	}

	public function test_issue_pulls_score_from_passed_final_exam(): void {
		$id                        = $this->seed_completed_enrollment();
		$this->course_meta[50]     = '1';
		$this->final_exam_quiz_ids = [ 101 ];

		$this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::SUBMITTED,
				$this->now,
				$this->now,
				0,
				600,
				92,
				100,
				true,
				70,
				[ 201, 202, 203 ],
				$this->now,
				$this->now
			)
		);

		$this->snapshot->shouldReceive( 'build_snapshot' )
			->with( 5, 50, 92, 100, $this->now )
			->andReturn( $this->default_snapshot() );

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertTrue( $result->success );
		self::assertNotNull( $result->certificate );
		self::assertSame( 92, $result->certificate->final_score );
		self::assertSame( 100, $result->certificate->final_max_score );
	}

	public function test_issue_picks_highest_score_across_multiple_final_exams(): void {
		$id                        = $this->seed_completed_enrollment();
		$this->course_meta[50]     = '1';
		$this->final_exam_quiz_ids = [ 101, 102 ];

		$this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				101,
				50,
				QuizAttemptStatus::SUBMITTED,
				$this->now,
				$this->now,
				0,
				600,
				70,
				100,
				true,
				70,
				[],
				$this->now,
				$this->now
			)
		);
		$this->attempts->insert(
			new QuizAttempt(
				0,
				5,
				102,
				50,
				QuizAttemptStatus::SUBMITTED,
				$this->now,
				$this->now,
				0,
				600,
				95,
				100,
				true,
				70,
				[],
				$this->now,
				$this->now
			)
		);

		$this->snapshot->shouldReceive( 'build_snapshot' )
			->with( 5, 50, 95, 100, $this->now )
			->andReturn( $this->default_snapshot() );

		$result = $this->service()->issue_for_enrollment( $id );

		self::assertTrue( $result->success );
		self::assertSame( 95, $result->certificate?->final_score );
	}

	public function test_revoke_flips_active_certificate(): void {
		$id = $this->certs->insert(
			new Certificate(
				0,
				'uuid',
				5,
				50,
				21,
				$this->now,
				null,
				null,
				null,
				$this->default_snapshot(),
				null,
				$this->now,
				$this->now
			)
		);

		$ok = $this->service()->revoke( $id );

		self::assertTrue( $ok );
		self::assertNotNull( $this->certs->find( $id )?->revoked_at );
	}

	public function test_revoke_returns_false_when_already_revoked(): void {
		$id = $this->certs->insert(
			new Certificate(
				0,
				'uuid',
				5,
				50,
				21,
				$this->now,
				$this->now,
				null,
				null,
				$this->default_snapshot(),
				null,
				$this->now,
				$this->now
			)
		);

		self::assertFalse( $this->service()->revoke( $id ) );
	}

	public function test_revoke_returns_false_when_certificate_missing(): void {
		self::assertFalse( $this->service()->revoke( 9999 ) );
	}

	public function test_find_for_user_passes_through_to_repo(): void {
		$id = $this->certs->insert(
			new Certificate(
				0,
				'uuid',
				5,
				50,
				21,
				$this->now,
				null,
				null,
				null,
				$this->default_snapshot(),
				null,
				$this->now,
				$this->now
			)
		);

		$rows = $this->service()->find_for_user( 5 );
		self::assertCount( 1, $rows );
		self::assertSame( $id, $rows[0]->id );
	}

	public function test_find_by_uuid_passes_through_to_repo(): void {
		$id = $this->certs->insert(
			new Certificate(
				0,
				'find-me-uuid',
				5,
				50,
				21,
				$this->now,
				null,
				null,
				null,
				$this->default_snapshot(),
				null,
				$this->now,
				$this->now
			)
		);

		$found = $this->service()->find_by_uuid( 'find-me-uuid' );
		self::assertNotNull( $found );
		self::assertSame( $id, $found->id );
	}
}
