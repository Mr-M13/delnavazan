# Teacher Introductory Requests and Unassigned Opportunity Board

Date: 2026-11-10
Branch: `feat/teacher-intro-opportunity-board`
Draft PR: https://github.com/Mr-M13/delnavazan/pull/592

## Summary of the existing implementation and gaps

Before this change the Teacher portal already had:

- `BookingTeacherResponseService` as the canonical Teacher accept/decline authority.
- `BookingTeacherClaimController` / `BookingTeacherResponseService::claim()` for unmatched requests.
- `TeacherOpenBookingRequestReadService` and `TeacherOpenBookingRequestRepository` for requests with no active Teacher.
- Booking allocation, confirmation, Google Calendar/Meet projection and the durable notification queue.

The gaps were presentation and one missing durable decline record:

1. The assigned request card opened a second dialog and exposed an old `مشاهدهٔ درخواست` action.
2. Availability used old red/green wording and a duplicated `مهلت پاسخ: ۲۴ ساعت` label.
3. The unassigned section was above the class list, on both Home and Classes, and used a separate card.
4. There was no Teacher-scoped rejection reason persisted against the request/assignment.
5. A Teacher who declined an unassigned request could be shown the same opportunity again.

## Release-blocker review and fixes

### 1. Migration reconciliation

- PR #588 reserves `074_god_mode_super_tester`.
- This branch now uses **`075_teacher_booking_declines`** and **Schema 61**.
- No `074_teacher_booking_declines` symbol remains.
- The migration rehearsal proved:
  - fresh install reaches Schema 61 and records 075;
  - existing Schema 60 install upgrades safely to Schema 61;
  - a ledger already containing PR #588's 074 preserves it and installs 075 beside it;
  - repeated `maybe_upgrade()` is idempotent;
  - booking/confirmation/delivery/decline row counts do not change during rehearsal.
- The migration is additive only: one new `booking_request_teacher_declines` table. No request, allocation, confirmation or provider table is altered.

### 2. Rebase with current main

The branch was rebased onto current `origin/main` (eight intervening commits) without losing the settings/RBAC work present in the separate workspace. No overlapping booking or migration change required manual conflict resolution beyond the migration renumbering.

### 3. Confirmed runtime defects and fixes

- **Teacher occupancy SQL aliases (pre-existing defect):** `TeacherBookingOccupancyQuery` selected literal/source columns without `source` / `conflict_id` aliases. Runtime produced PHP warnings and lost the conflict identifier. Fixed with explicit aliases.
- **Generic hard-conflict error:** the allocation candidate query collapsed real occupancy conflicts into `Booking time is no longer available`, so the card could not explain a genuine conflict. `BookingTeacherResponseService` now checks the shared Student/Teacher occupancy reads immediately before acceptance and raises the specific `student_slot_conflict` / `teacher_slot_conflict`; the canonical allocation check still runs afterward.
- **Mobile action row:** the two-column intro action grid had higher specificity than the mobile media query, so accept/reject stayed side-by-side at phone widths. The media query now matches the same specificity.
- **Single-card desktop width:** a lone request/opportunity now spans the attention grid instead of occupying one narrow third-width track.

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

- `platform/src/Core/Application/BookingTeacherResponseService.php` — reason validation/persistence, opportunity decline, eligibility and decline checks, specific hard-conflict diagnostics.
- `platform/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php` — decline evidence and exact course-eligibility read.
- `platform/src/Core/Infrastructure/Repository/TeacherOpenBookingRequestRepository.php` — country read, no preference truncation, declined-Teacher exclusion.
- `platform/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php` — country read, no preference truncation, explicit admin re-offer retires a previous decline.
- `platform/src/Core/Infrastructure/Repository/TeacherBookingOccupancyQuery.php` — fixed conflict column aliases.
- `platform/src/Portals/TeacherBookingClaimController.php` — accept/decline opportunity through the canonical claim authority.
- `platform/src/Portals/TeacherBookingResponseController.php` — assigned accept/decline with required rejection reason.
- `platform/src/Core/Infrastructure/Migration/Migrator.php` — additive migration `075_teacher_booking_declines`.
- `platform/delnavazan-platform.php` — schema 61.

### Acceptance/runtime verification

- `acceptance/bin/run-teacher-intro-opportunity-acceptance.sh`
- `acceptance/bin/common.sh` — forwards explicit runtime flags into disposable WP-CLI containers.
- `acceptance/Makefile` — new `teacher-intro-opportunity` target.
- `platform/tests/teacher-intro-opportunity-runtime.php`
- `platform/tests/teacher-intro-migration-runtime.php`
- `platform/tests/teacher-opportunity-claim-worker.php`
- `platform/tests/teacher-opportunity-claim-verify.php`

