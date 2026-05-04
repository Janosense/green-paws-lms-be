<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Enrollment;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;

final class EnrollmentServiceTest extends TestCase {

	private InMemoryEnrollmentRepository $repo;

	private EnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->repo    = new InMemoryEnrollmentRepository();
		$this->service = new EnrollmentService( $this->repo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enroll_creates_active_row_when_none_exists(): void {
		$before = $this->repo->update_call_count();

		$enrollment = $this->service->enroll( 1, 7 );

		self::assertSame( 1, $enrollment->user_id );
		self::assertSame( 7, $enrollment->course_id );
		self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
		self::assertSame( EnrollmentSource::MANUAL, $enrollment->source );
		self::assertSame( 0, $enrollment->progress_pct );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $enrollment->enrolled_at );
		self::assertSame( $before, $this->repo->update_call_count() );
	}

	public function test_enroll_persists_non_null_source_order_id(): void {
		$enrollment = $this->service->enroll(
			1,
			7,
			EnrollmentSource::PURCHASE,
			source_order_id: 99
		);

		self::assertSame( 99, $enrollment->source_order_id );
		self::assertSame( EnrollmentSource::PURCHASE, $enrollment->source );
	}

	public function test_enroll_persists_null_source_order_id_when_omitted(): void {
		$enrollment = $this->service->enroll( 1, 7 );

		self::assertNull( $enrollment->source_order_id );
	}

	public function test_enroll_on_active_row_is_noop_and_preserves_original_source_order_id(): void {
		$id     = $this->repo->seed(
			[
				'user_id'         => 1,
				'course_id'       => 7,
				'status'          => EnrollmentStatus::ACTIVE->value,
				'source_order_id' => 42,
			]
		);
		$before = $this->repo->update_call_count();

		$enrollment = $this->service->enroll(
			1,
			7,
			EnrollmentSource::PURCHASE,
			source_order_id: 99
		);

		self::assertSame( $id, $enrollment->id );
		self::assertSame( 42, $enrollment->source_order_id, 'original order reference must be preserved' );
		self::assertSame( $before, $this->repo->update_call_count(), 're-enroll must not write' );
	}

