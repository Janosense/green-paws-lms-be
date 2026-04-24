<?php
/**
 * Plugin Name:       VL JWT Auth
 * Plugin URI:        https://github.com/veterinary-lms/vl-jwt-auth
 * Description:       JWT-based authentication for headless WordPress. Exposes REST endpoints and a public PHP API for other plugins.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Veterinary LMS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vl-jwt-auth
 * Domain Path:       /languages
 *
 * @package VLJwtAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'VL_JWT_AUTH_VERSION', '0.1.0' );
define( 'VL_JWT_AUTH_FILE', __FILE__ );
define( 'VL_JWT_AUTH_DIR', plugin_dir_path( __FILE__ ) );
define( 'VL_JWT_AUTH_URL', plugin_dir_url( __FILE__ ) );
define( 'VL_JWT_AUTH_BASENAME', plugin_basename( __FILE__ ) );

$vl_jwt_auth_autoload = VL_JWT_AUTH_DIR . 'vendor/autoload.php';

if ( ! file_exists( $vl_jwt_auth_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'VL JWT Auth: Composer dependencies are missing. Run "composer install" inside the plugin directory.',
				'vl-jwt-auth'
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once $vl_jwt_auth_autoload;

// Expose the public facade as \VLJwtAuth\Auth for downstream plugins.
// Co-exists with the VLJwtAuth\Auth\* namespace — class names and
// namespace prefixes occupy separate symbol spaces in PHP.
if ( ! class_exists( 'VLJwtAuth\\Auth', false ) ) {
	class_alias( \VLJwtAuth\Auth\AuthFacade::class, 'VLJwtAuth\\Auth' );
}

register_activation_hook( __FILE__, [ \VLJwtAuth\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \VLJwtAuth\Deactivator::class, 'deactivate' ] );

if ( ! defined( 'VL_JWT_AUTH_SECRET_KEY' ) || '' === VL_JWT_AUTH_SECRET_KEY ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'VL JWT Auth: VL_JWT_AUTH_SECRET_KEY is not defined in wp-config.php. The plugin is inactive until a secret is configured.',
				'vl-jwt-auth'
			);
			echo '</p></div>';
		}
	);
	return;
}

add_action(
	'plugins_loaded',
	static function (): void {
		\VLJwtAuth\Plugin::instance()->init();
	}
);
