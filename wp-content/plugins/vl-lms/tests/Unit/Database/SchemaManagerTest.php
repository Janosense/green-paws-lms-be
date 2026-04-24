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

	public function test_install_short_circuits_when_version_matches(): void {
		Functions\when( 'get_option' )->justReturn( SchemaManager::CURRENT_DB_VERSION );
		Functions\expect( 'dbDelta' )->never();
		Functions\expect( 'update_option' )->never();

		SchemaManager::install();
	}

	public function test_install_runs_dbdelta_when_version_option_is_missing(): void {
		$captured_sql = null;
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )
			->once()
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ): array {
					$captured_sql = $sql;
					return [];
				}
			);

		SchemaManager::install();

		self::assertIsString( $captured_sql );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_enrollments', $captured_sql );
		self::assertStringContainsString( 'UNIQUE KEY uk_user_course (user_id, course_id)', $captured_sql );
		self::assertStringContainsString( 'KEY idx_course (course_id)', $captured_sql );
		self::assertStringContainsString( 'KEY idx_user_status (user_id, status)', $captured_sql );
		self::assertStringContainsString( 'KEY idx_group (source_group_id)', $captured_sql );
		self::assertStringContainsString( 'progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0', $captured_sql );
		self::assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $captured_sql );

		foreach (
			[
				'id',
				'user_id',
				'course_id',
				'status',
				'source',
				'source_group_id',
				'source_order_id',
				'enrolled_at',
				'started_at',
				'completed_at',
				'expires_at',
				'revoked_at',
				'revoked_by',
				'revoke_reason',
				'progress_pct',
				'created_at',
				'updated_at',
			] as $column
		) {
			self::assertStringContainsString( $column, $captured_sql, sprintf( 'Missing column %s', $column ) );
		}
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

	public function test_uninstall_drops_enrollments_table_and_deletes_version_option(): void {
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

		self::assertNotEmpty( $queries );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_enrollments', $queries[0] );
	}
}
