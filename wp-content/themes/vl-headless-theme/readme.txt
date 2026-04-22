=== VL Headless ===
Contributors: tymofiisynianskyi
Tags: headless, minimal
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Minimal headless WordPress theme for the Veterinary LMS. Public traffic is redirected to a separate Nuxt.js frontend.

== Description ==

This theme is a zero-frontend companion to the Nuxt.js public site. It only handles:

* 302 redirects from public URLs to the frontend configured via `VL_FRONTEND_URL`.
* Removal of WordPress head noise (generator, RSD, WLW, emoji scripts).
* Unauthenticated user-enumeration blocking on `/wp-json/wp/v2/users`.
* Disabling XML-RPC and the default WP sitemap.
* Admin UX cleanup for non-technical editors.

The theme has no dependencies on any plugin and activates cleanly on a stock WordPress install.

== Installation ==

1. Upload the theme folder to `/wp-content/themes/vl-headless-theme`.
2. Activate via Appearance → Themes.
3. Add `define('VL_FRONTEND_URL', 'https://your-frontend.example');` to `wp-config.php`.
4. Set Settings → Permalinks to "Post name" (required for REST URL generation).

== Changelog ==

= 1.0.0 =
* Initial release.
