# Phase 7.6 — Cohort Sessions, Reminders, Recording-Ready Smoke

Manual verification checklist for the Phase 7.6 closeout: cohort-session
player, transactional emails (reminders + recording-ready +
certificate-issued), demo seeder `--skip-zoom` flag.

Run against the DDEV stack with `mailpit` (or wp-cli's `wp mail-log`)
attached to capture outgoing mail. A real Zoom-configured site is needed
for the live join + recording webhook recipes; the rest can run on the
demo seed alone.

## Prerequisites

- Backend up: `ddev start` in `backend/`.
- Frontend up: `npm run dev` in `frontend/`.
- Mailpit (or equivalent SMTP catcher) listening on the DDEV mail relay.
- Test student with `vl_view_lesson` + `vl_register_for_webinar` caps.
- One cohort course seeded with at least 2 sessions (the demo seeder
  produces this).

```sh
STUDENT="student.bohdan"
PASSWORD="hunter2hunter2"
COURSE_SLUG="gp-anesthesia-101"     # or whichever cohort course your seed uses
SESSION_SLUG="gp-c1-s1"
WEBINAR_SLUG="gp-webinar-icu-12h"
```

---

## 1. Curriculum rail surfaces session leaves

1. Sign in as `STUDENT`. Navigate to a cohort course's first lesson:
   `/learn/<lesson-slug>`.
2. Curriculum rail shows the modules section first, then any orphan
   lessons, then a "Sessions" group at the bottom with the session
   leaves. Each session leaf shows status icon + title + "Сесія N" hint.
3. For an upcoming session, a small countdown chip displays "Nд Mг Kхв".

## 2. Click session leaf → /learn/sessions/[slug]

1. Click a `scheduled` session in the rail.
2. URL becomes `/learn/sessions/<slug>`.
3. Page renders inside the `learn` layout: header with session number +
   title + status badge, long-form countdown ("X д Y год Z хв"), no join
   button yet.
4. The rail still highlights the same session leaf.

## 3. Countdown ticks reactively

1. Stay on the page. Watch the countdown — the minute value should
   advance every 60 s.
2. Open DevTools network tab — the page should not be re-fetching the
   detail endpoint; the countdown is purely client-side.

## 4. Time-travel into the join window

1. In wp-admin (or via WP-CLI), update the session's
   `_vl_session_scheduled_start` to a moment within the join-grace
   window (default 15 min before now).
2. Reload `/learn/sessions/<slug>`. CTA flips to a pulsing "В ефірі"
   banner + "Приєднатися" button.

## 5. Click "Приєднатися" → 302 to Zoom

1. Click the join button.
2. Browser navigates top-level to
   `${API_BASE}/vl/v1/learn/sessions/<slug>/join?token=<jwt>`.
3. Backend responds 302; the browser follows to the Zoom join URL stored
   in `_vl_session_zoom_join_url`.
4. Note: the bearer token appears briefly in the address bar; the 302
   boundary plus referrer-policy stripping keeps it off Zoom's logs.

## 6. participant_joined → progress_pct increases

1. Trigger the Phase 7.2 `meeting.participant_joined` webhook for the
   session (via `wp vl-lms zoom simulate-webhook participant_joined …`
   or a curl with a valid signature).
2. `vl_session_attendance` row inserted.
3. Reload `/dashboard` — the cohort course card's `progress_pct`
   advances; the session leaf in the rail flips to its
   `completed_attended` icon next time the rail refreshes.

## 7. meeting.ended → "Сесія завершена" + "Очікує запису"

1. Trigger `meeting.ended` for the session.
2. `_vl_session_status` flips to `completed`.
3. Reload the session page. CTA reads "Сесію завершено" + "Запис
   підготовлюється" inline note.

## 8. recording.completed → CTA flips to "Дивитися запис"

1. Trigger `recording.completed` with a valid MP4 `play_url` in
   `recording_files`.
2. `_vl_session_recording_url` populated. Action `vl_lms_recording_published`
   fires.
3. Reload the session page. CTA flips to "Дивитися запис".
4. Click → 302 redirect to the playback URL via the same `?token=`
   carrier as the join button.

## 9. Recording-ready email arrives

1. After step 8 fires, check Mailpit. Each user with an active
   enrollment in the parent cohort course should receive an email with
   the subject "Session recording available: "<title>"".
2. The email body links to the same `/learn/sessions/<slug>` page.
3. For a webinar (registrants instead of enrollees) the symmetric
   "Webinar recording available" email arrives, linking to
   `/dashboard/webinars/<slug>`.

## 10. 24 h reminder via cron

1. Schedule a session ~25 h in the future:
   `wp post meta update <session-id> _vl_session_scheduled_start "2026-05-05T18:00:00Z"`.
2. Force run cron: `wp cron event run --due-now`.
3. (Or wait until cron's natural firing time.) The
   `vl_lms_send_reminder` event runs; mailpit shows the reminder email
   subject "Session "<title>" starts tomorrow".
4. The 1 h reminder is scheduled for the same session — verify with
   `wp cron event list --hook=vl_lms_send_reminder --format=json`.

## 11. Reschedule cancels old reminders

1. With the previous step still pending, move the session by editing
   `_vl_session_scheduled_start` to a date 7 days further out.
2. Re-save the session post (e.g. `wp post update <id> --post_status=publish`
   to re-fire the `save_post_vl_session` hook).
3. `wp cron event list --hook=vl_lms_send_reminder` shows the OLD
   timestamps gone and TWO NEW events scheduled for the new
   24 h / 1 h offsets.

## 12. `wp vl-lms demo seed --skip-zoom=true`

1. On a fresh DB / after `wp vl-lms demo reset`, run:
   ```sh
   wp vl-lms demo seed --skip-zoom=true
   ```
2. CLI logs include "Demo seed: Zoom sync bypassed; deterministic fake
   meeting meta will be written."
3. For every seeded session and webinar:
   - `_vl_*_zoom_meeting_id = "demo-<post_id>"`
   - `_vl_*_zoom_join_url = "https://example.test/join/demo-<post_id>"`
   - `_vl_*_zoom_start_url = "https://example.test/start/demo-<post_id>"`
   - `_vl_*_zoom_password = "demoNNNNNN"` (6-digit zero-padded post_id)
4. No real Zoom API calls are made (Mailpit / network logs show no
   `api.zoom.us` outbound traffic).
5. `MeetingSynchronizer::sync()` returns `SyncReason::DEMO_BYPASS`
   results — visible via the `vl_lms_zoom_meeting_synced` /
   `_sync_failed` action listeners if hooked.

## 13. Default `wp vl-lms demo seed` on non-prod DDEV

1. Run with no flag: `wp vl-lms demo seed` (after a reset).
2. The flag defaults to `true` because
   `wp_get_environment_type() !== 'production'` on DDEV.
3. Same outcome as step 12 — fake meta, no real Zoom calls.

## 14. Certificate-issued email

1. Complete a course as `STUDENT` (run quiz attempt + submit, or use
   `wp vl-lms course complete --user=… --course=…` if such a helper
   exists; otherwise nudge the propagator manually).
2. `CertificateAutoIssuer` issues the certificate, fires
   `vl_lms_certificate_issued`.
3. `CertificateIssuedListener` dispatches an email with the subject
   "Certificate issued: "<course title>"".
4. The email body links to `/dashboard/certificates/<uuid>`.
5. Re-running completion (idempotent path) does NOT fire a second email.

---

## Negative paths to spot-check

- **Cancelled session**: set `_vl_session_status = 'cancelled'` and
  reload the session page — the alert "Сесію скасовано" replaces the
  CTA. The rail leaf gets the `circle-x` icon with line-through styling.
  Reminder cron events for that session are unscheduled on save.
- **Past session, no recording**: the player shows "Запис не надається"
  when `_vl_session_recording_url` is empty and the recording webhook
  has not arrived. After the recording webhook fires, the same UI
  transition documented in step 8 happens.
- **Bad slug** (`/learn/sessions/does-not-exist`): renders the `learn`
  layout's `<NotFound>` (404), not a generic error page.
- **Not enrolled** (sign in as a user without an enrollment in the
  parent course → open `/learn/sessions/<slug>`): toast "Ви не записані
  на цей курс" + redirect to `/dashboard`.
- **Concurrent re-saves**: rapid-fire post updates do NOT pile up
  duplicate cron events. `wp cron event list --hook=vl_lms_send_reminder`
  shows at most one 24 h + one 1 h entry per `[post_id, kind]`.

## Test-debt note

Phase 7.6 production code lands without dedicated PHPUnit coverage for
the new mailers, scheduler, dispatcher, listeners, and seeder skip-zoom
path. The existing 1735-test suite stays green; lint + stan are green.
The ~50–80 unit-test backlog is documented in `ROADMAP.md` Phase 7
"intentionally deferred" → "focused testing-debt subphase".
