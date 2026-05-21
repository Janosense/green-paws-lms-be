<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Repositories\GroupMemberRepository;

final class GroupMemberRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private GroupMemberRepository $repo;

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

		$this->repo = new GroupMemberRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 5, int $group_id = 42, int $user_id = 7, ?string $left_at = null ): array {
		return [
			'id'            => (string) $id,
			'group_id'      => (string) $group_id,
			'user_id'       => (string) $user_id,
			'role_in_group' => 'member',
			'joined_at'     => '2026-04-23 10:00:00',
			'left_at'       => $left_at,
		];
	}

	public function test_find_active_filters_by_left_at_null(): void {
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
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$member = $this->repo->find_active( 42, 7 );

		self::assertInstanceOf( GroupMember::class, $member );
		self::assertStringContainsString( 'left_at IS NULL', $captured_sql );
		self::assertSame( [ 42, 7 ], $captured_args );
	}

	public function test_list_active_members_filters_by_group_id_and_left_at_null(): void {
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

		$members = $this->repo->list_active_members( 42 );

		self::assertCount( 1, $members );
		self::assertStringContainsString( 'WHERE group_id = %d', $captured_sql );
		self::assertStringContainsString( 'left_at IS NULL', $captured_sql );
	}

	public function test_list_active_memberships_for_user_filters_by_user_id_and_left_at_null(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$this->repo->list_active_memberships_for_user( 7 );

		self::assertStringContainsString( 'WHERE user_id = %d', $captured_sql );
		self::assertStringContainsString( 'left_at IS NULL', $captured_sql );
	}

	public function test_count_active_members_uses_count_query(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

		self::assertSame( 4, $this->repo->count_active_members( 42 ) );
	}

	public function test_list_active_for_users_short_circuits_on_empty_input(): void {
		$this->wpdb->shouldNotReceive( 'prepare' );
		$this->wpdb->shouldNotReceive( 'get_results' );

		self::assertSame( [], $this->repo->list_active_for_users( [] ) );
	}

	public function test_list_active_for_users_returns_keyed_map_of_members(): void {
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
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			[
				self::row( 1, 11, 7 ),
				self::row( 2, 22, 7 ),
				self::row( 3, 33, 11 ),
			]
		);

		$result = $this->repo->list_active_for_users( [ 7, 11, 99 ] );

		self::assertStringContainsString( 'WHERE user_id IN (%d, %d, %d)', $captured_sql );
		self::assertStringContainsString( 'left_at IS NULL', $captured_sql );
		self::assertSame( [ 7, 11, 99 ], $captured_args );
		self::assertCount( 2, $result[7] );
		self::assertCount( 1, $result[11] );
		self::assertArrayNotHasKey( 99, $result );
	}

	public function test_list_active_for_users_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		self::assertSame( [], $this->repo->list_active_for_users( [ 5 ] ) );
	}

	public function test_insert_auto_fills_joined_at_when_absent(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 5;

		$this->repo->insert(
			[
				'group_id'      => 42,
				'user_id'       => 7,
				'role_in_group' => 'member',
			]
		);

		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['joined_at'] );
	}

	public function test_mark_left_updates_left_at_and_returns_true_when_one_row_affected(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$ok = $this->repo->mark_left( 5 );

		self::assertTrue( $ok );
		self::assertArrayHasKey( 'left_at', $captured_data );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['left_at'] );
	}

	public function test_mark_left_uses_supplied_timestamp_when_provided(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->mark_left( 5, '2026-05-01 09:00:00' );

		self::assertSame( '2026-05-01 09:00:00', $captured_data['left_at'] );
	}

	public function test_mark_left_returns_false_when_no_rows_affected(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 0 );

		self::assertFalse( $this->repo->mark_left( 5 ) );
	}
}
