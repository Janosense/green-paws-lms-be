<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\SessionAttendance\SessionAttendance;
use VL\LMS\Learn\ProgressOverlay;
use VL\LMS\Learn\SessionNodeTransformer;
use VL\LMS\Repositories\SessionAttendanceRepository;
use WP_Post;

final class SessionNodeTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&SessionAttendanceRepository */
	private $attendance;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	private SessionNodeTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$this->attendance  = Mockery::mock( SessionAttendanceRepository::class );
		$this->transformer = new SessionNodeTransformer( $this->attendance );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function session( int $id, string $slug ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_title  = 'Module 3';
		$p->post_type   = 'vl_session';
		$p->post_status = 'publish';
		return $p;
	}

	public function test_marks_completed_when_attendance_row_exists(): void {
		$this->attendance->shouldReceive( 'list_for_user' )->with( 5, 100 )
			->andReturn( [ Mockery::mock( SessionAttendance::class ) ] );
		$this->meta = [
			'_vl_session_status'          => [ 100 => 'completed' ],
			'_vl_session_number'          => [ 100 => 3 ],
			'_vl_session_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_session_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
			'_vl_session_recording_url'   => [ 100 => 'https://zoom.us/r/x' ],
		];

		$out = $this->transformer->transform(
			$this->session( 100, 'module-3' ),
			5,
			ProgressOverlay::fromList( [] )
		);

		self::assertSame( 'session', $out['type'] );
		self::assertSame( 100, $out['id'] );
		self::assertSame( 'module-3', $out['slug'] );
		self::assertSame( 3, $out['session_number'] );
		self::assertTrue( $out['is_completed'] );
		self::assertSame( '/vl/v1/learn/sessions/module-3/join', $out['join_url_path'] );
		self::assertSame( '/vl/v1/learn/sessions/module-3/recording', $out['recording_url_path'] );
	}

	public function test_recording_url_path_is_null_when_recording_url_meta_empty(): void {
		$this->attendance->shouldReceive( 'list_for_user' )->andReturn( [] );

		$out = $this->transformer->transform(
			$this->session( 100, 'module-3' ),
			5,
			ProgressOverlay::fromList( [] )
		);

		self::assertNull( $out['recording_url_path'] );
		self::assertFalse( $out['is_completed'] );
	}

	public function test_status_falls_back_to_scheduled_when_meta_missing(): void {
		$this->attendance->shouldReceive( 'list_for_user' )->andReturn( [] );

		$out = $this->transformer->transform(
			$this->session( 100, 'module-3' ),
			5,
			ProgressOverlay::fromList( [] )
		);

		self::assertSame( 'scheduled', $out['status'] );
	}
}
