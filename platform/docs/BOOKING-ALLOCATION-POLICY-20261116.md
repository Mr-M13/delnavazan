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

### 5.1 A request that crosses the minimum notice after intake

The day grid, the intake transaction and `candidates()` share one authority,
`BookingLeadTimePolicy`: a start must be **strictly later** than
`now + 24h response window + 1h projection buffer`. The allocator no longer
re-derives that boundary; it calls `BookingLeadTimePolicy::isStartAllowed()` at
the instant it decides, which is the same rule and strictness the grid and
intake apply.

The boundary moves while a request is alive. A request accepted with just over
25 hours notice is inside the window by the time a Teacher declines, an offer
expires, or an operator re-runs `recover()`. Previously that produced
`no_viable_teacher` or `candidates_exhausted` — reasons that say every eligible
Teacher was considered, which is false: no Teacher could have been asked.

That case is now classified with `BookingLeadTimePolicy::allStartsInsideMinimumNotice()`
and referred with the reason **`requested_time_inside_lead_time`**. It is
retryable: the Student chooses later times and the request allocates normally.
When every requested start is inside the window the lead-time reason takes
precedence over Teacher-based reasons, because no Teacher decision can change
the outcome; a genuine coverage/occupancy failure with at least one
still-requestable start keeps `no_viable_teacher`/`candidates_exhausted`.

Accepting an **existing** offer is unaffected: it revalidates against the
projection buffer only (`includeResponseWindow = false`), because the response
window has already elapsed. Only a *new* offer is gated by the full window.

## 6. Rejected alternatives

- **Instrument-count normalisation** (divide workload by the number of eligible
  instruments) was rejected: it rewards a narrow portfolio rather than measuring
  actual work.
- **Currency of work** (favour Teachers who have not taught recently) was rejected
  as unapproved policy: it changes fairness semantics beyond workload volume.
- **Counting a pending offer at full weight** was rejected: unanswered offers
  would suppress a Teacher's availability as strongly as a confirmed class.

## 7. Evidence

Measured on a disposable WordPress 7.x + MariaDB 11.4 stack in GitHub Actions,
run `43742903765` (job `132899759731`), commit `da9a1edb`, 2026-11-16.
Duration 1m56s. Zero `deadlock`, `lock wait timeout` or `WordPress database error`
lines in any launcher or verify log (the runner greps for them and fails the step).

| Check | Environment | Result |
|---|---|---|
| `platform/tests/booking-allocation-unit.php` | native PHP 8.3.35 | PASS — strength, workload, round-robin, determinism |
| `platform/tests/booking-allocation-workload-unit.php` | native PHP 8.3.35 | PASS — 19 assertions: weights, query predicates, single counting, fail-closed read |
| `platform/tests/booking-engine-capacity-unit.php` | native PHP 8.3.35 | PASS — 14 overlap regressions |
| `platform/tests/booking-stress-runner-contract.sh` | native PHP 8.3.35 | PASS — 30 assertions |
| `scenario A` (50/100/500/1000 requests) | disposable MariaDB | PASS — 0 referrals, 0 errors, spread 0, Gini 0, cyclic order preserved |
| `scenario B` (cross-instrument scope) | disposable MariaDB | PASS — idle specialist takes the first Setar offer; totals 2/1/1 both orders |
| `scenario C` (500 mixed requests) | disposable MariaDB | PASS — 500 accepted, 0 referred, 0 errors |
| `scenario D` (lifecycle) | disposable MariaDB | PASS — decline, expiry, cancellation, projection failure, terminal failure |
| `scenario E` (boundaries, DST, lead time) | disposable MariaDB | PASS — 15 boundary cases, 2 DST conversions, Jalali, leave, 24.5h/25h/26h |
| `scenario F` (preferences, strength, claims) | disposable MariaDB | PASS — strong match offered over a lower-id possible match |
| `scenario G` (failure injection) | disposable MariaDB | PASS |
| concurrency `submit` 2…100 | disposable MariaDB | PASS — every worker succeeded; distinct DB sessions equal the level |
| concurrency `accept` 2…100 | disposable MariaDB | PASS — exactly one winner per level |
| `submit_partial` 25 | disposable MariaDB | PASS — 1 active offer, 24 referrals |
| `process` 2, `idempotent` 25, `submit_crash` 5 | disposable MariaDB | PASS |

### Measured fairness (scenario C, 500 requests over 10 Teachers)

| | min | max | spread | Gini |
|---|---:|---:|---:|---:|
| Audited per-instrument baseline | 27 | 143 | 116 | 0.297 |
| **Approved cross-instrument policy** | **37** | **125** | **88** | **0.1832** |

Gini improved 38% and spread fell 24%. The residual spread is **not** an ordering
defect and is fully explained by eligibility:

| Portfolio | Teachers | min | max | spread |
|---|---:|---:|---:|---:|
| `tar_only` | 4 | 37 | 38 | **1** |
| `setar_only` | 3 | 45 | 45 | **0** |
| `tar_setar` | 2 | 45 | 46 | **1** |
| `all_three` | 1 | 125 | 125 | 0 |

Within every interchangeable portfolio workload equalises to within one booking,
which is precisely what the least-loaded rule controls. The single `all_three`
Teacher holds 125 because 80 of the 500 requests are Santur and it is the only
Santur-eligible Teacher, giving a hard floor of 80 plus its share of Tar/Setar.
No greedy online policy can pre-empt a bottleneck that only appears in a later
phase of the request stream, so the global maximum is bounded by the corpus, not
by the ordering.

One honest consequence, worth review: **per-instrument** Setar equality got
*worse* (spread 37; the `all_three` Teacher takes only 8 Setar classes because it
is already the most loaded) while **cross-instrument total** equality got better.
That is the explicit trade-off the approved policy asks for — total workload
across instruments outranks per-instrument rotation.
