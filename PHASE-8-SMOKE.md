# Phase 8 — End-to-End Smoke Checklist

Manual recipes covering the entire payments lifecycle from a guest visiting
a paid course to an admin issuing a refund. Run against a dev environment
with LiqPay sandbox credentials configured (`VL_LMS_LIQPAY_PUBLIC_KEY`,
`VL_LMS_LIQPAY_PRIVATE_KEY`; sandbox auto-engaged when
`wp_get_environment_type()` is not `production`).

Prerequisites:

- `wp vl-lms demo seed` has been run.
- LiqPay sandbox credentials work (test card numbers from the LiqPay docs).
- Email delivery is hooked to a viewer (`wp-mail-logging` plugin or MailHog).
- Frontend (`vl-frontend`) is running at `http://localhost:3000`.
- Admin (an account with `vl_refund_orders` cap — typically `administrator`)
  exists; record its credentials.

---

## 1. Course purchase — happy path

1. Log in as `student.bohdan` (demo seed creates this account).
2. Open `/courses/[paid-course-slug]`. CourseHero CTA reads "Купити".
3. Click. `/checkout/[slug]?type=course` renders with the price.
4. Click "Сплатити через LiqPay" → LiqPay sandbox.
5. Pay with the sandbox-success card.
6. Redirect lands on `/orders/[uuid]/result`. Within ~30s the page flips
   to "Дякуємо за покупку!".
7. Open Mail Logging — `OrderPaidMailer` sent the order-paid email
   (subject starts with "Дякуємо за покупку — ").
8. `/dashboard/orders` lists the new order with status "Оплачено".
9. `/courses/[slug]` CTA flips to "Продовжити навчання".

**Verify**: `vl_orders` row in PAID; `vl_payments` row with
`transaction_type=charge`, `status=success`.

---

## 2. Webinar purchase — happy path

1. Open `/webinars/[paid-webinar-slug]`. WebinarHero CTA "Купити квиток".
2. Same flow as recipe 1; redirect-back lands on
   `/orders/[uuid]/result` → "Перейти до вебінару".
3. `/dashboard/webinars/[slug]` opens with the registered state.

**Verify**: `vl_webinar_registrations` row with
`source=PURCHASE`, `source_order_id=[order_id]`.

---

## 3. LiqPay sandbox failure card → FAILED order

1. Repeat recipe 1 with the sandbox-failure card.
2. Result page transitions to "Платіж не пройшов" with
   "Спробувати ще раз" CTA.
3. Mailer: `OrderFailedMailer` sent (subject starts with
   "Платіж не пройшов — ").

**Verify**: `vl_orders.status=failed`; `vl_payments` row with
`status=failure`.

---

## 4. Order expiration via cron

1. Create a PENDING order (begin checkout, do not redirect to LiqPay).
2. Manually set `expires_at` to 1 hour ago via direct SQL or
   `wp eval`.
3. Trigger the cron: `wp cron event run vl_lms_order_expiration_cron`.
4. `/dashboard/orders` shows the order with status "Прострочено".

**Verify**: `vl_orders.status=expired` (no payment row).

---

## 5. Already-enrolled prevention

1. Log in as a user already enrolled in the paid course.
2. Open the course detail page. CTA reads "Продовжити навчання".
3. If checkout is reached by URL manipulation, the API rejects with
   `409 already_enrolled`.

---

## 6. Webinar capacity hit

1. Set `_vl_webinar_capacity` to 1.
2. Register one user, then attempt checkout as a second user.
3. Expect `409 webinar_full` and a Ukrainian-language UI message.

---

## 7. Self-revoke free-tier course

1. Enroll in a free course (no order created).
2. Hit `DELETE /vl/v1/enrollments/me/[slug]` (via curl or dashboard
   button).
3. Access is revoked; `vl_enrollments.status=revoked`.

---

## 8. Self-revoke paid course → 403

