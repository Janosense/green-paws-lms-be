=== VL LMS ===
Contributors: veterinarylms
Tags: lms, headless, rest-api, education
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Veterinary LMS backend — headless WordPress plugin powering the Nuxt.js frontend.

== Description ==

VL LMS is the domain plugin for the Veterinary LMS project. It builds on top of the companion `vl-jwt-auth` plugin and exposes the `vl/v1` REST namespace consumed by the Nuxt.js frontend.

This release (0.1.0) is the Phase 0 infrastructure skeleton: service container, REST namespace registration, a public `/healthz` endpoint, and the full test / lint / static-analysis toolchain. Domain features (courses, lessons, enrollment, progress tracking) ship in later phases.

== Installation ==

1. Install and activate `vl-jwt-auth` first — VL LMS is inactive without it.
2. Upload the `vl-lms` directory to `wp-content/plugins/`.
3. Run `composer install` inside the plugin directory.
4. Activate VL LMS via the Plugins screen or `wp plugin activate vl-lms`.
5. Confirm `GET /wp-json/vl/v1/healthz` returns `{"status":"ok",...}`.

== Changelog ==

= 0.1.0 =
* Initial Phase 0 scaffolding: plugin bootstrap, service container, REST `/healthz` endpoint, test and tooling harness.
