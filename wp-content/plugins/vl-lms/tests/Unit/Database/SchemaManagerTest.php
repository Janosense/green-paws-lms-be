<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Database\SchemaManager;

final class SchemaManagerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant required by SchemaManager::install().
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_table_name_prefixes_wpdb_prefix_and_vl(): void {
		self::assertSame( 'wp_vl_enrollments', SchemaManager::table_name( 'enrollments' ) );
	}

	public function test_enrollments_table_matches_table_name_helper(): void {
		self::assertSame( SchemaManager::table_name( 'enrollments' ), SchemaManager::enrollments_table() );
	}

	public function test_groups_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_groups', SchemaManager::groups_table() );
	}

	public function test_group_members_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_group_members', SchemaManager::group_members_table() );
	}

	public function test_group_access_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_group_access', SchemaManager::group_access_table() );
	}

	public function test_current_db_version_is_two(): void {
		self::assertSame( '2', SchemaManager::CURRENT_DB_VERSION );
	}

	public function test_install_short_circuits_when_version_matches(): void {
		Functions\when( 'get_option' )->justReturn( SchemaManager::CURRENT_DB_VERSION );
		Functions\expect( 'dbDelta' )->never();
		Functions\expect( 'update_option' )->never();

		SchemaManager::install();
	}

	public function test_install_runs_dbdelta_for_every_table_when_version_missing(): void {
		$captured_sql = [];
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )
			->times( 4 )
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ): array {
					$captured_sql[] = $sql;
					return [];
				}
			);

		SchemaManager::install();

		$combined = implode( "\n", $captured_sql );

		self::assertStringContainsString( 'CREATE TABLE wp_vl_enrollments', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_groups', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_group_members', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_group_access', $combined );

		self::assertStringContainsString( 'UNIQUE KEY uk_user_course (user_id, course_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_slug (slug)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_group_user_active (group_id, user_id, left_at)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_group_entity (group_id, entity_type, entity_id)', $combined );

		self::assertStringContainsString( 'KEY idx_owner (owner_id)', $combined );
		self::assertStringContainsString( 'KEY idx_status (status)', $combined );
		self::assertStringContainsString( 'KEY idx_entity (entity_type, entity_id)', $combined );
	}

	public function test_install_updates_version_option_after_creating_tables(): void {
		$saved_value = null;
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'dbDelta' )->justReturn( [] );
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( string $option, $value ) use ( &$saved_value ): bool {
					if ( SchemaManager::DB_VERSION_OPTION === $option ) {
						$saved_value = $value;
					}
					return true;
				}
			);

		SchemaManager::install();

		self::assertSame( SchemaManager::CURRENT_DB_VERSION, $saved_value );
	}

	public function test_install_runs_migration_path_when_stored_version_is_behind(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )->times( 4 )->andReturn( [] );

		SchemaManager::install();
	}

	public function test_uninstall_drops_all_tables_and_deletes_version_option(): void {
		$queries = [];
		$GLOBALS['wpdb']->shouldReceive( 'query' )
			->andReturnUsing(
				static function ( string $sql ) use ( &$queries ): int {
					$queries[] = $sql;
					return 0;
				}
			);
		Functions\expect( 'delete_option' )
			->once()
			->with( SchemaManager::DB_VERSION_OPTION )
			->andReturn( true );

		SchemaManager::uninstall();

		$combined = implode( "\n", $queries );

		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_enrollments', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_groups', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_group_members', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_group_access', $combined );
	}
}