	public function test_enroll_is_idempotent_for_active_existing_row(): void {
		$id     = $this->repo->seed(
			[
				'user_id'   => 1,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
		$before = $this->repo->update_call_count();

		$enrollment = $this->service->enroll( 1, 7 );

		self::assertSame( $id, $enrollment->id );
		self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
		self::assertSame( $before, $this->repo->update_call_count() );
	}

	public function test_enroll_is_idempotent_for_completed_existing_row(): void {
		$id     = $this->repo->seed(
			[
				'user_id'      => 1,
				'course_id'    => 7,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'completed_at' => '2026-04-01 00:00:00',
			]
		);
		$before = $this->repo->update_call_count();

		$enrollment = $this->service->enroll( 1, 7 );

		self::assertSame( $id, $enrollment->id );
		self::assertSame( EnrollmentStatus::COMPLETED, $enrollment->status );
		self::assertSame( $before, $this->repo->update_call_count() );
	}

	public function test_enroll_resurrects_revoked_row_and_preserves_enrolled_at(): void {
		$original_enrolled_at = '2025-06-01 12:00:00';
		$id                   = $this->repo->seed(
			[
				'user_id'       => 1,
				'course_id'     => 7,
				'status'        => EnrollmentStatus::REVOKED->value,
				'source'        => EnrollmentSource::MANUAL->value,
				'enrolled_at'   => $original_enrolled_at,
				'revoked_at'    => '2025-07-01 00:00:00',
				'revoked_by'    => 2,
				'revoke_reason' => 'policy violation',
				'expires_at'    => '2025-12-31 00:00:00',
			]
		);

		$enrollment = $this->service->enroll(
			1,
			7,
			EnrollmentSource::GROUP,
			source_group_id: 99
		);

		self::assertSame( $id, $enrollment->id );
		self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
		self::assertSame( EnrollmentSource::GROUP, $enrollment->source );
		self::assertSame( 99, $enrollment->source_group_id );
		self::assertSame( $original_enrolled_at, $enrollment->enrolled_at );
		self::assertNull( $enrollment->revoked_at );
		self::assertNull( $enrollment->revoked_by );
		self::assertNull( $enrollment->revoke_reason );
		self::assertNull( $enrollment->expires_at );
	}

	public function test_revoke_sets_status_and_revoke_fields(): void {
		$id = $this->repo->seed(
			[
				'user_id'   => 1,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		$enrollment = $this->service->revoke( $id, 42, 'refund requested' );

		self::assertSame( EnrollmentStatus::REVOKED, $enrollment->status );
		self::assertSame( 42, $enrollment->revoked_by );
		self::assertSame( 'refund requested', $enrollment->revoke_reason );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $enrollment->revoked_at );
	}

	public function test_revoke_fires_vl_lms_enrollment_revoked_action(): void {
		$id = $this->repo->seed(
			[
				'user_id'   => 1,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		Actions\expectDone( 'vl_lms_enrollment_revoked' )
			->once()
			->with( $id, 'policy violation' );

		$enrollment = $this->service->revoke( $id, 42, 'policy violation' );

		// Belt-and-braces: ensure the row actually flipped before the action.
		self::assertSame( EnrollmentStatus::REVOKED, $enrollment->status );
	}

	public function test_revoke_throws_when_enrollment_missing(): void {
		$this->expectException( \RuntimeException::class );

		$this->service->revoke( 9999, 1 );
	}

	public function test_has_active_access_true_for_active_with_no_expiry(): void {
		$this->repo->seed(
			[
				'user_id'   => 1,
				'course_id' => 7,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		self::assertTrue( $this->service->has_active_access( 1, 7 ) );
	}

	public function test_has_active_access_true_for_active_with_future_expiry(): void {
		$this->repo->seed(
			[
				'user_id'    => 1,
				'course_id'  => 7,
				'status'     => EnrollmentStatus::ACTIVE->value,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
			]
		);

		self::assertTrue( $this->service->has_active_access( 1, 7 ) );
	}

	public function test_has_active_access_false_for_active_with_past_expiry(): void {
		$this->repo->seed(
			[
				'user_id'    => 1,
				'course_id'  => 7,
				'status'     => EnrollmentStatus::ACTIVE->value,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
			]
		);

		self::assertFalse( $this->service->has_active_access( 1, 7 ) );
	}

	public function test_has_active_access_true_for_completed(): void {
		$this->repo->seed(
			[
				'user_id'      => 1,
				'course_id'    => 7,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'completed_at' => '2026-04-01 00:00:00',
			]
		);

		self::assertTrue( $this->service->has_active_access( 1, 7 ) );
	}

	/**
	 * @return array<string, array{0: EnrollmentStatus}>
	 */
	public static function inactive_statuses(): array {
		return [
			'revoked'  => [ EnrollmentStatus::REVOKED ],
			'expired'  => [ EnrollmentStatus::EXPIRED ],
			'refunded' => [ EnrollmentStatus::REFUNDED ],
		];
	}

	/**
	 * @dataProvider inactive_statuses
	 */
	public function test_has_active_access_false_for_inactive_statuses( EnrollmentStatus $status ): void {
		$this->repo->seed(
			[
				'user_id'   => 1,
				'course_id' => 7,
				'status'    => $status->value,
			]
		);

		self::assertFalse( $this->service->has_active_access( 1, 7 ) );
	}

	public function test_has_active_access_false_when_no_row(): void {
		self::assertFalse( $this->service->has_active_access( 1, 7 ) );
	}

	public function test_mark_completed_updates_status_and_completed_at(): void {
		$id = $this->repo->seed(
			[
				'user_id'      => 1,
				'course_id'    => 7,
				'status'       => EnrollmentStatus::ACTIVE->value,
				'progress_pct' => 42,
			]
		);

		$enrollment = $this->service->mark_completed( $id );

		self::assertSame( EnrollmentStatus::COMPLETED, $enrollment->status );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $enrollment->completed_at );
		self::assertSame( 42, $enrollment->progress_pct, 'mark_completed must not touch progress_pct.' );
	}

	public function test_mark_completed_throws_for_revoked_row(): void {
		$id = $this->repo->seed(
			[
				'status' => EnrollmentStatus::REVOKED->value,
			]
		);

		$this->expectException( \RuntimeException::class );

		$this->service->mark_completed( $id );
	}

	public function test_mark_completed_throws_for_refunded_row(): void {
		$id = $this->repo->seed(
			[
				'status' => EnrollmentStatus::REFUNDED->value,
			]
		);

		$this->expectException( \RuntimeException::class );

		$this->service->mark_completed( $id );
	}

	public function test_mark_completed_throws_when_missing(): void {
		$this->expectException( \RuntimeException::class );

		$this->service->mark_completed( 9999 );
	}
}
