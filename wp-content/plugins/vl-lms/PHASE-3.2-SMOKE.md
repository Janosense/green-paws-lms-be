# Phase 3.2 Smoke

Manual smoke checks for the Phase 3.2 catalog detail endpoints. Run inside DDEV. The backend must be active at `https://green-paws-lms-backend.ddev.site/`.

All curl commands assume `--silent --show-error` is fine to swap in if you want quieter output. Pipe through `jq` for readability when available.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
```

## 0. Setup

Phase 3.2 builds on the same fixtures Phase 3.1 used; if you already followed `PHASE-3.1-SMOKE.md` you can reuse those posts.

### 0.1 Hero image regeneration on upgrade

Phase 3.2 registers a new `vl_hero` image size (1920×720, hard crop). WordPress only generates an image size at upload time, so existing cover attachments uploaded before this phase will not have a `hero` URL until you regenerate them:

```sh
ddev wp media regenerate --image_size=vl_hero --yes
```

The frontend treats the `hero` key as optional — if it's missing, the cover transformer simply omits the key and SEO `og_image` falls back to `full` → `card`. Regeneration is therefore a non-blocking upgrade step.

### 0.2 Create one course with full landing-page coverage

```sh
ddev wp post create --post_type=vl_course --post_status=publish \
  --post_title="Cardiology Fundamentals" \
  --post_content="<p>Welcome to the cardiology fundamentals course. This page is rendered through <code>apply_filters('the_content', …)</code> so shortcodes and embeds work.</p>" \
  --post_excerpt="Hands-on cardiology overview spanning ten hours of lectures and labs."
# → returns the post ID, e.g. 42

ddev wp post meta update 42 _vl_course_type cohort
ddev wp post meta update 42 _vl_course_price 1500.00
ddev wp post meta update 42 _vl_course_currency UAH
ddev wp post meta update 42 _vl_course_duration_hours 10.5
ddev wp post meta update 42 _vl_course_enrollment_open 1
ddev wp post meta update 42 _vl_course_enrollment_opens_at 2026-05-01T08:00:00Z
ddev wp post meta update 42 _vl_course_enrollment_closes_at 2026-05-15T20:00:00Z
ddev wp post meta update 42 _vl_course_starts_at 2026-06-01T09:00:00Z
ddev wp post meta update 42 _vl_course_ends_at 2026-08-01T18:00:00Z
ddev wp post meta update 42 _vl_course_max_students 100
ddev wp post meta update 42 _vl_course_preview_video_url "https://preview.test/cardiology"
ddev wp post meta update 42 _vl_course_certificate_enabled 1
ddev wp post meta update 42 _vl_course_passing_threshold 80
```

### 0.3 Create one webinar with full landing-page coverage

```sh
ddev wp post create --post_type=vl_webinar --post_status=publish \
  --post_title="Spring Cardiology Roundtable" \
  --post_content="<p>Live Q&A with field experts.</p>" \
  --post_excerpt="Live cardiology Q&A — 90 minutes."
# → e.g. 43

ddev wp post meta update 43 _vl_webinar_status scheduled
ddev wp post meta update 43 _vl_webinar_price 500.00
ddev wp post meta update 43 _vl_webinar_currency UAH
ddev wp post meta update 43 _vl_webinar_scheduled_start 2027-06-01T10:00:00Z
ddev wp post meta update 43 _vl_webinar_scheduled_end 2027-06-01T12:00:00Z
ddev wp post meta update 43 _vl_webinar_max_attendees 200
ddev wp post meta update 43 _vl_webinar_registration_opens_at 2027-04-01T00:00:00Z
ddev wp post meta update 43 _vl_webinar_registration_closes_at 2027-05-30T23:59:00Z
ddev wp post meta update 43 _vl_webinar_recording_access_days 30
ddev wp post meta update 43 _vl_webinar_recording_url "https://internal.test/rec.mp4"   # private, must NOT leak
ddev wp post meta update 43 _vl_webinar_zoom_join_url "https://internal.test/join"      # private, must NOT leak
```

### 0.4 Add a bio to the lead instructor

```sh
ddev wp user meta update 7 vl_instructor_bio "<p>Olena is a board-certified <strong>cardiologist</strong> with 15 years of clinical experience.</p>"
```

### 0.5 Build a curriculum (modules → lessons + an orphan lesson)

```sh
# Module under the course
ddev wp post create --post_type=vl_module --post_status=publish \
  --post_title="Module 1: Heart Anatomy" --post_parent=42 --menu_order=1
