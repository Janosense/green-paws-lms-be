<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEvent;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;
use VL\LMS\Repositories\ZoomWebhookEventRepository;

final class ZoomWebhookEventRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private ZoomWebhookEventRepository $repo;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new ZoomWebhookEventRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                => '1',
				'tracking_id'       => 'track-abc',
				'event_type'        => 'meeting.started',
				'event_ts'          => '1714000000000',
				'object_id'         => '987',
				'payload'           => '{}',
				'received_at'       => '2026-04-23 10:00:00',
				'processed_at'      => null,
				'processing_status' => 'pending',
				'processing_error'  => null,
			],
			$overrides
		);
	}

	public function test_record_inserts_pending_row(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->record(
			'track-abc',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			'987',
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);

		self::assertInstanceOf( WebhookEvent::class, $result );
		self::assertSame( 'track-abc', $captured_data['tracking_id'] );
		self::assertSame( 'meeting.started', $captured_data['event_type'] );
		self::assertSame( 1714000000000, $captured_data['event_ts'] );
		self::assertSame( 'pending', $captured_data['processing_status'] );
	}

	public function test_record_throws_when_insert_returns_false(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->expectException( \RuntimeException::class );

		$this->repo->record(
			'track-dup',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);
	}

	public function test_record_throws_when_insert_id_is_zero(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 0 );
		$this->wpdb->insert_id = 0;

		$this->expectException( \RuntimeException::class );

		$this->repo->record(
			'track-dup',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);
	}

	public function test_mark_processed_updates_status_and_processed_at(): void {
		$captured_data  = null;
		$captured_where = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);

		$this->repo->mark_processed( 1, self::utc( '2026-04-23 10:00:05' ) );

		self::assertSame( 'processed', $captured_data['processing_status'] );
		self::assertSame( '2026-04-23 10:00:05', $captured_data['processed_at'] );
		self::assertNull( $captured_data['processing_error'] );
		self::assertSame( [ 'id' => 1 ], $captured_where );
	}

	public function test_mark_failed_records_error_message(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->mark_failed( 1, 'boom', self::utc( '2026-04-23 10:00:05' ) );

		self::assertSame( 'failed', $captured_data['processing_status'] );
		self::assertSame( 'boom', $captured_data['processing_error'] );
	}

	public function test_mark_ignored_sets_status_to_ignored(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->mark_ignored( 1, self::utc( '2026-04-23 10:00:05' ) );

		self::assertSame( 'ignored', $captured_data['processing_status'] );
	}

	public function test_find_by_tracking_id_hydrates_existing_row(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_by_tracking_id( 'track-abc' );

		self::assertNotNull( $result );
		self::assertSame( 'track-abc', $result->tracking_id );
		self::assertSame( [ 'track-abc' ], $captured_args );
	}

	public function test_find_by_tracking_id_returns_null_for_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull( $this->repo->find_by_tracking_id( 'no-match' ) );
	}

	public function test_count_by_status_uses_get_var(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

		$result = $this->repo->count_by_status( WebhookProcessingStatus::PENDING );

		self::assertSame( 4, $result );
		self::assertSame( [ 'pending' ], $captured_args );
	}
}
