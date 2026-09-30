# Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority

Status: **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED — NOT deployed.**

Final independently approved candidate `815a301679fdebc5d058646749e5a206ab492629`, tree
`13757b52dd84810d44e6dba19550ec7e668ad169`, from authoritative pre-merge main
`b7378665af1bc3cce935cfae27d346326a1ddc10`, was **fast-forward merged into `main`** (the
implementation-merge SHA equals the approved candidate because no merge commit was required). Schema 24
/ migration `024_post_intro_continuation_slot_reservation_authority` / build
`phase2a2q-post-intro-continuation-slot-reservation-20260920.1` are authoritative. No deployment,
production activation, payment, provider, Portal or Theme/NIU work occurred.

Review history (retained as provenance): original candidate `33368f6a…` failed on Q-1/Q-2/Q-3;
Correction-Round-1 candidate `c43a89b3…` failed on Q-R1-1/Q-R1-2/Q-R1-3; Correction Round 2 passed final
independent re-review.

**Known follow-up (test hygiene only, not a production defect).** The `expiry_vs_new_claim` concurrency
mode uses a two-second hold-expiry window, so under heavy load the first claim attempt can start *after*
the boundary and the runner's expected winner order inverts (the production behaviour remains correct:
whichever attempt runs post-expiry succeeds and the pre-expiry attempt fails `teacher_slot_conflict`).
Both full-matrix runs passed after widening the window locally, and the deterministic expiry-boundary
behaviour is covered by the authority runtime. A bounded test-harness stability change should be made in
a future correction round rather than inside this merge, because the approved tree must remain unchanged.

Correction Round 1 candidate `c43a89b3aeecd74d4335b2a1fabd70c4e0ad263e` (tree
`0cdf92de266f70d9e22e887e6a384934082ec51a`) **failed independent review** on Q-R1-1 (a delayed slot
authority did not converge an already-continuing case into a hold), Q-R1-2 (the reported lock order did
not match the implemented Student-decision acquisition order) and Q-R1-3 (a dead concurrency fixture
still referenced the removed slot derivation). Correction Round 2 corrects all three as descendant
commits.

The independently reviewed candidate `33368f6a37c128b476419542e507462952909702` (tree
`a20c48d8c8f5aaf61688d029c5b5084daa113327`) **failed independent review** on Q-1 (a future first-paid
slot was derived from the one-off introductory occurrence), Q-2 (four cross-authority concurrency
modes were not process-proven) and Q-3 (accepted-arrangement lineage used insertion-id ordering).
Correction Round 1 corrects all three as descendant commits on the existing branch.

Schema 24 (candidate only) / migration `024_post_intro_continuation_slot_reservation_authority` / build
`phase2a2q-post-intro-continuation-slot-reservation-20260920.1`, from authoritative base
`b7378665af1bc3cce935cfae27d346326a1ddc10` (Phase P merged and closed at Schema 23).

Phase Q owns one narrow interval of the Student journey:

```
FREE INTRODUCTORY LESSON → POST-INTRO CONTINUATION DECISION → TEMPORARY HOLD OF THE EXPECTED FIRST REGULAR CLASS SLOT → LATER PAYMENT AUTHORITY
```

It stops before payment. It answers only: does the Student want to continue with this Teacher; if yes,
which exact first regular slot is temporarily held and when does that hold expire; has the Student
asked for another Teacher, asked to be contacted, or chosen not to continue; and has the Teacher
declared that the match needs administrator handling. It never answers whether the Student has paid,
whether a Term or the twelve paid Lessons should exist, or whether the introduction was delivered.

## Source-graph audit (what actually exists in this repository)

