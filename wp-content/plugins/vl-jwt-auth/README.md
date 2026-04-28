# VL JWT Auth

JWT authentication for headless WordPress. Issues access + refresh tokens, rotates refresh tokens with replay detection, and exposes a public PHP API so other plugins can protect their own REST routes.

## Requirements

- PHP 8.4+
- WordPress 6.4+
- Composer
- HTTPS (required in production — refresh cookie uses `SameSite=None` which browsers reject without `Secure`)

## Installation

```bash
cd wp-content/plugins/vl-jwt-auth
composer install
```

Add a secret to `wp-config.php` **above** the `/* That's all, stop editing! */` line:

```php
defined( 'VL_JWT_AUTH_SECRET_KEY' ) || define( 'VL_JWT_AUTH_SECRET_KEY', '<32+ bytes of entropy>' );
```

Generate a secret with:

```bash
openssl rand -base64 48
```

Activate the plugin. The table `{prefix}vl_refresh_tokens` is created on activation.

For DDEV, load the secret from the environment instead of hardcoding it:

```yaml
# .ddev/config.local.yaml (gitignored)
web_environment:
  - VL_JWT_AUTH_SECRET_KEY=<dev secret>
```

```php
// wp-config-ddev.php
defined( 'VL_JWT_AUTH_SECRET_KEY' )
  || define( 'VL_JWT_AUTH_SECRET_KEY', getenv( 'VL_JWT_AUTH_SECRET_KEY' ) ?: '' );
```

If the secret is missing, the plugin refuses to register any hooks and shows an admin notice.

## Configuration

Settings live in a single option, `vl_jwt_auth_settings`:

| Key | Default | Description |
|-----|---------|-------------|
| `access_token_ttl` | `1800` | Access-token lifetime in seconds (30 min) |
| `refresh_token_ttl` | `1209600` | Refresh-token lifetime in seconds (14 days) |
| `cookie_domain` | `''` | `Domain` attribute for the refresh cookie. Leave blank for host-only scope |
| `cookie_samesite` | `'None'` | `None` (cross-origin) / `Lax` (same-subdomain) / `Strict` |
| `rate_limit_login` | `5` | Max login attempts per IP per window |
| `rate_limit_window` | `900` | Window length in seconds (15 min) |
| `allowed_origins` | `[]` | Origin allowlist for `/token/refresh` and `/logout`. Empty list = unrestricted (dev only) |

Update from WP-CLI:

```bash
wp option patch update vl_jwt_auth_settings allowed_origins --format=json \
  '["https://app.vetlms.com"]'
```

**In production, always populate `allowed_origins`** — it is the plugin's CSRF defense for cookie-bearing endpoints.

## REST API

Namespace: `vl-auth/v1`. Base path: `/wp-json/vl-auth/v1/`.

All cURL examples below use `$WP_URL` as the WordPress host. Export it once for your environment:

```bash
export WP_URL=https://your-wp-backend.example   # e.g. https://green-paws-lms-backend.ddev.site
```

### Response envelope

Success:

```json
{ "success": true, "data": { ... } }
```

Error:

```json
{ "success": false, "error": { "code": "invalid_credentials", "message": "...", "status": 401 } }
```

Error codes: `invalid_credentials`, `token_expired`, `token_invalid`, `refresh_token_invalid`, `refresh_token_required`, `refresh_token_reused`, `rate_limit_exceeded`, `invalid_origin`, `user_not_found`, `not_found`.

In addition, `POST /token` may surface a small set of **whitelisted** authentication-failure codes pass-through from `wp_authenticate()`: the WordPress core codes `invalid_username`, `invalid_email`, `incorrect_password`, `empty_username`, `empty_password`, plus any code prefixed `vl_` (used by first-party plugins hooking `wp_authenticate_user`, e.g. `vl_lms_email_not_verified`). Anything else collapses to `invalid_credentials`. HTTP status is always `401` regardless of the code.

### `POST /token` — login

Accepts username or email in the `username` field.

