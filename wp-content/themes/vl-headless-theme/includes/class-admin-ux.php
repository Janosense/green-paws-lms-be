<?php
/**
 * Admin UX polish for non-technical content editors.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Admin_UX {

    public function register(): void {
        add_action('wp_dashboard_setup', [$this, 'cleanup_dashboard']);
        add_action('admin_menu', [$this, 'cleanup_menu'], 999);
        add_action('admin_bar_menu', [$this, 'cleanup_toolbar'], 999);
        add_filter('admin_footer_text', [$this, 'admin_footer']);
        add_filter('update_footer', '__return_empty_string', 11);
        add_action('admin_notices', [$this, 'missing_frontend_url_notice']);
    }

    public function cleanup_dashboard(): void {
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
    }

    public function cleanup_menu(): void {
        remove_menu_page('edit.php');          // Posts — using CPTs from vl-lms.
        remove_menu_page('edit-comments.php'); // Comments — not used in LMS.
    }

    public function cleanup_toolbar($wp_admin_bar): void {
        if (is_object($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_node')) {
            $wp_admin_bar->remove_node('wp-logo');
        }
    }

    public function admin_footer(): string {
        $frontend = (defined('VL_FRONTEND_URL') && VL_FRONTEND_URL !== '') ? (string) VL_FRONTEND_URL : '#';

        return sprintf(
            '%s · <a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_html__('Veterinary LMS admin panel', 'vl-headless-theme'),
            esc_url($frontend),
            esc_html__('Open frontend', 'vl-headless-theme')
        );
    }

    public function missing_frontend_url_notice(): void {
        if (defined('VL_FRONTEND_URL') && VL_FRONTEND_URL !== '') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html__('VL Headless:', 'vl-headless-theme'),
            esc_html__('VL_FRONTEND_URL is not defined in wp-config.php. Public frontend redirects are disabled until it is set.', 'vl-headless-theme')
        );
    }
}
