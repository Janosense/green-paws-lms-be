# Phase 3.1 Smoke

Manual smoke checks for the Phase 3.1 catalog list endpoints. Run inside DDEV. The backend must be active at `https://green-paws-lms-backend.ddev.site/`.

All curl commands assume `--silent --show-error` is fine to swap in if you want quieter output. Pipe through `jq` for readability when available.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
```

## 0. Setup helpers (one-time per smoke run)

Empty database (or freshly reset). If you have `jq`, all responses below benefit from `| jq .`.

Create one publishable course:

```sh
ddev wp post create --post_type=vl_course --post_status=publish \
  --post_title="Cardiology Fundamentals" \
  --post_content="Intro to cardiology." \
  --post_excerpt="Hands-on cardiology overview."
# → returns the post ID, e.g. 42

ddev wp post meta update 42 _vl_course_type self_paced
ddev wp post meta update 42 _vl_course_price 1500.00
ddev wp post meta update 42 _vl_course_currency UAH
ddev wp post meta update 42 _vl_course_duration_hours 10.5
ddev wp post meta update 42 _vl_course_enrollment_open 1
```

Create one publishable webinar:

```sh
ddev wp post create --post_type=vl_webinar --post_status=publish \
  --post_title="Spring Cardiology Update" \
  --post_excerpt="Live cardiology Q&A."
# → e.g. 43

ddev wp post meta update 43 _vl_webinar_status scheduled
ddev wp post meta update 43 _vl_webinar_price 0.00
ddev wp post meta update 43 _vl_webinar_currency UAH
ddev wp post meta update 43 _vl_webinar_scheduled_start 2027-06-01T10:00:00Z
ddev wp post meta update 43 _vl_webinar_scheduled_end 2027-06-01T12:00:00Z
```

Optional — assign a category, specialty, difficulty, and tag to the course (term names from the seed):

```sh
ddev wp term create vl_category Cardiology --slug=cardiology
ddev wp term create vl_specialty Therapist --slug=therapist
ddev wp post term set 42 vl_category cardiology
ddev wp post term set 42 vl_specialty therapist
ddev wp post term set 42 vl_difficulty basic
```

Optional — assign a co-instructor row so `lead_instructor` is populated. The `vl_course_instructors` table is mirrored from `post_author` automatically; if you set the post author, the lead row appears. To force it:

```sh
ddev wp user create instructor1 instructor1@example.test --role=instructor --user_pass='passw0rd!' --first_name=Olena --last_name=Petrenko
# Take the returned user ID, e.g. 7
ddev wp post update 42 --post_author=7
```

## 1. Empty catalog

Reset to an empty DB or use a fresh post type with no published rows.

```sh
curl "$BASE/catalog/courses" | jq .
```

Expected:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": { "page": 1, "per_page": 12, "total": 0, "total_pages": 0 }
  }
}
```

## 2. Single course present

```sh
curl "$BASE/catalog/courses" | jq '.data.items[0]'
```

Expected: a card with `id`, `slug = "cardiology-fundamentals"`, `price: 1500`, `currency: "UAH"`, `cover: null` (no cover meta set), `lead_instructor: null` (unless you ran the optional author-set step above).

## 3. Filter by category slug

Match:

```sh
curl "$BASE/catalog/courses?category[]=cardiology" | jq '.data.pagination.total'   # → 1
```

Mismatch:

```sh
curl "$BASE/catalog/courses?category[]=oncology" | jq '.data.pagination.total'     # → 0
curl "$BASE/catalog/courses?category[]=oncology" | jq '.data.items'                # → []
```

## 4. `sort=title-asc`

Create a second course and verify alphabetical order:

```sh
ddev wp post create --post_type=vl_course --post_status=publish --post_title="Anesthesia Basics"
curl "$BASE/catalog/courses?sort=title-asc" | jq '.data.items[].title'
# → "Anesthesia Basics" then "Cardiology Fundamentals"
```

## 5. `q=cardiology`

```sh
curl "$BASE/catalog/courses?q=cardiology" | jq '.data.pagination.total'   # → 1
```

## 6. Webinars `sort=upcoming` excludes past starts

Add a past webinar:

```sh
ddev wp post create --post_type=vl_webinar --post_status=publish --post_title="Last Year Webinar"
# → e.g. 44
ddev wp post meta update 44 _vl_webinar_status scheduled
ddev wp post meta update 44 _vl_webinar_scheduled_start 2024-01-01T10:00:00Z
```

Then:

```sh
curl "$BASE/catalog/webinars?sort=upcoming" | jq '.data.items[].slug'
# → only "spring-cardiology-update" (or whatever the future webinar's slug is)
```

## 7. `/taxonomies/{taxonomy}` with `in_use=1`

```sh
curl "$BASE/taxonomies/vl_category?post_type=vl_course&in_use=1" | jq '.data.items[].slug'
# → ["cardiology"]   (any category with no published course is filtered out)
```

## 8. Unknown taxonomy → 400

```sh
curl -i "$BASE/taxonomies/vl_unknown" | head -1
# → HTTP/2 400
curl "$BASE/taxonomies/vl_unknown" | jq '.code'
# → "vl_lms_invalid_taxonomy"
```

## 9. Unknown sort → 400

```sh
curl -i "$BASE/catalog/courses?sort=banana" | head -1
# → HTTP/2 400
curl "$BASE/catalog/courses?sort=banana" | jq '.code'
# → "vl_lms_invalid_sort"
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0.
