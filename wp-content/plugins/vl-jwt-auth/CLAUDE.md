# CLAUDE.md — vl-jwt-auth

Non-obvious rules for working in this plugin. Read alongside `README.md`, which covers the user-facing surface.

## Architecture invariants

- `TokenService` must not touch the database. `RefreshTokenRepository` must not know about JWT encoding or signing. Keep this separation even when a shortcut seems cheap — the split is what makes each class unit-testable in isolation.
- Refresh tokens are JWTs **and** SHA-256-hashed in the DB. This is double defense (signature check + revocation lookup). Don't "simplify" either layer away.
- IP addresses go through `INET6_ATON` / `INET6_NTOA` in SQL. Never push raw `inet_pton()` output through `$wpdb->prepare` — driver handling of NULs in binary values is unreliable.
- Scheduled `RefreshTokenRepository::cleanup_expired()` runs daily via the `vl_jwt_auth_cleanup_expired_tokens` WP-Cron event. The Activator schedules it; the Deactivator unschedules it. Do not rely on the table growing unboundedly for testing — long-expired rows are pruned in the background.

## Error envelope split

- **This plugin's own endpoints** (under `vl-auth/v1`) return `{success: bool, data|error: ...}` via handlers, not `permission_callback`. Preserving that envelope is why `RestController::require_user` does auth inline instead of delegating to `Middleware`.
- **Other plugins' endpoints** that use `\VLJwtAuth\Auth::require_*()` get WordPress's default `WP_Error` rendering. Don't try to unify these shapes.

## Login error passthrough contract

`RestController::login()` does not blindly forward every `WP_Error` code from `wp_authenticate()` — that would let arbitrary plugins inject internal-state strings (or unprintable codes) into the public response envelope. Instead, `translate_login_error()` enforces a whitelist:

- **Core WP authenticator codes** (`invalid_username`, `invalid_email`, `incorrect_password`, `empty_username`, `empty_password`) pass through with their messages.
- **Codes prefixed `vl_`** (e.g. `vl_lms_email_not_verified`) pass through. This covers the current `vl-lms` `UnverifiedLoginBlocker` and any future first-party plugin hooking `wp_authenticate_user`. The `vl_` prefix is deliberately reserved for plugins under our control — third-party plugins should not piggy-back on it.
- **Anything else** collapses to `invalid_credentials` with the generic "Invalid username or password." message.

HTTP status is always **401** regardless of which code surfaces — never leak validation-style distinctions through the status code. Messages run through `wp_strip_all_tags()` because core's `incorrect_password` embeds an anchor tag to the lost-password screen and our envelope is plain text.

When adding a new plugin under our control that needs a distinct login-failure code, it must use the `vl_` prefix; no other change here is required.

## Domain boundary

This plugin stays product-agnostic. No courses, enrollment, veterinary knowledge, or anything else LMS-specific lands here. LMS extensions hook in through `vl_jwt_auth_token_claims` (claims) and `vl_jwt_auth_user_authenticated` (side effects) — no other expansion path.

## Endpoint inventory (cookie-bearing endpoints)

The full REST surface lives in `README.md`. The list below is the subset of state-changing endpoints under `vl-auth/v1` that the **`OriginGuard`** allowlist covers — adding a new such endpoint means adding `OriginGuard` enforcement at the top of the handler:

- `POST /token/refresh` — rotates the refresh cookie.
- `POST /logout` — clears the refresh cookie.
- `DELETE /sessions` — **bulk** revoke every other active session for the current user. Requires bearer **and** a valid refresh cookie (the cookie identifies which session to keep — without it, an attacker holding only a stolen access token could log the legitimate user out everywhere).

Note: `DELETE /sessions/{id}` (single revoke) is bearer-only today and does not consume the refresh cookie, so `OriginGuard` is not applied there. If a future change makes that endpoint cookie-aware, the guard must be added.

## Tests

Tests are deliberately omitted for the MVP. Do not add PHPUnit, Brain Monkey, or any test scaffolding unless explicitly asked. The `tests/` tree in the original spec was dropped intentionally.

## Secrets

`VL_JWT_AUTH_SECRET_KEY` is read only from a PHP constant defined in `wp-config.php` (or, in DDEV, from `.ddev/config.local.yaml` → env → constant). Never read from the database, never committed in any `wp-config*.php` that lives in git. The plugin refuses to register hooks if the constant is missing.

## Composer

When bumping `firebase/php-jwt`, check Packagist advisories — the v6 line has an open LOW-severity CVE (key-size validation missing), which is why we pin `^7.0`.
