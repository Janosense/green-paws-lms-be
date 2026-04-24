<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Repositories\GroupAccessRepository;

final class GroupAccessRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private GroupAccessRepository $repo;

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

		$this->repo = new GroupAccessRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 9, int $group_id = 42, int $entity_id = 123 ): array {
		return [
			'id'          => (string) $id,
			'group_id'    => (string) $group_id,
			'entity_type' => 'course',
			'entity_id'   => (string) $entity_id,
			'access_type' => 'granted',
			'granted_at'  => '2026-04-23 10:00:00',
			'granted_by'  => '1',
			'expires_at'  => null,
		];
	}

	public function test_find_by_group_entity_passes_all_keys_to_prepare(): void {
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

		$result = $this->repo->find_by_group_entity( 42, AccessEntityType::COURSE, 123 );

		self::assertInstanceOf( GroupAccess::class, $result );
		self::assertSame( [ 42, 'course', 123 ], $captured_args );
	}

	public function test_list_active_for_group_includes_expires_at_filter(): void {
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

		$rows = $this->repo->list_active_for_group( 42 );

		self::assertCount( 1, $rows );
		self::assertStringContainsString( 'expires_at IS NULL OR expires_at > NOW()', $captured_sql );
	}

	public function test_list_by_entity_uses_entity_type_and_id(): void {
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

		$this->repo->list_by_entity( AccessEntityType::COURSE, 123 );

		self::assertSame( [ 'course', 123 ], $captured_args );
	}

	public function test_insert_auto_fills_granted_at_when_absent(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 9;

		$this->repo->insert(
			[
				'group_id'    => 42,
				'entity_type' => 'course',
				'entity_id'   => 123,
				'access_type' => 'granted',
				'granted_by'  => 1,
			]
		);

		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['granted_at'] );
	}

	public function test_delete_returns_false_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertFalse( $this->repo->delete( 9 ) );
	}
}
