<?php
/**
 * Plugin Name: VL CORS Handler
 * Description: Centralized CORS policy for all VL REST API namespaces.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace VLCors;

defined( 'ABSPATH' ) || exit;

final class Handler {

	private const DEFAULT_NAMESPACES = [ 'vl', 'vl-auth' ];
	private const ALLOWED_HEADERS    = [ 'Authorization', 'Content-Type', 'X-Requested-With' ];
	private const ALLOWED_METHODS    = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$instance = new self();

		// `rest_send_cors_headers` is hooked onto `rest_pre_serve_request` by
		// `rest_api_default_filters()` at `rest_api_init` priority 10. A
		// `remove_filter()` call on mu-plugin load runs too early to take
		// effect — the filter hasn't been added yet. Defer the swap until
		// after defaults register.
		add_action( 'rest_api_init', [ $instance, 'registerRestHooks' ], 11 );

		// Preflight OPTIONS requests must be answered before WP dispatches
		// normally; hook early on `rest_api_init`, which fires on every
		// REST request.
		add_action( 'rest_api_init', [ $instance, 'handlePreflight' ], 15 );
	}

	public function registerRestHooks(): void {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', [ $this, 'sendHeaders' ], 10, 4 );
	}

	public function sendHeaders( $served, $result, \WP_REST_Request $request, $server ) {
		if ( ! $this->isOurRoute( $request ) ) {
			// Delegate to WP's default behavior for routes we don't own.
			return rest_send_cors_headers( $served );
		}

		$this->emitCorsHeaders();
		return $served;
	}

	public function handlePreflight(): void {
		if ( 'OPTIONS' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		foreach ( $this->allowedNamespaces() as $ns ) {
			if ( str_starts_with( $uri, "/wp-json/{$ns}/" ) ) {
				// Emit CORS headers BEFORE exiting — a bare 204 with no
				// Allow-Origin header is rejected by every browser.
				$this->emitCorsHeaders();
				status_header( 204 );
				exit;
			}
		}
	}

	private function emitCorsHeaders(): void {
		$origin = $this->resolveOrigin();
		if ( null === $origin ) {
			return;
		}

		header( "Access-Control-Allow-Origin: {$origin}" );
		header( 'Access-Control-Allow-Methods: ' . implode( ', ', self::ALLOWED_METHODS ) );
		header( 'Access-Control-Allow-Headers: ' . implode( ', ', self::ALLOWED_HEADERS ) );
		// Required for the httpOnly refresh cookie issued by vl-jwt-auth.
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );
	}

	private function isOurRoute( \WP_REST_Request $request ): bool {
		$route = $request->get_route();
		foreach ( $this->allowedNamespaces() as $ns ) {
			if ( str_starts_with( $route, "/{$ns}/" ) ) {
				return true;
			}
		}
		return false;
	}

	private function resolveOrigin(): ?string {
		$origin = get_http_origin();
		if ( ! $origin ) {
			return null;
		}

		$allowed = apply_filters( 'vl_cors/allowed_origins', $this->configuredOrigins() );
		return in_array( $origin, $allowed, true ) ? $origin : null;
	}

	private function configuredOrigins(): array {
		$raw = defined( 'VL_CORS_ORIGINS' ) ? VL_CORS_ORIGINS : '';
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * @return string[]
	 */
	private function allowedNamespaces(): array {
		$namespaces = apply_filters( 'vl_cors/allowed_namespaces', self::DEFAULT_NAMESPACES );
		return is_array( $namespaces ) ? array_values( array_filter( $namespaces, 'is_string' ) ) : self::DEFAULT_NAMESPACES;
	}
}

Handler::boot();
