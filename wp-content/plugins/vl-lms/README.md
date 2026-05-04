# VL LMS

Headless Veterinary LMS plugin for WordPress. Backs the Nuxt.js frontend through the `vl/v1` REST namespace and delegates authentication to [`vl-jwt-auth`](../vl-jwt-auth).

This plugin owns the LMS domain — custom post types, taxonomies, roles and capabilities, custom DB tables, public registration / email verification / password reset, and the public catalog (list, detail, search). Authoring still happens in `wp-admin`; the frontend reads only through `vl/v1`.

## Requirements

- WordPress 6.4+
- PHP 8.4+ (the bootstrap declares strict types and uses typed class constants / readonly promotion)
- Composer
- [`vl-jwt-auth`](../vl-jwt-auth) must be active. VL LMS short-circuits during `plugins_loaded@20` and shows an admin notice if `\VLJwtAuth\Auth` is missing.
- CORS for the `vl/v1` namespace is handled by the `vl-cors` mu-plugin (`wp-content/mu-plugins/vl-cors.php`). Whitelist origins via `VL_CORS_ORIGINS` in `wp-config.php`:
  ```php
  define( 'VL_CORS_ORIGINS', 'http://localhost:3000,https://app.vetlms.com' );
  ```

## Setup

```bash
cd wp-content/plugins/vl-lms
composer install
wp plugin activate vl-lms
curl http://localhost:8080/wp-json/vl/v1/healthz
```

Activation runs `RolesInstaller`, `SchemaManager`, and queues a first-run flag picked up on the next `init` to seed default `vl_difficulty` terms and flush rewrite rules. Re-activation is idempotent at every step.

## External integrations — Zoom

The Phase 7 live-meeting subsystem talks to Zoom via Server-to-Server OAuth. Credentials resolve from `wp-config.php` constants first, with WP options as a fallback for a future admin UI (mirrors the `VL_CORS_ORIGINS` precedent):

```php
define( 'VL_ZOOM_ACCOUNT_ID',     'your-account-id' );
define( 'VL_ZOOM_CLIENT_ID',      'your-client-id' );
define( 'VL_ZOOM_CLIENT_SECRET',  'your-client-secret' );
define( 'VL_ZOOM_WEBHOOK_SECRET', 'your-webhook-secret' );
```

If a constant is undefined or empty, `ZoomSettingsProvider` reads the matching option (`vl_lms_zoom_account_id`, `vl_lms_zoom_client_id`, `vl_lms_zoom_client_secret`, `vl_lms_zoom_webhook_secret`). With nothing configured, `ZoomCredentials::is_configured()` returns `false` and the integration short-circuits without breaking plugin boot — only the live-meeting and webinar code paths surface a `ZoomAuthException` when actually invoked.

### Phase 7.1 — meeting sync

`MeetingSynchronizer` reconciles every `vl_session` and `vl_webinar` post with its Zoom meeting on each `save_post`, `wp_trash_post`, and `untrashed_post`. It runs at hook priority `20` so the Custom Fields metabox (priority `10`) has already persisted the new meta values when the synchronizer reads them.

Each run resolves to one of five outcomes (see `SyncDecision`):

- `CREATE` — first publish or untrash with no meeting on file. The Zoom-returned `id`, `join_url`, `start_url`, and `password` land in `_vl_{kind}_zoom_meeting_id|join_url|start_url|password`.
- `UPDATE` — meeting exists and the canonical payload fingerprint changed (title, start time, duration, password). The existing password is reused so previously-shared join URLs stay valid.
- `DELETE` — post was trashed or its `_vl_{kind}_status` was flipped to `cancelled`. The four meta fields are cleared. A 404 from Zoom on delete is treated as success.
- `NOOP` — payload identical to the last sync (`SyncReason::NO_DIFF`), or required scheduling meta is missing (`MISSING_REQUIRED_META`), or the post is cancelled and never had a meeting.
- `SKIPPED` — credentials missing (`NOT_CONFIGURED`), the request is a revision/autosave, the post status is `auto-draft` / `inherit`, or another sync is already in flight (`LOCKED`, 30-second transient lock keyed by post id).

Two reserved actions fire on every run for downstream consumers (Phase 7.6 reminder mailer, Phase 9 audit log):

