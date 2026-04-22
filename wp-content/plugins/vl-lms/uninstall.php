<?php
/**
 * Plugin uninstall cleanup.
 *
 * Invoked by WordPress when the user deletes the plugin via the admin UI.
 *
 * @package VL\LMS
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Phase 1+: delete custom tables, options, and user meta here.
