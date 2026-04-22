<?php
/**
 * Remove head noise and disable unused WP features.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Cleanup {

    public function register(): void {
        add_action('init', [$this, 'remove_head_clutter']);
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_filter('wp_headers', [$this, 'remove_pingback_header']);
        add_filter('xmlrpc_methods', [$this, 'remove_pingback_methods']);
    }

    public function remove_head_clutter(): void {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
    }

    public function remove_pingback_header($headers) {
        if (is_array($headers)) {
            unset($headers['X-Pingback']);
        }
        return $headers;
    }

    public function remove_pingback_methods($methods) {
        if (is_array($methods)) {
            unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
        }
        return $methods;
    }
}