| Concept | Authoritative source found |
| --- | --- |
| Introductory Lesson | a `legacy_phase1` Lesson with `lesson_type='introductory'`, Student/Teacher/Course set and **no** Enrolment or Term (created by `LessonService::create`). It is deliberately *not* a canonical Term Lesson. |
| Introductory occurrence | the single current (non-superseded) `lesson_schedule_versions` row that the Lesson's `current_schedule_version_id` points at, with UTC anchors plus `schedule_timezone`, `local_wall_date`, `local_wall_time`. |
| Expected first regular slot | **no existing source authorises a recurring future class.** `proposal_versions` and `accepted_service_arrangements` carry only `frequency_per_week`, `schedule_constraints`, `commencement_window_start/end` and `timezone` — a commencement **window**, never a weekday/wall-clock slot. Phase Q therefore introduces the narrowest explicit fact it can justify (below) rather than extending Phase G or assuming recurrence. |
| Student authority | Phase-F `student_principal_links` (adult) and `student_acceptance_authority_grants` with `authority_type='guardian_representative'` (minor), scoped by the Student's current acceptance capacity classification. |
| Teacher authority | Phase-J `teacher_principal_links`. |
| Optional canonical lineage | the applicable canonical Enrolment for the same Student/Teacher/Course (recorded only when it already exists), its applicable Teacher Assignment, and any `accepted_service_arrangements` row for the same Student/Teacher/Course. |
| Teacher capacity | Phase-N `canonical_lesson_schedule_versions` plus the per-Teacher `teacher_schedule_roots` serialization device. |

Phase Q invents none of the identity, lesson, occurrence or capacity truths above, and it never forces
an introductory Lesson into a paid Term or fabricates a delivery/attendance fact. It **does** own one
new explicit fact — the authorised first regular slot — because no existing authority carries one; that
fact is recorded deliberately and is never calculated.

### Q-1 — explicit first regular slot authority

The product intent remains that the introductory day/time is *normally* intended to become the ongoing
regular time, but that intent is not authority. Phase Q therefore exposes one administrator command,
`recordFirstRegularSlot()`, requiring `dzn_manage_canonical_continuation`, which records an immutable
`dzn_canonical_continuation_slot_authorities` row containing:

* the exact introductory Lesson and its bound occurrence/version;
* the exact Student, Teacher and Course;
* the exact timezone, local wall date and local wall time of the agreed first regular class;
* the resolved UTC start/end and the occupied (buffer-inclusive) interval;
* the controlled authority basis (`administrator_attestation`) and reason code;
* the evidence channel, keyed evidence-reference digest and evidence instant;
* the frozen rule version, recency and recording actor.

The command refuses a slot that is not strictly after the introductory occurrence
(`first_regular_slot_not_after_introduction`), refuses a second slot for the same introduction
(`first_regular_slot_already_authorised`), and refuses a nonexistent (`continuation_slot_wall_clock_invalid`)
or ambiguous (`continuation_slot_wall_clock_ambiguous`) local wall clock. No weekly recurrence, no
`+7 days`, and no future availability/acceptance assumption is ever made: a slot that is not in this
table cannot hold capacity.

If a Student continues before any slot has been authorised, the decision is recorded, **no capacity is
held**, and an administrator-intervention item `first_regular_slot_authority_required` is raised. The
aggregate is invalid if a continuing case has neither an authoritative slot with its hold nor that
explicit requirement.

### Q-R1-1 — delayed slot authority converges the existing decision

Continuing before the agreed slot exists is a supported ordering. Recording the authoritative slot later
now converges that **existing** case inside the same transaction: the slot authority is written, the
durable case is hydrated and revalidated, the current Student decision is revalidated, the Teacher
scheduling root is taken, Phase-N schedules and other Phase-Q holds are rechecked, exactly one
reservation is created referencing the **original** decision id, the frozen expiry is computed, and the
missing-slot requirement is resolved append-preservingly (history retained, `state='resolved'` with
resolution actor/instant). No second Student decision is ever created and the original consent is never
rewritten.

