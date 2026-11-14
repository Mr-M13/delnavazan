# Delnavazan introductory booking engine — stress audit and allocation review

**Date:** 2026-11-15
**Code under test:** `origin/main` at `27f789463e4282c8fb0e3eee4623d6a403e9632b` (PR #623 merge), schema 61
**Environment:** disposable WordPress 7.1.2 + MariaDB 11.4.13, loopback only
**Status:** completed audit and regression assets; no production change, no merge, no deployment
**Draft PR:** https://github.com/Mr-M13/delnavazan/pull/626

---

## 0. Executive verdict

**The tested code protects the critical safety invariant.** Across 2–100
simultaneous submitters and 2–100 competing Teacher acceptances, including
partially overlapping timeslots, the audit observed:

- exactly one active introductory offer per Teacher/timeslot;
- exactly one confirmation when competing acceptances targeted the same
  Teacher/timeslot;
- **zero confirmed double-bookings**;
- **zero overlapping confirmed capacity intervals**;
- 100 distinct database sessions in the 100-worker bursts;
- zero database deadlocks in the audit window.

**It does not make every allocation decision the best business decision.**
Four confirmed gaps remain:

1. **Teacher quality ordering is ignored.** `match_strength` is computed and
   stored, but Teacher selection is ordered only by Teacher database id. A
   lower-id “possible” match is offered before a higher-id “strong” match.
2. **Fairness is instrument-scoped only.** A Teacher who teaches Tar and Setar
   gets one independent turn in each instrument’s cursor. In a 500-request
   mixed workload, total introductory workload ranged from 27 to 143 lessons
   per Teacher (Gini 0.297) while each instrument was internally fair.
3. **Requests inside a 25-hour lead time cannot be allocated.** Initial offers
   require `start > now + 24h response window + 1h projection buffer`. A
   request at 24.5 hours or exactly 25 hours was referred; 26 hours was
   offered. The public booking window permits next-day requests.
4. **Concurrent demand for one slot becomes coordination work.** 100 students
   booking the same Teacher/timeslot correctly produced one offer and 99
   referrals, not a waitlist or a clear “fully booked” outcome.

The current round-robin is therefore **deterministic and fair within an
instrument among equally eligible Teachers**, but **not a global workload
balancer** and **not quality-ranked**.

---

## 1. Scope: executed versus code-review-only

| Area | Status | Evidence |
|---|---|---|
| Booking intake, idempotency, rollback | Executed | Scenario G; 25-worker same-key replay |
| Teacher selection / round-robin | Executed | Scenarios A, B, C |
| Multi-instrument scope | Executed | Scenario B, C |
| Lifecycle (decline/expire/accept/cancel/failure) | Executed | Scenario D |
| Partial availability, buffers, boundaries, midnight, DST, timezone, Jalali | Executed | Scenario E |
| Preferences, match strength, claims, opportunity board | Executed | Scenario F |
| Concurrent submissions and acceptances | Executed | 2–100 workers |
| Two workers processing one expired request | Executed | Process-2 |
| Worker crash and retry | Executed | Crash-5 |
| Database outage and recovery | Executed | Stop/start DB, fail closed, retry succeeded |
| Real Google Calendar/Meet provisioning | **Not executed** | Injected failing projector; no external call |
| Real email/SMS/WhatsApp delivery | **Not executed** | Portal obligation only |
| Stripe/payment execution | **Not executed** | No financial transaction |
| Student no-show attendance effect | **Code review only** | No allocation read path for attendance |
| Production records/configuration | **Not touched** | Synthetic stack only |

All results below are from actual executions unless explicitly labelled
“code review”.

**Governance.** The work followed the engineering and handover rules referenced
in PR #601: preserve existing work, use an isolated worktree and disposable
runtime, create no real provider/payment/communication side effects, add
regression tests, and do not merge or deploy. No application code was changed
by this audit; the deliverable is tests, a guarded runner, evidence and this
report.

---

## 2. What the current code actually implements

### 2.1 Intake is a transaction that ends in a pending offer

`BookingRequestSubmissionService::submitPublic()` opens a `READ COMMITTED`
transaction, resolves the submission key, acquires the Student scheduling root,
locks the Instrument/Course, creates the request, contact snapshot and requested
times, then calls `BookingTeacherDeliveryService::deliver()` inside the same
transaction. That call delegates to
`BookingAllocationService::startWithinTransaction()`.

The intake path never creates a Lesson, confirmation, Calendar event or Meet
space. The “booking” is a submitted request plus one active Teacher offer with
a 24-hour deadline.

### 2.2 Teacher selection is a per-Course cursor over Teacher ids

`BookingAllocationService`:

- `lockEligibleTeacherRoots($courseId)` locks every eligible Teacher’s
  canonical scheduling root in ascending Teacher-id order.
- `cursor($courseId)` reads `dzn_booking_allocation_cursors.last_teacher_id`,
  keyed by the **selected introductory Course** (effectively the instrument).
- `candidates()` builds one candidate per eligible Teacher and requested time.
  A candidate is “strong” when the whole occupied interval is in a `preferred`
  availability segment and the Teacher’s accepting state is `accepting`;
  otherwise it is “possible”. For each Teacher it keeps the best
  (strength, preference sequence) time.
- `next()` returns the first candidate with `teacher_id > last`, else wraps to
  the lowest candidate id, skipping Teachers already offered this request.
- `startWithinTransaction()` advances the cursor only when a **new request** is
  first allocated. It then creates the delivery and offer.

The cursor is **not** a count of lessons. It is the last Teacher id used for an
initial offer in that Course.

### 2.3 Match strength is stored, not used to rank Teachers

`next()` receives an array keyed by Teacher id and iterates `array_keys()`.
It never compares `match_strength` between Teachers. `match_strength` only
decides which requested time is chosen **within one Teacher**, and is persisted
for display/audit. Scenario F demonstrates the consequence directly.

### 2.4 Offer lifecycle

- One active offer per request (`UNIQUE KEY request_active(booking_request_id, active_slot)`).
- One offer per Teacher per request (`UNIQUE KEY request_teacher(...)`).
- Offer sequence is unique per request.
- Response window: 24 hours.
- Decline or expiry calls `advanceLocked()`: marks the offer declined/expired,
  chooses the next non-offered candidate after the current Teacher, rotates the
  delivery and creates the next offer.
- **Decline and expiry do not advance the Course cursor.** Only the initial
  allocation does.
- Exhaustion creates a coordination referral (`candidates_exhausted`).
- A two-minute worker advances expired offers and escalates after 72 hours from
  the original submission.

### 2.5 Acceptance is revalidated under a Teacher lock

`BookingTeacherResponseService::respond()`:

1. replays an existing command key idempotently;
2. loads the Teacher from the principal link;
3. locks the delivery/offer and checks the active offer;
4. revalidates the exact requested time and availability;
5. acquires the Student scheduling root, checks Student occupancy;
6. acquires the Teacher scheduling root, checks Teacher occupancy;
7. creates one confirmation and transitions the delivery to `accepting`;
8. projects to the provider later.

The teacher-wide occupancy read includes pending offers during allocation, and
confirmations/canonical Lessons during acceptance. This is the core
double-booking guard.

### 2.6 Availability is an exact covered interval, including the buffer

`TeacherAvailabilityService::effective()` merges recurring rules, dated
exceptions and open-ended temporary unavailability. `coverage()` requires
contiguous `preferred`/`requestable` coverage of the **entire**
`[starts_at_utc, occupied_ends_at_utc)` interval. The occupied end is the
instructional end plus the Course buffer (normally 30 + 15 minutes).

Adjacent availability records are merged by the resolver. DST gaps and folds
are rejected by `AvailabilityLocalTime` rather than guessed. Quiet hours are
advisory only; they do not remove coverage.

### 2.7 The reported lock/constraint model

| Protection | Implementation |
|---|---|
| Submission concurrency | One transaction; all eligible Teacher roots locked in stable id order; Course cursor row `FOR UPDATE`; unique active-offer indexes |
| Acceptance concurrency | Teacher root `FOR UPDATE`, then Teacher occupancy re-read under that lock |
| Student duplicate prevention | Student scheduling root + Student occupancy read + request/confirmation states |
| Idempotency | HMAC-digested submission keys and Teacher command keys with replay |
| Availability/occupancy | Canonical Lesson schedules, in-flight confirmations, pending offers (allocation only), continuation holds |
| Audit | Offer history, declines, commands, platform audit events, coordination cases and alerts |

There is **no database uniqueness constraint on a Teacher/timeslot
confirmation**. Safety relies on the Teacher scheduling root and the occupancy
read. The concurrency tests confirm the current implementation is safe, but a
future writer that bypasses the root would not be stopped by the schema alone.

---

## 3. Synthetic test population

The audit created more than 20 Teachers and more than 100 Students across
isolated courses. The population included:

- Tar-only, Setar-only, Tar+Setar, and Tar+Setar+Santur Teachers;
- identical all-day availability, overlapping availability, limited windows,
  adjacent windows, requestable vs preferred windows;
- Teachers in UTC, Asia/Tehran and America/New_York;
- Teachers on open-ended leave and Teachers with `limited` accepting state;
- Teachers with prior offers/confirmations and Teachers with no history;
- Students in UTC and Asia/Tehran, single and multiple preferences, duplicate
  submissions, retries, simultaneous submissions and cancelling requests.

Every row was tagged with a synthetic run suffix. No real identity, provider,
payment or production record was used.

**Reproducibility caveat:** the suite uses fresh unique identities per run
rather than a fixed seed. The allocation logic itself is deterministic for a
given Teacher-id order; exact Teacher ids differ between runs. The assertions
are order- and distribution-based, not id-based.

---

## 4. Scenario results

### 4.1 Scenario A — five identical Setar Teachers

Expected from the implemented policy: a strict cyclic order over the sorted
eligible Teacher ids; counts differ by at most one.

| Requests | Allocated | Referred | Distribution | Spread | Gini | Cycle order | Median / P95 / P99 |
|---:|---:|---:|---|---:|---:|---|---|
| 50 | 50 | 0 | 10 / 10 / 10 / 10 / 10 | 0 | 0 | exact | 17.0 / 18.8 / 32.4 ms |
| 100 | 100 | 0 | 20 each | 0 | 0 | exact | 17.1 / 18.8 / 20.6 ms |
| 500 | 500 | 0 | 100 each | 0 | 0 | exact | 17.3 / 20.1 / 33.9 ms |
| 1000 | 1000 | 0 | 200 each | 0 | 0 | exact | 12.9 / 19.2 / 28.5 ms |

**Result: PASS.** Identical eligibility produces perfectly equal initial
allocation and a deterministic sequence. No Teacher received a disproportionate
share.

### 4.2 Scenario B — multi-instrument fairness scope

Configuration 1 (A created first, then B Setar-only, C Tar-only):

- Tar request → A.
- Setar request → A (separate Setar cursor also starts at A).
- Two full cycles: A=2, B=1, C=1.

Configuration 2 (B, C created first; A created last):

- Tar request → C; Setar request → B.
- Second cycle: Tar → A; Setar → A.
- Two full cycles: A=2, B=1, C=1.

**Result: PASS for the implemented per-Course policy.** Fairness is measured
**separately within each instrument**, not across total introductory workload.
A multi-instrument Teacher can receive one lesson in each instrument’s cycle.

### 4.3 Scenario C — 500 mixed requests across 10 Teachers

Request mix: 260 Tar, 160 Setar, 80 Santur. All 500 offers were accepted through
the real Teacher response path.

| Teacher role | Tar | Setar | Santur | Total offers / confirmed |
|---|---:|---:|---:|---:|
| Tar-only ×4 | 37–38 | 0 | 0 | 37–38 |
| Setar-only ×3 | 0 | 27 | 0 | 27 |
| Tar+Setar ×2 | 37 | 26–27 | 0 | 63–64 |
| All three ×1 | 37 | 26 | 80 | **143** |

Per-instrument fairness:

| Instrument | Eligible Teachers | Min–max | Spread | Gini |
|---|---:|---:|---:|---:|
| Tar | 7 | 37–38 | 1 | 0.0033 |
| Setar | 6 | 26–27 | 1 | 0.0083 |
| Santur | 1 | 80 | 0 | 0 |

Global workload: min 27, max 143, **Gini 0.297, CV 0.671**.
All 500 confirmations succeeded; 0 double-bookings; 0 capacity violations.
Allocation median/P95/P99: 24.5 / 28.5 / 36.2 ms. Acceptance: 14.3 / 18.4 /
31.5 ms.

**Result: PASS for per-instrument fairness; NOT PASS as global workload
balancing.** The imbalance is driven by the instrument-scoped policy and by
Santur having only one eligible Teacher, not by a within-instrument bug.

### 4.4 Scenario D — allocation lifecycle

| Event | Observed | Cursor effect |
|---|---|---|
| Teacher declines first offer | Request advances to next Teacher | Cursor remains on the declining Teacher |
| New request after a decline | Starts after the original cursor Teacher, not the accepting Teacher | One cursor step per request |
| Offer expires | `process()` advances to the next Teacher | Cursor remains on the expired-offer Teacher |
| Accepted then cancelled | Confirmation and delivery become cancelled; occupancy released; same interval re-offered to the same Teacher | No cursor change |
| Downstream projection fails | Confirmation remains `pending_projection`, delivery `accepting`; interval stays occupied | No cursor change |
| Confirmation becomes terminally `failed` | Interval released; same Teacher can be offered again | No cursor change |
| Completion/confirmed state | No allocation participation; ordinary cycle continues | No cursor change |

**Result: PASS.** Decline/expiry do not corrupt the next initial allocation.
Failure states fail closed while capacity is unresolved and release capacity
only on a terminal failure. Cancelled/failed confirmations do not permanently
block the Teacher.

### 4.5 Scenario E — partial availability and time boundaries

| Case | Expected | Actual | Result |
|---|---|---|---|
| E1 Teacher 10:00–10:15, request 10:00–10:30 (+15) | Not covered | no offer | PASS |
| E2 Teacher 10:15–10:45, request 10:00–10:30 | Not covered | no offer | PASS |
| E3 Teacher 10:00–10:30, request 10:15–10:45 | Not covered | no offer | PASS |
| E4 Adjacent 10:00–10:15 + 10:15–10:30, buffer-free request | Covered | offer | PASS |
| E4b Adjacent covering 10:00–10:45 occupied interval | Covered | offer | PASS |
| E5 30-minute availability, 30+15 request | Not covered | no offer | PASS |
| E5b 45-minute availability, 30+15 request | Covered exactly | offer | PASS |
| E6 Exact end boundary at 10:45 | Covered | offer | PASS |
| E7 One minute short of the buffer | Not covered | no offer | PASS |
| E8 23:00 + 45 min inside 22:00–24:00 | Covered | offer | PASS |
| E9 23:30 + 45 min crosses midnight | Not covered | no offer | PASS |
| E10 DST spring-forward 03:00 local (America/New_York) | Covered | offer | PASS |
| E11 DST fall-back 02:00 local | Covered | offer | PASS |
| E12 10:00 Asia/Tehran = 06:30 UTC | Covered by UTC 06:30–07:30 | offer | PASS |
| E13 2026-12-15 = 1405-09-24 Jalali, full-day availability | Covered | offer | PASS |
| E14 Existing confirmed class inside requested interval | Teacher excluded | no offer | PASS |
| E15 Open-ended leave | Teacher excluded | no offer | PASS |
| E16 `limited` accepting state | Eligible, `possible` match | offer with `possible` | PASS |
| E17 Lead-time boundary | 24.5h and 25h no offer; 26h offer | as expected | **PASS (but see H3)** |

DST gap `02:30` on spring-forward and ambiguous `01:30` on fall-back were both
rejected with `UnavailableLocalTimeException`. No Teacher was presented as
available for an interval that could not actually be accommodated.

### 4.6 Scenario F — preferences, match strength, claims, opportunity board

| Case | Expected | Actual | Result |
|---|---|---|---|
| Three preferences, Teacher covers all | Earliest preference | #1 offered | PASS |
| Teacher covers only #3 | #3 offered, no referral | #3 offered | PASS |
| Teacher covers #1 and #3 | #1 offered | #1 offered | PASS |
| Teacher A only #1, Teacher B only #2 | Teacher id order decides | lower-id A / #1 | PASS (policy is id-first) |
| Lower-id `possible`, higher-id `strong` | Current code has no quality ranking | lower-id `possible` offered | **PASS for code; finding H1** |
| Claim outside availability | Explicit claim, audit override | confirmed; audit reason `teacher_claim_availability_override` | PASS |
| Second Teacher claims same request | Refused | refused | PASS |
| Later request at claimed interval | No offer to claimed Teacher | no offer | PASS |
| Opportunity board, multi-instrument | Only eligible Teacher sees it | Tar-only Teacher did not see Setar request | PASS |
| Opportunity decline | Teacher-scoped only | declining Teacher no longer sees it; other Setar Teacher still sees it | PASS |
| After claim | Removed from board | removed for all | PASS |
| Manual claim cursor effect | No cursor advance | cursor unchanged | PASS |

### 4.7 Scenario G — failure injection and recovery

| Failure | Result | Evidence |
|---|---|---|
| Offer-notification insert fails after delivery | Whole intake transaction rolled back; 0 committed requests | Error `Allocation notification persistence failed`; no contact snapshot |
| Delivery/assignment insert fails | Whole intake transaction rolled back; 0 committed requests | Error `Booking request teacher delivery persistence failed`; no snapshot |
| Portal notification insert fails | Alert remains unnotified; retry succeeds; one inbox row; second worker run does not duplicate | `first_state=null`, retry notified, inbox rows=1 |
| Projection fails after acceptance | Confirmation `pending_projection`; replay with same command is idempotent; exactly one confirmation | `confirmation_id` stable; count=1 |

### 4.8 Database outage

The database container was stopped. Submission failed closed with
`Error establishing a database connection` before any write. After restart, the
same key succeeded exactly once (`requests=1`). No orphan or partial request was
found.

---

## 5. Concurrency and load results

### 5.1 Simultaneous submissions for one Teacher/timeslot

All workers used distinct Student identities and separate database
connections, and paused at the Student-root hook before a simultaneous release.
Expected: every intake succeeds; exactly one Teacher offer; all others become
coordination referrals; zero confirmations.

| Workers | Intake rows | Active offers | Referrals | Confirmations | Distinct DB sessions | Median / P95 / P99 |
|---:|---:|---:|---:|---:|---:|---|
| 2 | 2 | 1 | 1 | 0 | 2 | 77.3 / 143.5 / 143.5 ms |
| 5 | 5 | 1 | 4 | 0 | 5 | 194.6 / 318.5 / 318.5 ms |
| 10 | 10 | 1 | 9 | 0 | 10 | 264.0 / 398.5 / 398.5 ms |
| 25 | 25 | 1 | 24 | 0 | 25 | 500.2 / 738.4 / 760.7 ms |
| 50 | 50 | 1 | 49 | 0 | 50 | 601.4 / 1173.8 / 1213.0 ms |
| 100 | 100 | 1 | 99 | 0 | 100 | 1606.5 / 2759.4 / 2862.4 ms |

No worker errors, no deadlocks, no duplicate offers. **PASS.**

The rising latency is the visible cost of locking **all eligible Teacher roots**
for the Course and serialising initial offers on the per-Teacher roots. The 100
submissions completed in about 2.9 seconds (roughly 35 intake transactions per
second in this one-Teacher, one-slot scenario).

### 5.2 Competing Teacher acceptances

Two to one hundred pending offers were intentionally injected for the same
Teacher/timeslot (a fault-injection state that normal allocation should prevent)
to test the acceptance guard.

| Workers | Successful acceptances | Refused | Confirmations | Losing pending deliveries | Median / P95 / P99 |
|---:|---:|---:|---:|---:|---|
| 2 | 1 | 1 (`teacher_slot_conflict`) | 1 | 1 | 57.6 / 67.4 / 67.4 ms |
| 5 | 1 | 4 (`teacher_slot_conflict`) | 1 | 4 | 62.4 / 78.6 / 78.6 ms |
| 10 | 1 | 9 (`teacher_slot_conflict`) | 1 | 9 | 90.3 / 125.5 / 125.5 ms |
| 25 | 1 | 24 (`teacher_slot_conflict`) | 1 | 24 | 227.6 / 315.1 / 323.1 ms |
| 50 | 1 | 49 (`teacher_slot_conflict`) | 1 | 49 | 252.7 / 412.4 / 423.4 ms |
| 100 | 1 | 99 (`teacher_slot_conflict`) | 1 | 99 | 721.4 / 1169.9 / 1293.7 ms |

**PASS on the safety invariant:** exactly one confirmation, zero double
bookings, zero overlapping confirmations.

**Cleanup observation:** the losing duplicate offers remain `pending` with no
Teacher able to accept them; they would expire after 24 hours. This state is
not reachable through normal allocation, but a repair path would be safer.

### 5.3 Partially overlapping simultaneous submissions

25 students submitted alternating 10:00 and 10:15 starts to the same Teacher.
Result: 25 intake rows, **1 active offer**, **24 referrals**, 0 confirmations.
PASS: only one half-open interval can own the Teacher.

### 5.4 Idempotent replay

25 concurrent workers submitted the same public payload with the same
idempotency key. Result: **1 request**, **1 distinct reference**, 25 successful
replays, 25 distinct database sessions. PASS.

### 5.5 Two workers process the same expired request

Two workers ran `BookingAllocationService::process()` for one request whose
offer had expired. Result: exactly one new offer; offer history is
`expired(sequence 1) → pending(sequence 2)`; the Course cursor remained on the
original Teacher. PASS.

### 5.6 Worker crash and retry

Five submitters paused with open transactions. One was killed with `SIGKILL`
before release. The other four committed; the killed transaction rolled back
with no request and no stuck lock. Retrying the same Student/key created the
fifth request. Final state: 5 intake rows, 1 offer, 4 referrals. PASS.

### 5.7 Load and database contention

The final suite executed 2,625 synthetic requests, 516 confirmations and 228
referrals in 106 seconds (all scenarios, including the concurrency bursts). The
database reported:

- `Max_used_connections = 100` during the 100-worker burst;
- `Innodb_deadlocks = 0`;
- row-lock waits were observed (1,271 cumulative across the repeated runs,
  average wait 485 ms), consistent with intentional Teacher-root serialisation;
- no lost requests, duplicate confirmations or capacity violations.

The 1,000-request sequential Scenario A completed with median 12.9 ms and P95
19.2 ms per allocation. Scenario C's 500 allocation+acceptance pairs completed
with median 24.5 ms / 14.3 ms respectively.

---

## 6. Allocation fairness analysis and recommendation

### 6.1 What “fair” means in the current code

The current code is fair in the following precise sense:

> For one introductory Course, among Teachers who are eligible for the whole
> test period and have equivalent availability, the initial offers follow a
> deterministic cycle and differ by at most one.

It is not fair in these senses:

- total introductory workload across Teachers with different instrument
  portfolios;
- workload including accepted, completed, cancelled or manually assigned
  lessons;
- Teacher match quality (`strong` vs `possible`);
- Teacher-specific capacity or availability weighting beyond simple eligibility.

### 6.2 Implications of global vs instrument-specific round-robin

**Instrument-specific (current):**

- *Advantages:* a Tar teacher is not penalised because they do not teach Setar;
  scarce instruments can still be covered; demand shocks in one instrument do
  not consume turns in another; the decision is simple and auditable.
- *Costs:* a multi-instrument Teacher receives one turn per instrument, so a
  Teacher eligible for three instruments can receive roughly three times the
  introductory volume of a single-instrument Teacher at equal demand. In the
  500-request test, the all-three Teacher received 143 lessons versus 27 for a
  Setar-only Teacher.

**Global:**

- *Advantages:* equalises total introductory workload.
- *Costs:* a Teacher who is the only eligible Teacher for an instrument may be
  skipped because they already had a Tar lesson, leaving a Setar request
  uncovered; scarce instruments become harder to staff; a Teacher can be
  penalised for having a broad skill set.

### 6.3 Recommended policy

**Keep instrument-specific fairness as the primary rule, and add a secondary
global workload guard.** Concretely:

1. Within the eligible Teachers for the Course, compute a workload score over a
   rolling window:
   `active future offers + confirmed introductory lessons + completed
   introductory lessons + manual assignments` (optionally weighted by recency).
2. Select the least-loaded Teacher whose score is within a configurable band of
   the minimum (for example ±1 lesson or ±10%).
3. Break ties with the existing deterministic Course cursor, then Teacher id.
4. Keep match strength as a **higher-priority eligibility/preference tier**:
   offer a `strong` candidate before a `possible` candidate when one exists,
   unless the strong Teacher is unavailable/at capacity or the request has
   already rotated through them.
5. Record the selected Teacher, score, cursor and candidate set in the offer
   history/audit so an allocation can be explained later.
6. Treat manual administrator assignments and claims as workload events even
   though they do not advance the cursor.

This preserves instrument coverage while preventing a multi-instrument Teacher
from being systematically overloaded. It also makes “fairness” measurable as a
workload distribution, not just an offer sequence.

### 6.4 Recommendation on lifecycle counting

Count **accepted/confirmed** lessons for workload, not only offers. Offers that
are declined or expired should not permanently count as delivered workload, but
repeated declines are useful operational evidence. A separate declining rate
can be reported without treating a decline as a completed lesson.

---

## 7. Confirmed findings and prioritized improvements

### Critical

No critical data-integrity, double-booking or security defect was confirmed in
the tested paths.

### High

**H1 — Match strength does not rank Teachers.**
- *Cause:* `BookingAllocationService::next()` iterates candidate Teacher ids
  only; `match_strength` is used to choose the time within a Teacher.
- *Evidence:* Scenario F: lower-id `possible` Teacher 436 was offered before
  higher-id `strong` Teacher 437.
- *Correction:* add match strength as the first ordering key (strong before
  possible), then fairness/cursor, with a deterministic tie-break.
- *Benefit:* better student/teacher fit and consistency with the portal’s
  “strong/possible” language.
- *Complexity:* Medium; requires product approval because it changes allocation
  order.
- *Regression tests:* same-request two-Teacher strong/possible scenario;
  multiple preferences; deterministic sequence.

**H2 — Instrument-scoped fairness can systematically overload multi-instrument
Teachers.**
- *Cause:* one cursor per Course and no global workload term.
- *Evidence:* Scenario C, 143 vs 27 total lessons; Gini 0.297.
- *Correction:* hybrid policy in §6.3.
- *Benefit:* fair total workload without losing instrument coverage.
- *Complexity:* Medium–High; needs a workload read model and a policy version.
- *Regression tests:* mixed-capability distribution bounds; scarce-instrument
  coverage; manual-assignment inclusion.

**H3 — The 25-hour lead time is invisible to the booking form.**
- *Cause:* `minStart = now + RESPONSE_SECONDS (24h) + PROJECTION_BUFFER (1h)`,
  while the public horizon permits next-day dates.
- *Evidence:* Scenario E17: 24.5h and 25h produced referrals; 26h produced an
  offer.
- *Correction:* either expose the minimum lead time in availability/UI and
  reject earlier requests, or allow a shorter response window with an
  operational escalation path. Do not accept a request that can never receive
  an initial offer without telling the Student.
- *Benefit:* fewer unexplained coordination cases and lost requests.
- *Complexity:* Medium.
- *Regression tests:* boundary at 24h59m/25h/25h1m; UI/API message; referral
  reason.

### Medium

**M1 — Same-slot demand creates a referral per losing Student.**
- *Cause:* intake is intentionally provisional; only one active offer per
  Teacher/time; all other requests go to coordination.
- *Evidence:* 100 simultaneous students → 1 offer, 99 referrals.
- *Correction:* add a waitlist/queue or return an explicit “no Teacher capacity
  for this time” outcome, and aggregate referrals for administrators.
- *Benefit:* less coordination load, clearer Student expectations.
- *Complexity:* Medium (product decision + queue state).
- *Regression tests:* 25/100 same-slot submissions; waitlist promotion when the
  offer expires/declines; no duplicate booking.

**M2 — Fairness ignores accepted/completed/manual workload.**
- *Cause:* cursor advances only on initial allocation; claims and administrator
  matches do not advance it; cancellations do not roll it back.
- *Evidence:* Scenario D (cursor unchanged by decline/expiry/completion);
  Scenario F (claim cursor unchanged).
- *Correction:* workload-aware policy in §6.3; count manual assignments and
  accepted lessons.
- *Benefit:* fairness reflects delivered teaching, not just offers.
- *Complexity:* Medium.
- *Regression tests:* manual match + claim + cancellation distribution; accepted
  vs offered metrics.

**M3 — Submission locks every eligible Teacher root, limiting throughput.**
- *Cause:* `lockEligibleTeacherRoots()` locks the full eligible set before the
  candidate read, and the lock is held to commit.
- *Evidence:* 100 one-Teacher submissions completed in ~2s with P95 1.88s;
  latency rose monotonically with concurrency; 1,271 row-lock waits.
- *Correction:* lock only the Teachers that can actually be selected (after a
  read-only candidate prefilter), or use a per-Course allocation queue/lease;
  preserve the global lock order.
- *Benefit:* higher throughput and lower tail latency at scale.
- *Complexity:* High (deadlock-order and correctness review required).
- *Regression tests:* 100/500 concurrent submissions; mixed Course eligibility;
  deadlock-freedom; deterministic allocation.

**M4 — Losing duplicate offers are not retired after one acceptance wins.**
- *Cause:* the acceptance guard refuses the loser but does not cancel/retire
  competing offers for the same Teacher/time.
- *Evidence:* 2–100 acceptance fault-injection: losers remain `pending` and
  pending deliveries remain `pending_request`.
- *Correction:* when a confirmation is created, retire any other active offer
  for the same Teacher/overlapping interval with reason
  `capacity_lost`; or prevent the state through a stronger constraint.
- *Benefit:* cleaner Teacher inbox, less confusion, fewer stale deadlines.
- *Complexity:* Medium.
- *Regression tests:* duplicate-offer fault injection; inbox reads; expiry
  worker.

**M5 — Lock-wait timeout is reported as “Active Teacher required”.**
- *Cause:* `teacherForUser()` returns null after a database lock-wait timeout,
  and the service maps null to an authorization-like error.
- *Evidence:* observed during an artificial 50-second Teacher-row hold; losers
  failed with “Active Teacher required”, not a retryable conflict.
- *Correction:* inspect `$wpdb->last_error`, map lock timeouts to
  `teacher_busy`/retryable, and keep authorization errors distinct.
- *Benefit:* accurate client behavior and diagnostics under load.
- *Complexity:* Small.
- *Regression tests:* forced row-lock timeout; retry path.

### Low

**L1 — A new low-id Teacher waits for the cursor to wrap.**
Bounded, but onboarding latency can be one full rotation. Consider seeding the
cursor or scheduling new Teachers explicitly.

**L2 — A cancelled/failed request still consumed one cursor step.**
The request may never become a lesson, but the initial offer already advanced
the rotation. This is acceptable if offers, not lessons, are the fairness unit;
otherwise use accepted lessons for fairness.

**L3 — No schema-level uniqueness for Teacher/timeslot confirmations.**
The application locks correctly, but a future non-canonical writer could bypass
the guard. Consider a canonical reservation/slot table with a unique key.

---

## 8. Unresolved product-policy decisions

These must be decided by the product owner; code should not silently choose:

1. Is the fairness unit an **offer**, an **accepted lesson**, or a
   **completed lesson**?
2. Is fairness measured **globally** or **per instrument**? Recommendation:
   per instrument primary + global workload guard.
3. Should `strong` match strength outrank fairness, or only act as a tie-break?
4. Should the platform accept a request it cannot offer within the 25-hour lead
   time, and if so, what should the Student see?
5. Should same-slot excess demand become a **waitlist**, a **hard rejection**,
   or a **coordination case**?
6. What is the authorized manual-override policy for a Teacher accepting a
   different preference outside availability, versus an administrator override?
7. Should manual administrator assignments and claims count toward future
   fairness?
8. How long should workload history count, and should it decay?

---

## 9. Prioritized implementation plan

**Phase 0 — Approve policy (product).** Decide §8 items 1–3 and 5–7. No code
change until these are explicit.

**Phase 1 — Low-risk correctness/UX fixes (1–2 days).**
- Map lock-wait/database errors to retryable `teacher_busy`/`allocation_busy`
  (M5).
- Add a clear lead-time validation/message at intake and availability preview
  (H3) without changing the 24-hour policy.
- Retire competing duplicate offers on acceptance (M4).
- Add regression tests for these paths.

**Phase 2 — Match quality ordering (3–5 days).**
- Rank `strong` before `possible`, then apply the existing deterministic
  fairness tie-break (H1).
- Add a policy version and audit snapshot so old offers remain explainable.
- Regression tests: two-Teacher strong/possible, declined strong fallback,
  multi-preference.

**Phase 3 — Workload-aware fairness (1–2 weeks).**
- Add a read model for offers, confirmed lessons, completed lessons, manual
  assignments and cancellations in a rolling window.
- Implement the hybrid policy in §6.3 behind a feature flag and policy version.
- Add distribution assertions: per-instrument spread, global Gini/ CV threshold,
  scarce-instrument coverage.
- Shadow-run against historical synthetic data before enabling.

**Phase 4 — Throughput and same-slot demand (2–3 weeks).**
- Reduce the Teacher-root lock set or introduce a Course allocation queue (M3).
- Add waitlist/explicit capacity outcome for same-slot demand (M1).
- Load-test at 100/500 concurrent submitters with deadlock and latency gates.

**Phase 5 — Schema hardening (separate approval).**
- Consider a canonical Teacher slot reservation table with a unique active
  key and a migration/backfill plan. Do not add it casually to the existing
  confirmation table.

---

## 10. Regression assets added by this audit

| File | Purpose |
|---|---|
| `platform/tests/booking-engine-stress-fixture.php` | Synthetic catalogue, Teachers, Students, requests, availability, offers, claims and metrics |
| `platform/tests/booking-engine-scenario-a.php` | Identical-Teacher fairness at 50/100/500/1000 |
| `platform/tests/booking-engine-scenario-b.php` | Multi-instrument cursor scope |
| `platform/tests/booking-engine-scenario-c.php` | 500 mixed requests and 10-Teacher workload |
| `platform/tests/booking-engine-scenario-d.php` | Decline/expiry/cancel/failure lifecycle |
| `platform/tests/booking-engine-scenario-e.php` | Partial availability, buffers, midnight, DST, timezone, Jalali, leave, lead time |
| `platform/tests/booking-engine-scenario-f.php` | Preferences, match strength, claims, opportunity board |
| `platform/tests/booking-engine-scenario-g.php` | DB write failures, notification retry, projection replay |
| `platform/tests/booking-engine-concurrency-setup.php` | Isolated concurrency fixtures |
| `platform/tests/booking-engine-concurrency-worker.php` | Full-WordPress worker |
| `platform/tests/booking-engine-concurrency-light-bootstrap.php` | Test-only SHORTINIT bootstrap for 100-process bursts |
| `platform/tests/booking-engine-concurrency-light-worker.php` | Lightweight 100-process worker |
| `platform/tests/booking-engine-concurrency-verify.php` | Committed-row and artefact verifier |
| `platform/runtime/bin/run-booking-engine-stress.sh` | Guarded end-to-end audit runner |
| `platform/docs/evidence/booking-engine-stress-20261115/results.json` | Machine-readable executed results |

All tests refuse to run outside a disposable `local`/`development` WordPress
whose home URL is loopback. They create synthetic rows only and make no external
provider call.

### Targeted pure checks run

`booking-allocation-unit.php`, `booking-teacher-delivery-unit.php`,
`booking-preference-order-contract.php`,
`booking-availability-timezone-dst-contract.php`,
`quiet-hours-advisory-unit.php`, `persian-calendar-unit.php` — all PASS.

---

## 11. Limitations and honesty notes

- The audit used a **service-level** submission path for high-volume load and a
  **public service** path for idempotency and validation. It did not drive the
  browser form or a real HTTP client for every request. The REST route’s CSRF
  and account-creation path were inspected, not load-tested.
- Real Google Calendar/Meet, email, SMS, WhatsApp and Stripe calls were
  **not** made. A failing projector closure simulated a downstream failure;
  portal notification rows simulated the durable notification obligation.
- The 100-process burst required a test-only SHORTINIT bootstrap because the
  local Docker VM has ~3.8 GiB memory. This changes only the test process
  bootstrap, not the booking services under test.
- The suite uses random unique suffixes rather than a fixed random seed. It is
  reproducible by command and by distribution assertions, not by identical
  database ids.
- Attendance/no-show behavior was not simulated. Allocation code has no read
  path for attendance; this is a code-review conclusion, not a runtime test.
- No production data, credentials, real identities or live providers were
  touched.

---

## 12. Final answer to the central question

**Can Delnavazan reliably and fairly allocate introductory lessons across
Teachers with different instruments and availability, even when hundreds of
students book concurrently, without conflicts, unfair distributions, or
inconsistent booking states?**

- **Reliably, yes, within the tested paths.** The locking, transaction,
  occupancy and idempotency design prevented double bookings and capacity
  violations at 100 concurrent submitters and 100 competing acceptances. The
  failure-injection and crash-recovery tests left no orphaned or duplicated
  state.
- **Fairly, only within the current definition.** It is fair per instrument for
  equally eligible Teachers, but not globally across portfolios and not
  quality-ranked. Those are product-policy gaps, not hidden data corruption.
- **Consistently, mostly.** The main operational weaknesses are the invisible
  25-hour lead time, same-slot referral volume, and Teacher-root lock
  serialisation at high concurrency.

The recommended next step is **not** to replace the engine. It is to approve
the policy decisions in §8, then implement match-quality ordering, workload-
aware fairness, and the lead-time/queue UX on top of the existing safe
transaction and occupancy model.
