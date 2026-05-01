<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook\Handlers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\LookupResult;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\Handlers\RecordingCompletedHandler;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Zoom\Webhook\StubPostLookup;
use WP_Post;

final class RecordingCompletedHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id, string $type ): WP_Post {
		$p            = Mockery::mock( 'WP_Post' );
		$p->ID        = $id;
		$p->post_type = $type;
		return $p;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function request( array $payload ): WebhookRequest {
		return new WebhookRequest( 'recording.completed', $payload, 'A', 'tr', 1, '{}', '' );
	}

	private function fixed_clock(): \Closure {
		return static fn (): \DateTimeImmutable
			=> new \DateTimeImmutable( '2026-05-01T10:00:00Z' );
	}

	public function test_unknown_meeting_id_is_noop(): void {
		$handler = new RecordingCompletedHandler(
			new StubPostLookup(),
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$outcome = $handler->handle(
			$this->request(
				[
					'id'              => 'X',
					'recording_files' => [
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/p',
						],
					],
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'unknown_meeting_id', $outcome->action_label );
	}

	public function test_session_writes_recording_url_only(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );

		$writes = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$handler = new RecordingCompletedHandler(
			$lookup,
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$outcome = $handler->handle(
			$this->request(
				[
					'id'              => 'm-1',
					'recording_files' => [
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/p.mp4',
						],
					],
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertCount( 1, $writes );
		self::assertSame( [ 11, '_vl_session_recording_url', 'https://example.test/p.mp4' ], $writes[0] );
	}

	public function test_webinar_with_zero_access_days_is_noop(): void {
		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		Functions\when( 'get_post_meta' )->justReturn( 0 );
		Functions\expect( 'update_post_meta' )->never();

		$handler = new RecordingCompletedHandler(
			$lookup,
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$outcome = $handler->handle(
			$this->request(
				[
					'id'              => 'm-1',
					'recording_files' => [
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/p.mp4',
						],
					],
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'webinar_recording_disabled', $outcome->action_label );
	}

	public function test_webinar_with_30_access_days_writes_url_and_until(): void {
		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single ) {
				if ( '_vl_webinar_recording_access_days' === $key ) {
					return 30;
				}
				return '';
			}
		);

		$writes = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);

		$handler = new RecordingCompletedHandler(
			$lookup,
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$outcome = $handler->handle(
			$this->request(
				[
					'id'              => 'm-1',
					'recording_files' => [
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/p.mp4',
						],
					],
				]
			)
		);

		self::assertFalse( $outcome->was_no_op );
		self::assertCount( 2, $writes );
		self::assertSame( [ 22, '_vl_webinar_recording_url', 'https://example.test/p.mp4' ], $writes[0] );
		self::assertSame( 22, $writes[1][0] );
		self::assertSame( '_vl_webinar_recording_available_until', $writes[1][1] );
		// 2026-05-01T10:00:00Z + 30 days = 2026-05-31T10:00:00Z
		self::assertSame( '2026-05-31T10:00:00Z', $writes[1][2] );
	}

	public function test_no_mp4_in_recording_files_is_noop(): void {
		$post                         = $this->post( 22, 'vl_webinar' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::WEBINAR );

		Functions\expect( 'update_post_meta' )->never();
		Functions\when( 'get_post_meta' )->justReturn( 30 );

		$handler = new RecordingCompletedHandler(
			$lookup,
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$outcome = $handler->handle(
			$this->request(
				[
					'id'              => 'm-1',
					'recording_files' => [
						[
							'file_type' => 'M4A',
							'play_url'  => 'https://example.test/audio',
						],
					],
				]
			)
		);

		self::assertTrue( $outcome->was_no_op );
		self::assertSame( 'no_mp4_recording', $outcome->action_label );
	}

	public function test_first_mp4_with_play_url_wins(): void {
		$post                         = $this->post( 11, 'vl_session' );
		$lookup                       = new StubPostLookup();
		$lookup->by_meeting_id['m-1'] = new LookupResult( $post, PostKind::SESSION );

		$writes = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$handler = new RecordingCompletedHandler(
			$lookup,
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->fixed_clock()
		);

		$handler->handle(
			$this->request(
				[
					'id'              => 'm-1',
					'recording_files' => [
						[
							'file_type' => 'M4A',
							'play_url'  => 'audio',
						],
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/first.mp4',
						],
						[
							'file_type' => 'MP4',
							'play_url'  => 'https://example.test/second.mp4',
						],
					],
				]
			)
		);

		self::assertSame( 'https://example.test/first.mp4', $writes[0][2] );
	}
}
