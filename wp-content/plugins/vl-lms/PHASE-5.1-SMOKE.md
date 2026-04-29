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

## Phase 5.2 — Curriculum endpoint

`GET /vl/v1/learn/courses/{slug}/curriculum` returns the personalised
navigation tree (modules → lessons → topics), the caller's enrollment
metadata, total course duration summed from leaves, and a `next_entity`
hint pointing at the first not-yet-completed leaf.

Reuses the JWTs from §1.

### 12. Guest (no token) → 401

```sh
curl -i "$BASE/learn/courses/feline-cardio/curriculum" | head -1
# → HTTP/2 401
```

### 13. Authed `subscriber` (no `vl_view_lesson` cap) → 403 `rest_forbidden`

```sh
curl -s -H "Authorization: Bearer $SUB_JWT" \
  "$BASE/learn/courses/feline-cardio/curriculum" | jq '.code'
# → "rest_forbidden"
```

### 14. Authed `student`, not enrolled → 403 `not_enrolled`

```sh
curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/feline-cardio/curriculum" | jq '.code'
# → "not_enrolled"
```

(Run before any POST to `/enrollments` — see Phase 4.1 for self-enroll.)

### 15. Authed `student`, enrolled — full curriculum

After enrolling the student in the course (`POST /vl/v1/enrollments` with
the course ID, see Phase 4.1):

```sh
curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/feline-cardio/curriculum" | jq '{
    success,
    course: .data.course | {id, slug, title, duration_seconds, enrollment},
    module_count: (.data.modules | length),
    orphan_count: (.data.orphan_lessons | length),
    next: .data.next_entity
  }'
# → {
#     "success": true,
#     "course": {
#       "id": 100,
#       "slug": "feline-cardio",
#       "title": "Feline Cardiology",
#       "duration_seconds": <sum-of-leaves>,
#       "enrollment": { "status": "active", "progress_pct": 0, "enrolled_at": "...", "completed_at": null }
#     },
#     "module_count": <n>,
#     "orphan_count": <n>,
#     "next": { "type": "lesson"|"topic", "id": ..., "slug": "...", "lesson_slug": "..." }
#   }
```

`course.duration_seconds` should equal the sum of:
- each lesson's `duration_seconds` when the lesson has no topics, plus
- each lesson's topics' summed `duration_seconds` when the lesson has topics.

### 16. All lessons completed → `next_entity` is `null`

After marking every leaf complete (5.3 will provide the write endpoint;
for now you can manually `INSERT INTO wp_vl_progress` rows or run the
seed script):

```sh
curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/feline-cardio/curriculum" | jq '.data.next_entity'
# → null
```

### 17. Course with only orphan lessons (no modules)

For a course where every lesson hangs off the course directly (no
`vl_module` children):

```sh
curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/orphan-only-course/curriculum" | jq '{
    modules: .data.modules,
    orphan_count: (.data.orphan_lessons | length),
    next: .data.next_entity
  }'
# → { "modules": [], "orphan_count": <n>, "next": { ... } }
```

### 18. Lesson with topics — topic-level fields visible

```sh
curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/feline-cardio/curriculum" | jq '
    [.data.modules[].lessons[]
      | select(.has_topics)
      | { id, slug, has_topics, topic_count: (.topics | length),
          topic_progress: [.topics[].progress.status] }]
  '
# → [{ "id": 123, "slug": "intro-to-cardiology", "has_topics": true,
#       "topic_count": <n>, "topic_progress": ["not_started"|"in_progress"|"completed", ...] }, ...]
```

### 19. Course slug doesn't exist → 404 `course_not_found`

```sh
curl -i -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/no-such-course/curriculum" | head -1
# → HTTP/2 404

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/no-such-course/curriculum" | jq '.code'
# → "course_not_found"
```

### 20. Course exists but is `draft` → 404 `course_not_found`

Draft courses do not surface a separate `course_unpublished` code — the
slug lookup misses entirely and the response is identical to slug-not-
found:

```sh
ddev wp post update <course-id> --post_status=draft

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/courses/<draft-slug>/curriculum" | jq '.code'
# → "course_not_found"
```

# POST /progress (Phase 5.3)

Single write endpoint that journals one lesson-player event, upserts
`vl_progress`, and (on `event_type=complete`) runs synchronous fan-up
through topic → lesson → module → course. Gated by `vl_view_lesson` plus
a course-level active enrollment.

The recipes assume the same `feline-cardio` fixture used above. Resolve
your concrete IDs once:

```sh
LESSON_ID=$(ddev wp post list --post_type=vl_lesson --name=cardio-anatomy --field=ID --format=ids)
TOPIC_ID=$(ddev wp post list --post_type=vl_topic --name=anatomy-of-feline-heart --field=ID --format=ids)
DRAFT_LESSON_ID=$(ddev wp post list --post_type=vl_lesson --post_status=draft --field=ID --format=ids | head -n1)

UUID="8c7e9f2a-2c1d-4d2c-9e89-3f5d2a3b4c5d"
```

### 1. Guest → 401

```sh
curl -i -X POST "$BASE/progress" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | head -1
# → HTTP/2 401
```