A capacity conflict or any other fault rolls the **whole** convergence back, including the slot
authority, so no `continue_with_teacher + slot authority + no hold + current missing-slot requirement`
false success can be observed. A case that is no longer eligible (a later terminal or suppressing
decision, or an existing reservation) keeps its correct history: the slot authority is still a
legitimate standalone fact, but no hold is created and no terminal state is reactivated. Exact replay of
the slot command returns the same authority, case and reservation idempotently; a changed slot under the
same command key fails `Idempotency conflict`.

## Post-intro eligibility gate

Phase Q binds exactly one authorised introductory occurrence and refuses any decision before that
occurrence's scheduled window has ended (`introductory_occurrence_not_ended`). This proves **only**
that "a post-intro decision may now be recorded" — it does **not** assert that the introductory
Lesson was delivered, attended or completed. No competing attendance authority is created; delivery
and attendance truth remain Phase O's.

## Locked owner decisions Q-D1…Q-D12

| Lock | Implementation |
| --- | --- |
| Q-D1 free/separate intro | The intro Lesson is a legacy `introductory` Lesson with no Term. Phase Q consumes no standard Lesson allocation, no replacement allowance and no academy obligation, and creates none. |
| Q-D2 continuation choices | Exactly four Student dispositions: `continue_with_teacher`, `different_teacher`, `contact_me`, `not_continuing`. |
| Q-D3 continue holds a slot | `continue_with_teacher` establishes a bounded pre-payment capacity hold. It is not a Lesson, schedule, Term, Assent, Assignment, payment, attendance, delivery or enrolment conversion. |
| Q-D4 only the first slot | Exactly one reservation per continuation case (`UNIQUE(continuation_case_id)`), holding only the first expected post-intro occurrence. No block, series or future Lesson materialisation. |
| Q-D5 expiry rule | `expires_at = min(expected_first_regular_start, introductory_occurrence_end + 6 days)`, both exact UTC instants; the six-day bound is a precise duration from the authoritative introductory occurrence boundary. |
| Q-D6 expiry keeps history | Expiry never deletes or rewrites Booking Request, Coordination, Proposal, acceptance, arrangement, identity, Enrolment, Assignment, introductory Lesson history or the decision itself. |
| Q-D7 different Teacher → admin | Records a `student_requested_different_teacher` administrator intervention. No automatic rerouting, proposal or second intro. |
| Q-D8 Teacher unsuitable → admin | The Teacher's own principal records `teacher_match_unsuitable`, releases the hold and suppresses ordinary continuation/payment-ready state (`teacher_match_suppressed`) until a later authorised admin workflow resolves it. No Student rejection, no rereoute, no Student-facing wording. |
| Q-D9 contact me → admin | Records a `student_requested_contact` intervention. No payment authority, no automatic routing. |
| Q-D10 not continuing | Closes the immediate continuation path (`continuation_closed`) and releases any hold. Optional feedback is a controlled reason code only (`teacher_fit`, `schedule`, `price`, `changed_mind`, `technical_experience`, `other`, `prefer_not_to_say`); it is never mandatory and no free text, CRM or analytics surface is created. |
| Q-D11 payment deferred | No payment intent, Stripe object, invoice, receipt, `payment_state`, Term or standard Lesson is created. A later payment authority consumes this state. |
| Q-D12 admin = exception handling | The normal path is intro → continue → first-slot hold → later payment; administrators handle alternative Teacher, contact requests, unsuitable matches and integrity conflicts. |

## Continuation aggregate and decision authority

`dzn_canonical_continuation_cases` is the narrow aggregate: it binds the source introductory Lesson and
its exact occurrence anchors/wall-clock provenance, Student, Teacher, Course, optional Enrolment /
Assignment / accepted-arrangement lineage, the locked rule version, the current decision and an
append-only decision chain. `dzn_canonical_continuation_decisions` is append-only; a case's decision
may legitimately be revised (a Student may change their mind) but `not_continuing` is terminal and a
Teacher suppression blocks ordinary continuation until admin resolution.

Commands are explicit seams rather than generic writes:

