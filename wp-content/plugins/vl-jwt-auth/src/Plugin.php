<?php

declare(strict_types=1);

namespace VLJwtAuth;

use VLJwtAuth\Api\RestController;
use VLJwtAuth\Auth\ClaimsBuilder;
use VLJwtAuth\Auth\TokenService;
use VLJwtAuth\Repository\RefreshTokenRepository;
use VLJwtAuth\Support\CookieManager;
use VLJwtAuth\Support\Settings;

/**
 * Main plugin bootstrap.
 *
 * Wires services together on demand. Nothing is instantiated until a
 * WordPress hook actually fires, so activation (which loads this file
 * to resolve Activator) pays no runtime wiring cost.
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
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		// Password-change revocation + public facade wiring land in chunk 4.
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'vl-jwt-auth',
			false,
			dirname( VL_JWT_AUTH_BASENAME ) . '/languages'
		);
	}

	public function register_rest_routes(): void {
		$settings       = new Settings();
		$claims_builder = new ClaimsBuilder( $settings );
		$token_service  = new TokenService( (string) VL_JWT_AUTH_SECRET_KEY, $claims_builder );
		$refresh_repo   = new RefreshTokenRepository();
		$cookies        = new CookieManager( $settings );

		( new RestController( $token_service, $refresh_repo, $cookies ) )->register_routes();
	}
}
