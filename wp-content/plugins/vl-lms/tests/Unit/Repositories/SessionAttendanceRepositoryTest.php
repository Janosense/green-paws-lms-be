<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\SessionAttendance\SessionAttendance;
use VL\LMS\Repositories\SessionAttendanceRepository;

final class SessionAttendanceRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SessionAttendanceRepository $repo;

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

		$this->repo = new SessionAttendanceRepository();
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
				'id'                    => '1',
				'session_id'            => '101',
				'user_id'               => '7',
				'zoom_participant_uuid' => 'uuid-abc',
				'participant_email'     => 'p@example.com',
				'participant_name'      => 'Pat',
				'joined_at'             => '2026-04-23 10:00:00',
				'left_at'               => null,
				'duration_seconds'      => null,
				'created_at'            => '2026-04-23 10:00:00',
				'updated_at'            => '2026-04-23 10:00:00',
			],
			$overrides
		);
	}

	public function test_record_join_inserts_when_no_open_row_exists(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		// First call: find_open returns null. Second call: find_row after insert.
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturnValues( [ null, self::row() ] );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;

		$result = $this->repo->record_join(
			101,
			7,
			'uuid-abc',
			'p@example.com',
			'Pat',
			self::utc( '2026-04-23 10:00:00' )
		);

		self::assertInstanceOf( SessionAttendance::class, $result );
		self::assertSame( 'uuid-abc', $captured_data['zoom_participant_uuid'] );
		self::assertSame( 101, $captured_data['session_id'] );
		self::assertSame( 7, $captured_data['user_id'] );
		self::assertSame( '2026-04-23 10:00:00', $captured_data['joined_at'] );
	}

	public function test_record_join_returns_existing_open_row_idempotently(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = $this->repo->record_join(
			101,
			7,
			'uuid-abc',
			'p@example.com',
			'Pat',
			self::utc( '2026-04-23 10:05:00' )
		);

		self::assertSame( 1, $result->id );
	}

	public function test_record_leave_updates_open_row_with_duration(): void {
		$captured_data  = null;
		$captured_where = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		// get_row #1: find_open. get_row #2: find_row after update.
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturnValues(
				[
					self::row(),
					self::row(
						[
							'left_at'          => '2026-04-23 10:45:00',
							'duration_seconds' => '2700',
						]
					),
				]
			);
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);

		$result = $this->repo->record_leave( 101, 'uuid-abc', self::utc( '2026-04-23 10:45:00' ) );

		self::assertNotNull( $result );
		self::assertSame( '2026-04-23 10:45:00', $captured_data['left_at'] );
		self::assertSame( 2700, $captured_data['duration_seconds'] );
		self::assertSame( [ 'id' => 1 ], $captured_where );
		self::assertSame( 2700, $result->duration_seconds );
	}

	public function test_record_leave_returns_null_when_no_open_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldNotReceive( 'update' );

		$result = $this->repo->record_leave( 101, 'uuid-missing', self::utc( '2026-04-23 10:45:00' ) );

		self::assertNull( $result );
	}

	public function test_find_open_filters_on_left_at_null(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_open( 101, 'uuid-abc' );

		self::assertNotNull( $result );
		self::assertStringContainsString( 'left_at IS NULL', $captured_sql );
	}

	public function test_list_for_session_orders_by_joined_at_asc(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [ self::row() ] );

		$result = $this->repo->list_for_session( 101 );

		self::assertCount( 1, $result );
		self::assertStringContainsString( 'ORDER BY joined_at ASC', $captured_sql );
	}

	public function test_list_for_user_filters_by_session_when_supplied(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$this->repo->list_for_user( 7, 101 );

		self::assertSame( [ 7, 101 ], $captured_args );
	}
}