```php
do_action( 'vl_lms_zoom_meeting_synced', int $post_id, PostKind $kind, SyncDecision $decision, ?string $meeting_id );
do_action( 'vl_lms_zoom_meeting_sync_failed', int $post_id, PostKind $kind, SyncDecision $intended, \Throwable $exception );
```

Troubleshooting a non-syncing post:

1. Check that the four `VL_ZOOM_*` constants are set and `ZoomCredentials::is_configured()` returns `true` — a misconfigured site silently produces `SKIPPED / NOT_CONFIGURED`.
2. Confirm the post status is not `auto-draft` / `inherit` and that the request is not a revision/autosave.
3. Confirm `_vl_{kind}_scheduled_start` is populated — a missing start yields `NOOP / MISSING_REQUIRED_META`.
4. Subscribe to `vl_lms_zoom_meeting_sync_failed` (or read the `error_log` line emitted by `Logger`) to see the wrapped `ZoomApiException` / `ZoomAuthException`.

### Phase 7.2 — Zoom webhooks

Zoom posts events to `POST https://<your-site>/wp-json/vl/v1/webhooks/zoom`. The receiver verifies an HMAC-SHA256 signature against `VL_ZOOM_WEBHOOK_SECRET` (5-minute replay window enforced via `x-zm-request-timestamp`), parses the body, dedups on `x-zm-trackingid` against `{prefix}vl_zoom_webhook_events`, and routes operational events to per-event handlers.

Two-step Marketplace setup:

1. Paste the URL into your Zoom app's "Event Subscriptions" panel and click **Validate**. The receiver answers the `endpoint.url_validation` challenge inline with the documented `{plainToken, encryptedToken}` shape.
2. Enable these five operational events:
   - `meeting.started` → flips `_vl_{kind}_status` from `scheduled` to `live`.
   - `meeting.ended` → flips `_vl_{kind}_status` to `completed`.
   - `meeting.participant_joined` → records a row in `vl_session_attendance` (sessions) or stashes an open join transient (webinars).
   - `meeting.participant_left` → closes the session row with `duration_seconds`, or credits `vl_webinar_registrations.attended_duration_seconds` for the matching registered user.
   - `recording.completed` → writes `_vl_session_recording_url` (sessions, leaving `_vl_session_recording_available_until` untouched) or `_vl_webinar_recording_url` + `_vl_webinar_recording_available_until = now + recording_access_days` (webinars). When `recording_access_days = 0` the handler is a no-op.

