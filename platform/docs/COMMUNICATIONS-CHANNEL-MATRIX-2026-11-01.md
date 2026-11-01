# Delnavazan event-to-channel matrix and delivery verification

Date: 2026-11-01 · This records what the repository actually wires, separately from what a provider is
configured to accept. It is not a claim that every declared channel is live.

## 1. Authorities and queues

| Event key | Source authority | Recipient | Declared channels | Effective channel policy | Template / copy | Destination | Delivery behaviour | Status |
|---|---|---|---|---|---|---|---|---|
| `booking.confirmed.student` | `BookingCalendarProjectionService` (queue row after Calendar/Meet confirmation) | Student | email, website, whatsapp | email, website; whatsapp only if the operator enables the operational policy | Catalogue `fa-IR`/`en` + `NotificationCopyResolver` | `booking_request_contact_snapshots.email`; portal inbox | Durable row, lease, max 3 attempts, `handed_off` = transport accepted, recipient delivery not confirmed | Email + inbox wired; WhatsApp declared but no operational sender calls it |
| `booking.declined.student` | `BookingTeacherResponseService` | Student | email, website | email, website | Catalogue + resolver | Same | Same as confirmed | Email + inbox wired |
| `booking.requested.student` | none | Student | email, website, whatsapp, sms | email, website | Catalogue only | — | — | **No producer**; request submission only creates the teacher delivery row |
| `booking.new.teacher` | none | Teacher | email, website, whatsapp, sms | email, website | Catalogue only | — | — | **No producer** |
| `post_intro.invitation.student` | `PostIntroPreparationService` | Student | email, website | email, website | Catalogue + resolver | Student email; inbox | Booking worker, max 3 attempts | Wired |
| `post_intro.activated.student` | `PostIntroActivationService` | Student | email, website, whatsapp | email, website | Catalogue + resolver | Student email; inbox | Booking worker | Wired |
| `post_intro.activated.teacher` | `PostIntroActivationService` | Teacher | email, website | email, website | Catalogue + resolver | Teacher email | Direct branded send + inbox | Wired |
| `lesson.reminder.student` | none | Student | email, website, whatsapp, sms | email, website | Catalogue only | — | — | **No producer** |
| `lesson.cancelled.student` | none | Student | email, website, whatsapp, sms | email, website | Catalogue only | — | — | **No producer** |
| `lesson.access.student` | none | Student | email, website, whatsapp, sms | email, website | Catalogue only | — | — | **No producer** |
| `attendance.absence_reported.teacher` | `StudentAbsenceController` → `CanonicalAttendanceNotificationService` | Teacher | email, website | email, website | Catalogue + resolver | Teacher email; teacher inbox | Best-effort direct send; no retry queue | Email + inbox wired |
| `attendance.missed_class_claim.teacher` | same | Teacher | email, website | email, website | Catalogue + resolver | Teacher email; teacher inbox | Same | Wired |
| `account.claim_issued.student` | `StudentAccountClaimNotificationService` (canonical intent) | Student | email, whatsapp, sms | security: email + website; WhatsApp/SMS forced off | Canonical template version required; catalogue default copy | Recipient projection (email / mobile) | Canonical dispatch worker; lease/retry; provider callback for mobile; SMS fallback only after verified terminal WhatsApp failure | Canonical intent wired; **email transport is not bound**; mobile depends on an active template/workflow and Telnyx mapping |
| `teacher.invitation_issued.teacher` | `PrincipalInvitationService` + `TeacherInvitationDeliveryWorker` | Teacher | email | email only | Dedicated `TeacherInvitationPresentation` (one-time secret) | Teacher email | Dedicated outbox; prepared secret is never retried; `wp_mail` acceptance recorded as delivered | Wired; branded shell added; no provider callback |
| `system.inbox_check.admin` | `NotificationManagementController` explicit admin test | Administrator | website | website | Catalogue | Admin inbox | Admin-only test, no external send | Wired |
| `reschedule.change.*` / `reschedule.replacement.*` (30 keys) | `LessonRescheduleService` | Student, Teacher, Admin | student/teacher: email, website, whatsapp; admin: email | email, website; WhatsApp only if enabled | Catalogue + resolver | Student/teacher email or `admin_email`; portal inbox | Durable row, max 6 attempts, backoff, idempotency key per proposal/event/audience | Email + inbox wired; WhatsApp not called; `proposed.teacher` and `rejected.student` have no producer |
| `payment.failed.student`, `payment.recovered.student`, `renewal.*`, `refund.review_required.student`, `refund.resolved.student` | Renewal/collection/recovery/refund services write identity-only intent rows to `platform_outbox` | Student | email, website, whatsapp | financial: email + website; WhatsApp only if enabled | Catalogue defaults are marked provisional | — | No production observer calls `NotificationService::observeIntent()`; no active workflow version is seeded | **Intent produced, not dispatched**; diagnostic reports unroutable |
| Statement email (not a catalogue key) | `TeacherStatementEmailService` | Teacher | email | email only | `TeacherStatementEmailRenderer` | Teacher email | Dedicated outbox, lease, max 5 attempts, `transport_accepted` only | Wired; branded shared shell; no delivery callback |
| Payment receipt email / refund confirmation email | `FinanceNotificationService` | Student | email | email only (not catalogue-governed) | Inline Persian copy + shared shell | Student email | Durable `finance_notification_intents`, max 5 attempts | Wired; delivery not confirmed |
| Student receipt download | `StudentReceiptController` | Authenticated Student | website (document download) | owner-only, nonce | `StudentReceiptRenderer` | Browser HTML / print | Read-only; no send | Wired |
| Portal notification inbox | `PortalNotificationRecorder`, `PortalNotificationInbox` | Student / Teacher / Admin | website | website | Active copy definitions | WordPress user inbox | Deduped by HMAC, 365-day retention, read actions only | Wired; opening never changes business state |
| Password reset | WordPress core `retrieve_password()` | WordPress user | email | WordPress policy | Theme body filter | User email | Core `wp_mail` | Wired; plain text by design |

