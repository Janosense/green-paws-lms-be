<?php

declare(strict_types=1);

namespace VLJwtAuth;

use VLJwtAuth\Api\RestController;
use VLJwtAuth\Auth\ClaimsBuilder;
use VLJwtAuth\Auth\TokenService;
use VLJwtAuth\Repository\RefreshTokenRepository;
use VLJwtAuth\Support\CookieManager;
use VLJwtAuth\Support\OriginGuard;
use VLJwtAuth\Support\RateLimiter;
use VLJwtAuth\Support\Settings;
use WP_User;

/**
 * Main plugin bootstrap.
 *
 * Wires services together on demand. Nothing is instantiated until a
 * WordPress hook actually fires, so activation (which loads this file
 * to resolve Activator) pays no runtime wiring cost.
 */
final class Plugin {

	private static ?self $instance = null;

	private ?RefreshTokenRepository $refresh_repo = null;

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

		// Invalidate every refresh token a user holds when their password
		// changes — both via profile edit and the lost-password flow.
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
		add_action( 'password_reset', [ $this, 'on_password_reset' ], 10, 2 );

		// Daily cleanup of long-expired refresh-token rows. The event is
		// scheduled by the Activator; binding the listener here ensures
		// the handler is reachable on every request, including WP-Cron's.
		add_action( Activator::CLEANUP_HOOK, [ $this, 'cleanup_expired_tokens' ] );
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
		$cookies        = new CookieManager( $settings );
		$rate_limiter   = new RateLimiter();
		$origin_guard   = new OriginGuard( $settings );

		( new RestController(
			$token_service,
			$this->refresh_repo(),
			$cookies,
			$rate_limiter,
			$origin_guard,
			$settings
		) )->register_routes();
	}

	/**
	 * Revoke a user's refresh tokens when their password has just changed.
	 *
	 * @param int     $user_id         The user being updated.
	 * @param WP_User $old_user_data   User data from *before* the update.
	 */
	public function on_profile_update( int $user_id, WP_User $old_user_data ): void {
		$updated = get_userdata( $user_id );
		if ( ! $updated instanceof WP_User ) {
			return;
		}
		if ( $updated->user_pass !== $old_user_data->user_pass ) {
			$this->refresh_repo()->revoke_user( $user_id );
		}
	}

	/**
	 * Revoke every refresh token belonging to the user that just completed
	 * a lost-password reset.
	 */
	public function on_password_reset( WP_User $user, string $new_password ): void {
		unset( $new_password );
		$this->refresh_repo()->revoke_user( (int) $user->ID );
	}

	/**
	 * Hook target for the daily cron event registered by the Activator.
	 * Delegates to the repository so this class stays orchestration-only.
	 */
	public function cleanup_expired_tokens(): void {
		$this->refresh_repo()->cleanup_expired();
	}

	private function refresh_repo(): RefreshTokenRepository {
		return $this->refresh_repo ??= new RefreshTokenRepository();
	}
}
