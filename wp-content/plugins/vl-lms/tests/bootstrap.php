<?php
/**
 * Unit test bootstrap.
 *
 * Pulls in Composer's autoloader and a stripped set of plugin constants so
 * classes under test can reference them without loading the full WordPress
 * runtime. Brain Monkey handles per-test setup/teardown from the test cases
 * themselves.
 *
 * @package VL\LMS\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'VL_LMS_VERSION' ) ) {
	define( 'VL_LMS_VERSION', '0.1.0' );
}

if ( ! defined( 'VL_LMS_FILE' ) ) {
	define( 'VL_LMS_FILE', dirname( __DIR__ ) . '/vl-lms.php' );
}

if ( ! defined( 'VL_LMS_DIR' ) ) {
	define( 'VL_LMS_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'VL_LMS_URL' ) ) {
	define( 'VL_LMS_URL', 'http://example.test/wp-content/plugins/vl-lms/' );
}

if ( ! defined( 'VL_LMS_BASENAME' ) ) {
	define( 'VL_LMS_BASENAME', 'vl-lms/vl-lms.php' );
}

if ( ! defined( 'VL_LMS_API_NAMESPACE' ) ) {
	define( 'VL_LMS_API_NAMESPACE', 'vl/v1' );
}
