# vl-lms — code-area conventions

The `vl-lms` plugin, a multi-feature code area: `core` (the LMS domain: CPTs, custom tables, the `vl/v1` REST API, the wp-admin LMS surface), `course-import` (the wp-admin Markdown course importer) and `study-time` (the active study-time ledger, its `vl/v1/study-time/*` endpoints and its wp-admin report sections). Feature docs live in the root repo, `docs/features/{core,course-import,study-time}/`.

## Feature isolation
- `core` owns everything under `src/` outside the `course-import` and `study-time` directories. Its entry is `Plugin` (`build_container()` + `boot()`).
- `course-import` lives in `src/Import/` (domain, bootstrap `Import\ImportProvider::boot()`) and `src/Admin/Import/` (wp-admin screens and `admin-post.php` handlers). Its tests go in `tests/Unit/Import/` and `tests/Unit/Admin/Import/`, its fixtures in `tests/Fixtures/Import/`.
- `study-time` lives in `src/StudyTime/` (domain, bootstrap `StudyTime\StudyTimeProvider::boot()`) and `src/Admin/StudyTime/` (wp-admin report sections). Its tests go in `tests/Unit/StudyTime/` and `tests/Unit/Admin/StudyTime/`.
- `Plugin` carries exactly one registration per non-core feature: one `$container->set()` in `build_container()` and one boot call in `boot()`. The only other wiring `course-import` gets in `core` is its `add_submenu_page` in `Admin\Menu\AdminMenuProvider` (constructor argument + factory) — `docs/DECISIONS.md` 2026-09-11. The only other wiring `study-time` gets in `core` is its table in `Database\SchemaManager` (version bump + sentinel, root invariant 6) and one `do_action` per wp-admin page it extends — `vl_lms_admin_student_detail_sections`, `vl_lms_admin_analytics_sections`, and the `vl_lms_admin_instructor_dashboard_columns` / `…_cells` pair (`docs/DECISIONS.md` 2026-09-15).
- Shared code (`Plugin`, `Admin\AdminProvider`, `Admin\Menu\AdminMenuProvider`, `Database\SchemaManager`, `Roles\*`, `Support\*`, `Slug\*`, `composer.json` and the tool configs) changes ONLY in an explicit plan task marked **"touches shared code — may affect other features"** that names the consuming features.
- `course-import` reaches `core` only through:
  - WordPress core functions
  - `Roles\CapabilitiesMap` caps
  - `Support\Logger`
  - `Slug\CyrillicTransliterator`
  - the CPT slugs, meta keys and taxonomy slugs from `docs/DATA-MODEL.md`, read as constants

  It never calls `core` services or repositories.
- `study-time` reaches `core` only through:
  - WordPress core functions
  - `Roles\CapabilitiesMap` caps (`vl_view_lesson`, `edit_posts`)
  - `Support\Logger` and `Auth\RestAuthenticator`
  - `Services\Enrollment\EnrollmentService::has_active_access()` (read-only gate) and `Learn\EntityHierarchy::resolveCourse()`
  - `Learn\Progression\CurriculumOrder::for_course()` (read-only, for the order and titles of a course's lessons in the reports) — root `CLAUDE.md` domain invariant 8 makes the curriculum walk a three-way invariant, so a feature reads it and never re-derives the order
  - `Database\SchemaManager` (its own table) and the four extension actions above
  - the table names and meta keys from `docs/DATA-MODEL.md`, read as constants — `vl_enrollments`, `vl_quiz_attempts`, `vl_session_attendance` are read, never written

  It never calls any other `core` service or repository.
- A feature never writes another feature's data (each `FEATURE.md` → Data):
  - `_vl_import_id`, `_vl_import_source` and `uploads/vl-lms-import/` belong to `course-import`.
  - `_vl_demo_seed` belongs to `core`'s seeder.
  - `vl_study_time` belongs to `study-time`, written only by its heartbeat handler; `vl_enrollments.started_at` stays `core`'s (`ProgressService`).

## Area conventions
- PSR-4: `VL\LMS\` → `src/`, `VL\LMS\Tests\` → `tests/`; one class per file; `declare(strict_types=1);` in every PHP file.
- WPCS WordPress-Extra via `phpcs.xml.dist`: errors fail the lint, warnings do not. PHPStan level 6 via `phpstan.neon.dist`.
- Prefixes `vl_lms` / `VL_LMS` / `VL\LMS`; text domain `vl-lms`. Every user-facing string is Ukrainian, wrapped in `__()` / `esc_html__()` / `esc_attr__()`.
- Class docblocks carry `@author Tymofii Synianskyi`.
- Classes are `final` by default. They are non-`final` only where a unit test subclasses a protected seam (`redirect_back()`, `fetch_users()` … — `docs/TESTING.md`).
- SQL only inside repositories, through `$wpdb->prepare()`. A multi-line SQL string with mid-string interpolated table names gets a `phpcs:disable` / `phpcs:enable` pair with a justification, not a one-line `phpcs:ignore`.
- Unit tests mirror `src/` under `tests/Unit/` (Brain Monkey, no WordPress). `phpunit.xml.dist` fails on warnings, risky tests and output; execution order is random.
- wp-admin screens use core markup (`wrap`, `form-table`, `notice`, `wp-list-table`). `admin-post.php` handlers check the cap, then `check_admin_referer()`, and redirect through a protected seam (`Admin\Settings\SettingsPage` is the reference).

## Local commands
```bash
# from backend/ — `ddev composer` / `ddev exec` start in /var/www/html, so name the plugin directory
ddev composer --working-dir=wp-content/plugins/vl-lms test|lint|stan
ddev exec --dir /var/www/html/wp-content/plugins/vl-lms vendor/bin/phpunit --filter <test_name>
# the commit gate, from the root repo
scripts/check.sh --backend
```
