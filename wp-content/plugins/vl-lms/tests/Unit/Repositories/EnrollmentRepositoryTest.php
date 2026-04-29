<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;

final class EnrollmentRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private EnrollmentRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
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

		$this->repo = new EnrollmentRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 42, int $user_id = 1, int $course_id = 7 ): array {
		return [
			'id'              => (string) $id,
			'user_id'         => (string) $user_id,
			'course_id'       => (string) $course_id,
			'status'          => 'active',
			'source'          => 'manual',
			'source_group_id' => null,
			'source_order_id' => null,
			'enrolled_at'     => '2026-04-23 10:00:00',
			'started_at'      => null,
			'completed_at'    => null,
			'expires_at'      => null,
			'revoked_at'      => null,
			'revoked_by'      => null,
			'revoke_reason'   => null,
			'progress_pct'    => '0',
			'created_at'      => '2026-04-23 10:00:00',
			'updated_at'      => '2026-04-23 10:00:00',
		];
	}

	public function test_find_by_id_prepares_sql_and_hydrates_row(): void {
		$captured_sql   = null;
		$captured_id    = null;
		$captured_fetch = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_id ): string {
					$captured_sql = $sql;
					$captured_id  = $args[0] ?? null;
					return $sql . '|' . implode( ',', $args );
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturnUsing(
				function ( string $sql, string $fetch ) use ( &$captured_fetch ): array {
					$captured_fetch = $fetch;
					return self::row();
				}
			);

		$result = $this->repo->find_by_id( 42 );

		self::assertInstanceOf( Enrollment::class, $result );
		self::assertSame( 42, $result->id );
		self::assertStringContainsString( 'SELECT * FROM wp_vl_enrollments', $captured_sql );
		self::assertStringContainsString( 'WHERE id = %d', $captured_sql );
		self::assertSame( 42, $captured_id );
		self::assertSame( ARRAY_A, $captured_fetch );
	}

	public function test_find_by_id_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find_by_id( 9999 ) );
	}

	public function test_find_for_user_and_course_passes_both_ids_to_prepare(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( self::row( 1, 1, 7 ) );

		$result = $this->repo->find_for_user_and_course( 1, 7 );

		self::assertInstanceOf( Enrollment::class, $result );
		self::assertSame( [ 1, 7 ], $captured_args );
	}

	public function test_list_for_user_omits_status_filter_when_null(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$this->repo->list_for_user( 1 );

		self::assertStringContainsString( 'WHERE user_id = %d', $captured_sql );
		self::assertStringNotContainsString( 'status', $captured_sql );
	}

	public function test_list_for_user_includes_status_filter_when_provided(): void {
		$captured_sql  = null;
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ self::row() ] );

		$result = $this->repo->list_for_user( 1, EnrollmentStatus::ACTIVE );

		self::assertStringContainsString( 'status = %s', $captured_sql );
		self::assertSame( [ 1, 'active' ], $captured_args );
		self::assertCount( 1, $result );
		self::assertInstanceOf( Enrollment::class, $result[0] );
	}

	public function test_count_for_course_uses_get_var_with_count_query(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );

		$result = $this->repo->count_for_course( 7 );

		self::assertSame( 5, $result );
		self::assertStringContainsString( 'SELECT COUNT(*)', $captured_sql );
		self::assertStringContainsString( 'course_id = %d', $captured_sql );
	}

	public function test_insert_auto_fills_timestamps_when_absent(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 123;

		$id = $this->repo->insert(
			[
				'user_id'     => 1,
				'course_id'   => 7,
				'status'      => 'active',
				'source'      => 'manual',
				'enrolled_at' => '2026-04-23 10:00:00',
			]
		);

		self::assertSame( 123, $id );
		self::assertArrayHasKey( 'created_at', $captured_data );
		self::assertArrayHasKey( 'updated_at', $captured_data );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['created_at'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['updated_at'] );
	}

	public function test_insert_preserves_caller_supplied_timestamps(): void {
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

		$this->repo->insert(
			[
				'user_id'     => 1,
				'course_id'   => 7,
				'status'      => 'active',
				'source'      => 'manual',
				'enrolled_at' => '2026-04-23 10:00:00',
				'created_at'  => '2020-01-01 00:00:00',
				'updated_at'  => '2020-01-02 00:00:00',
			]
		);

		self::assertSame( '2020-01-01 00:00:00', $captured_data['created_at'] );
		self::assertSame( '2020-01-02 00:00:00', $captured_data['updated_at'] );
	}

	public function test_update_always_refreshes_updated_at(): void {
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

		$ok = $this->repo->update( 42, [ 'status' => 'revoked' ] );

		self::assertTrue( $ok );
		self::assertArrayHasKey( 'updated_at', $captured_data );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['updated_at'] );
		self::assertSame( 'revoked', $captured_data['status'] );
		self::assertSame( [ 'id' => 42 ], $captured_where );
	}

	public function test_update_returns_false_when_wpdb_update_returns_false(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		self::assertFalse( $this->repo->update( 42, [ 'status' => 'revoked' ] ) );
	}

	public function test_delete_calls_wpdb_delete_with_id(): void {
		$captured_where = null;

		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->andReturnUsing(
				function ( string $table, array $where ) use ( &$captured_where ): int {
					$captured_where = $where;
					return 1;
				}
			);

		$ok = $this->repo->delete( 42 );

		self::assertTrue( $ok );
		self::assertSame( [ 'id' => 42 ], $captured_where );
	}

	public function test_delete_returns_false_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertFalse( $this->repo->delete( 42 ) );
	}

	public function test_update_progress_state_writes_to_user_course_row(): void {
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

		$now = new \DateTimeImmutable( '2026-04-28 12:34:56', new \DateTimeZone( 'UTC' ) );

		$ok = $this->repo->update_progress_state( 7, 100, 50, EnrollmentStatus::ACTIVE, null, $now );

		self::assertTrue( $ok );
		self::assertSame(
			[
				'user_id'   => 7,
				'course_id' => 100,
			],
			$captured_where
		);
		self::assertSame( 50, $captured_data['progress_pct'] );
		self::assertSame( 'active', $captured_data['status'] );
		self::assertNull( $captured_data['completed_at'] );
		self::assertSame( '2026-04-28 12:34:56', $captured_data['updated_at'] );
	}

	public function test_update_progress_state_writes_completed_at_when_status_completed(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$now          = new \DateTimeImmutable( '2026-04-28 12:34:56', new \DateTimeZone( 'UTC' ) );
		$completed_at = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );

		$this->repo->update_progress_state( 7, 100, 100, EnrollmentStatus::COMPLETED, $completed_at, $now );

		self::assertSame( '2026-04-28 12:00:00', $captured_data['completed_at'] );
		self::assertSame( 'completed', $captured_data['status'] );
		self::assertSame( 100, $captured_data['progress_pct'] );
	}

	public function test_update_progress_state_returns_false_on_miss(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 0 );

		$now = new \DateTimeImmutable( '2026-04-28 12:34:56', new \DateTimeZone( 'UTC' ) );

		self::assertFalse(
			$this->repo->update_progress_state( 999, 999, 0, EnrollmentStatus::ACTIVE, null, $now )
		);
	}

	public function test_update_progress_state_returns_false_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		$now = new \DateTimeImmutable( '2026-04-28 12:34:56', new \DateTimeZone( 'UTC' ) );

		self::assertFalse(
			$this->repo->update_progress_state( 7, 100, 50, EnrollmentStatus::ACTIVE, null, $now )
		);
	}
}
