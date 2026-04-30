# Phase 6.1 Smoke

Manual smoke checks for the Phase 6.1 quiz-attempt backend. Run inside DDEV against `https://green-paws-lms-backend.ddev.site/`.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
AUTH="https://green-paws-lms-backend.ddev.site/wp-json/vl-auth/v1"
```

## 0. Setup

These recipes assume a fresh DDEV with the demo seeder applied (`ddev wp vl-lms demo seed`). You will need at least:

- One published `vl_course` with at least one `vl_quiz` child reachable via lesson / module / session / direct chain.
- The quiz has 2+ published `vl_quiz_question` children covering a mix of types: at least one `single_choice`, one `multiple_choice`, one `true_false`, one `text`.
- Quiz CPT meta: `_vl_quiz_time_limit_seconds=600`, `_vl_quiz_passing_threshold=80`, `_vl_quiz_max_attempts=2`, `_vl_quiz_show_correct_answers=after_submit`.
- A second course whose `vl_quiz` is flagged `_vl_quiz_is_final_exam=1` (used for recipes 11 and 12).

Substitute the slugs / IDs below with your own:

```sh
QUIZ_SLUG="cardio-final-quiz"
EXAM_SLUG="cardio-final-exam"        # final-exam quiz for recipes 11, 12
COURSE_SLUG="feline-cardio"
EXAM_COURSE_SLUG="cardio-certificate-track"
```

## 1. Acquire JWTs

```sh
JWT=$(curl -s -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"student@example.test","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')

USER_ID=$(ddev wp user get student@example.test --field=ID)
QUIZ_ID=$(ddev wp post list --post_type=vl_quiz --name="$QUIZ_SLUG" --field=ID --format=ids)
EXAM_ID=$(ddev wp post list --post_type=vl_quiz --name="$EXAM_SLUG" --field=ID --format=ids)
COURSE_ID=$(ddev wp post list --post_type=vl_course --name="$COURSE_SLUG" --field=ID --format=ids)
EXAM_COURSE_ID=$(ddev wp post list --post_type=vl_course --name="$EXAM_COURSE_SLUG" --field=ID --format=ids)
```

Make sure the student is enrolled in `$COURSE_ID` and `$EXAM_COURSE_ID`:

```sh
curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
  -d "{\"course_id\": $COURSE_ID}"
curl -s -X POST "$BASE/enrollments" \
  -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
  -d "{\"course_id\": $EXAM_COURSE_ID}"
```

## 2. Recipes

### 1. Happy path — start a fresh attempt → 201

```sh
curl -i -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | head -1
# → HTTP/2 201

curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" \
  | jq '{
      id: .data.attempt.id,
      status: .data.attempt.status,
      time_remaining_seconds: .data.attempt.time_remaining_seconds,
      max_score: .data.attempt.max_score,
      questions_count: (.data.questions | length)
    }'
