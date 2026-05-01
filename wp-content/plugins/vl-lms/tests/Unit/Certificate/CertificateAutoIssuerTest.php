<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\CertificateAutoIssuer;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Certificate\IssueResult;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;

final class CertificateAutoIssuerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CertificateService */
	private $service;

	private InMemoryEnrollmentRepository $enrollments;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private CertificateAutoIssuer $issuer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->service     = Mockery::mock( CertificateService::class );
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->logger      = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->issuer      = new CertificateAutoIssuer( $this->service, $this->enrollments, $this->logger );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_completed( int $user_id = 5, int $course_id = 50 ): int {
		return $this->enrollments->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => EnrollmentStatus::COMPLETED->value,
			]
		);
	}

	private function dummy_certificate(): Certificate {
		$now = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		return new Certificate(
			1,
			'uuid',
			5,
			50,
			21,
			$now,
			null,
			null,
			null,
			[],
			null,
			$now,
			$now
		);
	}

	public function test_register_hooks_action(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'vl_lms_course_completed', Mockery::type( 'array' ), 10, 3 );

		$this->issuer->register();
	}

	public function test_logs_warning_when_enrollment_vanished(): void {
		$this->logger->shouldReceive( 'warning' )->once();
		$this->service->shouldNotReceive( 'issue_for_enrollment' );

		$this->issuer->auto_issue( 5, 50, 9999 );
	}

	public function test_logs_info_on_fresh_issue(): void {
		$id = $this->seed_completed();
		$this->service->shouldReceive( 'issue_for_enrollment' )
			->with( $id )
			->andReturn( IssueResult::issued( $this->dummy_certificate() ) );

		$this->logger->shouldReceive( 'info' )->once();

		$this->issuer->auto_issue( 5, 50, $id );
	}

	public function test_logs_debug_on_idempotent_path(): void {
		$id = $this->seed_completed();
		$this->service->shouldReceive( 'issue_for_enrollment' )
			->andReturn( IssueResult::idempotent( $this->dummy_certificate() ) );

		$this->logger->shouldReceive( 'debug' )->once();
		$this->logger->shouldNotReceive( 'info' );

		$this->issuer->auto_issue( 5, 50, $id );
	}

	public function test_skipped_path_logs_nothing(): void {
		$id = $this->seed_completed();
		$this->service->shouldReceive( 'issue_for_enrollment' )
			->andReturn( IssueResult::skipped( 'certificates_disabled', 'no' ) );

		$this->logger->shouldNotReceive( 'info' );
		$this->logger->shouldNotReceive( 'error' );
		$this->logger->shouldNotReceive( 'debug' );

		$this->issuer->auto_issue( 5, 50, $id );
	}

	public function test_failure_path_logs_error(): void {
		$id = $this->seed_completed();
		$this->service->shouldReceive( 'issue_for_enrollment' )
			->andReturn( IssueResult::failure( 'internal_error', 'boom' ) );

		$this->logger->shouldReceive( 'error' )->once();

		$this->issuer->auto_issue( 5, 50, $id );
	}

	public function test_swallows_thrown_exception_and_logs(): void {
		$id = $this->seed_completed();
		$this->service->shouldReceive( 'issue_for_enrollment' )
			->andThrow( new \RuntimeException( 'boom' ) );

		$this->logger->shouldReceive( 'error' )->once();

		// Smoke check — must not throw.
		$this->issuer->auto_issue( 5, 50, $id );
		self::assertTrue( true );
	}
}
