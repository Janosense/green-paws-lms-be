<?php
/**
 * Plugin Name: VL CORS Handler
 * Description: Centralized CORS policy for all VL REST API namespaces.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Lives in `mu-plugins/` because CORS is infrastructure, not a feature: it
 * must always be active, must load before regular plugins, and cannot be
 * deactivated through the admin UI. Owning it here also keeps a single
 * authoritative policy across every VL plugin (`vl-jwt-auth`, `vl-lms`,
 * and any future namespace that opts in via the `vl_cors/allowed_namespaces`
 * filter), so two plugins can never disagree about what to allow.
 *
 * What it does
 * ------------
 * 1. **Replaces WordPress's default CORS headers on VL routes.** WP's
 *    built-in `rest_send_cors_headers()` echoes the request `Origin` back
 *    unconditionally with `Access-Control-Allow-Origin: *`-style behavior
 *    and never emits `Access-Control-Allow-Credentials`. That is fine for
 *    public endpoints but breaks any flow that needs to send cookies — in
 *    our case the HttpOnly refresh-token cookie issued by `vl-jwt-auth`.
 *    On routes under `/vl/` and `/vl-auth/` we strip the default filter
 *    and emit our own headers: an exact-origin echo (only when the origin
 *    appears in the configured allowlist), `Allow-Credentials: true`, and
 *    a `Vary: Origin` so caches don't collapse responses across origins.
 * 2. **Answers preflight `OPTIONS` requests early.** `OPTIONS /wp-json/...`
 *    is dispatched by WP through the same routing pipeline as any other
 *    request, which sometimes 404s on routes that exist only for one
 *    method. We short-circuit on `rest_api_init` for VL namespaces and
 *    return a bare 204 — but only after emitting the CORS headers, since
 *    a 204 without `Allow-Origin` is rejected by every browser.
 * 3. **Leaves non-VL routes alone.** When the request is not under one of
 *    the configured namespaces, the filter delegates back to
 *    `rest_send_cors_headers()` so WP-Admin, Gutenberg, and any third-
 *    party plugin keep their existing behavior unchanged.
 *
 * Configuration
 * -------------
 * - `VL_CORS_ORIGINS` (constant in `wp-config.php`) — comma-separated
 *   list of fully-qualified origins permitted to send credentialed
 *   requests, e.g. `'http://localhost:3000,https://app.vetlms.com'`.
 *   The browser sends an exact origin string; we never wildcard.
 * - `vl_cors/allowed_origins` (filter) — runtime override of the parsed
 *   allowlist. Useful for environment-specific seams or test suites.
 * - `vl_cors/allowed_namespaces` (filter) — extend the set of REST
 *   namespaces this handler covers. Defaults to `['vl', 'vl-auth']`.
 *
 * Hook timing
 * -----------
 * - `rest_api_init@11` swaps the default `rest_send_cors_headers` filter
 *   for ours. Priority 11 is intentional — `rest_api_default_filters()`
 *   adds the default at priority 10 during `rest_api_init`, so a
 *   `remove_filter()` call on mu-plugin load runs too early to take
 *   effect.
 * - `rest_api_init@15` runs the preflight short-circuit, after the route
 *   table has been registered but before WP dispatches the request.
 *
 * @package VLCors
 */

namespace VLCors;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized CORS handler for every VL REST namespace.
 *
 * Booted exactly once on file load via {@see self::boot()}; the static
 * guard makes a second include (e.g. from a misconfigured loader) a
 * no-op. The class is intentionally final and stateful only at the
 * boot-flag level — instances hold no per-request data, so the same
 * instance services every hook for the lifetime of the request.
 */
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

		// VL endpoints are per-user and auth-sensitive; without an explicit
		// Cache-Control, browsers fall back to heuristic caching on GETs and
		// can serve a stale 401/403 (or empty body) after a fresh login,
		// leaving the SPA wedged on the previous render. `no-store` prevents
		// any disk/memory cache; `private` blocks shared caches in front of
		// the origin. Pragma is the HTTP/1.0 fallback.
		header( 'Cache-Control: no-store, private' );
		header( 'Pragma: no-cache' );
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
