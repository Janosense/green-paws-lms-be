<?php
/**
 * Favicon for the admin and login screens.
 *
 * The public site is rendered by Nuxt (which ships its own favicon set),
 * so the backend only needs icons where WP renders HTML: wp-admin and
 * wp-login.php. Direct /favicon.ico hits are answered via do_faviconico
 * instead of WP's default blue-W fallback.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Favicon {

    public function register(): void {
        add_action('admin_head', [$this, 'print_tags']);
        add_action('login_head', [$this, 'print_tags']);
        add_action('do_faviconico', [$this, 'serve_ico']);
    }

    public function print_tags(): void {
        $base = get_template_directory_uri() . '/assets/favicon';

        printf('<link rel="icon" type="image/png" href="%s" sizes="96x96" />' . "\n", esc_url($base . '/favicon-96x96.png'));
        printf('<link rel="icon" type="image/svg+xml" href="%s" />' . "\n", esc_url($base . '/favicon.svg'));
        printf('<link rel="shortcut icon" href="%s" />' . "\n", esc_url($base . '/favicon.ico'));
        printf('<link rel="apple-touch-icon" sizes="180x180" href="%s" />' . "\n", esc_url($base . '/apple-touch-icon.png'));
    }

    public function serve_ico(): void {
        wp_redirect(get_template_directory_uri() . '/assets/favicon/favicon.ico', 302);
        exit;
    }
}