| Command | Authority required |
| --- | --- |
| `continueWithTeacher`, `requestDifferentTeacher`, `requestContact`, `stopContinuation` | Student principal authority (adult) or guardian representative grant (minor) — revalidated from Phase F, never inferred from payload, email or display name. |
| `markMatchNeedsAdmin` | `dzn_submit_own_continuation_match_exception` **and** the authoritative Teacher principal link for the Teacher bound to the intro. It grants no Term, Lesson, payment, rejection, reassignment or admin-review authority. |
| `flagIntegrityConflict` | `dzn_manage_canonical_continuation` (administrator only). |

Every command hydrates and validates the complete source graph, locks in canonical order, records
durable digest-only command evidence, and is idempotent: an exact replay converges on the recorded
result, while the same command key with a changed context fails `Idempotency conflict`. The replay
path requires a non-null expected digest and compares unconditionally.

## Slot authority, hold lifecycle and expiry

The hold is bound to the exact `slot_authority_id` that authorised it and freezes that record's interval
verbatim. The validator re-resolves the authorised timezone/wall clock into UTC and requires an exact
match with the stored slot record and the reserved interval, so neither the authority record nor the
hold can drift from the agreed slot. Nothing is derived from the introduction time.

The reservation freezes `slot_authority_id`, `starts_at_utc`, `ends_at_utc`, `occupied_ends_at_utc`,
timezone/wall-clock provenance, `reserved_at`, `expires_at`, rule version and reservation version.
Lifecycle is deliberately minimal: `active` → `expired` → `released`. The later payment slice may
consume the state; Phase Q reserves that vocabulary without implementing the later transition.

Expiry is deterministic and lazy: `expires_at` is frozen at creation and never recomputed from "now";
a reservation is capacity-effective only while `state='active'` **and** `expires_at > now`. A hold
whose expiry has already passed is created `expired` and blocks nothing.

## Real capacity integration (Phase-N separation preserved)

The hold is real Teacher capacity but is never a Lesson schedule. Both authorities take the same
per-Teacher scheduling root, so they serialize on one device in one lock order:

* Phase Q consults Phase-N `canonical_lesson_schedule_versions` before inserting a hold;
* Phase N consults the active Phase-Q hold set through the single narrow seam
  `CanonicalContinuationCapacityAuthority::assertNoActiveHold()`.

A corrupt hold fails closed rather than silently blocking or silently releasing capacity.
`Teacher Availability Assent`, `Teacher Assignment`, Phase-N Lesson schedules and Phase-Q holds remain
four distinct concepts and are not interchangeable.

## Administrator intervention

`dzn_canonical_continuation_interventions` records the controlled, attributable requirement, linked to
the exact continuation case, its owning decision, Student, Teacher, introductory Lesson and reason
classification. A decision that requires human handling must carry its intervention fact: losing or
misattributing it makes the aggregate fail closed. No generic ticketing system is introduced and no
Student-facing wording belongs to this authority.

## Protected read

`CanonicalContinuationReadService` exposes only stable references, the current decision, whether admin
action is required, the single expected slot, its frozen expiry and whether the hold is currently
capacity-effective. It validates the complete aggregate, fails closed on corrupted lineage or
impossible reservation state, returns no guardian evidence internals and no unnecessary PII, and never
mutates state or opens a write transaction (capability `dzn_view_canonical_continuation`).

## Absolute boundaries

Phase Q creates **no** payment intent, Stripe customer/payment object, invoice, receipt,
`payment_state`, Term, standard or replacement Lesson, academy obligation, delivery/attendance
outcome, Phase-P settlement, notification, calendar, Google/Meet/OAuth/webhook/credential, Theme or
Portal change, public REST route or deployment. Provider-neutral only: no external call is made from
any Phase-Q path or from migration 024.

## Migration / schema