# → { "id": <N>, "status": "in_progress", "time_remaining_seconds": 600, "max_score": 10, "questions_count": 2 }
```

Save the attempt id for the rest:

```sh
ATTEMPT_ID=$(curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.attempt.id')
```

### 2. Idempotent start → 200

Calling start again on the same `(user, quiz)` returns the existing in-progress attempt.

```sh
curl -i -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | head -1
# → HTTP/2 200

curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" \
  | jq '.data.attempt.id'
# → <same as $ATTEMPT_ID>
```

### 3. Save answer — single choice → 200

Resolve the first question id:

```sh
Q1_ID=$(curl -s -X GET "$BASE/quizzes/attempts/$ATTEMPT_ID" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.questions[0].id')
```

```sh
curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$Q1_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_id": "a-uuid"}}' \
  | jq '{ expired: .data.expired, qid: .data.answer.question_id }'
# → { "expired": false, "qid": <Q1_ID> }
```

### 4. Save answer — overwrite (no duplicate row)

PATCH the same question with a different answer; verify only one row exists in the DB.

```sh
curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$Q1_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_id": "b-uuid"}}' \
  | jq '.data.answer.answer_data'
# → { "answer_id": "b-uuid" }

ddev wp db query \
  "SELECT COUNT(*) FROM wp_vl_quiz_answers WHERE attempt_id=$ATTEMPT_ID AND question_id=$Q1_ID"
# → 1   (upsert path — never duplicates)
```

### 5. Save answer — multiple choice → 200

```sh
Q2_ID=$(curl -s -X GET "$BASE/quizzes/attempts/$ATTEMPT_ID" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.questions[1].id')

curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$Q2_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_ids": ["a-uuid", "b-uuid"]}}' \
  | jq '.data.expired'
# → false
```

### 6a. Save answer — true/false → 200

```sh
QTF=<your true_false question id>

curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$QTF" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"value": true}}' \
  | jq '.data.answer.answer_data'
# → { "value": true }
```

### 6b. Save answer — text → 200

```sh
QTX=<your text question id>

curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$QTX" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"text": "fluffy"}}' \
  | jq '.data.answer.answer_data'
# → { "text": "fluffy" }
```

### 7. Submit — passing path → 200

After saving the correct answer for every question:

```sh
curl -s -X POST "$BASE/quizzes/attempts/$ATTEMPT_ID/submit" \
  -H "Authorization: Bearer $JWT" \
  | jq '{
      status: .data.attempt.status,
      score: .data.attempt.score,
      max_score: .data.attempt.max_score,
      passed: .data.attempt.passed,
      time_taken_seconds: .data.attempt.time_taken_seconds
    }'
# → { "status": "submitted", "score": 10, "max_score": 10, "passed": true, "time_taken_seconds": <N> }
```

The `saved_answers` array now carries `is_correct` + `points_awarded` per row (because the quiz's `_vl_quiz_show_correct_answers` is `after_submit`).

### 8. Submit — failing path → 200

Start a second attempt (`_vl_quiz_max_attempts=2` permits this). Save deliberately wrong answers, then submit:

```sh
ATTEMPT2_ID=$(curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.attempt.id')

# … PATCH wrong answers …

curl -s -X POST "$BASE/quizzes/attempts/$ATTEMPT2_ID/submit" \
  -H "Authorization: Bearer $JWT" \
  | jq '{ passed: .data.attempt.passed, score: .data.attempt.score }'
# → { "passed": false, "score": <below threshold> }
```

### 9. Time-limit auto-expire on save → 409 `attempt_expired`

Force the started_at into the past so the next save crosses the time limit:

```sh
ATTEMPT3_ID=$(curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.attempt.id')

# Quiz has time_limit_seconds=600 — push started_at 700 seconds back.
ddev wp db query \
  "UPDATE wp_vl_quiz_attempts SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 700 SECOND) WHERE id=$ATTEMPT3_ID"

curl -i -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT3_ID/answers/$Q1_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_id": "a-uuid"}}' | head -1
# → HTTP/2 409

curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT3_ID/answers/$Q1_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_id": "a-uuid"}}' \
  | jq '{ code, attempt_status: .data.attempt.status }'
# → { "code": "attempt_expired", "attempt_status": "expired" }
```

### 10. Attempts-exhausted → 409 `attempts_exhausted`

After two complete attempts (recipes 7 + 8), trying to start a third is rejected:

```sh
curl -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" \
  | jq '.code'
# → "attempts_exhausted"
```

(Reset by clearing rows: `ddev wp db query "DELETE FROM wp_vl_quiz_attempts WHERE user_id=$USER_ID AND quiz_id=$QUIZ_ID"` if iterating.)

### 11. Final-exam fan-up → enrollment flips to `completed`

Set the student to 100 % lesson progress on `$EXAM_COURSE_ID` (manually mark all leaves complete or run the demo helper), then submit a passing final-exam attempt:

```sh
# Confirm starting state.
ddev wp db query \
  "SELECT status, progress_pct FROM wp_vl_enrollments WHERE user_id=$USER_ID AND course_id=$EXAM_COURSE_ID"
# → status=active, progress_pct=100

EXAM_ATTEMPT_ID=$(curl -s -X POST "$BASE/quizzes/$EXAM_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.attempt.id')

# … PATCH all-correct answers …

curl -s -X POST "$BASE/quizzes/attempts/$EXAM_ATTEMPT_ID/submit" \
  -H "Authorization: Bearer $JWT" \
  | jq '.data.attempt.passed'
# → true

ddev wp db query \
  "SELECT status, progress_pct, completed_at FROM wp_vl_enrollments WHERE user_id=$USER_ID AND course_id=$EXAM_COURSE_ID"
# → status=completed, progress_pct=100, completed_at=<UTC ISO>
```

Also visible via `GET /enrollments/me`:

```sh
curl -s -H "Authorization: Bearer $JWT" "$BASE/enrollments/me" \
  | jq ".data.items[] | select(.course_slug == \"$EXAM_COURSE_SLUG\") | { status, completed_at }"
# → { "status": "completed", "completed_at": "<UTC ISO>" }
```

### 12. Final-exam fan-up gated by lesson progress → enrollment stays `active`

Same passing-final-exam flow but the student is at 80 % lesson progress:

```sh
# Reset:
ddev wp db query \
  "UPDATE wp_vl_enrollments SET status='active', progress_pct=80, completed_at=NULL \
   WHERE user_id=$USER_ID AND course_id=$EXAM_COURSE_ID"
# Remove a recently-completed leaf so the calculator yields 80%:
ddev wp db query \
  "DELETE FROM wp_vl_progress WHERE user_id=$USER_ID AND course_id=$EXAM_COURSE_ID AND status='completed' LIMIT 1"

EXAM_ATTEMPT_ID2=$(curl -s -X POST "$BASE/quizzes/$EXAM_SLUG/attempts" \
  -H "Authorization: Bearer $JWT" | jq -r '.data.attempt.id')

# … PATCH all-correct answers …

curl -s -X POST "$BASE/quizzes/attempts/$EXAM_ATTEMPT_ID2/submit" \
  -H "Authorization: Bearer $JWT" \
  | jq '.data.attempt.passed'
# → true

ddev wp db query \
  "SELECT status, progress_pct FROM wp_vl_enrollments WHERE user_id=$USER_ID AND course_id=$EXAM_COURSE_ID"
# → status=active, progress_pct=<some value below 100>
```

The E2 gate stops the flip — the final-exam-pass arm passes, but the lesson-progress arm fails, so `reevaluate_course_completion` is a no-op.

## 3. Negative-path quick checks

### 13. Unauthenticated → 401

```sh
curl -i -s -X POST "$BASE/quizzes/$QUIZ_SLUG/attempts" | head -1
# → HTTP/2 401
```

### 14. Quiz slug doesn't exist → 404 `quiz_not_found`

```sh
curl -s -X POST "$BASE/quizzes/no-such-quiz/attempts" \
  -H "Authorization: Bearer $JWT" | jq '.code'
# → "quiz_not_found"
```

### 15. Save with malformed body → 422 `invalid_answer_data`

```sh
curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/$Q1_ID" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{}' | jq '.code'
# → "invalid_answer_data"
```

### 16. Save with question id not in attempt → 422 `invalid_question_id`

```sh
curl -s -X PATCH "$BASE/quizzes/attempts/$ATTEMPT_ID/answers/999999" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{"answer_data": {"answer_id": "x"}}' | jq '.code'
# → "invalid_question_id"
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0 (lint may emit warnings — those are pre-existing and tolerated).
