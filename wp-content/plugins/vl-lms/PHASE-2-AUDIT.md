# Phase 2 — Audit of `vl-jwt-auth` and plan for `vl-lms` auth subsystem

Read-only inventory of what the sibling `vl-jwt-auth` plugin already provides, mapped against what `vl-lms` needs to add in Phase 2 (registration + email verification + gated login). This document is the single source of truth for Phase 2 decisions; it precedes and guides the `src/Auth/` implementation.

Scope of the audit: `backend/wp-content/plugins/vl-jwt-auth/` at commit `04dc3cb`. No source was modified while compiling it.

---

## 1. REST surface under `vl-auth/v1`

All routes are registered from `VLJwtAuth\Api\RestController::register_routes()`. Every route uses `permission_callback: __return_true` and performs authentication / authorization inline so the plugin can preserve its own envelope shape. Envelope shape for this plugin is `{ success: bool, data|error: {...} }` — deliberately different from the WP-default `{ code, message, data: { status } }` used for other plugins' endpoints (see `vl-jwt-auth/CLAUDE.md` → "Error envelope split").

| Method | Path | Auth | Body | Success payload | Notable errors |
|---|---|---|---|---|---|
| POST | `/token` | none (rate-limited) | `{ username, password }` | `{ access_token, token_type, expires_in, user }` + `Set-Cookie: vl_refresh_token` | `invalid_credentials` (401), `rate_limit_exceeded` (429) |
| POST | `/token/refresh` | refresh cookie + `Origin` allowlist | — | same as `/token` (rotates the cookie) | `refresh_token_invalid` (401), `refresh_token_reused` (401), `invalid_origin` (403), `rate_limit_exceeded` (429) |
| POST | `/token/validate` | bearer | — | `{ valid, user_id, expires_at }` | `token_invalid` / `token_expired` (401) |
| POST | `/logout` | refresh cookie (idempotent) + `Origin` allowlist | — | `{ logged_out: true }` | `invalid_origin` (403) |
| GET  | `/me` | bearer | — | `{ id, login, email, display_name, roles, capabilities }` | `token_invalid` (401), `user_not_found` (401) |
| GET  | `/sessions` | bearer | — | `{ sessions: [{id, device_name, ip_address, user_agent, created_at, last_used_at, expires_at, current}] }` | `token_invalid` (401) |
| DELETE | `/sessions/{id}` | bearer | — | `{ revoked: true }` | `not_found` (404) when the session does not belong to the caller |

**Error shape from `vl-auth/v1`:**

```json
{ "success": false, "error": { "code": "invalid_credentials", "message": "...", "status": 401 } }
```

Error code catalogue: `invalid_credentials`, `token_expired`, `token_invalid`, `refresh_token_invalid`, `refresh_token_reused`, `rate_limit_exceeded`, `invalid_origin`, `user_not_found`, `not_found`.

### Session management — what exists, what is missing
- `GET /sessions` and `DELETE /sessions/{id}` are present today, scoped per-user.
- "Revoke all other sessions" is **missing** (no bulk endpoint). Note for future subtask 2.B if the frontend needs a "log out everywhere" action.

---

## 2. Public PHP facade — `\VLJwtAuth\Auth`

`\VLJwtAuth\Auth` is a `class_alias` to `VLJwtAuth\Auth\AuthFacade` installed at bootstrap. Call sites must use the alias; the internal FQCN is not a stable contract.

| Method | Signature | Behaviour |
|---|---|---|
| `current_user()` | `(): ?WP_User` | Reads bearer from `$_SERVER` (HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION / `apache_request_headers()`). Returns `null` on any failure — never throws. |
| `user_from_request(WP_REST_Request)` | `(WP_REST_Request): ?WP_User` | Reads bearer from the request `Authorization` header. Returns `null` on failure. |
| `require_authenticated()` | `(): callable` | Returns a `permission_callback` closure — true on valid bearer, `WP_Error('token_invalid', …, 401)` otherwise. |
| `require_role(string ...$roles)` | `(...string): callable` | Returns a closure that passes when the user has any of the listed roles, `WP_Error('insufficient_role', …, 403)` otherwise. |
| `require_capability(string $capability)` | `(string): callable` | Returns a closure that passes when `$user->has_cap($capability)` is truthy. |
| `decode_access_token(string $jwt)` | `(string): array` | Decodes and validates an access JWT. The **only** method that surfaces failures as `TokenException` (`token_expired` / `token_invalid`, `status_code()` ∈ {401}). |

