# Phase 5.1 Smoke

Manual smoke checks for the Phase 5.1 lesson + topic read endpoints. Run inside DDEV. The backend must be active at `https://green-paws-lms-backend.ddev.site/`.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
AUTH="https://green-paws-lms-backend.ddev.site/wp-json/vl-auth/v1"
```

## 0. Setup

Reuses the seeded student and course fixtures from `PHASE-3.1-SMOKE.md` and `PHASE-4.1-SMOKE.md`.

You will need:

- One published `vl_course` with at least two `vl_lesson` children. Lesson #1 should expose a topic (`vl_topic`) child for the topic-route recipes.
- Slugs assumed below — substitute your own:
  - course slug: `feline-cardio`
  - first lesson slug: `intro-to-cardiology`
  - second lesson slug: `cardio-anatomy`
  - first lesson's topic slug: `anatomy-of-feline-heart`
  - a preview-flagged lesson slug: `preview-lesson`
  - a draft course's lesson slug: `draft-course-lesson`

If you do not already have these, seed them via `wp post create` and `wp post meta set`:

```sh
ddev wp post meta set <preview-lesson-id> _vl_lesson_is_preview 1
ddev wp post meta set <gating-lesson-id> _vl_lesson_requires_completion 1
```

## 1. Acquire JWTs

A regular `student`:

```sh
JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"student@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')
```

A `subscriber` (no `vl_view_lesson` cap):

```sh
SUB_JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"subscriber@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')
```

## 2. Recipes

### 2.1 Guest (no token) → 401

```sh
curl -i "$BASE/learn/lessons/intro-to-cardiology" | head -1
# → HTTP/2 401

curl -s "$BASE/learn/lessons/intro-to-cardiology" | jq '.code'
# → "rest_not_logged_in"
```

(Code from WP REST core; the `permission_callback` returns `false`, which WP maps to the standard 401 envelope.)

### 2.2 Authed without `vl_view_lesson` cap → 403 `rest_forbidden`

```sh
curl -i -H "Authorization: Bearer $SUB_JWT" \
  "$BASE/learn/lessons/intro-to-cardiology" | head -1
# → HTTP/2 403

curl -s -H "Authorization: Bearer $SUB_JWT" \
  "$BASE/learn/lessons/intro-to-cardiology" | jq '.code'
# → "rest_forbidden"
```

### 2.3 Student not enrolled, lesson not preview → 403 `not_enrolled`

Pick a course the student has never enrolled in:

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/cardio-anatomy" | head -1
# → HTTP/2 403

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/cardio-anatomy" | jq '.code'
# → "not_enrolled"
```

### 2.4 Student not enrolled, lesson IS preview → 200

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/preview-lesson" | head -1
# → HTTP/2 200

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/preview-lesson" \
  | jq '{success: .success, is_preview: .data.is_preview, slug: .data.slug}'
```

`is_preview` is `true`. The `progress` field defaults to `not_started`.

### 2.5 Student enrolled, no prerequisite → 200

Enroll the student first if needed:

```sh
COURSE_ID=$(ddev wp post list --post_type=vl_course --name=feline-cardio --field=ID)
curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" >/dev/null

curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/intro-to-cardiology" | head -1
# → HTTP/2 200

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/intro-to-cardiology" \
  | jq '{
    id: .data.id,
    slug: .data.slug,
    course_slug: .data.course.slug,
    has_module: (.data.module != null),
    block_types: [.data.content.blocks[].type],
    topics_count: (.data.topics | length),
    progress_status: .data.progress.status
  }'
```

`block_types` lists the discriminator for each block (e.g. `["paragraph","heading"]`).

### 2.6 Student enrolled, prerequisite not completed → 403 `prerequisite_not_completed`

The previous lesson (in `menu_order`) has `_vl_lesson_requires_completion=1` and the student hasn't completed it yet:

```sh
ddev wp post meta set <lesson-1-id> _vl_lesson_requires_completion 1

curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/cardio-anatomy" | head -1
# → HTTP/2 403

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/cardio-anatomy" | jq '.code'
# → "prerequisite_not_completed"
```

### 2.7 Student enrolled, previous lesson completed → 200

Seed a `completed` progress row for the prerequisite lesson, then re-request the gated lesson:

```sh
USER_ID=$(ddev wp user get student@example.test --field=ID)
PRIOR_ID=$(ddev wp post list --post_type=vl_lesson --name=intro-to-cardiology --field=ID)

ddev wp db query "INSERT INTO wp_vl_progress \
  (user_id, entity_type, entity_id, course_id, status, completed_at, last_seen_at, created_at, updated_at) \
  VALUES ($USER_ID, 'lesson', $PRIOR_ID, $COURSE_ID, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"

curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/cardio-anatomy" | head -1
# → HTTP/2 200
```

### 2.8 Lesson slug doesn't exist → 404 `lesson_not_found`

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/no-such-lesson" | head -1
# → HTTP/2 404

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/no-such-lesson" | jq '.code'
# → "lesson_not_found"
```

### 2.9 Lesson exists but parent course is `draft` → 404 `course_unpublished`

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/draft-course-lesson" | head -1
# → HTTP/2 404

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/draft-course-lesson" | jq '.code'
# → "course_unpublished"
```

### 2.10 Topic happy path → 200

The student is enrolled in the parent course (recipe 2.5 already covered that):

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/anatomy-of-feline-heart" | head -1
# → HTTP/2 200

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/anatomy-of-feline-heart" \
  | jq '{
    id: .data.id,
    slug: .data.slug,
    has_lesson_ref: (.data.lesson != null),
    has_topics_field: (.data | has("topics")),
    has_attachments_field: (.data | has("attachments"))
  }'
```

`has_lesson_ref` is `true`; `has_topics_field` and `has_attachments_field` are `false` (the topic shape omits both).

### 2.11 Topic on un-enrolled course → 403 `not_enrolled`

Pick a topic whose parent course the student has never enrolled in:

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/some-other-course-topic" | head -1
# → HTTP/2 403

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/some-other-course-topic" | jq '.code'
# → "not_enrolled"
```

### 2.12 Topic slug doesn't exist → 404 `topic_not_found`

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/no-such-topic" | head -1
# → HTTP/2 404

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/topics/no-such-topic" | jq '.code'
# → "topic_not_found"
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0 (lint may emit warnings — those are pre-existing and tolerated).
