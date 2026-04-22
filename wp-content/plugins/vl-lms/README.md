# VL LMS

Headless Veterinary LMS plugin for WordPress. Backs the Nuxt.js frontend via the `vl/v1` REST namespace.

This Phase 0 release is infrastructure only — container, REST bootstrap, `/healthz` probe, and the test + lint + static-analysis harness. Domain features arrive in later phases.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- [`vl-jwt-auth`](../vl-jwt-auth) must be active — VL LMS delegates all authentication to its `\VLJwtAuth\Auth` facade. When the facade is missing, VL LMS short-circuits during `plugins_loaded` and surfaces an admin notice.
- CORS for the `vl` namespace is handled by the `vl-cors` mu-plugin (already in `wp-content/mu-plugins/vl-cors.php`).

## Setup

```bash
cd wp-content/plugins/vl-lms
composer install
wp plugin activate vl-lms
curl http://localhost:8080/wp-json/vl/v1/healthz
```

Add whitelisted frontend origins to `wp-config.php`:

```php
define( 'VL_CORS_ORIGINS', 'http://localhost:3000,https://app.vetlms.com' );
```

## Layout

```
vl-lms/
├── vl-lms.php                # Bootstrap: headers, constants, vendor guard, plugins_loaded@20 hook
├── uninstall.php             # WP_UNINSTALL_PLUGIN-guarded cleanup (no-op for Phase 0)
├── composer.json             # PSR-4 + dev deps (phpunit, brain/monkey, phpstan, phpcs)
├── phpunit.xml.dist          # Unit suite active; Integration suite placeholder
├── phpcs.xml.dist            # WordPress-Extra ruleset, PHP 8.1
├── phpstan.neon.dist         # Level 6 + szepeviktor/phpstan-wordpress
├── src/
│   ├── Plugin.php            # Singleton bootstrap, dependency check, container build
│   ├── Container.php         # In-house lazy service locator
│   ├── Activator.php         # Stub
│   ├── Deactivator.php       # Stub
│   ├── Api/RestController.php
│   ├── Support/Logger.php
│   ├── Support/Assets.php
│   ├── CPT/                  # Phase 1+
│   ├── Domain/               # Phase 1+
│   └── Services/             # Phase 1+
└── tests/
    ├── bootstrap.php         # Brain Monkey + constant stubs
    └── Unit/PluginTest.php   # Boot idempotency + dependency short-circuit + action fire
```

## Architectural choices

- **Service container.** In-house ~60-line lazy service locator (`VL\LMS\Container`). No `league/container` dependency; can be swapped later without rewriting callers.
- **Logger.** Thin PSR-3-shaped wrapper around `error_log()` in `VL\LMS\Support\Logger`. Replace with Monolog when structured logging is needed.
- **Dependency on `vl-jwt-auth`.** Pure runtime `class_exists('\VLJwtAuth\Auth')` check during `plugins_loaded` priority 20. No Composer path repository — plugins stay independently installable. The check is extracted into `Plugin::dependenciesMet()` for testability.
- **Booted extension point.** `do_action( 'vl_lms/booted', $container )` fires after a successful boot so future submodules register themselves against the container without touching `Plugin.php`.

## Commands

```bash
composer run test    # PHPUnit unit suite
composer run lint    # phpcs against WordPress-Extra
composer run stan    # phpstan level 6
composer run makepot # regenerate translation template
```

Coverage is not gated in Phase 0. `phpunit --coverage-html build/coverage/` still works when Xdebug or PCOV is available.

## REST surface

| Method | Route                | Auth | Description |
|--------|----------------------|------|-------------|
| GET    | `/wp-json/vl/v1/healthz` | none | Returns `{status, version, timestamp}`. Probe for uptime + plugin version. |

Future auth-protected routes follow the pattern commented in `src/Api/RestController.php`:

```php
'permission_callback' => static fn (): bool =>
    \VLJwtAuth\Auth::require_role( 'veterinarian' ),
```
