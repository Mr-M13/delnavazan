# Teacher Introductory Requests and Unassigned Opportunity Board

Date: 2026-11-10
Branch: `feat/teacher-intro-opportunity-board`

## Summary of the existing implementation and gaps

Before this change the Teacher portal already had:

- `BookingTeacherResponseService` as the canonical Teacher accept/decline authority.
- `BookingTeacherClaimController` / `BookingTeacherClaimController::claim()` for unmatched requests.
- `TeacherOpenBookingRequestReadService` and `TeacherOpenBookingRequestRepository` for requests with no active Teacher.
- Booking allocation, confirmation, Google Calendar/Meet projection and the durable notification queue.

The gaps were presentation and one missing durable decline record:

1. The assigned request card opened a second dialog and exposed an old `مشاهدهٔ درخواست` action.
2. Availability was shown with an old red/green wording and a duplicated `مهلت پاسخ: ۲۴ ساعت` label.
3. The unassigned section was above the class list, on both Home and Classes, and used a separate card.
4. There was no Teacher-scoped rejection reason persisted against the request/assignment.
5. A Teacher who declined an unassigned request could be shown the same opportunity again.

## Files and components modified

### Theme

- `theme/template-parts/teacher-portal/intro-request-card.php` — one reusable card for assigned requests and opportunities.
- `theme/template-parts/teacher-portal/attention.php` — renders the shared card for the Teacher's own intro requests; other task types keep their existing controls.
- `theme/template-parts/teacher-portal/open-requests.php` — bottom-of-Classes opportunity board.
- `theme/template-parts/teacher-portal/shell.php` — opportunity board moved below the class list.
- `theme/template-parts/teacher-portal/home.php` — removed the duplicated opportunity board.
- `theme/inc/platform-bridge.php` — student/country/instrument model, dynamic deadline sentence, green/yellow preference model.
- `theme/inc/teacher-portal.php` — allowlisted outcome messages for the new rejection/opportunity outcomes.
- `theme/assets/css/teacher-portal.css` — compact green/yellow preference rows, inline rejection form, yellow opportunity card, mobile layout.
- `theme/assets/js/teacher-portal.js` — selection enables acceptance, inline rejection reason handling, expired card state.

### Platform

- `platform/src/Core/Application/BookingTeacherResponseService.php` — reason validation/persistence, opportunity decline, instrument eligibility and decline checks on claim.
- `platform/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php` — decline evidence and exact course-eligibility read.
- `platform/src/Core/Infrastructure/Repository/TeacherOpenBookingRequestRepository.php` — country read, no preference truncation, declined-Teacher exclusion.
- `platform/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php` — country read, no preference truncation, explicit admin re-offer retires a previous decline.
- `platform/src/Portals/TeacherBookingClaimController.php` — accept/decline opportunity through the canonical claim authority.
- `platform/src/Portals/TeacherBookingResponseController.php` — assigned accept/decline with required rejection reason.
- `platform/src/Core/Infrastructure/Migration/Migrator.php` — additive migration `074_teacher_booking_declines`.
- `platform/delnavazan-platform.php` — schema 61.

## Backend justification

The new `booking_request_teacher_declines` table is the only new storage. It records:

- booking request, Teacher and assigned delivery (when one exists);
- `assigned` or `opportunity` source;
- stable reason code and optional free text;
- an active/superseded state.

It never cancels, closes or confirms the Student request. The opportunity read excludes only active declines for the current Teacher, so other eligible Teachers still see the request. An explicit administrator reassignment retires an old decline before re-offering.

Both sources still use the same authority:

- assigned request → `TeacherBookingResponseController` → `BookingTeacherResponseService::respond()`;
- unassigned opportunity → `TeacherBookingClaimController` → `BookingTeacherResponseService::claim()`.

Both call `assertAcceptable()`, the Student/Teacher occupancy checks, `confirmDelivery()`, `acceptedLocked()` and the existing projection/notification pipeline. No parallel booking or notification path was added.

## Testing

Added/updated:

- `platform/tests/teacher-intro-opportunity-board-contract.php` (new source contract).
- `platform/tests/teacher-portal-presentation-contract.php` (new card/board rendering, expiry, direct actions).
- `platform/tests/booking-teacher-response-unit.php` (decline reason persistence, opportunity decline, no re-offer).
- `platform/tests/booking-teacher-delivery-contract.php` (all preferences, bottom-of-Classes placement, countdown model).
- `platform/tests/booking-teacher-response-contract.php` (nonce ownership moved to the shared card).
- Schema-version assertions updated from 60 to 61.

Executed successfully:

```text
php platform/tests/teacher-intro-opportunity-board-contract.php
php platform/tests/teacher-portal-presentation-contract.php
php platform/tests/booking-teacher-response-unit.php
php platform/tests/booking-teacher-response-contract.php
php platform/tests/booking-teacher-delivery-contract.php
node --check theme/assets/js/teacher-portal.js
```

A local render of both cards is preserved at
`docs/evidence/teacher-intro-opportunity-20261110/preview.html`.
The browser accessibility check confirmed the three preferences are labelled, the accept
button starts disabled and enables after selection, and the rejection form shows a required
free-text field for `دلیل دیگر`.

Not executed here: the WordPress runtime/concurrency suite and deployment CI, because this
workspace does not include a running WordPress database. The existing booking concurrency and
acceptance routines were not changed.

## Unresolved risks

- The runtime migration and concurrent two-Teacher claim test require the project's WordPress test environment.
- The project already has unrelated failing local contracts `teacher-today-classes-settings-contract.php` and `settings-redesign-contract.php`; they are outside this change and were left untouched.
- Google Calendar/Meet provisioning remains dependent on the existing provider integration configuration.
