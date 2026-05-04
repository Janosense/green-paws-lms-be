<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Webinars;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\JoinWindowPolicy;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarAccessReason;
use WP_Post;

final class WebinarAccessGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&WebinarRegistrationRepository */
	private $repository;

	private \DateTimeImmutable $now;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);

		$this->repository = Mockery::mock( WebinarRegistrationRepository::class );
		$this->now        = new \DateTimeImmutable( '2026-05-15T18:00:00Z' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function gate(): WebinarAccessGate {
		return new WebinarAccessGate(
			$this->repository,
			new JoinWindowPolicy(),
			fn (): \DateTimeImmutable => $this->now
		);
	}

	private function webinar( int $id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_type   = 'vl_webinar';
		$p->post_status = 'publish';
		return $p;
	}

	private function registration( int $webinar_id, int $user_id ): WebinarRegistration {
		return new WebinarRegistration(
			id: 1,
			webinar_id: $webinar_id,
			user_id: $user_id,
			status: WebinarRegistrationStatus::ACTIVE,
			source: WebinarRegistrationSource::SELF_SIGNUP,
			registered_at: '2026-04-01 00:00:00',
			cancelled_at: null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-04-01 00:00:00',
			updated_at: '2026-04-01 00:00:00'
		);
	}

	// can_join

	public function test_can_join_denies_when_user_is_not_actively_registered(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->with( 100, 5 )->andReturn( null );

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertFalse( $decision->allowed );
		self::assertSame( WebinarAccessReason::NOT_REGISTERED, $decision->reason );
	}

	public function test_can_join_denies_when_join_url_is_empty(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertSame( WebinarAccessReason::MEETING_NOT_PROVISIONED, $decision->reason );
	}

	public function test_can_join_denies_before_window_with_opens_at(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T19:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T20:00:00Z' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertSame( WebinarAccessReason::JOIN_WINDOW_NOT_OPEN, $decision->reason );
		self::assertArrayHasKey( 'opens_at', $decision->context );
	}

	public function test_can_join_allows_within_window(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertTrue( $decision->allowed );
		self::assertSame( 'https://zoom.us/j/x', $decision->redirect_url );
	}

	public function test_can_join_allows_at_early_window_boundary(): void {
		// now = 18:00; start = 18:15 → opens_at = 18:00. Boundary is allowed.
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T18:15:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertTrue( $decision->allowed );
	}

	public function test_can_join_allows_at_late_window_boundary(): void {
		// now = 18:00; end = 17:00 → closes_at = 18:00. Boundary is allowed.
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T16:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T17:00:00Z' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertTrue( $decision->allowed );
	}

	public function test_can_join_denies_one_second_after_late_boundary(): void {
		// now = 18:00; end = 16:59:59 → closes_at = 17:59:59. Outside window.
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T16:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T16:59:59Z' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertSame( WebinarAccessReason::JOIN_WINDOW_CLOSED, $decision->reason );
		self::assertArrayHasKey( 'closed_at', $decision->context );
	}

	public function test_can_join_denies_when_schedule_meta_is_unparseable(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_zoom_join_url'   => [ 100 => 'https://zoom.us/j/x' ],
			'_vl_webinar_scheduled_start' => [ 100 => '' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '' ],
		];

		$decision = $this->gate()->can_join( $post, 5 );

		self::assertSame( WebinarAccessReason::MEETING_NOT_PROVISIONED, $decision->reason );
	}

	// can_view_recording

	public function test_can_view_recording_denies_when_not_registered(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( null );

		$decision = $this->gate()->can_view_recording( $post, 5 );

		self::assertSame( WebinarAccessReason::NOT_REGISTERED, $decision->reason );
	}

	public function test_can_view_recording_denies_when_url_is_empty(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );

		$decision = $this->gate()->can_view_recording( $post, 5 );

		self::assertSame( WebinarAccessReason::RECORDING_NOT_AVAILABLE, $decision->reason );
	}

	public function test_can_view_recording_denies_when_until_meta_is_empty(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_recording_url' => [ 100 => 'https://zoom.us/r/x' ],
		];

		$decision = $this->gate()->can_view_recording( $post, 5 );

		self::assertSame( WebinarAccessReason::RECORDING_NOT_AVAILABLE, $decision->reason );
	}

	public function test_can_view_recording_denies_after_window_expires(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_recording_url'             => [ 100 => 'https://zoom.us/r/x' ],
			'_vl_webinar_recording_available_until' => [ 100 => '2026-05-14T00:00:00Z' ],
		];

		$decision = $this->gate()->can_view_recording( $post, 5 );

		self::assertSame( WebinarAccessReason::RECORDING_WINDOW_EXPIRED, $decision->reason );
		self::assertArrayHasKey( 'expired_at', $decision->context );
	}

	public function test_can_view_recording_allows_within_window(): void {
		$post = $this->webinar( 100 );
		$this->repository->shouldReceive( 'find_active' )->andReturn( $this->registration( 100, 5 ) );
		$this->meta = [
			'_vl_webinar_recording_url'             => [ 100 => 'https://zoom.us/r/x' ],
			'_vl_webinar_recording_available_until' => [ 100 => '2026-06-15T00:00:00Z' ],
		];

		$decision = $this->gate()->can_view_recording( $post, 5 );

		self::assertTrue( $decision->allowed );
		self::assertSame( 'https://zoom.us/r/x', $decision->redirect_url );
	}
}
