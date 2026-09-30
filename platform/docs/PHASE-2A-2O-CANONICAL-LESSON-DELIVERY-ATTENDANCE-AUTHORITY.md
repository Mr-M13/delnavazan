# Phase 2A.2-O — Canonical Lesson Delivery & Attendance Outcome Authority

Status: **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**

Final independently approved candidate `f5b43741f4b404fd102330aeb75d58ed8b3e2976`, tree
`7182101fcaf9855f8834cd08bc2e7a4a0311791c`, was fast-forward merged into `main`; the
implementation-merge SHA equals the approved candidate because no merge commit was required. The
docs-only closeout commit is recorded in the task closeout because a commit cannot embed its own
hash. No deployment occurred.

Schema 22 / migration `022_canonical_lesson_delivery_attendance_authority` / build
`phase2a2o-canonical-lesson-delivery-attendance-authority-20260918.1`.

> **Correction round 1 (independent review findings O-1, O-2, O-3).** The first review candidate
> `4f8af9067aa1ed463916d86b32a04f4193f44d23` (tree `8a79719e39496476debef4c67971648ab3447d57`) failed
> final independent review. See "Correction round 1" below. Schema 22, the migration and every
> locked product decision are unchanged; the corrections are application-service validation,
> capability enforcement and documentation only.
>
> **Correction round 2 (independent re-review findings on round 1).** Independent re-review of
> candidate `c63c1327047bd76f3155788874ec6ea6ae7a38e0` (tree
> `7fa33f0d406e25a403e0f2b5c7d4668923faaaca`) failed again: one HIGH defect in O-D8 completion-event
> lineage, one MEDIUM gap in corruption coverage and one LOW idempotent-replay issue. See
> "Correction round 2" below. Schema 22, migration `022_canonical_lesson_delivery_attendance_authority`,
> the build identity and O-D1…O-D9 remain unchanged; the round-2 corrections are validation, test and
> documentation work only.
>
> **Correction round 3 (independent re-review of round 2).** The round-2 candidate
> `5b18f20d576e5446cee6cecc0b21d010fb62ffa1` (tree `af035208b7096dad12e60b768e928372fb094778`) also
> failed: the O-D8 completion-event check was still a **local approximation** of canonical completion
> inside academy-obligation authority, and the referenced lifecycle event itself was not corrupted by
> any test. See "Correction round 3" below. Schema 22, migration
> `022_canonical_lesson_delivery_attendance_authority`, the build identity and O-D1…O-D9 are unchanged.

Phase O is provider-neutral. It records what actually happened to a canonical Lesson occurrence
without becoming a provider integration, without changing Lesson lifecycle, scheduling, payment,
notification or any external system, and without requiring a manual attendance record for ordinary
delivery.

## Locked product decisions implemented