`024_post_intro_continuation_slot_reservation_authority` is additive only: it creates the five Phase-Q
tables, performs no backfill, invents no continuation decision or reservation, creates no Term or
Lesson and preserves all existing legacy/canonical data. Only `canonical_continuation_cases` (decision
state) and `canonical_continuation_reservations` (lifecycle state) are mutable; everything else is
append-only. The verifier runs after migration 024, on current-schema verification and unconditionally
before Schema 24 activation (including the retained-024/stale-version path), and rejects
provider/payment-specific columns, missing indexes and any mutable column on an append-only table.
Capability repair is per capability and the Teacher role is explicitly denied administrative
continuation authority.

## Validation (owner-executed on a disposable WordPress/MariaDB harness)

| Evidence category | Result |
| --- | --- |
| Phase-Q contract | pass |
| Authority runtime | pass (continue + real hold, slot derivation, frozen expiry, exact replay and changed-context conflict, admin interventions, not-continuing closure with optional feedback, Teacher match exception, Student/guardian authority, capacity arbitration against both a second hold and canonical Lesson scheduling, expired-hold non-blocking, absolute boundaries) |
| Corruption runtime | **34 fail-closed cases with repair/recovery** (including slot-authority identity/interval/wall-clock/basis/rule/provenance and accepted-arrangement lineage corruption) |
| Failure-injection runtime | **10 injected boundaries**: 6 write boundaries (slot authority, case, decision, reservation, intervention, command evidence) plus the four delayed-convergence boundaries (Teacher root, reservation, intervention resolution, command evidence) with complete convergence rollback and retry |
| Migration runtime | fresh Schema 24, 23→24 rehearsal, repeat, partial capability repair, no backfill, no payment/Term creation, provider-neutral storage, slot-authority storage, 10 malformed-storage cases, retained-024 fail-closed |
| Concurrency runner | **16 deterministic gated modes**: the 13 above plus three delayed-slot races — delayed slot authority vs conflicting Phase-N Lesson scheduling, vs a Teacher match-unsuitable transition, and vs a Student terminal decision (no stale continue state may produce a hold) |

### Q-3 — accepted-arrangement lineage

Optional accepted-arrangement lineage is never selected by insertion id. Exactly one arrangement for the
Student/Teacher/Course is authoritative, none leaves the lineage absent, and several raise
`continuation_arrangement_ambiguous` rather than silently binding an arbitrary historical row. A bound
arrangement must also still be an intact accepted record (fingerprint digest, acceptance instants and
matching Student/Teacher/Course).

### Lock order (Q-R1-2: documented exactly as implemented)

The **actual** order taken by a Student continuation command is:

1. the introductory Lesson row (`hydrateIntro`);
2. the Phase-F authority row — the Student's own active principal link, or the acting guardian's active
   representative grant (`studentAuthority`);
3. the Phase-Q continuation case row (lock / create);
4. the per-Teacher scheduling root (`teacher_root`), only when a hold is actually created;
5. Phase-N `canonical_lesson_schedule_versions` reads inside the capacity check.

`recordFirstRegularSlot()` follows the same global direction: introductory Lesson → slot authority row →
continuation case → Teacher root → Phase-N versions. `markMatchNeedsAdmin` takes the introductory Lesson →
Teacher principal link → case → (release only) reservation.

Deadlock audit: Phase-F mutation paths (`revokePrincipal`, `revokeGuardian`, `establishPrincipal`,
`grantGuardian`, `supersede*`) acquire only the WordPress user row plus the principal/grant rows and never
read or lock a Lesson, a Phase-Q row or a Teacher scheduling root, so there is no reverse edge and no
cycle. Phase-N scheduling never touches Phase-F or Phase-Q rows. The documented order above is therefore
the implemented order, and the cross-authority races (guardian/principal revocation, Teacher exception,
Lesson scheduling, expiry) each prove a genuine wait followed by post-wait revalidation with exactly one
winner.

Phase-P, Phase-O, Phase-N, Phase-M, Phase-M0 and Phase-L regressions were re-run unchanged. No
deployment, production, Theme/NIU, Google/provider or payment work occurred.
