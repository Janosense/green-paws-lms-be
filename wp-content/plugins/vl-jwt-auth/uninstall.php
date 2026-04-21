<?php
/**
 * Plugin uninstall cleanup.
 *
 * Invoked by WordPress when the user deletes the plugin via the admin UI.
 *
 * @package VLJwtAuth
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'vl_refresh_tokens';
// Table name is interpolated from a WP-controlled prefix plus a literal suffix,
// so it is safe to drop without a prepared statement.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

delete_option( 'vl_jwt_auth_settings' );
delete_option( 'vl_jwt_auth_db_version' );