1. As a user with a PURCHASE-source enrollment, attempt the same
   `DELETE /vl/v1/enrollments/me/[slug]`.
2. Expect `403 purchase_enrollment_requires_refund`.

---

## 9. Admin refund — happy path

1. Login as administrator.
2. WP-admin → "Замовлення" menu item visible (top-level, position 26).
3. List screen shows seeded demo orders + the recipe-1 order.
4. Filter to "Оплачено" → click into the recipe-1 order.
5. Detail page renders: status badge, summary, timeline, payments
   audit table, and a primary "Відшкодувати … UAH" button.
6. Click. JS confirm dialog: "Ви впевнені? Це відшкодує …".
7. Confirm → admin notice "Замовлення повернено. Доступ скасовано,
   лист надіслано."
8. Detail page now shows status "Повернено" + new REFUND row in the
   payments audit.
9. Mail Logging: `OrderRefundedMailer` email sent.
10. As the original purchaser, `/courses/[slug]` access denied
    (CTA flips back to "Купити"). Certificate (if any) auto-revoked
    via Phase 6.3 chain.

**Verify**: `vl_orders.status=refunded`, `refunded_at` set,
`vl_payments` REFUND row with `status=reversed`.

---

## 10. Admin refund — failure mode

1. Temporarily clear `VL_LMS_LIQPAY_PRIVATE_KEY` (or set it to garbage).
2. Repeat recipe 9 steps 1–6.
3. Expect a red admin notice (e.g. "LiqPay не налаштовано." or
   "Помилка зв'язку з LiqPay: …").
4. Detail page status remains "Оплачено" (no state change).
5. `vl_payments` may show an `error`-status audit row depending on the
   point of failure (provider unavailable → no audit; HTTP failure →
   audit row present).

---

## 11. Reversed callback after sync refund

1. Issue a refund (recipe 9).
2. Wait for LiqPay sandbox's reversed callback (the sandbox sends one
   asynchronously after a successful refund).
3. The detail page's payments audit shows exactly **one** REFUND row.
4. The duplicate callback's `liqpay:[pid]:refund:reversed` idempotency
   key collides with the existing row → no second insert.

---

## 12. Admin orders list — filter, search, sort

1. Open "Замовлення".
2. Status dropdown: filter to "Не пройшов" → only FAILED orders shown.
3. Entity-type dropdown: filter to "Вебінари" → only webinar orders.
4. Search box: enter a UUID (full or first-8 chars from a row's link
   `title`) → exactly one row.
5. Search box: enter a user email (from a known purchaser) → all that
   user's orders.
6. Click "Дата" header → orders re-sort created_at ASC. Click again →
   DESC.
7. Click "Сума" → sort by amount.

---

## 13. Admin order detail — payments audit expand

1. Open any PAID order's detail page.
2. Payments audit table — click the "показати ↓" link on a row.
3. A second row expands showing `provider_payment_id`,
   `provider_action`, and the full `raw_payload` JSON pretty-printed.
4. Click again → row collapses.

---

## 14. Demo seeder — reset

1. `wp vl-lms demo reset --force` (in dev).
2. `vl_orders` and `vl_payments` rows tagged with `demo_seed=1` are
   wiped (FK-safe: payments first, orders second).
3. WP-admin "Замовлення" list is empty (or only contains non-demo
   orders such as recipe-1's manual purchase).

---

## 15. Demo seeder — re-seed

1. `wp vl-lms demo seed`.
2. `OrdersSeeder` creates 5 demo orders for the first demo student:
   PAID / REFUNDED / FAILED / EXPIRED / PENDING.
3. WP-admin "Замовлення" list shows all five with the correct status
   badges.
4. `vl_payments` shows 4 audit rows (PAID×1, REFUNDED×2, FAILED×1).
   All with `provider_payment_id` prefixed `demo-`.
5. The PENDING order's `expires_at` is +12h from `created_at` — left
   live so the recipe-4 cron path can be exercised against it.
