<?php
/**
 * Plugin Name: VL CORS Handler
 * Description: Centralized CORS policy for all VL REST API namespaces.
 * Version: 1.0.0
 */

namespace VLCors;

defined('ABSPATH') || exit;

final class Handler
{
	private const ALLOWED_NAMESPACES = ['vl', 'vl-auth'];
	private const ALLOWED_HEADERS = ['Authorization', 'Content-Type', 'X-Requested-With'];
	private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

	public static function boot(): void
	{
		$instance = new self();
		remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
		add_filter('rest_pre_serve_request', [$instance, 'sendHeaders'], 10, 4);
		add_action('rest_api_init', [$instance, 'handlePreflight'], 15);
	}

	public function sendHeaders($served, $result, \WP_REST_Request $request, $server)
	{
		if (!$this->isOurRoute($request)) {
			return rest_send_cors_headers($served);
		}

		$origin = $this->resolveOrigin();
		if ($origin === null) return $served;

		header("Access-Control-Allow-Origin: {$origin}");
		header('Access-Control-Allow-Methods: ' . implode(', ', self::ALLOWED_METHODS));
		header('Access-Control-Allow-Headers: ' . implode(', ', self::ALLOWED_HEADERS));
		header('Access-Control-Allow-Credentials: true'); // потрібно для refresh-cookie з vl-jwt-auth
		header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
		header('Access-Control-Max-Age: 600');
		header('Vary: Origin', false);

		return $served;
	}

	public function handlePreflight(): void
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') return;
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		foreach (self::ALLOWED_NAMESPACES as $ns) {
			if (str_starts_with($uri, "/wp-json/{$ns}/")) {
				status_header(204);
				exit;
			}
		}
	}

	private function isOurRoute(\WP_REST_Request $request): bool
	{
		$route = $request->get_route();
		foreach (self::ALLOWED_NAMESPACES as $ns) {
			if (str_starts_with($route, "/{$ns}/")) return true;
		}
		return false;
	}

	private function resolveOrigin(): ?string
	{
		$origin = get_http_origin();
		if (!$origin) return null;

		$allowed = apply_filters('vl_cors/allowed_origins', $this->configuredOrigins());
		return in_array($origin, $allowed, true) ? $origin : null;
	}

	private function configuredOrigins(): array
	{
		$raw = defined('VL_CORS_ORIGINS') ? VL_CORS_ORIGINS : '';
		return array_filter(array_map('trim', explode(',', $raw)));
	}
}

Handler::boot();