# → e.g. 50
ddev wp post meta update 50 _vl_module_duration_minutes 180
ddev wp post meta update 50 _vl_module_passing_threshold 70
ddev wp post meta update 50 _vl_module_intro_video_url "https://intro.test/heart"

# Lesson under that module
ddev wp post create --post_type=vl_lesson --post_status=publish \
  --post_title="Lesson 1: External anatomy" --post_parent=50 --menu_order=1
# → e.g. 51
ddev wp post meta update 51 _vl_lesson_duration_seconds 600
ddev wp post meta update 51 _vl_lesson_is_preview 1
ddev wp post meta update 51 _vl_lesson_video_url "https://internal.test/secret.mp4"  # NOT exposed in detail

# Orphan lesson directly under the course (module-less)
ddev wp post create --post_type=vl_lesson --post_status=publish \
  --post_title="Quick orientation" --post_parent=42 --menu_order=1
# → e.g. 60
ddev wp post meta update 60 _vl_lesson_duration_seconds 120
```

### 0.6 Add webinar materials

```sh
ddev wp post meta update 43 _vl_webinar_materials --format=json '[{"url":"https://example.test/slides.pdf","name":"Slides","size":1234567}]'
```

---

## 1. Course detail — happy path

```sh
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq .
```

Expected envelope shape:

```json
{
  "success": true,
  "data": {
    "id": 42,
    "slug": "cardiology-fundamentals",
    "title": "Cardiology Fundamentals",
    "type": "cohort",
    "duration_hours": 10.5,
    "price": 1500,
    "currency": "UAH",
    "enrollment_opens_at": "2026-05-01T08:00:00Z",
    "starts_at": "2026-06-01T09:00:00Z",
    "max_students": 100,
    "certificate_enabled": true,
    "passing_threshold": 80,
    "instructors": [{ "id": 7, "role_in_course": "lead", "bio": "<p>Olena is a board-certified…</p>", "...": "" }],
    "curriculum": {
      "modules": [
        {
          "id": 50,
          "title": "Module 1: Heart Anatomy",
          "lessons": [
            { "id": 51, "title": "Lesson 1: External anatomy", "duration_seconds": 600, "is_preview": true }
          ]
        }
      ],
      "orphan_lessons": [
        { "id": 60, "title": "Quick orientation", "duration_seconds": 120, "is_preview": false }
      ]
    },
    "seo": {
      "title": "Cardiology Fundamentals | Green Paws LMS",
      "canonical_path": "/courses/cardiology-fundamentals",
      "og_image": "https://…/cover-1920x720.jpg",
      "description": "Hands-on cardiology overview…"
    }
  }
}
```

## 2. Module-less course → only `orphan_lessons` populated

```sh
ddev wp post create --post_type=vl_course --post_status=publish --post_title="Quick Refresher"
# → e.g. 80

ddev wp post create --post_type=vl_lesson --post_status=publish --post_title="Refresher Lesson 1" --post_parent=80
# → e.g. 81

curl "$BASE/catalog/courses/quick-refresher" | jq '.data.curriculum'
# → "modules": [], "orphan_lessons": [ { "id": 81, ... } ]
```

## 3. Course with only modules → `orphan_lessons: []`

After running step 0.5 against course 42 above, an empty-orphans variant lands when no lessons hang directly off the course:

```sh
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '.data.curriculum | { modules: (.modules | length), orphan_lessons: .orphan_lessons }'
# → modules: 1, orphan_lessons: []  (after deleting post 60)
```

## 4. Certificate disabled → `passing_threshold: null`

```sh
ddev wp post meta update 42 _vl_course_certificate_enabled 0
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '{cert: .data.certificate_enabled, threshold: .data.passing_threshold}'
# → { "cert": false, "threshold": null }
```

## 5. Cohort vs self-paced schedule fields

```sh
ddev wp post meta update 42 _vl_course_type cohort
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '{type: .data.type, starts: .data.starts_at, ends: .data.ends_at}'
# → "cohort", non-null dates