The receiver always returns 200 for handler-side outcomes — `{success: true, status: 'processed'|'ignored'|'failed', message}` — because Zoom retries on non-2xx and the dedup table is the single retry-mitigation strategy. Authenticity failures return 401 (`webhook_invalid_signature` / `webhook_misconfigured`); shape failures return 400 (`webhook_invalid_payload`, with the parser's reason code in the body).

`MeetingSynchronizer` from 7.1 stays dormant during webhook handling — handlers write meta with `update_post_meta`, which does NOT trigger `save_post`.

Troubleshooting:

1. **401 webhook_invalid_signature** — check `VL_ZOOM_WEBHOOK_SECRET` matches your Zoom app's secret and that your server clock is within 5 minutes of UTC (NTP).
2. **401 webhook_misconfigured** — `VL_ZOOM_WEBHOOK_SECRET` is empty. Define the constant or set the `vl_lms_zoom_webhook_secret` option.
3. **400 webhook_invalid_payload** — the body shape changed; the response code (e.g. `missing_event_ts`) tells you which validation tripped. Compare against Zoom's published payload version.
4. **Events processed but no state change** — the event's meeting_id doesn't match any post. Confirm `wp post meta get <id> _vl_{kind}_zoom_meeting_id` matches the Zoom-side meeting that actually fired.
5. **Webinar `attended_duration_seconds` not incrementing** — under heavy traffic an object-cache backend (Redis / Memcached) may evict `WebinarJoinTracker` transients before TTL, in which case the matching `participant_left` becomes a no-op. Check the cache configuration.

### Phase 7.3 — Webinar registration & access

Five JWT-authenticated endpoints expose the webinar lifecycle to the Nuxt frontend.

| Method | Route | Cap | Notes |
|--------|-------|-----|-------|
| POST | `/vl/v1/webinars/{slug}/registrations` | `vl_register_for_webinar` | Self-register the caller. 201 on first register, 200 on re-register / already-active (`idempotent: true`). 402 `payment_required` for paid webinars (with `price.amount` / `price.currency` in the body) is the explicit Phase 8 hand-off. |
| GET | `/vl/v1/webinars/me` | authenticated | Lists the caller's registrations. Query params: `status` (`active` (default) / `cancelled` / `all`), `time_filter` (`upcoming` (default) / `past` / `all`). Each row carries `webinar.*` summary plus a `computed.*` block (`join_window_open`, `join_opens_at`, `join_closes_at`, `recording_available`, `is_past`) so the frontend doesn't re-implement window math. |
| DELETE | `/vl/v1/webinars/{slug}/registrations` | `vl_register_for_webinar` | Cancels the caller's registration. 200 on cancel and on already-cancelled (`idempotent: true`); 409 `not_registered` when no row. |
| GET | `/vl/v1/webinars/{slug}/join` | `vl_register_for_webinar` | 302 to `_vl_webinar_zoom_join_url` when `WebinarAccessGate::can_join` allows. 403 `not_registered`, 409 `join_window_not_open` (with `opens_at`), 410 `join_window_closed`, 503 `meeting_not_provisioned` (with `retry_after: 60`) otherwise. |
| GET | `/vl/v1/webinars/{slug}/recording` | `vl_view_webinar_recording` | 302 to `_vl_webinar_recording_url` when the gate allows. 403 / 404 `recording_not_available` / 410 `recording_window_expired` (with `expired_at`) otherwise. |

Worked example (free webinar happy path):

1. `POST /vl/v1/webinars/vet-may/registrations` → `201 { success: true, registration: {...} }`
2. `GET /vl/v1/webinars/me?time_filter=upcoming` → row appears with `computed.join_window_open: false` until 15 min before start.
3. From 15 min before scheduled start through 60 min after scheduled end, `GET /vl/v1/webinars/vet-may/join` redirects to Zoom.
4. After the recording webhook fires (Phase 7.2), `GET /vl/v1/webinars/vet-may/recording` redirects until `_vl_webinar_recording_available_until`.

Notes:

- **Best-effort gating.** The 302 redirects expose the canonical Zoom URL; a registered user can theoretically capture and share it. We accept this for Phase 7 — Zoom-side per-registrant join URLs are explicitly out of scope.
- **Capacity-check race.** The `_vl_webinar_max_attendees` check reads `count_active_for_webinar` and is racy under concurrent registration. The unique key on `(webinar_id, user_id)` still prevents the same user double-booking, and marginal over-capacity (1–2 seats over a cap of N) is acceptable.
- **Bad-meta resilience.** Unparseable `_vl_webinar_*_at` strings are treated as missing (the field is silently ignored) rather than producing a 500.

### Phase 7.4 — Cohort sessions: access endpoints + curriculum extension

Three endpoints expose `vl_session` posts under cohort courses to the Nuxt frontend. The Phase 5.2 `Learn\CurriculumTransformer` is extended so sessions appear as top-level leaves in `GET /vl/v1/learn/courses/{slug}/curriculum` for cohort-type courses.

| Method | Route | Cap | Notes |
|--------|-------|-----|-------|
| GET | `/vl/v1/learn/sessions/{slug}` | `vl_view_lesson` | Detail payload (`session.*` summary + `computed.*` block: `join_window_open`, `join_opens_at`, `join_closes_at`, `recording_available`, `is_past`, `user_attended`). Course enrollment enforced via `EnrollmentService::has_active_access`. |
| GET | `/vl/v1/learn/sessions/{slug}/join` | `vl_view_lesson` | 302 to `_vl_session_zoom_join_url` when `SessionAccessGate::can_join` allows. Otherwise 403 / 410 / 503 with reason codes (`not_enrolled`, `session_cancelled`, `meeting_not_provisioned` with `retry_after: 60`, `join_window_not_open` with `opens_at`, `join_window_closed`). |
| GET | `/vl/v1/learn/sessions/{slug}/recording` | `vl_view_session_recording` | 302 to `_vl_session_recording_url` when the gate allows. Otherwise 403 `not_enrolled`, 404 `recording_not_available`, 410 `recording_window_expired` (with `expired_at`). |

Notes:

- **Session recording window is instructor-controlled.** Unlike webinars (where Phase 7.2 `RecordingCompletedHandler` auto-stamps `_vl_webinar_recording_available_until = now + recording_access_days`), the session handler only writes the URL — `_vl_session_recording_available_until` stays at whatever the instructor entered. An empty until-date is treated as "no expiry" (recording available indefinitely while course access lasts), matching how cohort courses typically work.
- **Session attendance feeds the progress fan-up.** When `ParticipantJoinedHandler` (Phase 7.2) records a session join, it now fires the `vl_lms_session_attendance_recorded` action. `Services\Progress\SessionAttendanceProgressListener` reacts by recomputing `progress_pct` (which now treats sessions as leaves for cohort courses via `CourseProgressCalculator`) and re-evaluating the E2 course-completion contract — so a session that closes out the leaf set can flip a cohort enrollment to `COMPLETED` directly from the webhook path.
- **Curriculum tree.** The cohort variant of `GET /vl/v1/learn/courses/{slug}/curriculum` includes a top-level `sessions` array; nodes carry a `type: "session"` discriminant, an `is_completed` field (true iff at least one attendance row exists), and `join_url_path` / `recording_url_path`. `next_entity` walks modules → orphan lessons → sessions in `scheduled_start ASC` and surfaces a session-shaped payload (`type: "session"`, plus `scheduled_start`) when the next incomplete leaf is a session.
- **Recording-view tracking is not implemented.** Only live attendance contributes to `progress_pct`. A "session viewed" event for recordings is deferred to a future phase; until then, a user who only watches recordings will not flip the enrollment to completed via the session arm.

## REST surface

All routes live under `vl/v1`. Success responses use `{ success: true, data: {...} }`; errors come back as WordPress's default `WP_Error` shape (`{ code, message, data: { status } }`). This is distinct from `vl-jwt-auth`'s `{ success: false, error: {...} }` envelope on its own routes.

### Health

| Method | Route | Auth | Notes |
|--------|-------|------|-------|
| GET | `/healthz` | none | `{ status, version, timestamp }` — uptime + plugin version probe. |

### Auth (registration / verification / password reset)

| Method | Route | Auth | Notes |
|--------|-------|------|-------|
| POST | `/auth/register` | none | Creates a Student or Instructor account. Rate-limited. Returns the same generic 201 whether the email is new, already pending, or already verified — prevents enumeration. |
| POST | `/auth/verify-email` | none | Consumes a verification token, marks the account verified, and returns a JWT access + refresh pair via `\VLJwtAuth\Auth` so the user lands logged in. |
| POST | `/auth/resend-verification` | none | Re-sends the verification email. Generic body, rate-limited internally. |
| POST | `/auth/request-password-reset` | none | Starts a reset flow. Generic body — rate-limit hits and missing-account branches are absorbed by the service. |
| POST | `/auth/reset-password` | none | Consumes a reset token, applies the new password. WP core's `password_reset` cascades refresh-token revocation through `vl-jwt-auth`. |

Login itself stays in `vl-jwt-auth` (`POST /wp-json/vl-auth/v1/token`). VL LMS only adds an `UnverifiedLoginBlocker` that hooks `wp_authenticate_user` and surfaces the `vl_lms_email_not_verified` error code (one of the `vl_*` codes whitelisted by `vl-jwt-auth`'s login error passthrough).

### Catalog

| Method | Route | Auth | Notes |
|--------|-------|------|-------|
| GET | `/catalog/courses` | none | Paginated list of published `vl_course` posts. Filters: `q`, `category[]`, `specialty[]`, `difficulty[]`, `tag[]`. Sort: see `SortOrder`. Defaults: page 1, per_page 12. |
| GET | `/catalog/webinars` | none | Same shape as `/catalog/courses` against `vl_webinar`. |
| GET | `/catalog/courses/{slug}` | none | Single course detail (cover, taxonomy chips, instructor list, curriculum, SEO block). 404 on draft / private / pending / trashed. |
| GET | `/catalog/webinars/{slug}` | none | Single webinar detail (cover, instructors, materials, registration window, SEO block). |
| GET | `/taxonomies/{taxonomy}` | none | Lists terms for `vl_category` / `vl_specialty` / `vl_difficulty` / `vl_tag`. Optional `post_type=vl_course\|vl_webinar` and `in_use=true` to restrict to terms attached to published posts. Hierarchical taxonomies emit `parent_slug`. |
| GET | `/search?q=…` | none | Cross-type search across courses and webinars, returned as parallel sub-objects each with the same card payload as the list endpoints. Pagination is per-section. |

List endpoints batch-fetch lead instructors (one query) and all four taxonomies (one `wp_get_object_terms` call) per page, so a page of N posts costs O(1) auxiliary queries. Object-cache hooks live in `CatalogController::run_query()` and `CatalogDetailController::find_published_post()` for a future Phase 9 cache layer.

## Domain model

### Custom post types

Registered by `CptRegistrar` on `init@10`. All nine are headless: `public=false`, `show_in_rest=false`, admin-only UI. Frontend reads exclusively via `vl/v1`.

| Slug | Role |
|------|------|
| `vl_course` | Top-level course |
| `vl_module` | Course section (parent: `vl_course`) |
| `vl_lesson` | Lesson inside a module |
| `vl_topic` | Sub-lesson topic |
| `vl_session` | Live session (cohort meeting) |
| `vl_webinar` | One-off webinar with registration window |
| `vl_quiz` | Quiz container |
| `vl_quiz_question` | Question inside a quiz |
| `vl_assignment` | Submitted assignment |

### Taxonomies

Registered by `TaxonomyRegistrar` on `init@10`. All four are attached to both `vl_course` and `vl_webinar`.

| Slug | Hierarchical | Notes |
|------|--------------|-------|
| `vl_category` | yes | Catalog categorization with parent/child levels |
| `vl_specialty` | no | Veterinary specialty |
| `vl_difficulty` | no | Seeded with default terms on first run by `DifficultyTermsInstaller` |
| `vl_tag` | no | Free-form tags |

### Roles and capabilities

`RolesInstaller` (activation) and the source-of-truth `Roles\CapabilitiesMap` install three custom roles on top of WP's `administrator`:

- `student` — read, enrollments, quiz/assignment submission, webinar join, certificate download.
- `instructor` — student caps + own-content CPT caps (no `edit_others_*`, no `delete_others_*`), grade submissions, view own stats. Co-instructor access on assigned posts is layered on at runtime by `Access\InstructorAccessFilter` using the `vl_course_instructors` table — see below.
- `moderator` — enrollment management, group management, submission review, discussion moderation.
- `administrator` — full domain caps (`vl_manage_all_courses`, `vl_manage_finances`, etc.) plus every primitive CPT cap.

CPTs use `capability_type=[singular, plural]` with `map_meta_cap=true`, so meta-caps (`edit_post`, `delete_post`, `read_post`) are derived per-post by WP at check time.

### Custom tables

Owned by `Database\SchemaManager`; `CURRENT_DB_VERSION` (currently `'3'`) gates re-runs so repeated activations are no-ops.

| Table (`{prefix}vl_*`) | Purpose |
|------------------------|---------|
| `vl_enrollments` | User ↔ course enrollments with status, source, progress, audit fields |
| `vl_groups` | Group container (cohorts, ad-hoc groups, B2B clients) |
| `vl_group_members` | Group memberships. `uk_group_user_active(group_id, user_id, left_at)` allows multiple historical rows per `(group, user)` plus exactly one active row (`left_at IS NULL`) |
| `vl_group_access` | Group-level entitlements to courses / webinars |
| `vl_course_instructors` | Many-to-many: users ↔ courses/webinars with `role_in_course` (`lead` / `co_instructor`). One row per `(entity_type, entity_id, user_id)`; promotions UPDATE the existing row |

`uninstall.php` drops every `vl_*` table and clears `vl_lms_db_version`, `vl_lms_plugin_version`, `vl_lms_first_run_pending`. `vl_difficulty` taxonomy terms are deliberately preserved — taxonomy data belongs to the taxonomy, not to this plugin's installer.

### Instructor data

- `User\InstructorProfileMetaRegistrar` registers the structured `vl_instructor_profile_*` user-meta fields used in instructor cards / detail pages.
- `Repositories\CourseInstructorRepository` is the authoritative read/write surface for `vl_course_instructors`.
- `Services\CourseInstructors\AuthorSyncService` keeps `post_author` and the `lead` row in sync when the post author changes in wp-admin.
- `Access\InstructorAccessFilter` extends WP's own-content cap rules: an instructor whose user id is in the `vl_course_instructors` row of the current post receives the same per-post caps WP would grant to its `post_author`.

## Layout

```
vl-lms/
├── vl-lms.php                # Bootstrap: constants, vendor guard, plugins_loaded@20 hook
├── uninstall.php             # Roles + tables + version options cleanup
├── composer.json             # PSR-4 + dev tooling (phpunit, brain/monkey, phpstan, phpcs)
├── phpunit.xml.dist          # Unit suite; Integration suite placeholder
├── phpcs.xml.dist            # WordPress-Extra ruleset
├── phpstan.neon.dist         # phpstan + szepeviktor/phpstan-wordpress
├── src/
│   ├── Plugin.php                        # Singleton bootstrap, container build, hook wiring
│   ├── Container.php                     # In-house lazy service locator
│   ├── Activator.php / Deactivator.php   # Activation: roles + tables + first-run flag
│   ├── Api/
│   │   ├── RestController.php            # /healthz
│   │   └── AuthController.php            # /auth/* (register, verify, resend, reset)
│   ├── Auth/
│   │   ├── AccountKind.php               # Allowed `account_kind` values for /auth/register
│   │   ├── PasswordPolicy.php            # Shared min-length / strength rules
│   │   ├── TokenIssuer.php               # Interface — JwtBridgeTokenIssuer hands off to vl-jwt-auth
│   │   ├── JwtBridgeTokenIssuer.php
│   │   ├── LoginGate/UnverifiedLoginBlocker.php
│   │   ├── Mail/ (VerificationMailer, PasswordResetMailer)
│   │   ├── Registration/    (Service + Request/Result/Exception)
│   │   ├── Verification/    (Service + Token + Exception)
│   │   └── PasswordReset/   (Service + Token + Request/Confirm/Exception)
│   ├── Catalog/
│   │   ├── CatalogController.php         # /catalog/courses, /catalog/webinars (list)
│   │   ├── CatalogDetailController.php   # /catalog/{post_type}/{slug}
│   │   ├── TaxonomyController.php        # /taxonomies/{taxonomy}
│   │   ├── CatalogQuery.php              # WP_Query arg builder for lists
│   │   ├── FilterRequest.php / SortOrder.php / PostType.php
│   │   ├── Detail/                       # CourseDetail, WebinarDetail, Curriculum, Materials, SEO, RegistrationWindow…
│   │   ├── Search/                       # SearchController + SearchQuery + SearchQueryRunner + RelevanceRanker
│   │   └── Transformers/                 # Card transformers (course, webinar, cover image, lead instructor)
│   ├── CPT/                              # Nine CPT registrars + AbstractCptRegistrar + CptRegistrar
│   ├── Taxonomy/                         # Four taxonomy registrars + AbstractTaxonomyRegistrar + DifficultyTermsInstaller
│   ├── Roles/                            # CapabilitiesMap (single source of truth) + RolesInstaller
│   ├── Database/SchemaManager.php        # Versioned dbDelta installer / uninstaller for every vl_* table
│   ├── Repositories/                     # Enrollment, Group, GroupMember, GroupAccess, CourseInstructor
│   ├── Services/                         # Enrollment, Groups, CourseInstructors (AuthorSync + CourseInstructorService)
│   ├── Access/                           # InstructorAccessFilter + Co-instructor lookup
│   ├── Domain/                           # Plain DTOs (CourseInstructor, Enrollment, Group, …) + enums
│   ├── User/InstructorProfileMetaRegistrar.php
│   └── Support/                          # Logger, HeroImageSize, Assets
└── tests/
    ├── bootstrap.php                     # Brain Monkey + WP constant stubs
    ├── Unit/                             # Per-namespace coverage (Access, Api, Auth, Catalog, CPT, …)
    └── Integration/                      # Placeholder
```

## Architectural choices

- **Service container.** `VL\LMS\Container` is a ~60-line lazy locator (factory closures, memoized). No `league/container` runtime dependency; can be swapped later without touching consumers.
- **Activation split.** `Activator::activate()` only does work that doesn't need CPTs/taxonomies (roles + tables). `register_activation_hook` fires after `plugins_loaded` has already happened in the activation request, so the `init`-bound registrars never get a chance to run there. CPT/taxonomy-dependent first-run tasks (default `vl_difficulty` terms, rewrite flush) run on the next request's `init@20` — gated by the `vl_lms_first_run_pending` option, which is deleted as soon as those tasks complete.
- **`init@10` ordering.** `CptRegistrar` and `TaxonomyRegistrar` both hook `init@10`. WordPress runs same-priority callbacks in registration order, so post types are registered before the taxonomies that attach to them.
- **Capability authority.** `Roles\CapabilitiesMap` is pure data — no WP API calls. `RolesInstaller` (activation) and the CPT `capability_type` declarations both read from it, so adding/removing a cap means editing one file.
- **Headless CPTs.** Every CPT is registered with `public=false`, `show_in_rest=false`, `query_var=false`. The `vl/v1` controllers are the only public surface; nothing leaks through WP's default REST routes.
- **Error envelope split.** This plugin's controllers return WP's default `WP_Error` rendering on failure. `vl-jwt-auth` keeps its own `{ success: false, error }` envelope for its own routes. Don't try to unify the two shapes — downstream consumers are coded against the boundary.
- **Email enumeration defense.** Registration / verification-resend / password-reset request endpoints all return an identical generic body regardless of branch outcome (account exists, account already verified, rate-limited, mailer failed). Only the consume-token endpoints (`/auth/verify-email`, `/auth/reset-password`) surface specific failure codes.
- **Logger.** Thin PSR-3-shaped wrapper around `error_log()` (`Support\Logger`). Replace with Monolog when structured logging is needed.
- **Caching.** Deferred to Phase 9. Hook points are documented inline in `CatalogController::run_query()`, `CatalogController::transform_items()`, and `CatalogDetailController::find_published_post()`.
- **Booted extension point.** `do_action( 'vl_lms/booted', $container )` fires once the plugin has finished wiring. Future submodules register their services and routes against the container here without touching `Plugin.php`.

## Commands

```bash
composer run test    # PHPUnit unit suite
composer run lint    # phpcs against WordPress-Extra
composer run stan    # phpstan (1G memory limit; the catalog detail transformers push the analyser)
composer run makepot # regenerate translation template
```

Coverage is not gated. `phpunit --coverage-html build/coverage/` works when Xdebug or PCOV is available.

## Demo data seeder (Phase 5.7)

`wp vl-lms demo …` populates the database with realistic Ukrainian veterinary
content — taxonomies, instructors with bios + avatars, students, 6 self-paced
courses (with modules, lessons, topics, quizzes), 2 cohort courses with
sessions, 5 webinars, course-instructor relationships, 7 enrollments at
varied progress states (100% completed, 25%, 60% in-progress with a
resume-toast-demoable leaf, just-started, empty), and the matching
`vl_progress` + `vl_lesson_views` rows.

Subcommands:

```bash
ddev wp vl-lms demo seed [--force] [--skip-progress]
ddev wp vl-lms demo reset [--force]
ddev wp vl-lms demo status
```

Behaviour:

- **Idempotent.** Re-running `seed` skips every artifact already tagged with
  `_vl_demo_seed = '1'` (posts/users/attachments) or `vl_demo_seed = '1'`
  (terms / users). `ProgressSeeder` is the single exception — it always
  wipes its rows and re-emits, so the row count after run 2 equals run 1.
- **Environment guard.** Refuses to run on `wp_get_environment_type() ===
  'production'` without `--force`; even with `--force`, an interactive
  `WP_CLI::confirm` is required.
- **`--skip-progress`.** Skips the progress / lesson-views write loop —
  handy when iterating on lesson content.

Demo users (login):

- `instructor.melnychenko`, `instructor.lytvynenko`, `instructor.shevchenko`
- `student.bohdan`, `student.sofia`, `student.dmytro`, `student.iryna`

Passwords are randomly generated. Reset one explicitly when manual login is
needed: `ddev wp user update student.bohdan --user_pass='your-password'`.
Demo users are flagged email-verified at seed time so no inbox round-trip is
required to log in.

### Cover and avatar images

Primary source: Picsum (`https://picsum.photos/seed/{stable-key}/{w}/{h}`).
Fallback: locally bundled JPEGs under `assets/demo/`. To pre-fetch the
fallback once on a developer machine:

```bash
bash wp-content/plugins/vl-lms/assets/demo/fetch-fallback.sh
```

The script is idempotent — already-downloaded files are skipped.

### Frontend smoke URLs

After seeding:

- `/dashboard` as `student.bohdan` → 1 completed course (Review CTA) +
  1 in-progress course (Continue CTA with progress bar).
- `/dashboard` as `student.iryna` → empty state with "Browse catalog" CTA.
- `/learn/{lesson-slug}` as `student.sofia` for the in-progress leaf in
  course 2 → resume toast appears at 1:30.

## License

GPL-2.0-or-later.
