# Phase 6.3 Smoke

Manual smoke checks for the Phase 6.3 certificate backend. Run inside DDEV against `https://green-paws-lms-backend.ddev.site/`.

```sh
BASE="https://green-paws-lms-backend.ddev.site/wp-json/vl/v1"
AUTH="https://green-paws-lms-backend.ddev.site/wp-json/vl-auth/v1"
```

## 0. Setup

These recipes assume the demo seeder has been applied and at least one course has `_vl_course_certificate_enabled='1'` plus a final-exam quiz.

```sh
COURSE_SLUG="cardio-certificate-track"
COURSE_ID=$(ddev wp post list --post_type=vl_course --name="$COURSE_SLUG" --field=ID --format=ids)
EXAM_SLUG="cardio-final-exam"
EXAM_ID=$(ddev wp post list --post_type=vl_quiz --name="$EXAM_SLUG" --field=ID --format=ids)
USER_ID=$(ddev wp user get student.bohdan --field=ID)

# Enable certificates and final-exam flag if not already set:
ddev wp post meta update "$COURSE_ID" _vl_course_certificate_enabled 1
ddev wp post meta update "$EXAM_ID" _vl_quiz_is_final_exam 1
```

## 1. Acquire JWT

```sh
JWT=$(curl -sk -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"student.bohdan","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')
```

## 2. Recipes

### 1. Auto-issue on completion

Mark all lessons complete + submit a passing final-exam attempt. The auto-issuer fires from `vl_lms_course_completed`.

Verify the row exists:

```sh
ddev wp db query "SELECT id, uuid, user_id, course_id, revoked_at, JSON_EXTRACT(snapshot_data, '$.course_title') AS course_title FROM wp_vl_certificates ORDER BY id DESC LIMIT 1"
# → id, uuid, user_id, course_id, revoked_at=NULL, course_title="Сертифікаційний трек: Кардіологія"
```

Inspect the snapshot:

```sh
ddev wp eval 'global $wpdb; $row = $wpdb->get_row("SELECT snapshot_data FROM wp_vl_certificates ORDER BY id DESC LIMIT 1"); $s = json_decode($row->snapshot_data, true); foreach (["course_title","course_slug","learner_full_name","learner_display_name","instructor_names","issuer_name","issued_at_iso","final_score_pct","template_version"] as $k) { echo "$k: " . (is_array($s[$k] ?? null) ? implode(", ", $s[$k]) : ($s[$k] ?? "MISSING")) . PHP_EOL; }'
# → all 9 keys present
```

### 2. Auto-issue idempotency

Re-trigger course-completion re-evaluation. NO duplicate row should appear.

```sh
ddev wp eval 'do_action("vl_lms_course_completed", '"$USER_ID"', '"$COURSE_ID"', (int) (new \VL\LMS\Repositories\EnrollmentRepository())->find_for_user_and_course('"$USER_ID"', '"$COURSE_ID"')->id);'

ddev wp db query "SELECT COUNT(*) FROM wp_vl_certificates WHERE user_id=$USER_ID AND course_id=$COURSE_ID"
# → 1 (no duplicate)
```

### 3. Skip when disabled

```sh
ddev wp post meta update "$COURSE_ID" _vl_course_certificate_enabled 0
# Re-trigger — service returns IssueResult::skipped, no row written.
ddev wp db query "SELECT COUNT(*) FROM wp_vl_certificates WHERE user_id=$USER_ID AND course_id=$COURSE_ID"
# → 1 (still the original; not a second one)
# Restore for the rest of the recipes:
ddev wp post meta update "$COURSE_ID" _vl_course_certificate_enabled 1
```

### 4. List mine

```sh
curl -sk -H "Authorization: Bearer $JWT" "$BASE/certificates/me" \
  | jq '.data.items[] | { uuid, course_title, status, final_score_pct }'
# → an array of items, status="active"
```

### 5. Fetch detail

```sh
UUID=$(curl -sk -H "Authorization: Bearer $JWT" "$BASE/certificates/me" | jq -r '.data.items[0].uuid')

curl -sk -H "Authorization: Bearer $JWT" "$BASE/certificates/$UUID" \
  | jq '{ uuid, course_title, learner_full_name, instructor_names, download_url, verification_url }'
# → full detail; verification_url includes the configured frontend host
```

### 6. Forbidden — wrong user

