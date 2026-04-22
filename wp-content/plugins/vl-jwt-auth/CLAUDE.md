# CLAUDE.md — vl-jwt-auth

Non-obvious rules for working in this plugin. Read alongside `README.md`, which covers the user-facing surface.

## Architecture invariants

- `TokenService` must not touch the database. `RefreshTokenRepository` must not know about JWT encoding or signing. Keep this separation even when a shortcut seems cheap — the split is what makes each class unit-testable in isolation.
- Refresh tokens are JWTs **and** SHA-256-hashed in the DB. This is double defense (signature check + revocation lookup). Don't "simplify" either layer away.
- IP addresses go through `INET6_ATON` / `INET6_NTOA` in SQL. Never push raw `inet_pton()` output through `$wpdb->prepare` — driver handling of NULs in binary values is unreliable.

## Error envelope split

- **This plugin's own endpoints** (under `vl-auth/v1`) return `{success: bool, data|error: ...}` via handlers, not `permission_callback`. Preserving that envelope is why `RestController::require_user` does auth inline instead of delegating to `Middleware`.
- **Other plugins' endpoints** that use `\VLJwtAuth\Auth::require_*()` get WordPress's default `WP_Error` rendering. Don't try to unify these shapes.

## Domain boundary

This plugin stays product-agnostic. No courses, enrollment, veterinary knowledge, or anything else LMS-specific lands here. LMS extensions hook in through `vl_jwt_auth_token_claims` (claims) and `vl_jwt_auth_user_authenticated` (side effects) — no other expansion path.

## Tests

Tests are deliberately omitted for the MVP. Do not add PHPUnit, Brain Monkey, or any test scaffolding unless explicitly asked. The `tests/` tree in the original spec was dropped intentionally.

## Secrets

`VL_JWT_AUTH_SECRET_KEY` is read only from a PHP constant defined in `wp-config.php` (or, in DDEV, from `.ddev/config.local.yaml` → env → constant). Never read from the database, never committed in any `wp-config*.php` that lives in git. The plugin refuses to register hooks if the constant is missing.

## Composer

When bumping `firebase/php-jwt`, check Packagist advisories — the v6 line has an open LOW-severity CVE (key-size validation missing), which is why we pin `^7.0`.
