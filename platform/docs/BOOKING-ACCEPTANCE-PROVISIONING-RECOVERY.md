# Booking acceptance and classroom provisioning recovery

Status: source fix prepared. No production schema migration, booking mutation or deployment is
performed by this change. Production verification and any record recovery require explicit approval.

## Verified root cause

Teacher acceptance and classroom provisioning were conflated in `BookingCalendarProjectionService`.
The acceptance transaction was durable, but every terminal projection path called:

```php
transitionDelivery($deliveryId, 'accepting', 'pending_request', $now);
(new BookingAllocationRepository())->referral($bookingRequestId, 'provider_projection_failed', $now);
```

That reversed the accepted delivery, made the request look unanswered in allocation reads, and hid
the booking from `BookingTeacherDeliveryRepository::pendingForTeacher()` because that query requires
`offer.active_slot=1` and `offer.state='pending'`. The accepted offer has `active_slot=NULL`, so the
Teacher portal had no row for the accepted booking at all. The administrator workspace had no branch
for a failed non-confirmed confirmation and fell through to coordination, where it could show
“Record the Teacher’s agreement” even though acceptance was already recorded.

The same reversal existed in the `awaiting_conference` attempt-exhaustion path. Successful admin
retry happened to move the delivery forward again, which masked the defect for some records.

## State model

| Concept | Durable record | Allowed terminal/next states |
| --- | --- | --- |
| Teacher offer pending | `booking_allocation_offers.state='pending', active_slot=1` | accepted, declined, expired, superseded |
| Teacher accepted | delivery `state='accepting'` plus confirmation row | classroom provisioning |
| Provisioning pending | confirmation `pending_projection`, `retryable`, `awaiting_conference` | confirmed, failed |
| Provisioning failed | confirmation `state='failed'` | administrator retry only |
| Provisioned | confirmation `state='confirmed'`, delivery `state='confirmed'`, request closed | outcome |
| Cancelled/superseded/ended | confirmation cancelled or request closed, or start time passed | no retry |

A provider failure now changes only the confirmation state. It never changes delivery state, request
lifecycle/resolution state, allocation offers, or notification acceptance evidence.

## Changed behaviour

- `BookingCalendarProjectionService::retry()` and `awaitConference()` no longer return an accepted
  delivery to `pending_request` and no longer create allocation referrals.
- `retryFailed()` requires the exact confirmation, optional reviewed version, active unresolved
  request, valid delivery ownership, future start, no live re-offer, and an authorised operator.
  Historical `pending_request` deliveries are moved forward only when the same confirmation still
  owns them.
- `confirm()` repairs a historical `pending_request` delivery during the same transaction before
  writing the confirmed state. It refuses if allocation has started a new live offer.
- `BookingTeacherDeliveryRepository::acceptedProvisioningForTeacher()` keeps accepted bookings visible
  for `pending_projection`, `retryable`, `awaiting_conference`, `failed` and `awaiting_payment`,
  including historical `pending_request` deliveries that already have a confirmation.
- `TeacherBookingProvisioningReadService` projects Student, instrument, confirmed time/timezone,
  acceptance state, provisioning state, failure reason, attempts and last attempt. It never exposes a
  Join action for a non-confirmed booking.
- The Teacher portal renders these as informational attention cards with the required Persian
  failure copy and no Accept button.
- `EnrolmentWorkspaceReadService` now distinguishes awaiting coordination, awaiting Teacher response,
  provisioning pending, provisioning failed, payment pending, confirmed and cancelled. A pending
  delivery outranks stale coordination candidates. Failed confirmations use the required Persian
  status and next action.
- The admin case detail shows failure reason, attempt count, last attempt, organizer state/scope,
  classroom state and recovery eligibility. A version-checked retry button reuses the same booking.
- One pure `BookingProvisioningRecoveryRule` now governs the dashboard flag, the recovery report and
  the mutating retry. It requires a failed confirmation, exact delivery ownership, an active
  `submitted`/`unresolved` request, a future start and no live re-offer. Organizer connection remains
  a separate diagnostic. The dashboard can no longer offer a retry that the authority rejects.
- `wp dzn-meet booking-recovery` provides read-only discovery by default and an exact, audited apply
  path. `--confirmation_id` queries that confirmation directly, so a valid record outside the bounded
  discovery page is still assessable. `wp dzn-meet retry-booking` remains available and now accepts
  `--expected_version` and `--reason`.

## Database migration

None. The fix reuses `booking_request_confirmations` (`state`, `version`, `failure_reason_code`,
`attempt_count`, `next_attempt_at`, `updated_at`) and `meet_classrooms` (
`classroom_state`, `cohost_state`, `failure_reason_code`, `cohost_attempt_count`, `updated_at`).
Recovery audits use the existing `platform_audit_events` table.

## Existing production records

1. Run the read-only diagnostic and discovery:
   ```sh
   wp dzn-meet status --user=<administrator_id>
   wp dzn-meet booking-recovery --limit=200 --json --user=<administrator_id> > /secure/booking-recovery.json
   ```
2. Review the booking reference, Teacher id, confirmation id/version, delivery/offer state, failure
   reason, organizer state and `recommended_action`.
3. For one exact failed record, use `booking-recovery --apply --confirmation_id --expected_version
   --reason`. Do not bulk replay.
4. Re-run discovery after each batch. A changed confirmation version makes the old approval stale.

Records whose delivery was reassigned to another Teacher, whose request is closed/cancelled, or whose
class time has passed must be reviewed manually. They are not automatically resurrected.

## Notifications

The confirmed notification is still created only by the `confirm()` transition, which checks for an
existing `confirmed` notification for that delivery. Failed provisioning queues no joining link.
Successful recovery creates the confirmed notification once. WhatsApp/SMS routing is unchanged.

## Rollback

Code rollback is safe: no schema was changed. Reverting the deployment restores the old projection
behaviour but does not alter any accepted delivery/confirmation state already written. If recovery is
aborted, stop running `booking-recovery --apply`; the booking remains accepted and failed, with no
duplicate offer, classroom, calendar event, Meet space or notification. Use
`wp dzn-meet rollback` only for the documented organizer-wide emergency, not for one failed booking.

## Known limitations

- A permanent Google policy refusal (for example co-host membership) still requires an operational
  fix or an explicitly approved exception. The code does not fake readiness.
- If the organizer is disconnected or paused, retry remains durably pending/failed and no Teacher
  acceptance is lost. The dashboard reports the organizer state.
- Historical records with both a live re-offer and an accepted confirmation require manual review to
  avoid duplicate lessons.
- Live Google acceptance, production diagnostic output and production record repair are not executed
  from this workspace and remain deployment gates.