No mutation methods are exposed through the facade; issuing or rotating tokens stays inside the plugin's REST endpoints.

---

## 3. Refresh-token cookie mechanics

Set and cleared exclusively by `VLJwtAuth\Support\CookieManager`:

| Attribute | Value | Source |
|---|---|---|
| Name | `vl_refresh_token` | `CookieManager::COOKIE_NAME` |
| Path | `/wp-json/vl-auth/v1/` (subdir-prefixed if WP runs under a subdir) | `wp_parse_url(rest_url('vl-auth/v1/'), PHP_URL_PATH)` |
| Domain | value of `vl_jwt_auth_settings.cookie_domain` — empty = host-only | `Settings::cookie_domain()` |
| SameSite | default `None`, whitelist `{None, Lax, Strict}` | `Settings::cookie_samesite()` |
| Secure | `is_ssl() OR samesite === 'None'` (browsers reject `SameSite=None` without `Secure`) | `CookieManager::options()` |
| HttpOnly | always `true` | `CookieManager::options()` |
| TTL | `Settings::refresh_ttl()` — default 14 days (1 209 600 s), minimum 60 s, overridable via the `vl_jwt_auth_refresh_ttl` filter | `Settings::refresh_ttl()` |

**Rotation policy** (from `RestController::refresh()`):
1. Read cookie → look up row by SHA-256 hash.
2. Missing row → `refresh_token_invalid`; cookie cleared.
3. Row already `revoked_at !== NULL` → reuse signal: revoke the entire `token_family`, clear cookie, `refresh_token_reused`.
4. Valid row → decode + validate JWT, revoke the presented row, issue a new access + refresh pair **under the same `token_family`**, set the new cookie.

**Reuse-detection invariants** to preserve when `vl-lms` hands a user to `vl-jwt-auth` post-verification:
- Family membership is preserved across rotations within the same device/session.
- Presenting an already-revoked token is fatal for the whole family — callers never get a "soft" retry.
- DB stores **only** SHA-256 hashes (`VLJwtAuth\Support\Hasher::hash()`). Raw tokens never touch the database. `vl-lms` should mirror this invariant for its own verification tokens.

Password changes (`profile_update`, `password_reset`) revoke every active refresh token for the user via `RefreshTokenRepository::revoke_user()`.

---

## 4. Filters / actions already exposed

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `vl_jwt_auth_token_claims` | filter | `(array $claims, int $user_id, string $type): array` | Inject extra JWT claims before signing. **Phase 2 does NOT need to use this** — the verified flag belongs in user meta, not the JWT. |
| `vl_jwt_auth_access_ttl` | filter | `(int): int` | Override access-token lifetime (floored at 60 s). |
| `vl_jwt_auth_refresh_ttl` | filter | `(int): int` | Override refresh-token lifetime (floored at 60 s). |
| `vl_jwt_auth_rate_limit_refresh` | filter | `(int $limit): int` | Tune `/token/refresh` rate limit. |
| `vl_jwt_auth_rate_limit_refresh_window` | filter | `(int $seconds): int` | Tune `/token/refresh` window. |
| `vl_jwt_auth_client_ip` | filter | `(string $ip): string` | Override client IP for reverse-proxy setups. |
| `vl_jwt_auth_user_authenticated` | action | `(WP_User $user, WP_REST_Request $request)` | Fires after successful `/token` or `/token/refresh`. |

### Pre-login gating — **gap flagged**

`vl-jwt-auth` does NOT expose a `vl_jwt_auth_pre_login` or similar filter that would let `vl-lms` reject an authentication attempt **by user record** (i.e. after username/password have matched, but before token issuance). The only present hook fires **after** a successful authentication.

Options evaluated:

