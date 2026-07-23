# VL Headless Theme

Minimal WordPress theme for the Veterinary LMS backend. The public site is rendered by a separate Nuxt.js app — this theme exists only to satisfy WordPress's "must have an active theme" requirement and to keep the admin comfortable for course editors.

## What it does

- **Redirects public requests** (302) to `VL_FRONTEND_URL`, preserving path and query string.
- **Strips WP head noise** — generator, RSD, WLW manifest, shortlinks, REST discovery link, emoji scripts.
- **Disables unused surface area** — XML-RPC, pingbacks, the WP default sitemap.
- **Blocks anonymous user enumeration** on `/wp-json/wp/v2/users`.
- **Cleans up the admin** — removes the "Posts" and "Comments" menu items, the WordPress News / Activity / Quick Draft dashboard widgets, and the WP logo from the toolbar. Custom footer with a link to the frontend.
- **Serves the brand favicon** on wp-admin and wp-login.php, and redirects direct `/favicon.ico` hits to the theme icon instead of WP's default blue-W fallback. Icons live in `assets/favicon/`.

## What it does **not** do

- It does not render any public page. Gutenberg/block-editor previews still work, and so does the REST API, but there is no theme template.
- It does not depend on `vl-jwt-auth`, `vl-lms`, or any other plugin. It activates cleanly on a stock WordPress install.
- It does not disable the block editor — course content is authored as blocks and parsed downstream by `vl-lms`.

## Configuration

Add the frontend URL to `wp-config.php`:

```php
define('VL_FRONTEND_URL', 'http://localhost:3000');        // dev
// define('VL_FRONTEND_URL', 'https://staging.vetlms.com'); // staging
// define('VL_FRONTEND_URL', 'https://app.vetlms.com');     // production
```

The theme deliberately has no default for `VL_FRONTEND_URL`. If it is missing, the theme stays inert (no redirects) and a warning notice is shown in the admin. This is intentional — we never want to silently redirect to a wrong URL.

## Permalinks (required)

Set **Settings → Permalinks** to **Post name** (`/%postname%/`) or any other pretty-permalink structure. The default "Plain" setting breaks REST URL generation and yields `guid`-style URLs that Nuxt cannot consume cleanly.

The theme does not force this programmatically because that would stomp on a legitimate admin choice — but it is a hard requirement.

## Paths that are NOT redirected

| Path                         | Reason                                  |
| ---------------------------- | --------------------------------------- |
| `/wp-admin/*`                | Admin must work                         |
| `/wp-login.php`, `/wp-signup.php` | Auth must work                     |
| `/wp-json/*`                 | REST API must work                      |
| AJAX (`/wp-admin/admin-ajax.php`) | Admin AJAX must work               |
| Post previews                | Editors need to preview content         |
| Customizer preview           | Not used, but harmless                  |
| `/robots.txt`                | Served by WP                            |
| Favicon                      | Redirected to the theme icon            |
| `/feed/`                     | Let WP serve RSS natively               |

## Project structure

```
vl-headless-theme/
├── style.css               # WP theme header
├── index.php               # JSON fallback for non-redirected public hits
├── functions.php           # Loads includes/ and boots the theme
├── readme.txt              # WP.org-style readme
├── README.md               # This file
├── .gitignore
├── assets/
│   └── favicon/            # Brand icons for wp-admin / wp-login
└── includes/
    ├── class-theme.php             # Bootstrap
    ├── class-frontend-redirect.php # template_redirect → VL_FRONTEND_URL
    ├── class-cleanup.php           # head + XML-RPC + sitemap cleanup
    ├── class-rest-hardening.php    # Anonymous user endpoint blocking
    ├── class-admin-ux.php          # Dashboard / menu / toolbar / footer
    └── class-favicon.php           # Admin/login favicon + /favicon.ico
```

## Requirements

- WordPress 6.4+
- PHP 8.4+
