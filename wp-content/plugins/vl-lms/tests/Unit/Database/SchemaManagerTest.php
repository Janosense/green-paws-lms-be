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

	public function test_course_instructors_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_course_instructors', SchemaManager::course_instructors_table() );
	}

	public function test_progress_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_progress', SchemaManager::progress_table() );
	}

	public function test_lesson_views_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_lesson_views', SchemaManager::lesson_views_table() );
	}

	public function test_quiz_attempts_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_quiz_attempts', SchemaManager::quiz_attempts_table() );
	}

	public function test_quiz_answers_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_quiz_answers', SchemaManager::quiz_answers_table() );
	}

	public function test_certificates_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_certificates', SchemaManager::certificates_table() );
	}

	public function test_session_attendance_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_session_attendance', SchemaManager::session_attendance_table() );
	}

	public function test_webinar_registrations_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_webinar_registrations', SchemaManager::webinar_registrations_table() );
	}

	public function test_zoom_webhook_events_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_zoom_webhook_events', SchemaManager::zoom_webhook_events_table() );
	}

	public function test_orders_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_orders', SchemaManager::orders_table() );
	}

	public function test_payments_table_matches_table_name_helper(): void {
		self::assertSame( 'wp_vl_payments', SchemaManager::payments_table() );
	}

	public function test_current_db_version_is_seven(): void {
		self::assertSame( '7', SchemaManager::CURRENT_DB_VERSION );
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
			->times( 15 )
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
		self::assertStringContainsString( 'CREATE TABLE wp_vl_course_instructors', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_progress', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_lesson_views', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_quiz_attempts', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_quiz_answers', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_certificates', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_session_attendance', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_webinar_registrations', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_zoom_webhook_events', $combined );

		self::assertStringContainsString( 'UNIQUE KEY uk_user_course (user_id, course_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_slug (slug)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_group_user_active (group_id, user_id, left_at)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_group_entity (group_id, entity_type, entity_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uk_entity_user (entity_type, entity_id, user_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uniq_user_entity (user_id, entity_type, entity_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY attempt_question (attempt_id, question_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uuid (uuid)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uniq_session_participant (session_id, zoom_participant_uuid)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY uniq_webinar_user (webinar_id, user_id)', $combined );
		self::assertStringContainsString( 'UNIQUE KEY tracking_id (tracking_id)', $combined );

		self::assertStringContainsString( 'KEY idx_owner (owner_id)', $combined );
		self::assertStringContainsString( 'KEY idx_status (status)', $combined );
		self::assertStringContainsString( 'KEY idx_entity (entity_type, entity_id)', $combined );
		self::assertStringContainsString( 'KEY idx_user (user_id)', $combined );
		self::assertStringContainsString( 'KEY idx_user_course_status (user_id, course_id, status)', $combined );
		self::assertStringContainsString( 'KEY idx_user_lesson_time (user_id, lesson_id, created_at)', $combined );
		self::assertStringContainsString( 'KEY idx_session (session_uuid)', $combined );
		self::assertStringContainsString( 'KEY idx_lesson_event (lesson_id, event_type)', $combined );
		self::assertStringContainsString( 'KEY user_quiz (user_id, quiz_id)', $combined );
		self::assertStringContainsString( 'KEY user_course (user_id, course_id)', $combined );
		self::assertStringContainsString( 'KEY session_user (session_id, user_id)', $combined );
		self::assertStringContainsString( 'KEY webinar_status (webinar_id, status)', $combined );
		self::assertStringContainsString( 'KEY user_status (user_id, status)', $combined );
		self::assertStringContainsString( 'KEY processing_status (processing_status)', $combined );
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
		Functions\when( 'get_option' )->justReturn( '2' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )->times( 15 )->andReturn( [] );

		SchemaManager::install();
	}

	public function test_install_upgrade_path_from_five_creates_three_new_tables(): void {
		$captured_sql = [];
		Functions\when( 'get_option' )->justReturn( '5' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )
			->times( 15 )
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ): array {
					$captured_sql[] = $sql;
					return [];
				}
			);

		SchemaManager::install();

		$combined = implode( "\n", $captured_sql );

		self::assertStringContainsString( 'CREATE TABLE wp_vl_session_attendance', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_webinar_registrations', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_zoom_webhook_events', $combined );
	}

	public function test_install_upgrade_path_from_five_writes_current_version(): void {
		$saved_value = null;
		Functions\when( 'get_option' )->justReturn( '5' );
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

	public function test_install_creates_orders_and_payments_tables(): void {
		$captured_sql = [];
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )
			->times( 15 )
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ): array {
					$captured_sql[] = $sql;
					return [];
				}
			);

		SchemaManager::install();

		$combined = implode( "\n", $captured_sql );

		self::assertStringContainsString( 'CREATE TABLE wp_vl_orders', $combined );
		self::assertStringContainsString( 'CREATE TABLE wp_vl_payments', $combined );

		// Order columns and indexes.
		self::assertStringContainsString( 'liqpay_order_id VARCHAR(64)', $combined );
		self::assertStringContainsString( 'amount DECIMAL(12,2) NOT NULL', $combined );
		self::assertStringContainsString( 'currency CHAR(3) NOT NULL', $combined );
		self::assertStringContainsString( 'KEY status_expires (status, expires_at)', $combined );
		self::assertStringContainsString( 'KEY user_entity_status (user_id, entity_type, entity_id, status)', $combined );

		// Payment indexes.
		self::assertStringContainsString( 'UNIQUE KEY idempotency_key (idempotency_key)', $combined );
		self::assertStringContainsString( 'KEY order_received (order_id, received_at)', $combined );
		self::assertStringContainsString( 'KEY provider_payment (provider, provider_payment_id)', $combined );
	}

	public function test_install_adds_source_order_id_column_to_enrollments(): void {
		$captured_sql = [];
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )
			->times( 15 )
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ): array {
					$captured_sql[] = $sql;
					return [];
				}
			);

		SchemaManager::install();

		$combined = implode( "\n", $captured_sql );

		self::assertStringContainsString( 'source_order_id BIGINT UNSIGNED', $combined );
		self::assertStringContainsString( 'KEY idx_source_order (source_order_id)', $combined );
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
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_course_instructors', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_progress', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_lesson_views', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_quiz_attempts', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_quiz_answers', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_certificates', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_session_attendance', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_webinar_registrations', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_zoom_webhook_events', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_orders', $combined );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS wp_vl_payments', $combined );
	}
}
