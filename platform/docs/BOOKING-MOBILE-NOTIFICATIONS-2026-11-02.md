# Booking notification delivery — 2026-11-02

## Problem

Introductory booking notices had three disconnected delivery paths:

- Email was sent by the event-owned `BookingStudentNotificationWorker` through `wp_mail()`.
- The portal inbox was written only when the worker could resolve `student_id`, but the queue-read
  projection did not select it, so every booking inbox write silently returned early.
- No producer created the request-time student or teacher notices, and no booking path called the
  Telnyx WhatsApp/SMS adapters. The Telnyx adapters were reachable only from the canonical
  notification runtime and explicit provider tests.

The result was: email only, no portal inbox, and no WhatsApp/SMS for booking events.

## Fix

### Request-time notices

`BookingRequestSubmissionService` now queues two additional leased rows with the matched Teacher
delivery:

- `requested_student` → `booking.requested.student`
- `requested_teacher` → `booking.new.teacher`

The worker resolves the teacher address, teacher timezone, student id, student WhatsApp/mobile and
teacher WhatsApp/phone from the same booking projection. The teacher and student portal inbox rows are
written before email hand-off, so a mail transport failure cannot hide the in-app alert.

### Portal inbox

`BookingTeacherResponseRepository::notificationPayload()` now selects `r.student_id`, the student mobile
and WhatsApp number, and the teacher phone/WhatsApp number. `recordInbox()` therefore records the
student notice for confirmed/declined/requested events and the teacher notice for `booking.new.teacher`.

### WhatsApp first, SMS only as fallback

`BookingNotificationMobileDispatcher` handles the mobile route for an existing booking notification:

1. It refuses an event that does not offer WhatsApp and a disabled event.
2. It applies the category channel policy, provider readiness, approved-template authority and the
   active STOP suppression register.
3. It sends an approved Telnyx WhatsApp template through `TelnyxWhatsAppAdapter::sendBookingNotification()`.
4. Only a synchronous, permanent WhatsApp refusal may use `TelnyxSmsAdapter::sendBookingNotification()`.
   SMS also requires the category's explicit `sms_fallback` permission and a valid mobile number.
5. A transient or ambiguous WhatsApp result stays on the booking queue's existing retry policy and is
   never fanned out to SMS.

Provider references are correlated in `dzn_telnyx_messages` with the booking notification id and
`attempt_id = 0`. The webhook already treats that shape as a tracked business delivery, so delivery
receipts update the provider message state without attempting a canonical aggregate transition. The
same row also makes a leased retry idempotent.

Nothing is stored in the booking queue that is not already there: no new table, column or migration is
required.

## Operational requirements

- Enable WhatsApp for the booking category in **Notifications → Delivery rules**.
- Keep at least one approved Telnyx Meta template for the event; the existing registry maps
  `booking.requested.student`, `booking.confirmed.student` and `booking.new.teacher`.
- Configure the Telnyx API key, sender/WABA, messaging profile and webhook public key.
- For SMS, explicitly enable **SMS fallback** for the category. SMS is not a primary channel.

## Known limitation

An asynchronous WhatsApp delivery failure reported by the Telnyx webhook is recorded against the
provider message but does not currently create an SMS fallback. Only a synchronous terminal provider
refusal can authorise the fallback in this path. That keeps the send decision deterministic and avoids
duplicate messages while the canonical fallback authority remains the long-term owner.

## Verification

- `tests/booking-student-notification-unit.php` — request email, portal student/teacher rows, mobile
  dispatcher invocation and idempotency.
- `tests/booking-mobile-notification-unit.php` — student/teacher recipient resolution, WhatsApp policy,
  variable contract, E.164 normalisation, suppression, transient retry and SMS fallback.
- `tests/booking-mobile-adapter-unit.php` — approved template resolution, WhatsApp/SMS payloads,
  provider correlation and repeated-hand-off idempotency.
- The existing notification, Telnyx and booking contract suites remain green.
