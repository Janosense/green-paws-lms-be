<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\GroupRepository;

final class GroupRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private GroupRepository $repo;

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

		$this->repo = new GroupRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 42, string $slug = 'test-clinic' ): array {
		return [
			'id'          => (string) $id,
			'name'        => 'Test Clinic',
			'slug'        => $slug,
			'description' => null,
			'type'        => 'organization',
			'owner_id'    => '7',
			'max_members' => null,
			'status'      => 'active',
			'created_at'  => '2026-04-23 10:00:00',
			'updated_at'  => '2026-04-23 10:00:00',
		];
	}

	public function test_find_by_id_prepares_sql_and_hydrates_row(): void {
		$captured_sql = null;
		$captured_id  = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_id ): string {
					$captured_sql = $sql;
					$captured_id  = $args[0] ?? null;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_by_id( 42 );

		self::assertInstanceOf( Group::class, $result );
		self::assertStringContainsString( 'SELECT * FROM wp_vl_groups', $captured_sql );
		self::assertStringContainsString( 'WHERE id = %d', $captured_sql );
		self::assertSame( 42, $captured_id );
	}

	public function test_find_by_id_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find_by_id( 9999 ) );
	}

	public function test_find_by_slug_passes_slug_to_prepare(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( self::row() );

		$this->repo->find_by_slug( 'test-clinic' );

		self::assertSame( [ 'test-clinic' ], $captured_args );
	}

	public function test_list_by_owner_omits_status_filter_when_null(): void {
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

		$this->repo->list_by_owner( 7 );

		self::assertStringContainsString( 'WHERE owner_id = %d', $captured_sql );
		self::assertStringNotContainsString( 'status', $captured_sql );
	}

	public function test_list_by_owner_includes_status_filter_when_provided(): void {
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

		$result = $this->repo->list_by_owner( 7, GroupStatus::ACTIVE );

		self::assertStringContainsString( 'status = %s', $captured_sql );
		self::assertSame( [ 7, 'active' ], $captured_args );
		self::assertCount( 1, $result );
	}

	public function test_count_by_owner_uses_get_var_with_count_query(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$result = $this->repo->count_by_owner( 7 );

		self::assertSame( 3, $result );
		self::assertStringContainsString( 'SELECT COUNT(*)', $captured_sql );
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
		$this->wpdb->insert_id = 42;

		$id = $this->repo->insert(
			[
				'name'     => 'Test Clinic',
				'slug'     => 'test-clinic',
				'type'     => 'organization',
				'owner_id' => 7,
				'status'   => 'active',
			]
		);

		self::assertSame( 42, $id );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['created_at'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['updated_at'] );
	}

	public function test_update_always_refreshes_updated_at(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->update( 42, [ 'status' => 'archived' ] );

		self::assertArrayHasKey( 'updated_at', $captured_data );
		self::assertSame( 'archived', $captured_data['status'] );
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

	public function test_paginate_without_filters_omits_where_and_orders_by_created_at(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ self::row() ] );

		$result = $this->repo->paginate( null, null, 20, 0 );

		self::assertCount( 1, $result );
		self::assertStringContainsString( 'SELECT * FROM wp_vl_groups', $captured_sql );
		self::assertStringNotContainsString( 'WHERE', $captured_sql );
		self::assertStringContainsString( 'ORDER BY created_at DESC', $captured_sql );
		self::assertStringContainsString( 'LIMIT %d OFFSET %d', $captured_sql );
		self::assertSame( [ 20, 0 ], $captured_args );
	}

	public function test_paginate_applies_status_filter(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$this->repo->paginate( GroupStatus::ARCHIVED, null, 10, 30 );

		self::assertStringContainsString( 'WHERE status = %s', $captured_sql );
		self::assertSame( [ 'archived', 10, 30 ], $captured_args );
	}

	public function test_paginate_applies_search_filter_with_escaped_like(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'esc_like' )
			->once()
			->with( 'cohort' )
			->andReturn( 'cohort' );
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$this->repo->paginate( null, '  cohort  ', 25, 0 );

		self::assertStringContainsString( '(name LIKE %s OR slug LIKE %s)', $captured_sql );
		self::assertSame( [ '%cohort%', '%cohort%', 25, 0 ], $captured_args );
	}

	public function test_paginate_combines_status_and_search_with_and(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'esc_like' )->andReturn( 'qa' );
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$this->repo->paginate( GroupStatus::ACTIVE, 'qa', 10, 0 );

		self::assertStringContainsString( 'status = %s AND (name LIKE %s OR slug LIKE %s)', $captured_sql );
		self::assertSame( [ 'active', '%qa%', '%qa%', 10, 0 ], $captured_args );
	}

	public function test_count_without_filters_skips_prepare_and_uses_plain_count(): void {
		$captured_sql = null;

		$this->wpdb->shouldNotReceive( 'prepare' );
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return '12';
				}
			);

		$result = $this->repo->count( null, null );

		self::assertSame( 12, $result );
		self::assertSame( 'SELECT COUNT(*) FROM wp_vl_groups', $captured_sql );
	}

	public function test_count_with_status_filter_prepares_with_args(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );

		$result = $this->repo->count( GroupStatus::ARCHIVED, null );

		self::assertSame( 5, $result );
		self::assertStringContainsString( 'WHERE status = %s', $captured_sql );
		self::assertSame( [ 'archived' ], $captured_args );
	}

	public function test_count_with_search_filter_escapes_like_and_uses_both_columns(): void {
		$captured_sql  = null;
		$captured_args = null;

		$this->wpdb->shouldReceive( 'esc_like' )
			->once()
			->with( 'cohort' )
			->andReturn( 'cohort' );
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, $args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$result = $this->repo->count( null, 'cohort' );

		self::assertSame( 2, $result );
		self::assertStringContainsString( 'WHERE (name LIKE %s OR slug LIKE %s)', $captured_sql );
		self::assertSame( [ '%cohort%', '%cohort%' ], $captured_args );
	}

	public function test_count_returns_zero_when_get_var_is_non_numeric(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		self::assertSame( 0, $this->repo->count( null, null ) );
	}
}