### 2. Authed `subscriber` (no `vl_view_lesson`) → 403 `rest_forbidden`

```sh
SUB_JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"subscriber@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')

curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $SUB_JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '.code'
# → "rest_forbidden"
```

### 3. Missing `entity_type` → 400 `invalid_payload`

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '{ code, message }'
# → { "code": "invalid_payload", "message": "Field 'entity_type' is required." }
```

### 4. Malformed UUID → 400 `invalid_payload`

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"not-a-uuid\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '.code'
# → "invalid_payload"
```

### 5. `entity_type=module` → 400 `invalid_payload`

Modules don't receive direct events. Backend rejects.

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"module\",\"entity_id\":1,\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '.code'
# → "invalid_payload"
```

### 6. Draft lesson → 404 `entity_not_found`

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${DRAFT_LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '.code'
# → "entity_not_found"
```

### 7. Published lesson, user not enrolled → 403 `not_enrolled`

```sh
NOENROLL_JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"unenrolled-student@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')

curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $NOENROLL_JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10}" \
  | jq '.code'
# → "not_enrolled"
```

### 8. First `progress` event on a fresh lesson → 201

```sh
curl -i -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":240}" \
  | head -1
# → HTTP/2 201

curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":240}" \
  | jq '.data.progress.status, .data.progress.position_seconds, .data.fanup.lesson_completed'
# → "in_progress"
# → 240
# → false
```

### 9. Smaller `progress` value does NOT shrink stored position

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":120}" \
  | jq '.data.progress.position_seconds'
# → 240   (preserved per write rule §key-decision-2)
```

### 10. `seek` overwrites the stored position

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"seek\",\"position_seconds\":60,\"payload\":{\"from\":240,\"to\":60}}" \
  | jq '.data.progress.position_seconds'
# → 60
```

### 11. `complete` on a topic-less lesson promotes the lesson

`cardio-anatomy` is the topic-less lesson in the fixture. Completion sets
`fanup.lesson_completed=true` and recomputes `course_progress_pct`.

```sh
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"complete\",\"position_seconds\":600}" \
  | jq '.data.progress.status, .data.fanup.lesson_completed, .data.fanup.course_progress_pct'
# → "completed"
# → true
# → <new pct>
```

### 12. Completing every topic of a lesson auto-promotes the lesson

`intro-to-cardiology` has topic children. Issue a `complete` for each
topic; the response on the LAST one carries `lesson_completed=true`
(promotion is implicit from the topic fan-up). Verify by re-reading the
lesson via the read endpoint.

```sh
for TID in $(ddev wp post list --post_type=vl_topic --post_parent="$LESSON_ID_INTRO" --field=ID --format=ids); do
  curl -s -X POST "$BASE/progress" \
    -H "Authorization: Bearer $JWT" \
    -H "Content-Type: application/json" \
    -d "{\"entity_type\":\"topic\",\"entity_id\":${TID},\"session_uuid\":\"$UUID\",\"event_type\":\"complete\",\"position_seconds\":300}" \
    | jq '.data.fanup.lesson_completed'
done
# Last iteration → true

curl -s -H "Authorization: Bearer $JWT" \
  "$BASE/learn/lessons/intro-to-cardiology" \
  | jq '.data.progress.status'
# → "completed"
```

### 13. Completing the final lesson in a no-final-exam course flips the enrollment

```sh
# After completing every leaf:
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LAST_LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"complete\",\"position_seconds\":600}" \
  | jq '.data.fanup'
# → { "lesson_completed": true, "module_completed": <bool>,
#     "course_progress_pct": 100, "course_completed": true }

curl -s -H "Authorization: Bearer $JWT" "$BASE/enrollments/me" \
  | jq '.data.items[] | select(.course_slug == "feline-cardio") | { status, progress_pct, completed_at }'
# → { "status": "completed", "progress_pct": 100, "completed_at": "<ISO>" }
```

### 14. `payload` over 4 KB → 413 `payload_too_large`

```sh
BIG=$(python3 -c 'print("a"*5000)')
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"progress\",\"position_seconds\":10,\"payload\":{\"blob\":\"$BIG\"}}" \
  | jq '.code'
# → "payload_too_large"
```

### 15. Re-completing a lesson preserves the original `completed_at`

The journal accepts duplicate `complete` events (no server-side dedup),
but `vl_progress.completed_at` is NOT overwritten on re-completion.

```sh
# First complete:
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$UUID\",\"event_type\":\"complete\",\"position_seconds\":600}" \
  | jq '.data.progress.completed_at'
# → "<original ISO>"

# Second complete (same lesson, fresh session):
curl -s -X POST "$BASE/progress" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"entity_type\":\"lesson\",\"entity_id\":${LESSON_ID},\"session_uuid\":\"$(uuidgen | tr A-Z a-z)\",\"event_type\":\"complete\",\"position_seconds\":600}" \
  | jq '.data.progress.completed_at, .data.fanup.lesson_completed'
# → "<original ISO>"   (NOT overwritten)
# → true               (already-complete is consistent)
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0 (lint may emit warnings — those are pre-existing and tolerated).
