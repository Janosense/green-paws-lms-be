# Phase 3.5 Smoke

Manual smoke checks for the Phase 3.5 cross-type search endpoint and the SEO polish that ships alongside it. Run inside DDEV. The backend must be active at `https://green-paws-lms-backend.ddev.site/`.

All curl commands assume `--silent --show-error` is fine to swap in if you want quieter output. Pipe through `jq` for readability when available.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
```

## 0. Setup

Phase 3.5 reuses the fixtures from Phase 3.1 / 3.2. If you have not already done so, run through `PHASE-3.1-SMOKE.md` § 0 and `PHASE-3.2-SMOKE.md` § 0 first.

For the title-vs-content priority check (§ 4 below) we need two extra posts: one whose title contains the search term and one whose body does. The Phase 3.1 setup already gives us the title-match course (`Cardiology Fundamentals`); add the content-only course:

```sh
ddev wp post create --post_type=vl_course --post_status=publish \
  --post_title="Anesthesia Basics" \
  --post_content="<p>Introduction to anesthesia, including a short cardiology aside.</p>" \
  --post_excerpt="Anesthesia basics."
```

## 1. Happy path — `q=cardiology`

```sh
curl "$BASE/search?q=cardiology" | jq '.success, (.data | keys)'
```

Expected:

```json
true
[ "courses", "q", "webinars" ]
```

```sh
curl "$BASE/search?q=cardiology" | jq '{
  q: .data.q,
  course_count: (.data.courses.items | length),
  webinar_count: (.data.webinars.items | length),
  course_pagination: .data.courses.pagination,
  webinar_pagination: .data.webinars.pagination
}'
```

Each section's `items` is byte-for-byte identical to the catalog list endpoint shape — no extra fields, no missing fields. `pagination` matches `CatalogPagination` (`page`, `per_page`, `total`, `total_pages`).

## 2. Empty `q` → 400

```sh
curl -i "$BASE/search?q=" | head -1
# → HTTP/2 400
curl "$BASE/search?q=" | jq '.code'
# → "vl_lms_search_q_required"
```

Whitespace-only query is the same shape:

```sh
curl -i --get --data-urlencode "q=   " "$BASE/search" | head -1
# → HTTP/2 400
```

Missing `q` (no parameter at all) is also rejected by the WP REST argument layer (`required: true`), but the response shape is the WP REST default `rest_missing_callback_param`. Either way the request never hits the controller body.

## 3. `&page=2` paginates both sub-arrays independently

Add enough cardiology-mention posts so each section has more than one page. With `per_page=1`:

```sh
curl "$BASE/search?q=cardiology&per_page=1&page=1" | jq '{
  course_page: .data.courses.pagination.page,
  course_total_pages: .data.courses.pagination.total_pages,
  webinar_page: .data.webinars.pagination.page,
  webinar_total_pages: .data.webinars.pagination.total_pages
}'

curl "$BASE/search?q=cardiology&per_page=1&page=2" | jq '{
  course_page: .data.courses.pagination.page,
  webinar_page: .data.webinars.pagination.page,
  course_first: .data.courses.items[0].slug,
  webinar_first: .data.webinars.items[0].slug
}'
```

Both `course_page` and `webinar_page` jump to `2` together — single shared page param. The card slugs returned are the second page of each section, computed independently of the other.

## 4. Title vs content priority

With the fixtures from § 0:

- `Cardiology Fundamentals` — term in the **title**.
- `Anesthesia Basics` — term in the **content** only.

```sh
curl "$BASE/search?q=cardiology" | jq '.data.courses.items | map(.slug)'
# → first slug is "cardiology-fundamentals" (title match outranks content match)
```

If the relevance ranker falls back to default WP ordering (date-only), the slug order is determined by `post_date DESC` instead. The endpoint stays shippable in that fallback — relevance is a quality-of-life improvement, not a blocker.

## 5. Drafts and other statuses are excluded

Create a draft course whose title contains the term and verify it does not appear:

```sh
ddev wp post create --post_type=vl_course --post_status=draft \
  --post_title="Draft Cardiology Atlas"

curl "$BASE/search?q=cardiology" | jq '.data.courses.items | map(.slug) | index("draft-cardiology-atlas")'
# → null
```

## 6. `per_page` clamping

```sh
curl "$BASE/search?q=cardiology&per_page=999" | jq '.data.courses.pagination.per_page'
# → 50  (PER_PAGE_MAX)

curl "$BASE/search?q=cardiology&per_page=0" | jq '.data.courses.pagination.per_page'
# → 1   (PER_PAGE_MIN)
```

## 7. Frontend SEO checks (validated against the Nuxt dev server)

`frontend/` must be running (`npm run dev`).

### 7.1 `/sitemap.xml`

```sh
curl http://localhost:3000/sitemap.xml | head -20
```

Expected: a valid `<urlset>` containing `<url>` entries for `/`, `/courses`, `/webinars`, and every published course/webinar slug. No auth routes, no `/account`, no `/dashboard`, no `/learn`, no `/search`.

### 7.2 `/robots.txt`

```sh
curl http://localhost:3000/robots.txt
```

Expected:

```
User-agent: *
Allow: /
Disallow: /login
Disallow: /register
…
Disallow: /search
Disallow: /*?return_to=

Sitemap: http://localhost:3000/sitemap.xml
```

### 7.3 JSON-LD on a course landing

```sh
curl -s http://localhost:3000/courses/cardiology-fundamentals \
  | grep -oE 'application/ld\+json[^<]*' | head -2
```

Expect at least two `<script type="application/ld+json">` blocks: one for the `Course` schema and one for `BreadcrumbList`.

### 7.4 JSON-LD on a webinar landing

```sh
curl -s http://localhost:3000/webinars/spring-cardiology-roundtable \
  | grep -oE 'application/ld\+json[^<]*' | head -2
```

Same shape, but the type-specific block is `Event`.

### 7.5 Validate at https://search.google.com/test/rich-results

Paste the rendered URL of one course landing and one webinar landing into the Rich Results Test. Both must validate without errors.

### 7.6 `/search` is `noindex,follow`

```sh
curl -s 'http://localhost:3000/search?q=cardiology' \
  | grep -oE '<meta[^>]+name="robots"[^>]*>'
# → <meta name="robots" content="noindex,follow">
```

Two layers (page-level `noindex` + `robots.txt` `Disallow: /search`) are intentional defence-in-depth.

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0.
