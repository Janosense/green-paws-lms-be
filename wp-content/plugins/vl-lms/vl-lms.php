<?php
/**
 * Plugin Name:       VL LMS
 * Plugin URI:        https://github.com/veterinary-lms/vl-lms
 * Description:       Veterinary LMS backend — headless WordPress plugin powering the Nuxt.js frontend.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Veterinary LMS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vl-lms
 * Domain Path:       /languages
 *
 * @package VL\LMS
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'VL_LMS_VERSION', '0.1.0' );
define( 'VL_LMS_FILE', __FILE__ );
define( 'VL_LMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VL_LMS_URL', plugin_dir_url( __FILE__ ) );
define( 'VL_LMS_BASENAME', plugin_basename( __FILE__ ) );
define( 'VL_LMS_API_NAMESPACE', 'vl/v1' );

$vl_lms_autoload = VL_LMS_DIR . 'vendor/autoload.php';

if ( ! file_exists( $vl_lms_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'VL LMS: Composer dependencies are missing. Run "composer install" inside the plugin directory.',
				'vl-lms'
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once $vl_lms_autoload;

register_activation_hook( __FILE__, [ \VL\LMS\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \VL\LMS\Deactivator::class, 'deactivate' ] );

// Boot after vl-jwt-auth (priority 10). The Plugin class verifies the dependency
// internally and short-circuits with an admin notice if it is missing.
add_action(
	'plugins_loaded',
	static function (): void {
		\VL\LMS\Plugin::instance()->boot();
	},
	20
);