| Decision | Implementation |
| --- | --- |
| **O-D1 — `completed` means delivered** | Completion is guarded by the delivery authority: a Lesson with an effective `teacher_non_delivery` or `review_required` outcome cannot be completed (`lesson_not_delivered`, `lesson_delivery_review_required`). No new Lesson lifecycle state was added. |
| **O-D2 — exception-based attendance** | Ordinary delivery is the *absence* of an outcome row. Only material or exceptional outcomes are recorded, so no administrator entry is required for a normal Lesson. The evidence boundary is provider-neutral, so a later meeting-evidence source can be recorded without domain coupling. |
| **O-D3 — student no-show** | `student_no_show` is a distinct canonical fact (`delivery_state = delivered`, `attendance_state = student_absent`, `remedy_class = none`). The Lesson is consumed, completion is still permitted, and no replacement entitlement is created. |
| **O-D4 — Teacher/academy non-delivery** | `teacher_non_delivery` records `delivery_state = not_delivered` with `remedy_class = academy_obligation`. Completion is refused, and the resulting replacement is classified `academy_obligation`, which never consumes the Student's two-per-Term allowance. |
| **O-D5 — cancelled vs not delivered** | Cancellation stays a pre-occurrence scheduling/lifecycle fact: the Phase-M replacement-eligible cancellation reason is refused once the recorded occurrence start has elapsed (`occurrence_already_started_use_delivery_outcome`) and refused whenever an outcome already exists (`delivery_outcome_exists`). Post-occurrence non-delivery is a delivery fact, never a cancellation reason. |
| **O-D6 — corrections** | Correction appends a superseding outcome, records actor, reason, evidence, prior outcome and successor, and never mutates the historical row, never reopens Lesson lifecycle and never moves money. A correction that would contradict a completed Lesson fails closed and must use the explicit O-D8 reconciliation command instead (`lesson_completed_reconciliation_required`). |
| **O-D7 — authorised absence is immediate** | Recording is capability-protected administrator authority (`dzn_manage_canonical_lesson_delivery`), takes effect immediately, is idempotent and needs no second approval state. Student/teacher self-service is explicitly not authorised by Phase O. |
| **O-D8 — reconciliation of a completed occurrence** | An explicit authorised append-only command (`reconcile_completion_non_delivery`) invalidates the *effective delivery meaning* of a historical `completed` Lesson when later evidence establishes Teacher/academy non-delivery. The immutable completion lifecycle event is never deleted, rewritten or mutated; the corrected effective canonical truth becomes non-delivery; the academy obligation is established with lineage naming the exact historical completion event; and the Lesson is never reopened, rescheduled or replaced automatically. No Finance, payment or payroll effect is introduced. |
| **O-D9 — advance Teacher/academy cancellation** | A pre-occurrence cancellation recorded with the controlled reason `academy_unavailable` (mapped to lifecycle reason `canonical_lesson_cancelled_academy_unavailable`) establishes an academy obligation without consuming the Student's allowance. A Student-requested cancellation uses the generic reason and never creates an obligation merely because the Lesson was cancelled. No broader cancellation/refund policy is invented. |

## Authority introduced

- one applicable canonical delivery/attendance outcome per canonical Lesson, with append-only
  supersession history;
- immutable evidence provenance: controlled channel, keyed reference digest, observed time,
  recorded time and actor;
- digest-only durable command evidence with complete replay revalidation;
- a protected integrity-checked read seam;
- a capability-gated academy-obligation write seam and integrity-validated aggregate reads;
- fail-closed cross-phase guards consumed by Lesson completion, Lesson cancellation reconciliation,
  replacement issuance and canonical scheduling.

## Correction round 1

