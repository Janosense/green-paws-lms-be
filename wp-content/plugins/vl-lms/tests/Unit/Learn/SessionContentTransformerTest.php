<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\SessionAttendance\SessionAttendance;
use VL\LMS\Learn\Access\SessionAccessDecision;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\Access\SessionAccessReason;
use VL\LMS\Learn\SessionContentTransformer;
use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Services\JoinWindowPolicy;
use WP_Post;

final class SessionContentTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&SessionAccessGate */
	private $gate;

	/** @var Mockery\MockInterface&SessionAttendanceRepository */
	private $attendance;

	private \DateTimeImmutable $now;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	/** @var array<int, WP_Post|null> */
	private array $posts = [];

	private SessionContentTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$this->gate        = Mockery::mock( SessionAccessGate::class );
		$this->attendance  = Mockery::mock( SessionAttendanceRepository::class );
		$this->now         = new \DateTimeImmutable( '2026-05-10T00:00:00Z' );
		$this->transformer = new SessionContentTransformer(
			$this->gate,
			$this->attendance,
			new JoinWindowPolicy(),
			fn (): \DateTimeImmutable => $this->now
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function session( int $id, int $course_id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = 'module-3';
		$p->post_title  = 'Module 3: Clinical Cases';
		$p->post_type   = 'vl_session';
		$p->post_status = 'publish';
		$p->post_parent = $course_id;
		return $p;
	}

	private function course_post( int $id, string $slug ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_type   = 'vl_course';
		$p->post_status = 'publish';
		return $p;
	}

	public function test_full_payload_with_attendance_and_no_leak_of_zoom_secrets(): void {
		$session          = $this->session( 100, 200 );
		$this->posts[200] = $this->course_post( 200, 'cardiology' );
		$this->meta       = [
			'_vl_session_scheduled_start'           => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_session_scheduled_end'             => [ 100 => '2026-05-15T19:30:00Z' ],
			'_vl_session_status'                    => [ 100 => 'scheduled' ],
			'_vl_session_number'                    => [ 100 => 3 ],
			'_vl_session_recording_available_until' => [ 100 => '2026-06-15T00:00:00Z' ],
			'_vl_session_materials'                 => [
				100 => [
					[
						'url'  => 'https://example.com/slides.pdf',
						'name' => 'Slides',
						'size' => 12345,
					],
				],
			],
		];

		$this->gate->shouldReceive( 'can_join' )
			->andReturn( SessionAccessDecision::deny( SessionAccessReason::JOIN_WINDOW_NOT_OPEN ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( SessionAccessDecision::deny( SessionAccessReason::RECORDING_NOT_AVAILABLE ) );
		$this->attendance->shouldReceive( 'list_for_user' )->with( 5, 100 )
			->andReturn( [ Mockery::mock( SessionAttendance::class ) ] );

		$out = $this->transformer->transform( $session, 5 );

		self::assertSame( 100, $out['session']['id'] );
		self::assertSame( 'module-3', $out['session']['slug'] );
		self::assertSame( 'Module 3: Clinical Cases', $out['session']['title'] );
		self::assertSame( 200, $out['session']['course_id'] );
		self::assertSame( 'cardiology', $out['session']['course_slug'] );
		self::assertSame( 3, $out['session']['session_number'] );
		self::assertSame( '2026-05-15T18:00:00Z', $out['session']['scheduled_start'] );
		self::assertSame( '2026-06-15T00:00:00Z', $out['session']['recording_available_until'] );
		self::assertSame( 'https://example.com/slides.pdf', $out['session']['materials'][0]['url'] );
		self::assertFalse( $out['computed']['join_window_open'] );
		self::assertFalse( $out['computed']['recording_available'] );
		self::assertFalse( $out['computed']['is_past'] );
		self::assertTrue( $out['computed']['user_attended'] );
		self::assertSame( '2026-05-15T17:45:00+00:00', $out['computed']['join_opens_at'] );
		self::assertSame( '2026-05-15T20:30:00+00:00', $out['computed']['join_closes_at'] );

		// no zoom secrets leaked
		self::assertArrayNotHasKey( 'zoom_join_url', $out['session'] );
		self::assertArrayNotHasKey( 'zoom_start_url', $out['session'] );
		self::assertArrayNotHasKey( 'zoom_password', $out['session'] );
		self::assertArrayNotHasKey( 'recording_url', $out['session'] );
	}

	public function test_join_window_open_when_gate_allows(): void {
		$session          = $this->session( 100, 200 );
		$this->posts[200] = $this->course_post( 200, 'cardiology' );
		$this->meta       = [
			'_vl_session_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
		];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( SessionAccessDecision::allow( 'https://zoom.us/j/x' ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( SessionAccessDecision::deny( SessionAccessReason::RECORDING_NOT_AVAILABLE ) );
		$this->attendance->shouldReceive( 'list_for_user' )->andReturn( [] );

		$out = $this->transformer->transform( $session, 5 );

		self::assertTrue( $out['computed']['join_window_open'] );
		self::assertFalse( $out['computed']['user_attended'] );
	}

	public function test_is_past_true_when_now_after_scheduled_end(): void {
		$session          = $this->session( 100, 200 );
		$this->posts[200] = $this->course_post( 200, 'cardiology' );
		$this->meta       = [
			'_vl_session_scheduled_start' => [ 100 => '2026-04-01T18:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-04-01T19:30:00Z' ],
		];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( SessionAccessDecision::deny( SessionAccessReason::JOIN_WINDOW_CLOSED ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( SessionAccessDecision::allow( 'https://zoom.us/r/x' ) );
		$this->attendance->shouldReceive( 'list_for_user' )->andReturn( [] );

		$out = $this->transformer->transform( $session, 5 );

		self::assertTrue( $out['computed']['is_past'] );
		self::assertTrue( $out['computed']['recording_available'] );
	}
}
