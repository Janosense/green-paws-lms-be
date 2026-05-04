<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\Access\SessionAccessReason;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\JoinWindowPolicy;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_Post;

final class SessionAccessGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollment_repo;
	private EnrollmentService $enrollments;

	private \DateTimeImmutable $now;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	/** @var array<int, WP_Post|null> */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);

		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );
		$this->now             = new \DateTimeImmutable( '2026-05-15T18:00:00Z' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function gate(): SessionAccessGate {
		return new SessionAccessGate(
			$this->enrollments,
			new JoinWindowPolicy(),
			fn (): \DateTimeImmutable => $this->now
		);
	}

	private function post( int $id, string $type, string $status, int $parent = 0 ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_type   = $type;
		$p->post_status = $status;
		$p->post_parent = $parent;
		return $p;
	}

	private function enroll( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);
	}

	private function published_session_under_course( int $session_id, int $course_id ): WP_Post {
		$session                   = $this->post( $session_id, 'vl_session', 'publish', $course_id );
		$this->posts[ $course_id ] = $this->post( $course_id, 'vl_course', 'publish' );
		return $session;
	}

	// can_join

	public function test_can_join_denies_when_parent_course_unresolvable(): void {
		// post_parent = 0 → no course
		$session = $this->post( 100, 'vl_session', 'publish', 0 );

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::COURSE_NOT_FOUND, $decision->reason );
	}

	public function test_can_join_denies_when_parent_course_unpublished(): void {
		$session          = $this->post( 100, 'vl_session', 'publish', 200 );
		$this->posts[200] = $this->post( 200, 'vl_course', 'draft' );

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::COURSE_NOT_FOUND, $decision->reason );
	}

	public function test_can_join_denies_when_user_not_enrolled(): void {
		$session = $this->published_session_under_course( 100, 200 );
		// no enrollment seeded → has_active_access returns false

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::NOT_ENROLLED, $decision->reason );
	}

	public function test_can_join_denies_when_session_cancelled(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_status' => [ 100 => 'cancelled' ],
		];

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::SESSION_CANCELLED, $decision->reason );
	}

	public function test_can_join_denies_when_join_url_empty(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::MEETING_NOT_PROVISIONED, $decision->reason );
	}

	public function test_can_join_denies_before_window(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_session_scheduled_start' => [ 100 => '2026-05-15T19:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-05-15T20:30:00Z' ],
		];

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::JOIN_WINDOW_NOT_OPEN, $decision->reason );
		self::assertArrayHasKey( 'opens_at', $decision->context );
	}

	public function test_can_join_denies_after_window(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_session_scheduled_start' => [ 100 => '2026-05-15T15:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-05-15T16:00:00Z' ],
		];

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertSame( SessionAccessReason::JOIN_WINDOW_CLOSED, $decision->reason );
		self::assertArrayHasKey( 'closed_at', $decision->context );
	}

	public function test_can_join_allows_within_window(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_session_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-05-15T19:00:00Z' ],
		];

		$decision = $this->gate()->can_join( $session, 5 );

		self::assertTrue( $decision->allowed );
		self::assertSame( 'https://zoom.us/j/x', $decision->redirect_url );
	}

	// can_view_recording

	public function test_can_view_recording_denies_when_not_enrolled(): void {
		$session = $this->published_session_under_course( 100, 200 );
		// no enrollment seeded → has_active_access returns false

		$decision = $this->gate()->can_view_recording( $session, 5 );

		self::assertSame( SessionAccessReason::NOT_ENROLLED, $decision->reason );
	}

	public function test_can_view_recording_denies_when_url_empty(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );

		$decision = $this->gate()->can_view_recording( $session, 5 );

		self::assertSame( SessionAccessReason::RECORDING_NOT_AVAILABLE, $decision->reason );
	}

	public function test_can_view_recording_allows_when_until_date_is_empty(): void {
		// Empty until = indefinite access while course access lasts.
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_recording_url' => [ 100 => 'https://zoom.us/r/x' ],
		];

		$decision = $this->gate()->can_view_recording( $session, 5 );

		self::assertTrue( $decision->allowed );
		self::assertSame( 'https://zoom.us/r/x', $decision->redirect_url );
	}

	public function test_can_view_recording_denies_after_until_date(): void {
		$session = $this->published_session_under_course( 100, 200 );
		$this->enroll( 5, 200 );
		$this->meta = [
			'_vl_session_recording_url'             => [ 100 => 'https://zoom.us/r/x' ],
			'_vl_session_recording_available_until' => [ 100 => '2026-05-14T00:00:00Z' ],
		];

		$decision = $this->gate()->can_view_recording( $session, 5 );

		self::assertSame( SessionAccessReason::RECORDING_WINDOW_EXPIRED, $decision->reason );
		self::assertArrayHasKey( 'expired_at', $decision->context );
	}
}
