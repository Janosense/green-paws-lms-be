# Phase 4.1 Smoke

Manual smoke checks for the Phase 4.1 enrollment endpoints. Run inside DDEV. The backend must be active at `https://green-paws-lms-backend.ddev.site/`.

All curl commands assume `--silent --show-error` is fine to swap in if you want quieter output. Pipe through `jq` for readability when available.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
AUTH="https://green-paws-lms-backend.ddev.site/wp-json/vl-auth/v1"
```

## 0. Setup

Reuses the course fixtures from Phase 3.1 / 3.2. If you have not already done so, run through `PHASE-3.1-SMOKE.md` § 0.

For the capacity branch (§ 6) we need a course whose `_vl_course_max_students` is set and is currently full. Create a tiny-capacity free course and seed an enrollment row directly so the seat is taken without burning a real test user:

```sh
ddev wp post create --post_type=vl_course --post_status=publish \
  --post_title="Capacity Test Course" \
  --post_name="capacity-test"
ddev wp post meta set "$(ddev wp post list --post_type=vl_course --name=capacity-test --field=ID)" \
  _vl_course_price 0
ddev wp post meta set "$(ddev wp post list --post_type=vl_course --name=capacity-test --field=ID)" \
  _vl_course_enrollment_open 1
ddev wp post meta set "$(ddev wp post list --post_type=vl_course --name=capacity-test --field=ID)" \
  _vl_course_max_students 1

# Seat-occupier — anybody but the smoke-test student.
SEAT_USER_ID=$(ddev wp user create seat-occupier seat@example.test \
  --role=student --user_pass=hunter2hunter2 --porcelain)
COURSE_ID=$(ddev wp post list --post_type=vl_course --name=capacity-test --field=ID)
ddev wp db query "INSERT INTO wp_vl_enrollments \
  (user_id, course_id, status, source, enrolled_at, progress_pct, created_at, updated_at) \
  VALUES ($SEAT_USER_ID, $COURSE_ID, 'active', 'manual', UTC_TIMESTAMP(), 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
```

For the `enrollment_closed` branch (§ 5) flip the open flag on a different course:

```sh
ddev wp post meta set <free-course-id> _vl_course_enrollment_open 0
```

For the paid branch (§ 4) point at any course where `_vl_course_price > 0`. Phase 3.1 fixtures already include one.

## 1. Acquire JWT

```sh
JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"student@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')
echo "$JWT" | head -c 32; echo
```

A non-empty token. Subsequent calls send `Authorization: Bearer $JWT`.

For the 403 branch (§ 8) acquire a separate JWT with a `subscriber`-only account:

```sh
SUB_JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"subscriber@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')
```

(Create the subscriber once with `ddev wp user create sub subscriber@example.test --role=subscriber --user_pass=hunter2hunter2`.)

## 2. Happy path — POST a free course

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | head -1
# → HTTP/2 201

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" \
  | jq '{
    success: .success,
    id: .data.id,
    status: .data.status,
    source: .data.source,
    course_slug: .data.course.slug,
    has_cover: (.data.course.cover != null)
  }'
```

Expected envelope: `{ success: true, data: EnrollmentRecord }`. Status `active`, source `self_signup`, `course.slug` equals the slug of the requested course.

## 3. Idempotent — POST again returns 200

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | head -1
# → HTTP/2 200

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" \
  | jq '.data.id'
# → same id as in § 2
```

The validation pipeline is skipped on the idempotent path — even tightening capacity / closing enrollment / flipping the price afterward must keep returning the existing record (re-test by repeating § 4–6 setup, then re-running this curl).

## 4. Paid course → 402

```sh
PAID_ID=$(ddev wp post list --post_type=vl_course \
  --meta_key=_vl_course_price --meta_compare='>' --meta_value=0 \
  --field=ID | head -1)

curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $PAID_ID}" | head -1
# → HTTP/2 402

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $PAID_ID}" | jq '.code'
# → "payment_required"
```

## 5. Enrollment closed → 422

Flip `_vl_course_enrollment_open` to `0` on a free course (different from the one in § 2 — a course you have not yet enrolled in, so the idempotent path doesn't short-circuit).

```sh
CLOSED_ID=$(ddev wp post list --post_type=vl_course \
  --meta_key=_vl_course_enrollment_open --meta_value=0 \
  --field=ID | head -1)

curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $CLOSED_ID}" | head -1
# → HTTP/2 422

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $CLOSED_ID}" | jq '.code'
# → "enrollment_closed"
```

The same code is returned for `now > closes_at`. Set `_vl_course_enrollment_closes_at` to `2024-01-01T00:00:00Z` on a different course and repeat.

The `enrollment_not_open` branch (status 422) is reached by setting `_vl_course_enrollment_opens_at` to a far-future ISO 8601 timestamp.

## 6. Capacity full → 422

Use the seat-already-taken course set up in § 0:

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | head -1
# → HTTP/2 422

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | jq '.code'
# → "enrollment_full"
```

Note: this assumes the smoke-test student has not already taken the seat in §2. If §2 was run against this same course, the idempotent path returns 200 instead — increase `_vl_course_max_students` to 1 and re-seat with a different occupier.

## 7. Missing Authorization → 401

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | head -1
# → HTTP/2 401

curl -s -X POST "$BASE/enrollments" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | jq '.code'
# → "rest_not_logged_in"
```

## 8. Subscriber JWT → 403

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $SUB_JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | head -1
# → HTTP/2 403

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $SUB_JWT" \
  -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}" | jq '.code'
# → "rest_forbidden"
```

## 9. Invalid `course_id` → 400

```sh
curl -i -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"course_id": 0}' | head -1
# → HTTP/2 400

curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"course_id": 0}' | jq '.code'
# → "invalid_course_id"
```

Negative integers and non-numeric strings emit the same shape. A positive integer for a non-existent post emits `course_not_found` (404).

## 10. GET /enrollments/me → sorted list

```sh
curl -i -H "Authorization: Bearer $JWT" "$BASE/enrollments/me" | head -1
# → HTTP/2 200

curl -s -H "Authorization: Bearer $JWT" "$BASE/enrollments/me" | jq '{
  success: .success,
  count: (.data.items | length),
  first_id: .data.items[0].id,
  ordered_desc: ([.data.items[].enrolled_at] | . == sort | not)
}'
```

`items` is in `enrolled_at DESC` order (newest first). Revoked / expired enrollments are filtered out.

## 11. GET /enrollments/me without Authorization → 401

```sh
curl -i "$BASE/enrollments/me" | head -1
# → HTTP/2 401

curl -s "$BASE/enrollments/me" | jq '.code'
# → "rest_not_logged_in"
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0.