1. **Hook WordPress core `wp_authenticate_user`** (the filter fires inside `wp_authenticate()`, which `RestController::login()` calls unchanged). Returning `WP_Error` aborts the login — `wp_authenticate()` forwards the error straight back to the REST handler, which returns it as `invalid_credentials` (the handler does not inspect the inner code). — **Chosen for Phase 2**.
2. Add a new filter to `vl-jwt-auth` (e.g. `vl_jwt_auth_pre_issue_tokens`). — **Deferred** to avoid a coupled change across plugin boundaries inside a single subtask; captured as a recommendation below.

Consequence for Phase 2A:
- `UnverifiedLoginBlocker` hooks `wp_authenticate_user` at priority 30 (after WP's own validators at 10–20) and returns a `WP_Error('vl_lms_email_not_verified', …, 401)` when `get_user_meta($user_id, '_vl_email_verified', true) !== '1'`.
- `RestController::login()` in `vl-jwt-auth` currently collapses any `WP_Error` from `wp_authenticate()` into `invalid_credentials` (`401`). Returning a specific code from the blocker is therefore **not enough** to give the frontend the real reason by itself. The error **code** surfaced to the frontend via `vl-auth/v1/token` will still be `invalid_credentials`; the **message** is preserved.
- To expose the distinct `vl_lms_email_not_verified` code, the frontend has two practical paths in Phase 2.B:
  - Match on the message string returned from `/token` (brittle, English-coupled).
  - After a 401, call a new `vl/v1/auth/verification-status` endpoint (out of scope for 2.A) to disambiguate.
- The right fix — and **recommendation for a future vl-jwt-auth subtask** — is to add a `vl_jwt_auth_login_error_code` filter (or simply let `WP_Error::get_error_code()` pass through to the REST envelope) so downstream plugins can communicate a distinct error code. Until that lands, `vl-lms` accepts that `/vl-auth/v1/token` returns `invalid_credentials` for an unverified user.

Additional consequence: **after** the frontend calls `POST /vl/v1/auth/verify-email` (which issues tokens via the facade, NOT by delegating to `/vl-auth/v1/token`), the next `/vl-auth/v1/token` login for the same account will succeed — the `UnverifiedLoginBlocker` will now see `_vl_email_verified === '1'`.

---

## 5. Reusable infrastructure — `RateLimiter`, `OriginGuard`

### `VLJwtAuth\Support\RateLimiter`
Fixed-window transient bucket. **Reusable from `vl-lms`**:
- Concrete `final` class. Not registered in any container; `vl-lms` can instantiate it directly.
- Key space is shared (`vl_jwt_auth_rl_<md5(key)>`); `vl-lms` uses distinct key prefixes (`vl_lms_verify_resend:<email>`, `vl_lms_verify_resend:<ip>`) to avoid collisions.
- No PHP type conflict: the class lives under `VLJwtAuth\Support\*` and is loaded by its own plugin's autoloader; `vl-lms` can `use` the FQCN with no autoload changes because WordPress loads both `vendor/autoload.php` files during `plugins_loaded` before either plugin's code runs.
- **Trade-off:** `vl-lms` would then have a compile-time dependency on a class outside its own `vendor/`. The plugin already depends on `\VLJwtAuth\Auth` at runtime, so this is a soft extension of existing coupling rather than a new one — acceptable.

Decision: **reuse `VLJwtAuth\Support\RateLimiter` directly** from `vl-lms` rather than duplicate a transient-bucket implementation. If the `VLJwtAuth\Support\*` namespace is ever renamed, only the single `use` line in `EmailVerificationService` needs updating.

### `VLJwtAuth\Support\OriginGuard`
Not reused by `vl-lms`. None of the three Phase 2A endpoints ship the refresh cookie — `register` / `resend-verification` are both fully public and stateless; `verify-email` accepts a POST body with a one-time token and delegates cookie-setting to `\VLJwtAuth\Auth` (which internally calls the existing `CookieManager`, so the origin guard on `/vl-auth/v1/*` continues to cover cookie issuance). CSRF is not a concern for bearer-less, cookieless public endpoints.

---

## 6. Bugs / oversights noticed while auditing (NOT fixed here)

These are captured for follow-up. Do NOT address them in this subtask.

1. **`vl-jwt-auth/src/Api/RestController.php` login path swallows `WP_Error` codes.** `wp_authenticate()` can return a variety of codes (`invalid_username`, `incorrect_password`, `empty_username`, plus anything added by `wp_authenticate_user` filters). The REST handler maps every one of them to `invalid_credentials`. That hides information from consumers and is the root cause of Phase 2's inability to surface `vl_lms_email_not_verified` cleanly. Recommend: pass through the code (still 401) or introduce a `vl_jwt_auth_login_error_code` filter.
2. **README/plugin header PHP requirement mismatch.** `vl-jwt-auth/README.md` line 8 still says "PHP 8.1+" while the plugin header (`vl-jwt-auth.php`) and CODING-STANDARDS.md pin 8.4. Documentation drift.
3. **`list_sessions` exposes refresh-token hashes indirectly.** The `current` flag is derived by SHA-256-hashing the current cookie and comparing. That is safe, but the row's `token_hash` is also returned from the repository and then thrown away in the controller — trivial but worth verifying no caller accidentally forwards it.
4. **No bulk "revoke all other sessions" endpoint.** Common UX for security settings; not a bug, but a missing feature the frontend is likely to ask for.
5. **`cleanup_expired()` is not scheduled.** `RefreshTokenRepository::cleanup_expired()` exists but nothing calls it — the plugin ships without a cron registration. Missing scheduled job.

---

## 7. Frontend integration notes (for Phase 2.B Nuxt work)

A single pass of everything the Nuxt developer needs:

### Endpoint-to-origin contract
- Base URL env var: `NUXT_PUBLIC_WP_API_BASE` (e.g., `https://green-paws-lms-backend.ddev.site/wp-json`).
- Auth endpoints owned by `vl-jwt-auth`: `${base}/vl-auth/v1/{token,refresh,validate,logout,me,sessions,…}`.
- Registration / verification endpoints owned by `vl-lms` (this subtask): `${base}/vl/v1/auth/{register,verify-email,resend-verification}`.

### CORS
Handled by `vl-cors` (mu-plugin). Allowed namespaces: `vl`, `vl-auth`. Relevant headers emitted on any request to those namespaces:
- `Access-Control-Allow-Origin: <echoed origin>` — list populated from `VL_CORS_ORIGINS` constant in `wp-config.php`.
- `Access-Control-Allow-Credentials: true` (required for the refresh cookie to be attached / received).
- `Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With`.
- `Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS`.

### Fetch settings
- `$fetch` / `useFetch` must be configured with `credentials: 'include'` for **any** call to `${base}/vl-auth/v1/*` so the refresh cookie is sent and received.
- For `${base}/vl/v1/*` calls, `credentials: 'include'` is only required for `POST /vl/v1/auth/verify-email` (because the response `Set-Cookie`s the refresh cookie issued by the facade). For the other `/vl/v1/auth/*` endpoints it is harmless and recommended to keep defaults uniform.
- `Origin` header is attached automatically by the browser on cross-origin POST/DELETE; `vl-jwt-auth` enforces an allowlist against it for `/vl-auth/v1/token/refresh` and `/vl-auth/v1/logout` via `OriginGuard`. Ensure the frontend's effective origin is in `vl_jwt_auth_settings.allowed_origins`.

### Headers the frontend sends
- For authenticated calls: `Authorization: Bearer <access_token>` on every request except the three listed in "Public routes" below.
- `Content-Type: application/json` on every request that has a body.
- No CSRF token is needed — origin allowlist + HttpOnly path-scoped cookie are the CSRF defense.

### Cookie behaviour
- The refresh cookie is scoped to `/wp-json/vl-auth/v1/`, so it is **never** attached to any `vl/v1/*` request. `vl-lms` does not read or write it directly — only the facade does.
- After a successful `POST /vl/v1/auth/verify-email` the response sets the cookie via `vl-jwt-auth`'s `CookieManager`. The frontend cannot read it (HttpOnly) — it just needs to store the returned `access_token` and perform subsequent calls with the bearer.

### Public routes (no bearer required)
- `POST /vl-auth/v1/token` (login)
- `POST /vl-auth/v1/token/refresh` (the refresh cookie authenticates)
- `POST /vl/v1/auth/register`
- `POST /vl/v1/auth/verify-email`
- `POST /vl/v1/auth/resend-verification`

### Error code → UI hint (pre-Phase-2.B reminder)

| Code from `/vl-auth/v1/token` | Meaning | UI action |
|---|---|---|
| `invalid_credentials` | Either wrong password, wrong username, OR unverified email (see §4 — this is ambiguous today). | Show "Invalid credentials or email not verified", include a "Resend verification email" link. |
| `rate_limit_exceeded` | Too many attempts from this IP. | Show "Too many attempts, try again later." |

---

## 8. Phase 2A decisions

These were explicitly delegated in the subtask brief. The rationale for each lives with the code; the summary here is canonical.

| Decision | Value | Rationale |
|---|---|---|
| Verification token format | 64-char URL-safe string from `wp_generate_password(64, false, false)` | Already shipped WP primitive — audited, high-entropy (`random_int` under the hood), no vendor risk. 64 base-62-ish chars is ~380 bits — overkill vs. the 128-bit floor, fine for short-lived tokens. Signed HMAC-JWT would need a secret rotation plan and buys nothing for a single-use 24h token. Opaque UUIDv4 is only 122 bits of entropy and semantically "identifier" — wrong shape. |
| Verification token hashing | `hash('sha256', $plain)` stored in `_vl_verification_token_hash` | Mirrors the decision already made in `vl-jwt-auth` (`Support\Hasher`) for refresh tokens. `wp_hash_password` (bcrypt/phpass) is designed for slow offline attack resistance against reusable credentials and is overkill for single-use 24h tokens; sha256 is fast and adequate when the token itself is 380 bits of entropy — preimage resistance doesn't matter when the plain value is unguessable. |
| Token TTL | 24 hours | Industry convention. Long enough to survive "open verification email on phone, switch to laptop" flows; short enough that a leaked token is a bounded risk. Overridable via the `vl_lms_verification_token_ttl` filter. |
| Single-use semantics | Enforced by clearing the hash and expiry fields on successful verify. Generating a new token also overwrites the previous hash — so "resend" invalidates any outstanding token for that account. | Simpler than a token table; matches the spec's "single-active + single-use" requirement. |
| Password policy | Minimum 8 characters. No explicit complexity rule (no uppercase/symbol requirement). NIST 800-63B explicitly recommends against mandatory complexity in favour of length + breach-list checks. Breach-list check is out of scope for 2A — tracked for future hardening. | 8 chars is the floor WP itself uses. Configurable via `vl_lms_min_password_length` filter. |
| Login gating hook | WP core `wp_authenticate_user` filter at priority 30 | No native `vl-jwt-auth` filter exists (see §4). WP core filter is documented, covers both `/vl-auth/v1/token` (via `wp_authenticate()`) and future `wp-login.php` fallbacks. |
| Email template translation seam | `wp_mail` subject + body strings run through `apply_filters('vl_lms_verification_email_subject', …)` and `apply_filters('vl_lms_verification_email_body', …, $user_id, $verification_url)`. English text is the default for Phase 2A. | Ukrainian copy lands in Phase 2.B alongside the frontend flow; the filter seam lets it drop in without re-touching the mailer. |
| Rate limiter | Reuse `VLJwtAuth\Support\RateLimiter` (see §5). | Avoids duplicating a transient-bucket implementation with subtly different semantics. |

### Account kind
- Allow-list is a class constant on `VL\LMS\Auth\AccountKind` (`final class AccountKind { public const string STUDENT = 'student'; public const array ALLOWED = [ self::STUDENT ]; }`).
- Adding `moderator` later is literally one line: append `self::MODERATOR` to the `ALLOWED` array and add the `public const` defining it. No call-site changes required — `RegistrationRequest::__construct` validates against `AccountKind::ALLOWED`.
- Phase 2A only assigns the WP `student` role; if a future request specifies `account_kind = 'moderator'`, the user still gets the `student` WP role by default. The WP-role-vs-account-kind mapping is deliberately *not* baked into the allow-list — it will be a `match` inside `RegistrationService` later.

### User meta keys added

| Key | Type | Default | Purpose |
|---|---|---|---|
| `_vl_email_verified` | `'0'` / `'1'` | `'0'` | Gate for `UnverifiedLoginBlocker`. String values because `update_user_meta` serializes booleans to `'1'` / `''`, and `''` compares-equal to `'0'` in non-strict mode — string `'0'`/`'1'` avoids that trap. |
| `_vl_verification_token_hash` | 64-char sha256 hex | `''` | Lookup key for `EmailVerificationService::verify()`. |
| `_vl_verification_token_expires` | Unix timestamp as string | `''` | Checked by `verify()`. |
| `_vl_account_kind` | string (from `AccountKind::ALLOWED`) | `'student'` | Application-level account classification, independent of WP role. |

Underscore prefix hides the keys from the default `user_meta` REST surface — consistent with WP core conventions for "private" meta.

### Token lookup strategy

`EmailVerificationService::verify()` takes a plain token, hashes it, and looks up the user by the hash. Two strategies evaluated:

1. **`WP_User_Query` / `get_users(['meta_key' => '_vl_verification_token_hash', 'meta_value' => $hash, 'number' => 1])`** — O(N) worst-case on `wp_usermeta` but with the default index on `(user_id, meta_key)` and our sha256 (high-selectivity) values, MySQL collapses to effectively O(1). Zero schema changes.
2. **Custom indexed table.** Faster asymptotically but adds migrations, a repository, and a storage surface to keep in sync.

**Chosen:** #1. Expected volume (organic-signup LMS) is nowhere near the threshold where meta lookups hurt — the `vl_enrollments` table will outgrow `wp_usermeta` long before verification meta does. Revisit if registration traffic ever passes ~100k accounts.

### `app_url` resolution

- Read from the `VL_LMS_APP_URL` PHP constant defined in `wp-config.php` (next to `VL_JWT_AUTH_SECRET_KEY`).
- When missing: fall back to `home_url()` and emit a warning through `VL\LMS\Support\Logger::warning()` (single line per boot is acceptable; the warning is suppressed after the first call via a static flag on the mailer instance).
- Documented in the README / DDEV docs alongside the existing `VL_JWT_AUTH_SECRET_KEY` instructions (follow-up doc commit, not part of this subtask's source changes).

### Email delivery
- Uses `wp_mail()` with `Content-Type: text/html` headers attached via the `wp_mail_content_type` filter, scoped to a single send and removed in a `finally` block so it does not leak to other plugins.
- HTML body is the primary content; `wp_mail` already falls back to a plain-text part when PHPMailer is configured with `AltBody`, but `wp_mail` doesn't expose `AltBody` directly — Phase 2A ships HTML-only. If a plain-text alternative becomes necessary we can hook `phpmailer_init`, but the MVP does not.

---

## 9. Implementation plan (for this subtask's Step 2)

```
vl-lms/src/Auth/
├── AccountKind.php                         # final class + const ALLOWED
├── Api/
│   └── AuthController.php                  # (see below — filed under src/Api too? see path decision)
├── LoginGate/
│   └── UnverifiedLoginBlocker.php
├── Mail/
│   └── VerificationMailer.php
├── Registration/
│   ├── RegistrationException.php
│   ├── RegistrationRequest.php
│   ├── RegistrationResult.php
│   └── RegistrationService.php
└── Verification/
    ├── EmailVerificationService.php
    ├── VerificationException.php
    └── VerificationToken.php
```

**Path decision:** the brief says `Api/AuthController.php` under `vl-lms/src/` (so it sits alongside the existing `Api/RestController.php`). Keeping it under `src/Api/AuthController.php` (NOT `src/Auth/Api/AuthController.php`) aligns with the project convention — `src/Api/` is the REST layer, and having every controller there makes route registration discoverable. The `Auth/` tree is pure domain.

Container wiring (additions to `VL\LMS\Plugin::build_container()`):
- `RegistrationService`
- `EmailVerificationService`
- `VerificationMailer`
- `UnverifiedLoginBlocker`
- `AuthController`

`Plugin::boot()` changes:
- After CPT / taxonomy / access registrars, pull `UnverifiedLoginBlocker` from the container and call `register_hooks()`.
- `register_rest_routes()` already resolves `RestController`; extend it to also resolve `AuthController` and call `register_routes()`.

---

## 10. Manual verification (curl commands)

The following is the smoke-test script used to confirm the subsystem against a DDEV stack (`https://green-paws-lms-backend.ddev.site`). Mailpit is bundled with DDEV at `https://green-paws-lms-backend.ddev.site:8026` — it catches every `wp_mail` call.

```bash
export WP_URL="https://green-paws-lms-backend.ddev.site"

# 1. Register a brand-new user. Expect HTTP 201 with a generic "check your email" body.
curl -i -X POST "$WP_URL/wp-json/vl/v1/auth/register" \
  -H 'Content-Type: application/json' \
  -d '{
        "email":"phase2.smoke+$(date +%s)@example.test",
        "password":"CorrectHorseBatteryStaple",
        "first_name":"Phase",
        "last_name":"Smoke"
      }'
# Expected: HTTP/2 201
#   {"success":true,"data":{"message":"If the account can be created and is not yet verified, a verification email has been sent."}}

# 2. Inspect Mailpit for the most recent "Verify your email" message; copy the plaintext token (not the URL) out of the
#    "If you prefer to paste the token manually" section of the email body.
open "https://green-paws-lms-backend.ddev.site:8026"
export VERIFY_TOKEN=$(... paste ...)

# 3. Attempt login BEFORE verification — must fail.
curl -i -X POST "$WP_URL/wp-json/vl-auth/v1/token" \
  -H 'Content-Type: application/json' \
  -d '{"username":"phase2.smoke+$(date +%s)@example.test","password":"CorrectHorseBatteryStaple"}'
# Expected: HTTP/2 401
#   {"success":false,"error":{"code":"invalid_credentials","message":"Invalid username or password.","status":401}}
# NOTE: surfaced as "invalid_credentials" per §4. The server-side `WP_Error` code was
#       `vl_lms_email_not_verified` — `vl-jwt-auth`'s login handler collapses it.

# 4. Verify the token — must issue access_token + set refresh cookie.
curl -i -c /tmp/vl-cookies.txt -X POST "$WP_URL/wp-json/vl/v1/auth/verify-email" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$VERIFY_TOKEN\"}"
# Expected: HTTP/2 200
#   Set-Cookie: vl_refresh_token=…; Path=/wp-json/vl-auth/v1/; HttpOnly; Secure; SameSite=None
#   {"success":true,"data":{"user":{…},"access_token":"eyJ…"}}

# 5. Login AFTER verification — must succeed.
curl -i -X POST "$WP_URL/wp-json/vl-auth/v1/token" \
  -H 'Content-Type: application/json' \
  -d '{"username":"phase2.smoke+$(date +%s)@example.test","password":"CorrectHorseBatteryStaple"}'
# Expected: HTTP/2 200
#   {"success":true,"data":{"access_token":"eyJ…","token_type":"Bearer",…}}

# 6. Re-send verification (idempotent, generic response even if unknown email).
curl -i -X POST "$WP_URL/wp-json/vl/v1/auth/resend-verification" \
  -H 'Content-Type: application/json' \
  -d '{"email":"does-not-exist@example.test"}'
# Expected: HTTP/2 200
#   {"success":true,"data":{"message":"If the account exists and is unverified, an email has been sent."}}
# Mailpit should NOT have received any new message for this call.
```

**Email-enumeration check:** step 1 against an email that is already verified returns the same 201 + identical body, and Mailpit records **no** new message. Step 6 against an unknown email returns the same body and Mailpit records **no** new message. An attacker cannot distinguish (a) new account created, (b) account exists but verified, (c) account does not exist.

---

## 11. Out-of-scope reminders (for future subtasks)

Tracked here so they don't vanish:
- Password reset (lost-password) flow — subtask 2.C.
- Bulk "revoke other sessions" endpoint — subtask 2.B or later.
- Add `vl_jwt_auth_login_error_code` filter (or pass-through) to `vl-jwt-auth` so `UnverifiedLoginBlocker`'s distinct error code can reach the frontend.
- Schedule `RefreshTokenRepository::cleanup_expired()` as daily cron.
- Add breach-list check to password policy (HIBP k-anonymity API).
- Ukrainian (i18n) email templates.
