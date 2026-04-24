<?php

declare(strict_types=1);

namespace VL\LMS\Database;

/**
 * Owns every custom DB table the plugin ships and the version option that
 * gates schema migrations.
 *
 * `install()` is idempotent — it compares the stored
 * {@see self::DB_VERSION_OPTION} value to {@see self::CURRENT_DB_VERSION}
 * and short-circuits when they match, so repeated activations never
 * re-run `dbDelta`. Bumping `CURRENT_DB_VERSION` re-enters the
 * migration path on the next activation.
 *
 * `uninstall()` drops every `vl_*` table in one place so new tables
 * added in later phases only need their DROP line here.
 *
 * @author Tymofii Synianskyi
 */
final class SchemaManager {

	public const DB_VERSION_OPTION  = 'vl_lms_db_version';
	public const CURRENT_DB_VERSION = '1';

	/**
	 * Returns the full prefixed table name for a base suffix.
	 *
	 * The single point where `$wpdb->prefix` is joined with the `vl_`
	 * namespace — every other class routes through {@see self::enrollments_table()}
	 * (or future siblings) so the prefix convention can change without a
	 * codebase-wide rename.
	 */
	public static function table_name( string $base ): string {
		global $wpdb;
		return $wpdb->prefix . 'vl_' . $base;
	}

	public static function enrollments_table(): string {
		return self::table_name( 'enrollments' );
	}

	/**
	 * Installs (or migrates) the schema when the stored DB version is
	 * behind {@see self::CURRENT_DB_VERSION}. Safe to call on every
	 * activation — it is a no-op after the first successful run.
	 */
	public static function install(): void {
		$current = get_option( self::DB_VERSION_OPTION );
		if ( is_string( $current ) && self::CURRENT_DB_VERSION === $current ) {
			return;
		}

		self::create_enrollments_table();

		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * Drops every `vl_*` table and clears the version option.
	 *
	 * Invoked by `uninstall.php`. Deactivation never calls this — the
	 * user must explicitly delete the plugin before data is destroyed.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = [ self::enrollments_table() ];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_enrollments`.
	 *
	 * Column and index declarations follow the `dbDelta` formatting rules:
	 * two spaces between column name and type, uppercase keywords, `PRIMARY
	 * KEY` / `UNIQUE KEY` / `KEY` each on their own line. `dbDelta` is
	 * sensitive to whitespace — reformatting this SQL will break upgrades.
	 */
	private static function create_enrollments_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::enrollments_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			source_group_id BIGINT UNSIGNED NULL DEFAULT NULL,
			source_order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			enrolled_at DATETIME NOT NULL,
			started_at DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			revoked_by BIGINT UNSIGNED NULL DEFAULT NULL,
			revoke_reason VARCHAR(255) NULL DEFAULT NULL,
			progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user_course (user_id, course_id),
			KEY idx_course (course_id),
			KEY idx_user_status (user_id, status),
			KEY idx_group (source_group_id)
		) {$charset};";

		dbDelta( $sql );
	}
}
