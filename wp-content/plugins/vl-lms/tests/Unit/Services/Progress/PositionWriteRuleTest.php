<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Services\Progress\PositionWriteRule;
use VL\LMS\Services\Progress\ProgressEventRequest;
use WP_Post;

final class PositionWriteRuleTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rule( ?int $duration = null ): PositionWriteRule {
		return new class( $duration ) extends PositionWriteRule {

			public function __construct( private ?int $duration ) {
			}

			protected function read_duration( WP_Post $post ): ?int {
				return $this->duration;
			}
		};
	}

	private static function progress( ?int $position ): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: 1,
			user_id: 1,
			entity_type: EntityType::LESSON,
			entity_id: 100,
			course_id: 50,
			status: ProgressStatus::IN_PROGRESS,
			position_seconds: $position,
			completed_at: null,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now
		);
	}

	private static function request( string $event_type, ?int $position ): ProgressEventRequest {
		return new ProgressEventRequest(
			entity_type: EntityType::LESSON,
			entity_id: 100,
			session_uuid: '8c7e9f2a-2c1d-4d2c-9e89-3f5d2a3b4c5d',
			event_type: \VL\LMS\Domain\Progress\ViewEventType::from_string( $event_type ),
			position_seconds: $position,
			payload: null
		);
	}

	private static function post(): WP_Post {
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 100;
		$post->post_type = 'vl_lesson';
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @dataProvider provide_table
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- $new is the incoming-event position; $current already names the stored value.
	public function test_table_driven_rule(
		string $event_type,
		?int $current,
		?int $new,
		?int $duration,
		?int $expected
	): void {
		$rule             = $this->rule( $duration );
		$current_progress = ( null === $current ) ? null : self::progress( $current );

		$result = $rule->apply(
			$current_progress,
			self::request( $event_type, $new ),
			self::post()
		);

		self::assertSame( $expected, $result );
	}

	/**
	 * @return iterable<string, array{0:string, 1:?int, 2:?int, 3:?int, 4:?int}>
	 */
	public static function provide_table(): iterable {
		// seek: always overwrite, even with null and even with smaller values.
		yield 'seek-overwrites-smaller'      => [ 'seek', 240, 60, null, 60 ];
		yield 'seek-overwrites-with-null'    => [ 'seek', 240, null, null, null ];
		yield 'seek-into-null-current'       => [ 'seek', null, 100, null, 100 ];
		yield 'seek-larger'                  => [ 'seek', 100, 200, null, 200 ];

		// monotonic events on null current — accept the new value verbatim.
		foreach ( [ 'progress', 'pause', 'unload', 'play', 'view_start' ] as $ev ) {
			yield "$ev-null-current"             => [ $ev, null, 100, null, 100 ];
			yield "$ev-null-current-null-new"    => [ $ev, null, null, null, null ];
		}

		// monotonic events on null stored position — accept the new value.
		yield 'progress-null-stored-position'    => [ 'progress', null, 50, null, 50 ];

		// monotonic with smaller new — preserve current.
		yield 'progress-smaller-preserved'       => [ 'progress', 240, 120, null, 240 ];
		yield 'pause-smaller-preserved'          => [ 'pause', 300, 100, null, 300 ];
		yield 'unload-equal-overwrites'          => [ 'unload', 240, 240, null, 240 ];
		yield 'play-larger-overwrites'           => [ 'play', 240, 250, null, 250 ];
		yield 'view_start-larger-overwrites'     => [ 'view_start', 0, 1, null, 1 ];

		// monotonic with new=null when current set — preserve current.
		yield 'progress-null-new-keeps-current'  => [ 'progress', 240, null, null, 240 ];

		// complete: max(current, new), no duration cap.
		yield 'complete-takes-max'               => [ 'complete', 100, 200, null, 200 ];
		yield 'complete-takes-max-when-current-bigger' => [ 'complete', 300, 100, null, 300 ];
		yield 'complete-null-current-uses-new'   => [ 'complete', null, 100, null, 100 ];
		yield 'complete-null-everything'         => [ 'complete', null, null, null, 0 ];

		// complete with duration cap.
		yield 'complete-caps-overshoot'          => [ 'complete', 0, 1000, 600, 600 ];
		yield 'complete-keeps-under-duration'    => [ 'complete', 100, 500, 600, 500 ];
		yield 'complete-cap-equals-duration'     => [ 'complete', 0, 600, 600, 600 ];
		yield 'complete-zero-duration-no-cap'    => [ 'complete', 0, 1000, 0, 1000 ];
	}

	public function test_read_duration_throws_logic_exception_for_session_post(): void {
		// Phase 7.4: sessions have no position semantics. Reaching the
		// duration lookup with a vl_session post means upstream gating was
		// bypassed.
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 1;
		$post->post_type = 'vl_session';

		$exposed = new class() extends PositionWriteRule {
			public function call_read_duration( WP_Post $post ): ?int {
				return $this->read_duration( $post );
			}
		};

		$this->expectException( \LogicException::class );
		$exposed->call_read_duration( $post );
	}
}