| Finding | Correction |
| --- | --- |
| **O-1 (HIGH)** — `CanonicalAcademyObligationService::owe()` was a public state-mutating seam that did not itself enforce `dzn_manage_canonical_lesson_delivery` | `owe()` now calls the Phase-O capability check as its first statement, before any source lookup, validation or canonical mutation. An unauthorized actor fails closed with `Unauthorized`, no obligation row is created, no evidence is persisted and no partial authority survives. The two legitimate callers (the Phase-O delivery authority's non-delivery path and the Phase-M advance-cancellation reconciliation) run after their own capability check and inside the caller's transaction, so authorized behaviour and atomicity are unchanged; an advance cancellation performed by a principal without the Phase-O capability now fails closed and rolls back completely. No capability was broadened and no new public authority was introduced. |
| **O-2 (HIGH)** — `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` could return or count raw repository rows | All three now hydrate every selected obligation against its canonical source Lesson and validate it through `CanonicalAcademyObligationValidator`, which now also requires that the stored Term, Enrolment, Student, Course, Teacher and Teacher Assignment selectors **agree** with the source authority, that source lineage (outcome/event, schedule version, occurrence anchors) is intact, and that the obligation's evidence is byte-identical to the evidence of the source fact it was raised from. A single invalid row fails the whole aggregate closed (`canonical_obligation_integrity_conflict`); nothing is silently omitted. `outstandingCountForTerm()` is derived from the same validated aggregate as `outstandingForTerm()`; the raw SQL count seam was removed from the repository. To make a corrupted stored selector detectable rather than invisible, Term and Enrolment selectors now select the **union** of rows that claim the selector and rows whose source canonical Lesson belongs to it. |
| **O-3 (LOW)** — documentation claimed 36 static/source contract tests | Corrected. See "Validation performed" below: 29 files match `tests/*contract*.php`, and PHP lint (`tests/static.php`) plus `sh -n` shell syntax checks are reported separately as syntax checks, not contract tests. |

## Correction round 2

Independent re-review of round 1 found that the round-1 documentation overstated aggregate corruption
coverage ("source outcome/event lineage") where the teacher_non_delivery branch did **not** validate
the historical completion-event lineage, and that the Teacher Assignment corruption case promised by
round 1 was never added. Both are corrected here, together with the replay idempotency issue.

| Finding | Correction |
| --- | --- |
| **HIGH — O-D8 completion-event lineage was not validated for `teacher_non_delivery`** | The validator now reconciles the obligation's `source_event_id` with the effective outcome's canonical reconciliation lineage. For an ordinary (non-reconciled) non-delivery the obligation must carry **no** completion-event lineage, and any stored event identifier is an integrity conflict. For an O-D8 reconciled completion the obligation must carry the exact event the effective outcome names, that event must exist in the Lesson's canonical lifecycle, belong to the same Lesson, be a `completed` event, and still be the Lesson's latest canonical completed event, with the Lesson itself in `completed` state. The obligation remains subordinate to the canonical Lesson/outcome/reconciliation facts — it never becomes the authority over completion, and the validation reuses the same hydrated lifecycle facts and rule the delivery authority applies rather than a second interpretation. |
| **MEDIUM — missing corruption cases** | Added: Teacher Assignment identity to the aggregate identity matrix, and four O-D8 completion-lineage cases (wrong event identifier, completion event belonging to another Lesson, missing lineage where reconciliation requires it, and a wrong lifecycle-event type) plus the ordinary non-reconciled "spurious completion lineage" case. Every one of these is exercised through **all three** public aggregate seams — `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` — with repair proving recovery. |
| **LOW — same-kind replay bypassed the source/evidence contract** | `owe()` no longer returns an existing same-kind obligation before validating the supplied authority. A same-kind call now validates the supplied source, requires the existing obligation to bind to the same canonical source identifiers (source outcome for non-delivery, source cancellation event for academy cancellation) and to match the immutable evidence exactly, and additionally revalidates the existing obligation's canonical aggregate. An exact legitimate replay remains idempotent (same row, no duplicate); conflicting replay intent fails closed with `obligation_replay_conflict`. The uniqueness semantics (`UNIQUE(source_lesson_id)`) and concurrency behaviour are unchanged. |

## Correction round 3

Round-2 validation decided canonical completion with a **local approximation** — scanning the raw
lifecycle rows for `to_state = 'completed'` and taking the maximum event id — which is not a
canonicality proof: a row can keep those fields while `from_state`, the event sequence, the
append-only chain structure or the injected-row legality is corrupted. Academy-obligation authority
must never maintain a second definition of canonical Lesson completion, so completion is now decided
by Lesson authority alone.

**Architecture chosen — direct, one-way reuse (no cycle).** `CanonicalLessonAuthorityValidator::valid()`
references **no** Phase-O authority (verified: zero occurrences of the delivery or obligation
validators), so calling it from academy-obligation validation introduces no circular or recursive
validation and no competing lifecycle authority. The alternative — extracting a shared lifecycle
primitive — was unnecessary because the existing authority is already a pure, non-mutating,
repository-hydrated gate.

| Finding | Correction |
| --- | --- |
| **HIGH — academy-obligation authority established completion locally** | `CanonicalAcademyObligationValidator::valid()` now consumes `CanonicalLessonAuthorityValidator::valid()` before accepting any obligation lineage, and both Phase-O consumers do the same: the obligation validator when obligations exist, and the delivery validator whenever a reconciliation pointer exists, plus the delivery service's reconciliation command itself. Completion is then bound to the canonical chain's **terminal event** — a consequence of the validated legal progression, not a local maximum-id scan. The obligation remains subordinate: it never decides whether a malformed Lesson lifecycle is acceptable. |
| **MEDIUM — referenced lifecycle event was never corrupted by a test** | Added three cases that corrupt the **referenced** completion event while keeping the obligation pointer, lesson id and `to_state` intact: `from_state`, `event_sequence`, and an injected structurally noncanonical same-Lesson completed row (with an explicit assertion that Lesson authority itself rejects it). Each is exercised through `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` with repair proving recovery. The Round-2 wording that claimed the referenced event was already "the Lesson's latest canonical completed event" was an overclaim and is corrected here. |

**Sequencing fix found during Round-3 verification.** The Phase-M cancellation service updates the
Lesson lifecycle row and then appends its lifecycle event, so a guard that validated the aggregate in
between compared a post-transition row with a pre-transition chain. The append-only event and (for
academy cancellations) the academy obligation are therefore written first inside the same transaction,
and the advance-cancellation guards — evaluated on the fully consistent post-state — reject with the
precise reason (`delivery_outcome_exists`, `occurrence_already_started_use_delivery_outcome`) while any
rejection still rolls the event, the obligation and the lifecycle update back together. The guard is
also invoked at the service seam before any mutation, so no partial authority can survive.

## Outcome vocabulary

| `outcome_code` | `delivery_state` | `attendance_state` | `remedy_class` | `occurrence_attempted` | Blocks completion |
| --- | --- | --- | --- | --- | --- |
| `delivered` | delivered | attended | none | 1 | no |
| `student_no_show` | delivered | student_absent | none | 1 | no |
| `teacher_non_delivery` | not_delivered | unknown | academy_obligation | 1 | **yes** |
| `interruption` | delivered | unknown | none | 1 | no |
| `review_required` | unknown | unknown | none | 0 | **yes** |

The profile columns are derived from `outcome_code` and are validated on every read, so a caller can
never choose a favourable delivery, attendance or remedy classification.

## Academy-owed occurrence authority (distinct from the Phase-M replacement)

`{prefix}dzn_canonical_academy_obligations` is its own canonical authority. It is deliberately **not**
a Phase-M `replacement` Lesson:

| | Phase-M replacement (`replacement` Lesson) | Phase-O academy obligation |
| --- | --- | --- |
| Meaning | the Student's bounded make-up allowance | a teaching occurrence the academy owes because it failed to deliver |
| Trigger | pre-occurrence cancellation with the controlled `attested_non_delivery` attestation | effective `teacher_non_delivery` outcome, explicit completion reconciliation (O-D8), or advance `academy_unavailable` cancellation (O-D9) |
| Bounded by | maximum two per Term, one per origin, never restored | one immutable obligation per source occurrence, never capped by the two-per-Term allowance |
| Effect | issues a `replacement` Lesson on an explicit command | records the debt only; no Lesson, no schedule, no notification, no money |
| Term closure | allowance expires when the Term closes | the obligation survives Term closure and remains readable |

The Phase-M replacement cap, eligibility rule and Lesson classification are unchanged by Phase O, and
historical `canonical_lesson_cancelled_replacement_eligible` / `attested_non_delivery` records are never
reinterpreted as Phase-O non-delivery or academy obligations (no semantic backfill).

## Explicit non-authority

No Lesson lifecycle mutation or automatic completion/cancellation; no `scheduled`, `delivered` or
`attended` Lesson lifecycle state; no scheduling creation, revision or release; no automatic
replacement entitlement; no Term allocation change beyond the O-D4 distinction; no payability,
rate, statement or payment logic; no notification, Google/Meta/Amelia/calendar/provider call,
webhook or credential; no public or signed link; no portal; no legacy attendance import or
dual-read; no Amelia dependency.

## Replacement / entitlement reconciliation

Two remedies now exist and are never merged:

1. **Student allowance (`student_allowance`)** — the pre-existing Phase-M path: a standard Lesson
   cancelled before the occurrence with the controlled `attested_non_delivery` reason. It remains
   capped at two per Term and is recorded on the issued replacement Lesson.
2. **Academy obligation (`academy_obligation`)** — a Lesson whose effective outcome is
   `teacher_non_delivery`. It is bounded to one replacement per non-delivered origin by the existing
   origin uniqueness, does not consume the two-per-Term allowance, and is issued only after the
   non-delivered occurrence is terminalised.

The allowance cap counts only `student_allowance` replacements, so a Teacher failure can never
reduce the Student's normal allowance. No Finance consequence, no automatic scheduling and no
generic unlimited replacement mechanism is introduced.

## Temporal honesty

An outcome may only be recorded for a Lesson that was genuinely scheduled and whose recorded
occurrence start has already elapsed. Unscheduled occurrences (`schedule_required_for_delivery_outcome`)
and future occurrences (`occurrence_not_started`) are refused, and a Lesson with a recorded outcome
can no longer be scheduled or rescheduled (`delivery_outcome_exists`). Outcomes are anchored to the
frozen UTC occurrence start taken from the canonical schedule history, and wall-clock/DST behaviour
is inherited from the Phase-N scheduling authority.

## Locking

```text
Student–Course identity root → Enrolment → Term → canonical Lesson → Lesson lifecycle evidence
→ schedule versions → schedule events → delivery outcomes → delivery commands (innermost)
```

The Phase O authority never acquires the per-Teacher scheduling root, so the Phase-N contract rule
("no phase may acquire a Teacher scheduling root and then an earlier Enrolment identity-root chain")
remains satisfied.

## Data model

| Table | Purpose |
| --- | --- |
| `{prefix}dzn_canonical_lesson_delivery_outcomes` | Append-only outcome history; one applicable outcome per Lesson; only `applicable_slot`, `superseded_at` and `superseded_by_outcome_id` may mutate. |
| `{prefix}dzn_canonical_lesson_delivery_commands` | Digest-only durable command evidence with complete intent and result identity. |
| `{prefix}dzn_canonical_academy_obligations` | Immutable academy-owed occurrences, `UNIQUE(source_lesson_id)`, independent of Term lifecycle. |

Every outcome binds the exact canonical schedule version that governed it (`schedule_version_id`), the
frozen occurrence start and the frozen occurrence end, and — where applicable — the immutable
completion event it reconciles (`reconciles_completion_event_id`). The migration is additive: it
creates the three tables only. There is no legacy attendance backfill, no dual-read and no
reinterpretation of legacy `legacy_phase1` Lessons.

## Temporal rule

A final delivery/no-show/non-delivery fact may only become effective **after the governing occurrence
has ended** (`occurrence_not_ended`); the Teacher capacity buffer is explicitly not the waiting
period, so a one-minute occurrence is recordable one minute later rather than sixteen. An occurrence
that has not started is refused (`occurrence_not_started`), an occurrence that was never scheduled is
refused (`schedule_required_for_delivery_outcome`), and a Lesson with a recorded outcome can no longer
be scheduled or rescheduled.

## Provider-neutral evidence boundary (Google Meet future verification)

Evidence is a controlled channel (`staff_record`, `authenticated_platform`, `document_reference`)
plus an opaque keyed digest, an observed time and an actor. Phase O never calls a provider and never
lets provider output become authority merely because it was emitted: a provider-shaped assertion
must travel through the same authorised recording command and the same canonical aggregate gate.
When roadmap Phase 4 adds meeting evidence (teacher joined, student joined, join/leave interval,
overlap, duration), it can be ingested as evidence against the same boundary and reconciled into an
outcome without changing canonical identity or introducing Google coupling into the domain.

## Validation performed (disposable MariaDB 11.4 runtime; no production, NIU, Theme, Amelia or external system contacted)

**Evidence provenance.** Final independent review independently reproduced: Git identity and
ancestry checks, `git diff --check`, 29/29 `tests/*contract*.php`, PHP lint, shell syntax,
Schema/migration identity and the Round-3 commit-scope inspection. The runtime evidence below is the
implementation owner's on a disposable MariaDB/WordPress harness; it was not re-executed by the
independent reviewer and is reported as owner evidence, not as independently reproduced runtime
evidence.

- **Contract tests:** 29 files match `tests/*contract*.php` and all pass, including the Phase O
  contract (`tests/phase-2a2o-contract.php`).
- **Syntax/lint checks (not contract tests):** `tests/static.php` PHP-lints every file under `src/`
  and `sh -n` parses every committed shell harness; both pass. The earlier "36 contract tests" claim
  in this document was a miscount with no defined basis and is superseded by this breakdown.
- Authority runtime: ordinary delivery without any attendance row, student no-show, exact replay,
  conflicting assertion, correction and supersession lineage, Teacher non-delivery blocking
  completion, academy-funded remedy after the allowance cap is exhausted, advance-cancellation
  versus post-occurrence non-delivery, temporal refusals, review-required resolution, capability
  denial and digest-only evidence.
- **Corruption runtime (44 cases):** 18 delivery fact classes (Lesson relationship, outcome sequence,
  occurrence start and end anchors, schedule-version binding, profile columns, actor, reason,
  channel, reference digest, observed time, applicable relationship, supersession target,
  provider-shaped evidence, command intent and command result) fail closed through the protected
  read, the guard and Lesson completion, then recover; plus 26 academy-obligation classes verified
  through the **aggregate reads** (`outstandingForTerm`, `outstandingCountForTerm`,
  `outstandingForEnrolment`) covering source Lesson relationship, Term, Enrolment, Student, Course
  and Teacher identity, **Teacher Assignment identity**, source outcome and cancellation-event
  lineage, O-D8 completion-event lineage (wrong identifier, cross-Lesson event, missing lineage,
  wrong lifecycle-event type, and spurious lineage on an ordinary non-delivery), **corruption of the
  referenced canonical lifecycle event itself** (`from_state`, `event_sequence`, injected
  noncanonical completed row), schedule-version and occurrence anchors, evidence channel, reference
  digest, observed time, actor, reason code and state classification. Each case proves all three
  reads reject the corrupted authority and that the count never reports it.
- Failure injection: outcome insert, supersession, obligation establishment, command evidence and
  lock-boundary injections all roll back completely with no orphan evidence, no half-superseded
  authority, no phantom entitlement and no falsely replayable command.
- Migration runtime: fresh Schema 22, exact Schema 21 → 22 rehearsal, repeat safety, capability
  repair, legacy preservation, no delivery/obligation backfill, six malformed-storage fail-closed
  cases and the retained-022/stale-schema-version activation path.
- Concurrency: 10-mode deterministic gated matrix (outcome vs completion in both orders, outcome vs
  cancellation, schedule release, schedule revision, Enrolment closure, Term closure, correction vs
  correction, duplicate identical assertion, unrelated Lesson independence).
- Phase F–N regression suites re-run as part of the owner matrix.

Per-mode database lock attribution is recorded as `privilege_required` in this environment because
the least-privilege test user cannot read `information_schema.INNODB_TRX`; determinism is enforced by
the committed gate, worker artefacts and verifier instead, exactly as recorded for Phase N.

## Residual and deferred work

Deferred by decision: Google Meet/Calendar ingestion and reconciliation, signed public join/absence
links, Student/Teacher self-service, payability and Finance consequences, notifications, partial
delivery/proration, legacy attendance migration and cutover, retention durations, and any automated
materialisation or scheduling of an owed occurrence. `review_required` outcomes block completion
until an authorised correction resolves them. Materialising an academy obligation into a delivered
future occurrence remains a later explicit command/phase; Phase O records the entitlement only and
never creates or schedules a Lesson for it.
