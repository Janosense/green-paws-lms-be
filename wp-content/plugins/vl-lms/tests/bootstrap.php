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

// WordPress core's row-format constants used by `$wpdb` and `get_page_by_path()`.
// In production these come from `wp-includes/wp-db.php`; in unit tests neither
// the function nor the constant is loaded by default. Brain Monkey intercepts
// the function call, but PHP still needs the constant to evaluate at the call site.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constants.
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
// phpcs:enable

// vl-jwt-auth's secret key constant is defined in `wp-config.php` at runtime.
// Tests and static analysis never need a real value; this placeholder is
// enough to make `defined(...)` / `(string) VL_JWT_AUTH_SECRET_KEY` type-check.
if ( ! defined( 'VL_JWT_AUTH_SECRET_KEY' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant name is owned by the sibling vl-jwt-auth plugin.
	define( 'VL_JWT_AUTH_SECRET_KEY', 'phpstan-analysis-placeholder' );
}

// Minimal stub for WP_Error so code paths that construct a real
// `new WP_Error(...)` (e.g. the login gate) work in unit tests without
// booting WordPress. Mirrors the getter surface the tests actually use.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore

		private string $code;
		private string $message;
		/** @var array<string, mixed> */
		private array $data;

		/**
		 * @param string|int           $code
		 * @param array<string, mixed> $data
		 */
		public function __construct( $code = '', string $message = '', $data = [] ) {
			$this->code    = (string) $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : [];
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}