Acquire a token for a different student and hit the same UUID:

```sh
JWT2=$(curl -sk -X POST "$AUTH/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"student.olena","password":"hunter2hunter2"}' \
  | jq -r '.data.access_token')

curl -sk -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer $JWT2" "$BASE/certificates/$UUID"
# → 403
```

### 7. Download — first call

```sh
curl -sk -OJ -H "Authorization: Bearer $JWT" "$BASE/certificates/$UUID/download"
# → certificate-<uuid>.pdf saved locally; first 6 bytes "%PDF-1."

ls "wp-content/uploads/certificates/$UUID.pdf"
# → file exists on disk

ddev wp db query "SELECT pdf_path FROM wp_vl_certificates WHERE uuid='$UUID'"
# → certificates/<uuid>.pdf
```

Open the PDF in a viewer — confirm Cyrillic renders correctly (no `?????`) and the QR code scans to `https://<frontend>/certificates/<uuid>`.

### 8. Download — cache hit

Re-issue the same call. The on-disk file is reused; `pdf_path` is unchanged; no re-render.

```sh
curl -sk -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer $JWT" "$BASE/certificates/$UUID/download"
# → 200, no log entry for "rendering" (PdfGenerator::generate returns cache_hit=true)
```

### 9. Public verify

No auth required:

```sh
curl -sk -i "$BASE/certificates/$UUID/public" | head -8
# → HTTP/2 200
# → X-Robots-Tag: noindex,follow

curl -sk "$BASE/certificates/$UUID/public" | jq '.'
# → minimal shape; no course_id, no user_id, no learner_full_name
```

### 10. Revoke via enrollment revocation

```sh
ENROLLMENT_ID=$(ddev wp db query "SELECT id FROM wp_vl_enrollments WHERE user_id=$USER_ID AND course_id=$COURSE_ID" --skip-column-names)

ddev wp eval '$svc = new \VL\LMS\Services\Enrollment\EnrollmentService(new \VL\LMS\Repositories\EnrollmentRepository()); (new \VL\LMS\Certificate\CertificateRevoker(new \VL\LMS\Certificate\CertificateService(new \VL\LMS\Repositories\CertificateRepository(), new \VL\LMS\Repositories\EnrollmentRepository(), new \VL\LMS\Certificate\SnapshotBuilder(new \VL\LMS\Services\CourseInstructors\CourseInstructorService(new \VL\LMS\Repositories\CourseInstructorRepository())), new \VL\LMS\Repositories\QuizAttemptRepository(), new \VL\LMS\Support\Logger("vl-lms")), new \VL\LMS\Repositories\CertificateRepository(), new \VL\LMS\Support\Logger("vl-lms")))->register(); $svc->revoke('"$ENROLLMENT_ID"', 1, "smoke test");'

ddev wp db query "SELECT revoked_at FROM wp_vl_certificates WHERE uuid='$UUID'"
# → revoked_at populated (UTC datetime)

curl -sk -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer $JWT" "$BASE/certificates/$UUID/download"
# → 410
```

In production the `Plugin::boot()` flow registers the revoker once; the `wp eval` above re-registers it inline because the eval starts a fresh PHP context.

### 11. Public verify of revoked

```sh
curl -sk "$BASE/certificates/$UUID/public" \
  | jq '{ status, revoked_at }'
# → { "status": "revoked", "revoked_at": "<ISO>" }
```

### 12. Manual revoke via service

```sh
ddev wp eval '$repo = new \VL\LMS\Repositories\CertificateRepository(); $svc = new \VL\LMS\Certificate\CertificateService($repo, new \VL\LMS\Repositories\EnrollmentRepository(), new \VL\LMS\Certificate\SnapshotBuilder(new \VL\LMS\Services\CourseInstructors\CourseInstructorService(new \VL\LMS\Repositories\CourseInstructorRepository())), new \VL\LMS\Repositories\QuizAttemptRepository(), new \VL\LMS\Support\Logger("vl-lms")); $cert = $repo->find_by_uuid("'"$UUID"'"); echo $svc->revoke($cert->id) ? "yes" : "no";'
# → "no" — the certificate is already revoked (idempotent)
```

## Toolchain

From `backend/wp-content/plugins/vl-lms/`:

```sh
ddev composer lint
ddev composer stan
ddev composer test
```

All three should exit 0 (lint may emit warnings — those are pre-existing and tolerated).
