<?php
/**
 * Redirect public frontend requests to VL_FRONTEND_URL.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Frontend_Redirect {

    public function register(): void {
        add_action('template_redirect', [$this, 'maybe_redirect']);
    }

    public function maybe_redirect(): void {
        if ($this->should_skip()) {
            return;
        }

        if (!defined('VL_FRONTEND_URL') || VL_FRONTEND_URL === '') {
            error_log('[vl-headless-theme] VL_FRONTEND_URL is not defined; skipping frontend redirect.');
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $target      = rtrim((string) VL_FRONTEND_URL, '/') . $request_uri;

        wp_redirect($target, 302);
        exit;
    }

    private function should_skip(): bool {
        if (is_admin() || wp_doing_ajax()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if ($this->is_rest_path() || $this->is_login_path()) {
            return true;
        }

        if (is_preview() || is_customize_preview()) {
            return true;
        }

        if (is_robots() || (function_exists('is_favicon') && is_favicon())) {
            return true;
        }

        // Let WP serve feeds natively; no reason to proxy RSS through the frontend.
        if (is_feed()) {
            return true;
        }

        return false;
    }

    private function is_rest_path(): bool {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        $prefix = '/' . rest_get_url_prefix() . '/';
        return strpos($path, $prefix) === 0;
    }

    private function is_login_path(): bool {
        if (!isset($GLOBALS['pagenow'])) {
            return false;
        }
        return in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-signup.php'], true);
    }
}