ddev wp post meta update 42 _vl_course_type self_paced
ddev wp post meta delete 42 _vl_course_starts_at
ddev wp post meta delete 42 _vl_course_ends_at
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '{type: .data.type, starts: .data.starts_at, ends: .data.ends_at}'
# → "self_paced", null, null
```

## 6. Two instructors with mixed roles and bios

```sh
ddev wp post term set 42 vl_category cardiology   # if needed
# Add a co-instructor
ddev wp eval 'wp_insert_post(["import_id"=>0]); global $wpdb; $wpdb->insert($wpdb->prefix."vl_course_instructors", ["entity_type"=>"course","entity_id"=>42,"user_id"=>8,"role_in_course"=>"co_instructor","display_order"=>1,"assigned_at"=>gmdate("Y-m-d H:i:s"),"assigned_by"=>1]);'
ddev wp user meta update 8 vl_instructor_bio ""

curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '.data.instructors | map({id, role_in_course, has_bio: (.bio != "")})'
# → [{lead, has_bio: true}, {co_instructor, has_bio: false}]
```

## 7. 404 for unknown slug

```sh
curl -i "$BASE/catalog/courses/no-such-slug" | head -1
# → HTTP/2 404
curl "$BASE/catalog/courses/no-such-slug" | jq '.code'
# → "vl_lms_not_found"
```

## 8. Cross-type slug lookups return 404

The course slug must not be retrievable through the webinar route, and vice versa:

```sh
curl -i "$BASE/catalog/webinars/cardiology-fundamentals" | head -1
# → HTTP/2 404
curl -i "$BASE/catalog/courses/spring-cardiology-roundtable" | head -1
# → HTTP/2 404
```

## 9. Webinar privacy — no zoom / recording leakage

```sh
curl "$BASE/catalog/webinars/spring-cardiology-roundtable" | jq 'keys'
# Verify: no "recording_url", "zoom_meeting_id", "zoom_join_url", etc. in the response keys.
```

A more thorough grep:

```sh
curl "$BASE/catalog/webinars/spring-cardiology-roundtable" | jq -r 'tostring' | grep -E "recording_url|zoom" || echo "OK — no zoom or recording_url leak"
```

## 10. `recording_offered` flag responds to `_vl_webinar_recording_access_days`

```sh
ddev wp post meta update 43 _vl_webinar_recording_access_days 30
curl "$BASE/catalog/webinars/spring-cardiology-roundtable" | jq '{offered: .data.recording_offered, days: .data.recording_access_days}'
# → { "offered": true, "days": 30 }

ddev wp post meta update 43 _vl_webinar_recording_access_days 0
curl "$BASE/catalog/webinars/spring-cardiology-roundtable" | jq '{offered: .data.recording_offered, days: .data.recording_access_days}'
# → { "offered": false, "days": 0 }
```

## 11. Webinar materials round-trip

```sh
curl "$BASE/catalog/webinars/spring-cardiology-roundtable" | jq '.data.materials'
# → [ { "url": "https://example.test/slides.pdf", "name": "Slides", "size": 1234567 } ]
```

## 12. Hero cover size when generated

After uploading a wide cover (≥ 1920×720) and assigning it via `_vl_{course,webinar}_cover_image_id`, then running `media regenerate`:

```sh
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '.data.cover | keys'
# → ["card","full","hero","thumbnail"]
```

If you skip `media regenerate` on a pre-3.2 install, the `hero` key is simply absent — a deliberate, non-fabricating fallback.

## 13. `og_image` precedence (hero → full → card → null)

Three quick variants you can exercise by switching `_vl_{course,webinar}_cover_image_id` to attachments where:

1. The `vl_hero` size has been generated → `og_image` returns the hero URL.
2. Only `medium_large` and `full` exist → `og_image` returns the `full` URL.
3. Only `thumbnail` and `medium_large` exist → `og_image` returns the `card` URL (`medium_large`).
4. No cover assigned (`0`) → `og_image: null`.

```sh
curl "$BASE/catalog/courses/cardiology-fundamentals" | jq '.data.seo.og_image'
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0.
