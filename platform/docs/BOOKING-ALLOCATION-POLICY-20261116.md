# Introductory booking — Teacher allocation policy

**Status:** approved policy, implemented on `test/booking-engine-stress-audit-20261115`
**Date:** 2026-11-16
**Scope:** introductory (`course_type='introductory'`) automatic Teacher selection only

This note records the approved allocation policy, the numerical workload weights,
and the exact query shape, so the behaviour can be reviewed without reading the
implementation.

---

## 1. Selection order

1. **Eligibility (hard filters, unchanged).** Course eligibility, active Teacher
   and onboarding state, accepting state, declared availability for the exact
   occupied interval, the 25-hour minimum notice, Teacher occupancy including
   course buffers and live offers, and continuation holds. A Teacher excluded
   here can never be selected by a later key.
2. **Stronger match first.** `strong` (declared `preferred` coverage on an
   `accepting` Teacher) before `possible` (declared `requestable` coverage, or a
   `limited` accepting state).
3. **Lowest weighted cross-instrument workload.** See §2.
4. **Deterministic round-robin.** The next eligible Teacher id after the Course
   allocation cursor, wrapping once; the Teacher id is the final total order.

Every key is total, so identical inputs always select the same Teacher.

## 2. Workload model and weights

Workload is summed across **all** introductory instruments and all lifecycle
states, so a Teacher who teaches Tar and Setar accrues one workload, not one per
instrument.

| Component | Weight | Source (authoritative row) |
|---|---|---|
| Pending offer | **0.25** | `booking_allocation_offers` where `state='pending' AND active_slot=1` |
| Accepted booking | **1.00** | `booking_request_confirmations` where `state NOT IN ('failed','cancelled')` |
| Confirmed lesson | **1.00** | `lessons` where `COALESCE(lifecycle_state,status) IN ('authorised','scheduled')` |
| Completed lesson | **1.00** | `lessons` where `COALESCE(lifecycle_state,status)='completed'` |

Constants live on `BookingTeacherWorkloadService` (`PENDING_OFFER_WEIGHT`,
`ACCEPTED_BOOKING_WEIGHT`, `CONFIRMED_LESSON_WEIGHT`, `COMPLETED_LESSON_WEIGHT`).

**Why 0.25 for a pending offer.** A pending offer is provisional: the Teacher has
not committed. At 0.25 it still tips a tie toward the Teacher who has not just
been offered work, but four unanswered offers are worth less than one confirmed
commitment, so an ignored inbox cannot lock a Teacher out of rotation.
Every weight is a multiple of 0.25, so the comparison is exact in binary
floating point and needs no epsilon.

**Rolling window.** Past work is bounded to **30 days**; future commitments are
never aged out.

- Accepted bookings: `starts_at_utc >= now - 30 days`.
- Completed lessons: `completed_at >= now - 30 days`.
- Confirmed lessons: no window, because they are still expected to be taught.
- Pending offers: no window, because `state='pending' AND active_slot=1` is live
  by definition.

## 3. No double counting

Each booking contributes exactly one term:

- A pending offer and the confirmation it later produces are *different* offer
  states (`pending` → `accepted`), so the same booking cannot appear in two terms.
- An accepted booking and its confirmation are the same row, counted once.
- **Administrator/manual assignments** produce the same confirmation and are
  therefore counted once there. The delivery row is never read by the workload
  query, which the `booking-allocation-workload-unit.php` regression asserts.
- **Introductory canonical lessons are excluded** (`lesson_type<>'introductory'`).
  An intro that is later projected into a canonical lesson is already counted
  from the confirmation, so counting the projection too would double it.
- Cancelled and failed confirmations, and archived lessons, are excluded.

## 4. Concurrency and determinism

- The workload read happens **inside** the allocation transaction, after
  `lockEligibleTeacherRoots()` has locked every eligible Teacher root in ascending
  id order. A concurrent allocation therefore cannot change a score between the
  read and the offer insert, and lock ordering is unchanged, so the existing
  deadlock-avoidance property holds.
- No global lock is introduced. The read is three grouped aggregates for a whole
  candidate set, not one query per Teacher.
- The Course cursor is still advanced only when a *new* request is accepted, so a
  decline or expiry rotates to a different Teacher without resetting round-robin.
- The selected Teacher id is appended to the offer row as before, so the sequence
  is reproducible from the database.

## 5. Failure behaviour

If any workload aggregate fails (`$wpdb->last_error` set) the read throws and the
allocation transaction rolls back. A workload read never degrades into "every
Teacher is idle", which would silently disable the policy. This is asserted by
`booking-allocation-workload-unit.php`.

## 6. Rejected alternatives

- **Instrument-count normalisation** (divide workload by the number of eligible
  instruments) was rejected: it rewards a narrow portfolio rather than measuring
  actual work.
- **Currency of work** (favour Teachers who have not taught recently) was rejected
  as unapproved policy: it changes fairness semantics beyond workload volume.
- **Counting a pending offer at full weight** was rejected: unanswered offers
  would suppress a Teacher's availability as strongly as a confirmed class.

## 7. Evidence

| Check | Environment | Result |
|---|---|---|
| `platform/tests/booking-allocation-unit.php` | native PHP 8.3.35 | PASS — ordering: strength, then workload, then round-robin; determinism |
| `platform/tests/booking-allocation-workload-unit.php` | native PHP 8.3.35 | PASS — weight arithmetic, query predicates, single counting, fail-closed read |
| `platform/tests/booking-engine-capacity-unit.php` | native PHP 8.3.35 | PASS — overlap authority |
| `platform/tests/booking-engine-scenario-b.php` | disposable WordPress/MariaDB | **pending runtime execution** — cross-instrument scope |
| `platform/tests/booking-engine-scenario-c.php` | disposable WordPress/MariaDB | **pending runtime execution** — 500-request fairness measurement |
| `platform/tests/booking-engine-scenario-f.php` | disposable WordPress/MariaDB | **pending runtime execution** — strength before id |

The runtime rows are unverified in the authoring environment (no Docker). Do not
treat the fairness improvement as measured until a run of
`run-booking-engine-stress.sh` on a disposable stack records
`fairness_global_workload` for scenario C and the audited baseline (range
27–143, Gini 0.297) is replaced with a measured post-change value.