```bash
curl -c cookies.txt \
  -X POST $WP_URL/wp-json/vl-auth/v1/token \
  -H 'Content-Type: application/json' \
  -d '{"username":"vet@example.com","password":"••••••"}'
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "expires_in": 1800,
    "user": {
      "id": 42,
      "login": "vet42",
      "email": "vet@example.com",
      "display_name": "Ivan Petrenko",
      "roles": ["veterinarian"]
    }
  }
}
```

The refresh token is set as an HttpOnly cookie (`vl_refresh_token`) scoped to `/wp-json/vl-auth/v1/`. It is **never** returned in the body.

### `POST /token/refresh` — rotate tokens

Requires the refresh cookie. Issues a new access token and rotates the refresh cookie.

```bash
curl -b cookies.txt -c cookies.txt \
  -X POST $WP_URL/wp-json/vl-auth/v1/token/refresh \
  -H 'Origin: https://app.vetlms.com'
```

If an already-revoked refresh token is presented (replay signal), the entire token family is revoked and the response is `refresh_token_reused` (401).

### `POST /token/validate`

```bash
curl -X POST $WP_URL/wp-json/vl-auth/v1/token/validate \
  -H 'Authorization: Bearer <access_token>'
```

### `POST /logout`

```bash
curl -b cookies.txt -c cookies.txt \
  -X POST $WP_URL/wp-json/vl-auth/v1/logout \
  -H 'Origin: https://app.vetlms.com'
```

Idempotent. Revokes the refresh-token row (if present) and clears the cookie.

### `GET /me`

```bash
curl -X GET $WP_URL/wp-json/vl-auth/v1/me \
  -H 'Authorization: Bearer <access_token>'
```

Returns `id`, `login`, `email`, `display_name`, `roles`, and an array of capability names the user holds.

### `GET /sessions`

Lists the user's active (non-revoked, non-expired) refresh tokens. Each entry is flagged with `current: true` when it matches the cookie on the current request.

```bash
curl -b cookies.txt \
  -X GET $WP_URL/wp-json/vl-auth/v1/sessions \
  -H 'Authorization: Bearer <access_token>'
```

### `DELETE /sessions/{id}`

Revoke a specific session. Revoking the current session also clears the cookie.

```bash
curl -b cookies.txt -c cookies.txt \
  -X DELETE $WP_URL/wp-json/vl-auth/v1/sessions/17 \
  -H 'Authorization: Bearer <access_token>'
```

### `DELETE /sessions` — revoke other sessions (bulk)

Revoke every active refresh-token row for the current user **except** the one tied to the refresh cookie on this request — the standard "log out everywhere else" action for security-settings UIs. Requires both the bearer header and a valid refresh cookie; `Origin` is checked against `allowed_origins`.

```bash
curl -b cookies.txt \
  -X DELETE $WP_URL/wp-json/vl-auth/v1/sessions \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Origin: https://app.vetlms.com'
```

```json
{ "success": true, "data": { "revoked_count": 3 } }
```

Returns `refresh_token_required` (401) when the refresh cookie is missing, and `refresh_token_invalid` (401) when the cookie does not match a live row owned by the bearer user.

## Public PHP API

Other plugins consume vl-jwt-auth through `\VLJwtAuth\Auth` (an alias for `\VLJwtAuth\Auth\AuthFacade`).

### Resolving the current user

```php
$user = \VLJwtAuth\Auth::current_user();           // WP_User|null — reads Authorization header from $_SERVER
$user = \VLJwtAuth\Auth::user_from_request( $request ); // WP_User|null from a WP_REST_Request
```

Both return `null` on any failure (missing header, expired token, invalid signature, deleted user).

