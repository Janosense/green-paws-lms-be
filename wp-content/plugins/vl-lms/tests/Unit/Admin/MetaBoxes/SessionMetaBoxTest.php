<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\SessionMetaBox;
use WP_Post;

final class SessionMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_session_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	/** @var list<array<string, mixed>> */
	private array $post_updates = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias(
			static function ( int|string $id ): string {
				return match ( (int) $id ) {
					77      => 'vl_course',
					88      => 'page',
					default => 'vl_session',
				};
			}
		);
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'wp_timezone' )->alias(
			static fn (): \DateTimeZone => new \DateTimeZone( 'Europe/Kyiv' )
		);

		$this->writes       = [];
		$this->post_updates = [];
		$writes             = &$this->writes;
		$post_updates       = &$this->post_updates;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$post_updates ): int {
				$post_updates[] = $postarr;
				return (int) ( $postarr['ID'] ?? 0 );
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_updates_post_parent_when_course_id_changes(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$_POST = [
			self::NONCE_FIELD       => 'nonce-x',
			'_vl_session_course_id' => '77',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 44, $post );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 44, $this->post_updates[0]['ID'] );
		self::assertSame( 77, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_skips_post_parent_update_when_course_id_unchanged(): void {
		Functions\when( 'get_post_field' )->justReturn( 77 );

		$_POST = [
			self::NONCE_FIELD       => 'nonce-x',
			'_vl_session_course_id' => '77',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 44, $post );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_rejects_post_parent_pointing_at_non_course(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$_POST = [
			self::NONCE_FIELD       => 'nonce-x',
			'_vl_session_course_id' => '88',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 44, $post );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_clears_post_parent_when_course_id_zero(): void {
		Functions\when( 'get_post_field' )->justReturn( 77 );

		$_POST = [
			self::NONCE_FIELD       => 'nonce-x',
			'_vl_session_course_id' => '0',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 44, $post );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 44, $this->post_updates[0]['ID'] );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}

	/**
	 * Regression: HTML `datetime-local` inputs submit values without a
	 * timezone, but the `_vl_session_scheduled_*` meta sanitizer
	 * rejects everything lacking a `Z`/offset suffix. The metabox must
	 * convert the wall-clock value to ISO 8601 with the WP timezone
	 * offset before writing — otherwise `MeetingSynchronizer` reads
	 * `null` for `start_time` and skips the Zoom CREATE call.
	 */
	public function test_save_converts_datetime_local_inputs_to_iso8601_with_offset(): void {
		$_POST = [
			self::NONCE_FIELD                       => 'nonce-x',
			'_vl_session_scheduled_start'           => '2026-05-08T14:30',
			'_vl_session_scheduled_end'             => '2026-05-08T15:30:00',
			'_vl_session_recording_available_until' => '',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 44, $post );

		$saved = [];
		foreach ( $this->writes as [ , $key, $value ] ) {
			$saved[ $key ] = $value;
		}

		self::assertSame( '2026-05-08T14:30:00+03:00', $saved['_vl_session_scheduled_start'] );
		self::assertSame( '2026-05-08T15:30:00+03:00', $saved['_vl_session_scheduled_end'] );
		self::assertSame( '', $saved['_vl_session_recording_available_until'] );

		$iso8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:?\d{2})$/';
		self::assertMatchesRegularExpression( $iso8601, $saved['_vl_session_scheduled_start'] );
		self::assertMatchesRegularExpression( $iso8601, $saved['_vl_session_scheduled_end'] );
	}

	public function test_save_does_not_write_zoom_meeting_id(): void {
		$_POST = [
			self::NONCE_FIELD             => 'nonce-x',
			'_vl_session_zoom_meeting_id' => 'hacked',
			'_vl_session_zoom_join_url'   => 'https://hacked.example.com',
			'_vl_session_recording_url'   => 'https://hacked.example.com/r',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new SessionMetaBox() )->save( 33, $post );

		$forbidden_keys = [
			'_vl_session_zoom_meeting_id',
			'_vl_session_zoom_join_url',
			'_vl_session_zoom_start_url',
			'_vl_session_zoom_password',
			'_vl_session_recording_url',
			'_vl_session_zoom_synced_payload_hash',
		];
		foreach ( $forbidden_keys as $key ) {
			$matches = array_filter(
				$this->writes,
				static fn ( array $row ): bool => $row[1] === $key
			);
			self::assertSame( [], $matches, "Read-only key {$key} must not be saved" );
		}
	}
}
