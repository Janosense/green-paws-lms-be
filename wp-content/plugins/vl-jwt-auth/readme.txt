=== VL JWT Auth ===
Contributors: veterinary-lms
Tags: jwt, authentication, rest-api, headless, spa
Requires at least: 6.4
Tested up to: 6.9.4
Requires PHP: 8.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

JWT authentication for headless WordPress. Issues access + refresh tokens, exposes a public PHP API for other plugins.

== Description ==

VL JWT Auth turns a WordPress install into an authentication backend for a JavaScript SPA or a native client. It issues short-lived access tokens (returned in the response body) alongside long-lived refresh tokens (stored in an HttpOnly cookie scoped to the plugin's REST namespace), rotates refresh tokens on use, detects replay, and revokes every refresh token a user holds when their password changes.

The plugin is standalone and product-agnostic. Other WordPress plugins can protect their own REST routes with a single `permission_callback` call and add custom JWT claims through a documented filter.

See README.md in the plugin directory for full documentation, endpoint reference, and integration examples.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/vl-jwt-auth/`.
2. Run `composer install` inside the plugin directory.
3. Define `VL_JWT_AUTH_SECRET_KEY` in `wp-config.php` with at least 32 bytes of entropy.
4. Activate the plugin. The `{prefix}vl_refresh_tokens` table is created automatically.

== Frequently Asked Questions ==

= Does it handle CORS? =

No. CORS belongs in the theme, a dedicated plugin, or a reverse proxy — keeping CORS separate from auth is deliberate.

= Does it replace wp_set_auth_cookie? =

Only for REST traffic. `wp-admin` continues to use WordPress's native cookie authentication.

= Does it work on multisite? =

Single-site only in this release. Network activation is out of scope for the MVP.

== Changelog ==

= 0.1.0 =
* Initial release: token issuance, rotation with replay detection, public PHP API, session management, password-change revocation, rate limiting, origin allowlist.
