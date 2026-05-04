<?php
/**
 * Unit test bootstrap.
 *
 * Pulls in Composer's autoloader and a stripped set of plugin constants so
 * classes under test can reference them without loading the full WordPress
 * runtime. Brain Monkey handles per-test setup/teardown from the test cases
 * themselves.
 *
 * @package VLJwtAuth\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'VL_JWT_AUTH_VERSION' ) ) {
	define( 'VL_JWT_AUTH_VERSION', '0.1.0' );
}

if ( ! defined( 'VL_JWT_AUTH_FILE' ) ) {
	define( 'VL_JWT_AUTH_FILE', dirname( __DIR__ ) . '/vl-jwt-auth.php' );
}

if ( ! defined( 'VL_JWT_AUTH_DIR' ) ) {
	define( 'VL_JWT_AUTH_DIR', dirname( __DIR__ ) . '/' );
}

// VL_JWT_AUTH_SECRET_KEY is provided by wp-config.php at runtime. Tests that
// exercise token signing must mock it explicitly via Brain Monkey or override
// the constant inside the test class — the placeholder here is just enough
// to satisfy `defined(...)` / `(string) VL_JWT_AUTH_SECRET_KEY` type checks
// for code paths that don't depend on a real value.
if ( ! defined( 'VL_JWT_AUTH_SECRET_KEY' ) ) {
	define( 'VL_JWT_AUTH_SECRET_KEY', 'phpunit-bootstrap-placeholder' );
}
