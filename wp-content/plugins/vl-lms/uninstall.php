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
	\VL\LMS\Database\SchemaManager::uninstall();
}

// Phase 2+: delete remaining options and user meta here.