## Backend justification

The new `booking_request_teacher_declines` table records:

- booking request, Teacher and assigned delivery (when one exists);
- `assigned` or `opportunity` source;
- stable reason code and optional free text;
- an active/superseded state.

It never cancels, closes or confirms the Student request. The opportunity read excludes only active declines for the current Teacher, so other eligible Teachers still see the request. An explicit administrator reassignment retires an old decline before re-offering.

Both sources still use the same authority:

- assigned request → `TeacherBookingResponseController` → `BookingTeacherResponseService::respond()`;
- unassigned opportunity → `TeacherBookingClaimController` → `BookingTeacherResponseService::claim()`.

Both call the shared occupancy checks, `assertAcceptable()`, `confirmDelivery()`, `acceptedLocked()` and the existing projection/notification pipeline. No parallel booking or notification path was added.

## Runtime and concurrency results

Command:

```sh
make -C acceptance teacher-intro-opportunity
```

Result: **pass** (exit 0).

Verified against disposable WordPress + MariaDB:

- assigned green preference acceptance — pass;
- assigned yellow preference acceptance — pass, selected preference bound and override audited;
- assigned rejection — pass, reason persisted against request + delivery, request remains open;
- expired assignment — pass, no confirmation created;
- hard Teacher scheduling conflict — pass, specific `teacher_slot_conflict`;
- opportunity acceptance — pass, one delivery/offer/confirmation/notification through the canonical lifecycle;
- opportunity decline — pass, Teacher-specific row written, hidden from that Teacher's board, request remains unresolved and unassigned;
- Teacher eligibility — pass, ineligible Teacher refused;
- provisioning failure + replay + retry — pass, one confirmation/one offer, acceptance preserved, no premature notification;
- Persian/Gregorian deadline localisation, Persian country name and green/yellow classification — pass;
- migration 60→61, PR #588 074 preservation and idempotent repeat — pass.

Concurrent opportunity claim, two Teachers, separate WP-CLI/MySQL connections:

```json
{"result":"pass","winner_teacher_id":10,"deliveries":1,"confirmations":1,"offers":1,"notifications":0,"meet_classrooms":0,"request":"submitted_unresolved_retryable"}
```

Exactly one claim won; the other received `Booking request is no longer pending`. No duplicate delivery, confirmation, class, Meet classroom or notification was created. The winning acceptance remained durably retryable because the organizer was deliberately paused for deterministic testing.

## Regression/source tests

Passed:

```text
php platform/tests/teacher-intro-opportunity-board-contract.php
php platform/tests/teacher-portal-presentation-contract.php
php platform/tests/booking-teacher-response-unit.php
php platform/tests/booking-teacher-response-contract.php
php platform/tests/booking-teacher-delivery-contract.php
php platform/tests/teacher-classroom-finish-contract.php
node --check theme/assets/js/teacher-portal.js
```

PHP lint passed across `platform/` and `theme/`; `git diff --check` passed.

## UX verification

Static render evidence:

- `docs/evidence/teacher-intro-opportunity-20261110/preview.html`
- `docs/evidence/teacher-intro-opportunity-20261110/desktop.png`
- `docs/evidence/teacher-intro-opportunity-20261110/mobile.png`

Verified visually with headless Chrome at 500px and 1280px widths:

- assignee card and yellow opportunity card render through the same component;
- country is shown as **استرالیا** / **کانادا**, not an ISO code;
- green/yellow preference rows carry text labels as well as colour;
- countdown and full deadline sentence render;
- opportunity card has no countdown and carries the unassigned label;
- accept/reject stack on mobile and remain side-by-side on desktop;
- single cards use the available desktop width;
- expired state disables all actions and explains reassignment.

## Remaining risks

- Live Google Calendar/Meet success was not exercised because this environment has no organizer credentials. Local success is proven with a deterministic projector stub; the real projection service is exercised in paused/failed and administrator-retry states.
- The runtime concurrency winner remained `retryable` because the organizer was paused; the durable acceptance and absence of duplicates were verified.
- CI runtime execution requires the three cached Docker images and a Docker-capable worker.

## Merge/deploy recommendation

Ready for final review and CI. Do **not** merge or deploy until the PR runtime gate is green and the migration ordering with PR #588 is accepted. No deploy was performed.