## 2. Channel verification status

Report each channel separately. “Configured” never means “delivered”.

### Email

- **Configuration verified (repository):** `WordPressMailSettings` registers SMTP and From/From-Name
  from integration settings; default sender remains `no-reply@delnavazan.com`. The brand identity does
  not alter the sender, Return-Path or SMTP configuration.
- **Event wiring verified (repository):** booking, post-intro, attendance, reschedule, finance,
  statement and invitation send paths call the shared renderer; canonical `email` has no registered
  transport binding, so canonical-only events cannot email until a binding is deliberately added.
- **Provider accepted:** not verified from this environment. No production message was sent.
- **Delivery confirmed:** not verified. Operational queues record `transport_accepted`/`handed_off`,
  not mailbox delivery. Statement and finance rows intentionally leave `delivered_at` null.
- **Failed/suppressed:** no failure claim is made; unverified is not failure.

### WhatsApp

- **Configuration verified (repository):** the Telnyx WhatsApp adapter, approved-template registry,
  mapping and signed webhook code are unchanged and still require an `APPROVED` provider template.
- **Event wiring verified (repository):** canonical mobile routing exists; operational booking,
  reschedule, attendance and finance queues do not call WhatsApp. The catalogue declares WhatsApp for
  several events but does not make it live.
- **Provider accepted:** not verified.
- **Delivery confirmed:** not verified.
- **Approval dependency:** any local copy change to a WhatsApp event requires a matching approved Meta
  template and a new mapping. Until then the provider template must not be replaced silently.

### SMS

- **Configuration verified (repository):** Telnyx SMS adapter and signed webhook are unchanged.
- **Event wiring verified (repository):** SMS is fallback-only. It becomes routable only through the
  durable `NotificationSmsFallbackService` after a verified terminal WhatsApp failure, SMS consent,
  suppression checks, valid phone and policy permission. Security events force SMS off.
- **Provider accepted:** not verified.
- **Delivery confirmed:** not verified.
- **Security restriction preserved:** one-time codes are never moved into SMS by this change.

## 3. Test and production rules

- Controlled, synthetic recipients only. Never replay a production business event to a real student or
  teacher to test a channel.
- Provider acceptance is reconciled before resending; ambiguous sends are not retried automatically.
- Webhook callbacks are authenticated with the existing Ed25519 verification and idempotent event
  digest.
- A proposed replacement time is communicated as proposed. Acceptance is a separate state; no
  notification may describe a proposal as confirmed before acceptance.

## 4. Remaining dependencies

1. A deliberate canonical email transport binding if canonical-only events are to email. This is a
   product decision, not a brand change.
2. An active canonical template/workflow version for each canonical event.
3. Telnyx sender, messaging profile and approved WhatsApp template mapping per event.
4. Provider-side delivery verification (accepted → delivered) using the existing signed callbacks.
5. Producer wiring for booking request/new-teacher, lesson reminder/cancellation/access and the two
   missing reschedule audiences.
6. A production-safe test account set and an explicit operator runbook for live channel tests.