### Protecting your own REST routes

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'vl/v1', '/courses', [
        'methods'             => 'GET',
        'callback'            => [ VL_LMS_Courses::class, 'index' ],
        'permission_callback' => \VLJwtAuth\Auth::require_authenticated(),
    ] );

    register_rest_route( 'vl/v1', '/courses/(?P<id>\d+)/publish', [
        'methods'             => 'POST',
        'callback'            => [ VL_LMS_Courses::class, 'publish' ],
        'permission_callback' => \VLJwtAuth\Auth::require_role( 'administrator' ),
    ] );

    register_rest_route( 'vl/v1', '/progress', [
        'methods'             => 'POST',
        'callback'            => [ VL_LMS_Progress::class, 'record' ],
        'permission_callback' => \VLJwtAuth\Auth::require_capability( 'read' ),
    ] );
} );
```

`require_role()` accepts multiple roles — pass any one holds access:

```php
\VLJwtAuth\Auth::require_role( 'administrator', 'veterinarian' )
```

Permission-callback failures return WordPress `WP_Error` with codes `token_invalid` (401), `insufficient_role` (403), or `insufficient_capability` (403), and WordPress renders them in its standard error format.

### Decoding a raw JWT

```php
try {
    $claims = \VLJwtAuth\Auth::decode_access_token( $jwt );
} catch ( \VLJwtAuth\Exception\TokenException $e ) {
    // $e->error_code() ∈ {token_expired, token_invalid}
    // $e->status_code() is the HTTP status we would have returned
}
```

### Reacting to successful authentication

Fired after `/token` and `/token/refresh` resolve a user:

```php
add_action( 'vl_jwt_auth_user_authenticated', function ( WP_User $user, WP_REST_Request $request ) {
    VL_LMS_Login_Log::record( $user->ID, $request );
}, 10, 2 );
```

## Extending claims

Inject plugin-specific claims into every access token:

```php
add_filter( 'vl_jwt_auth_token_claims', function ( array $claims, int $user_id, string $type ) {
    if ( 'access' !== $type ) {
        return $claims;
    }
    $claims['license_verified'] = (bool) get_user_meta( $user_id, 'vl_license_verified', true );
    $claims['enrolled_courses'] = VL_LMS_Enrollment::get_user_course_ids( $user_id );
    return $claims;
}, 10, 3 );
```

Custom claims are visible to any code that calls `\VLJwtAuth\Auth::decode_access_token()` and are passed through to the SPA as part of the JWT payload (the frontend can decode the payload segment of a JWT without a secret — just don't trust it without round-tripping).

## Hooks reference

| Filter / Action | Signature | Purpose |
|-----------------|-----------|---------|
| `vl_jwt_auth_token_claims` (filter) | `(array $claims, int $user_id, string $type): array` | Add/override claims before signing |
| `vl_jwt_auth_access_ttl` (filter) | `(int $seconds): int` | Override access-token lifetime |
| `vl_jwt_auth_refresh_ttl` (filter) | `(int $seconds): int` | Override refresh-token lifetime |
| `vl_jwt_auth_rate_limit_refresh` (filter) | `(int $limit): int` | Tune `/token/refresh` rate limit |
| `vl_jwt_auth_rate_limit_refresh_window` (filter) | `(int $seconds): int` | Tune `/token/refresh` window |
| `vl_jwt_auth_client_ip` (filter) | `(string $ip): string` | Override client IP (reverse-proxy setups) |
| `vl_jwt_auth_user_authenticated` (action) | `(WP_User $user, WP_REST_Request $request)` | Fires after `/token` and `/token/refresh` |

## CORS

Out of scope for this plugin. Configure CORS in the theme, a dedicated plugin, or the reverse proxy. Required headers for a cross-origin SPA:

- `Access-Control-Allow-Origin: https://app.vetlms.com` (echo the exact origin)
- `Access-Control-Allow-Credentials: true`
- `Access-Control-Allow-Headers: Content-Type, Authorization`
- `Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS`

## Security model

