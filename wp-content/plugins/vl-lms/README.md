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

## License

GPL-2.0-or-later.
