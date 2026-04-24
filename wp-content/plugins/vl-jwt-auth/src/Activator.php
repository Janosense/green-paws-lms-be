<?php

declare(strict_types=1);

namespace VLJwtAuth;

/**
 * Runs on plugin activation.
 *
 * Creates the refresh-token table, seeds the default settings option, and
 * schedules the daily refresh-token cleanup job.
 * Safe to run repeatedly — `dbDelta` applies schema changes idempotently,
 * `add_option` is a no-op when the option already exists, and the cron
 * scheduler is guarded with `wp_next_scheduled`.
 */
final class Activator {

	public const CLEANUP_HOOK = 'vl_jwt_auth_cleanup_expired_tokens';

	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'vl_refresh_tokens';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY,
		// each column/key on its own line, no trailing comma before `)`.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			token_family CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			device_name VARCHAR(191) NULL,
			ip_address VARBINARY(16) NULL,
			user_agent VARCHAR(500) NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_token_hash (token_hash),
			KEY idx_user_active (user_id, revoked_at, expires_at),
			KEY idx_token_family (token_family)
		) {$charset_collate};";

		dbDelta( $sql );

		add_option(
			'vl_jwt_auth_settings',
			[
				'access_token_ttl'  => 1800,
				'refresh_token_ttl' => 1209600,
				'cookie_domain'     => '',
				'cookie_samesite'   => 'None',
				'rate_limit_login'  => 5,
				'rate_limit_window' => 900,
				'allowed_origins'   => [],
			]
		);

		add_option( 'vl_jwt_auth_db_version', VL_JWT_AUTH_VERSION );

		// Schedule the daily cleanup of expired refresh-token rows. The
		// `wp_next_scheduled` guard makes this safe to run on every
		// activation without producing duplicate events.
		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}
}
