# vl-lms — code-area conventions

The `vl-lms` plugin, a multi-feature code area: `core` (the LMS domain: CPTs, custom tables, the `vl/v1` REST API, the wp-admin LMS surface) and `course-import` (the wp-admin Markdown course importer). Feature docs live in the root repo, `docs/features/{core,course-import}/`.

## Feature isolation
- `core` owns everything under `src/` outside the `course-import` directories. Its entry is `Plugin` (`build_container()` + `boot()`).
- `course-import` lives in `src/Import/` (domain, bootstrap `Import\ImportProvider::boot()`) and `src/Admin/Import/` (wp-admin screens and `admin-post.php` handlers). Its tests go in `tests/Unit/Import/` and `tests/Unit/Admin/Import/`, its fixtures in `tests/Fixtures/Import/`.
- `Plugin` carries exactly one registration per non-core feature: one `$container->set()` in `build_container()` and one boot call in `boot()`. The only other wiring `course-import` gets in `core` is its `add_submenu_page` in `Admin\Menu\AdminMenuProvider` (constructor argument + factory) — `docs/DECISIONS.md` 2026-09-11.
- Shared code (`Plugin`, `Admin\AdminProvider`, `Admin\Menu\AdminMenuProvider`, `Roles\*`, `Support\*`, `Slug\*`, `composer.json` and the tool configs) changes ONLY in an explicit plan task marked **"touches shared code — may affect other features"** that names the consuming features.
- `course-import` reaches `core` only through:
  - WordPress core functions
  - `Roles\CapabilitiesMap` caps
  - `Support\Logger`
  - `Slug\CyrillicTransliterator`
  - the CPT slugs, meta keys and taxonomy slugs from `docs/DATA-MODEL.md`, read as constants

  It never calls `core` services or repositories.
- A feature never writes another feature's data (each `FEATURE.md` → Data):
  - `_vl_import_id`, `_vl_import_source` and `uploads/vl-lms-import/` belong to `course-import`.
  - `_vl_demo_seed` belongs to `core`'s seeder.

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
