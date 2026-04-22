<?php
/**
 * Theme bootstrap.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Theme {

    public static function boot(): void {
        add_action('after_setup_theme', [__CLASS__, 'setup']);

        (new VL_Headless_Frontend_Redirect())->register();
        (new VL_Headless_Cleanup())->register();
        (new VL_Headless_Rest_Hardening())->register();
        (new VL_Headless_Admin_UX())->register();
    }

    public static function setup(): void {
        load_theme_textdomain('vl-headless-theme', get_template_directory() . '/languages');
    }
}
