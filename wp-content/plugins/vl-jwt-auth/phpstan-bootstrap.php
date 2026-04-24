<?php
/**
 * PHPStan-only bootstrap.
 *
 * Defines the runtime constants PHPStan needs to see but that are declared
 * in files it cannot safely load at analysis time — either because they
 * call WordPress functions (`plugin_dir_path`) or because they live in the
 * site's `wp-config.php` (`VL_JWT_AUTH_SECRET_KEY`). Loaded from
 * `phpstan.neon.dist` via `parameters.bootstrapFiles`.
 *
 * Nothing in this file runs in production or in tests; it exists purely
 * for static analysis symbol discovery.
 *
 * @package VLJwtAuth
 */

declare(strict_types=1);

if ( ! defined( 'VL_JWT_AUTH_VERSION' ) ) {
	define( 'VL_JWT_AUTH_VERSION', '0.1.0' );
}

if ( ! defined( 'VL_JWT_AUTH_FILE' ) ) {
	define( 'VL_JWT_AUTH_FILE', __DIR__ . '/vl-jwt-auth.php' );
}

if ( ! defined( 'VL_JWT_AUTH_DIR' ) ) {
	define( 'VL_JWT_AUTH_DIR', __DIR__ . '/' );
}

if ( ! defined( 'VL_JWT_AUTH_URL' ) ) {
	define( 'VL_JWT_AUTH_URL', 'http://example.test/wp-content/plugins/vl-jwt-auth/' );
}

if ( ! defined( 'VL_JWT_AUTH_BASENAME' ) ) {
	define( 'VL_JWT_AUTH_BASENAME', 'vl-jwt-auth/vl-jwt-auth.php' );
}

if ( ! defined( 'VL_JWT_AUTH_SECRET_KEY' ) ) {
	define( 'VL_JWT_AUTH_SECRET_KEY', 'phpstan-analysis-placeholder' );
}