- **Secret** — HS256 signing key read from `VL_JWT_AUTH_SECRET_KEY`. Never stored in the database, never exposed via any endpoint.
- **Refresh tokens in DB** — only SHA-256 hashes of issued tokens are persisted. The raw token is never written.
- **Rotation + replay detection** — every `/token/refresh` call revokes the presented token and issues a new one sharing the same `token_family`. A second attempt to refresh with a revoked token is treated as compromise: the whole family is revoked.
- **Cookies** — `HttpOnly`, `Secure` (forced when `SameSite=None`), path-scoped to `/wp-json/vl-auth/v1/` so the cookie is never sent on regular frontend or `wp-admin` requests.
- **CSRF defense** — `/token/refresh` and `/logout` check the `Origin` (or `Referer`) against `allowed_origins`. A token header is not used; with an HttpOnly cookie + origin allowlist, it would be defense against nothing extra.
- **Rate limiting** — fixed-window transient bucket on `/token` (per IP, per settings) and `/token/refresh` (30 per minute by default).
- **Password change** — `profile_update` and `password_reset` revoke every refresh token the user holds.

## Out of scope

- Social / OAuth login — belongs in a separate plugin.
- Two-factor authentication.
- Multisite / network activation.
- RS256 signing (HS256 only in this release; the algorithm is not pluggable yet).
- Email notifications on new logins (product-level concern, not auth-plugin concern).

## Implementation notes

Rules to keep in mind when contributing to the plugin:

- **Layer separation.** `TokenService` does not touch the database; `RefreshTokenRepository` does not know about JWT encoding or signing. The split is what makes each class unit-testable in isolation — keep it even when a shortcut seems cheap.
- **Double defense on refresh tokens.** Refresh tokens are JWTs **and** SHA-256-hashed in the DB (signature check + revocation lookup). Do not "simplify" either layer away.
- **IP storage.** IP addresses are persisted as `VARBINARY(16)` via `INET6_ATON` / `INET6_NTOA`. Never push raw `inet_pton()` output through `$wpdb->prepare()` — driver handling of NULs in binary values is unreliable.
- **Envelope split.** This plugin's own `vl-auth/v1` endpoints render `{success, data|error}` from inside the handler rather than from `permission_callback`, so the envelope survives auth failures. That is also why `RestController::require_user()` does auth inline instead of delegating to `Middleware`. Other plugins using `\VLJwtAuth\Auth::require_*()` get WordPress's default `WP_Error` rendering — do not try to unify the two shapes.
- **Login error whitelist.** `RestController::translate_login_error()` only forwards core WP authenticator codes (`invalid_username`, `invalid_email`, `incorrect_password`, `empty_username`, `empty_password`) and codes prefixed `vl_` (reserved for first-party plugins hooking `wp_authenticate_user`, e.g. `vl_lms_email_not_verified`). Anything else collapses to `invalid_credentials`. HTTP status is always `401`. Messages run through `wp_strip_all_tags()` because core's `incorrect_password` embeds an anchor to the lost-password screen and the JSON envelope must stay plain text.
- **OriginGuard scope.** The guard is applied only on cookie-bearing, state-changing endpoints: `POST /token/refresh`, `POST /logout`, and the bulk `DELETE /sessions`. `DELETE /sessions/{id}` is bearer-only today (does not consume the refresh cookie); if a future change makes it cookie-aware, the guard must be added.
- **Background cleanup.** Expired refresh-token rows are hard-deleted daily by the `vl_jwt_auth_cleanup_expired_tokens` WP-Cron event (rows whose `expires_at` is older than 30 days). The Activator schedules it; the Deactivator unschedules it. The table does not grow unboundedly.
- **Domain boundary.** The plugin stays product-agnostic. No courses, enrollment, veterinary knowledge, or anything LMS-specific lands here. Extensions hook in through `vl_jwt_auth_token_claims` (claims) and `vl_jwt_auth_user_authenticated` (side effects) — no other expansion path.
- **Secrets.** `VL_JWT_AUTH_SECRET_KEY` is read only from a PHP constant (in DDEV: env var → constant). Never read from the database, never committed in any `wp-config*.php` that lives in git. The plugin refuses to register hooks if the constant is missing.
- **Composer.** `firebase/php-jwt` is pinned to `^7.0`. The v6 line has an open low-severity CVE (missing key-size validation) — when bumping, check Packagist advisories before downgrading the constraint.
- **Tests.** Deliberately omitted for the MVP. Do not add PHPUnit, Brain Monkey, or other test scaffolding without an explicit ask.

## License

GPL-2.0-or-later.
