# Review — duplicate introductory bookings and Student slot prevention

Status: **review candidate, not merged and not deployed.** No production data was modified.

## Defects fixed

1. **Intake accepted a second overlapping request for the same Student.** The account-aware
   submission now passes the resolved Student into the intake transaction, acquires the per-Student
   scheduling root, and refuses any overlapping open request, in-flight confirmation, confirmed
   introductory class, canonical Lesson or legacy Lesson.
2. **Teacher acceptance did not re-check Student occupancy.** Both the Teacher-owned and
   administrator-confirmed paths now acquire the same Student root and re-check occupancy before
   creating a confirmation. An existing duplicate pair can therefore no longer both be accepted.
3. **Intake was `REPEATABLE READ`.** A contender that waited for the Student root could continue with
   a pre-wait snapshot and miss the winner's committed request. The intake and account transactions
   now use `READ COMMITTED`, matching acceptance and the existing scheduling transactions.
4. **Occupancy SQL was duplicated.** `StudentOccupancyQuery` is now the one read model for Student
   time ownership. Reschedule, replacement, canonical scheduling, legacy scheduling, intake and
   acceptance all use or delegate to it.
5. **Canonical and legacy scheduling did not check Student occupancy.** Both now enforce the shared
   rule at write time; legacy scheduling also acquires the Student root before locking the Lesson.

## Lock and transaction audit

- **Intake:** `acquireEmailLock` → `START TRANSACTION` (`READ COMMITTED`) → Student row/identity →
  submission key → **Student scheduling root** → catalogue locks → occupancy read → request,
  delivery and notification rows → account binding → `COMMIT`. The Student root is acquired before
  the occupancy read and remains held through commit.
- **Teacher/administrator acceptance:** transaction (`READ COMMITTED`) → Teacher/delivery locks →
  **Student scheduling root** → occupancy read → confirmation/delivery/notification rows → `COMMIT`.
- **Canonical schedule/revise:** `lockAggregate()` already acquires the Student root before the
  Lesson/enrolment chain; `assertStudentCapacity()` now runs before the Teacher root.
- **Legacy schedule/reschedule:** Student root is now acquired before the Lesson row and Teacher
  root. The confirmed-intro occurrence import uses the same order and excludes its own booking
  source.
- **Reschedule/replacement:** proposal row → Student root → shared occupancy read → canonical
  schedule service. Replacement issuance remains in the caller-owned transaction.

## Interval and state rules

- Occupancy is half-open: `[starts_at_utc, occupied_ends_at_utc)`.
- Introductory Course snapshot is 30 minutes plus the applicable 15-minute buffer; the occupied end
  is 45 minutes after the start.
- Canonical Lessons use their stored `occupied_ends_at_utc`; legacy Lessons add the historical
  15-minute buffer; booking confirmations use the requested time's `occupied_ends_at_utc`.
- Confirmed and in-flight confirmation states count: `pending_projection`, `awaiting_conference`,
  `retryable`, `awaiting_payment`, `confirmed`. `failed` does not.
- Unresolved requests count for intake only. Cancelled/declined/closed requests, failed
  confirmations and privacy-erased requests do not.
- There is no time-based expiry for an unresolved booking request. Expired submission keys are
  idempotency facts and create no occupancy; expired post-intro preparation is downstream and does
  not affect this rule.
- UTC instants are authoritative; the same instant expressed in another IANA timezone is refused.

## Test evidence

Source/contract suite (final pure-test bundle): **PASS** (all tests, including
`student-occupancy-authority-contract`).

Disposable WordPress + MariaDB integration (`run-booking-slot-conflict.sh`): **PASS**

- simultaneous overlapping submissions: one created, one `student_slot_conflict`
- simultaneous overlapping acceptances: one confirmation, one `student_slot_conflict`
- non-overlapping submission: created
- same UTC instant in `Asia/Tehran`: refused
- 30+15 half-open boundary: `10:44` conflicts, `10:45` allowed
- declined request: ignored
- pending confirmation: blocked
- failed confirmation: ignored
- canonical Lesson: blocked; self-exclusion verified
- legacy Lesson +15 buffer: blocked; occupied end allowed

Account-aware acceptance fixture: **PASS**, including the new duplicate 409 assertion.

Lock-wait attribution in the disposable MariaDB user is reported as `unavailable`; outcome
determinism is still enforced by the gate, worker artefacts and committed-row verifier.

## Production inspection (read-only)

References inspected: `REQ-01M6E0V3H3FSPDW0SR4X2W7W6E` (request 14) and
`REQ-01M6E6APWX3QGD7ZFK6HXK133V` (request 15), Student `DZN-STU-000007`.

| Fact | Request 14 | Request 15 |
| --- | --- | --- |
| Created | 2026-11-02 11:38:16 | 2026-11-02 13:14:10 |
| State | closed / `teacher_confirmed` | closed / `teacher_confirmed` |
| Teacher | 10 | 10 |
| Requested interval | 2026-11-03 05:45–06:15 UTC, occupied to 06:30 | same |
| Delivery | confirmed | confirmed |
| Confirmation | 6, confirmed | 7, confirmed |
| Calendar projection | active sealed secret | active sealed secret |
| Provider event digest | present, distinct from request 15 | present, distinct from request 14 |
| Linked Lesson | none | none |
| Payment obligation | none | none |
| Attendance | none | none |
| Confirmed notification | handed off | failed (`contact_unusable`) |
| Coordination case | none | case 3, open / `manual_review` |
| Duplicate flags | candidate side of 15's flags | three open flags pointing to 14 |

Both records have no Lesson, payment or attendance state. Both have active booking projection
secrets and distinct provider-event digests, so they represent two separate Calendar/Meet
projections. No provider object IDs or join URLs were read or printed.

### Retention recommendation

Retain **request 14 / confirmation 6**:

- it is the earlier request and the earlier confirmation;
- its confirmation notification was handed off successfully;
- the later request's duplicate flags all point to request 14;
- it has no open coordination case.

Request 15 / confirmation 7 is the later duplicate. Its notifications failed and it already has an
open `manual_review` coordination case. The case should be closed as duplicate once the later
confirmation is cancelled.

## Cleanup blocker

There is **no existing authorised cancellation workflow for a confirmed introductory booking**.
The Enrolment workspace explicitly directs operators to the protected booking record until a
canonical reschedule/cancel authority exists, but that record currently offers only duplicate review,
match assessment and privacy erasure for submitted/unresolved requests. Privacy erasure is not
applicable to these closed requests and would not retract the Calendar/Meet projection.

Therefore production cleanup cannot proceed safely with existing tooling. A dedicated cancellation
service is required to atomically:

1. lock and verify the duplicate/retention decision with audit evidence;
2. cancel the later confirmation/delivery/request without deleting history;
3. retire the sealed projection record and enqueue a provider `retract` operation;
4. queue one idempotent student notification;
5. close the coordination case with a duplicate reason;
6. keep the retained confirmation, notification and Calendar event untouched.

Until that authority is reviewed and explicitly approved, **do not cancel, delete or modify either
production record**.

## Remaining gaps

- No production cancellation path for confirmed intro bookings (blocker described above).
- No live Google Calendar retraction test; the projection contract is provider-neutral only.
- Lock-wait view attribution requires database privileges unavailable to the disposable test user;
  the race outcome is still proven by gating and committed rows.
- Existing duplicate confirmations are not repaired automatically.
