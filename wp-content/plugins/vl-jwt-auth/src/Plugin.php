<?php

declare(strict_types=1);

namespace VLJwtAuth;

/**
 * Main plugin bootstrap.
 *
 * Responsible for wiring services together and registering WordPress hooks.
 * Services are constructed lazily so that activation (which loads this file
 * to resolve the Activator class) does not incur runtime wiring cost.
 */
final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Called on `plugins_loaded` from the main plugin file.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		// REST routes, password-change revocation and other wiring land in later chunks.
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'vl-jwt-auth',
			false,
			dirname( VL_JWT_AUTH_BASENAME ) . '/languages'
		);
	}
}
