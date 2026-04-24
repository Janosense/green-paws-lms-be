<?php
/**
 * Plugin uninstall cleanup.
 *
 * Invoked by WordPress when the user deletes the plugin via the admin UI.
 *
 * @package VL\LMS
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$vl_lms_uninstall_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $vl_lms_uninstall_autoload ) ) {
	require_once $vl_lms_uninstall_autoload;
}

if ( class_exists( \VL\LMS\Roles\RolesInstaller::class ) ) {
	\VL\LMS\Roles\RolesInstaller::uninstall();
}

if ( class_exists( \VL\LMS\Database\SchemaManager::class ) ) {
	// Note: `vl_lms_db_version` is deleted inside SchemaManager::uninstall()
	// alongside the table drops. The parallel `vl_lms_plugin_version` is
	// deleted below so both version options are cleared together.
	\VL\LMS\Database\SchemaManager::uninstall();
}

delete_option( 'vl_lms_plugin_version' );

// `vl_difficulty` terms are intentionally preserved. Taxonomy data belongs
// to the taxonomy, not to this plugin's installer — deleting them would
// break any other plugin or theme that references the same terms, and
// term cleanup is outside Phase 1 scope.
