# Admin class-handling workflow audit

Date: 2026-11-19 · Session: opencode/intro-offer-visibility-20261119
Status: audit and plan only — no production code change accompanies this document.

## 1. Why this audit exists

One real learner journey (`REQ-01M7NS5PF2ND3ZXJXC3C5RAHF2`) exposed the following failures in
one afternoon:

1. The teacher account stopped showing the intro request after its automatic offer expired.
2. The administrator could not confirm the intro: the standard manual match refused with
   `This request already has a Teacher confirmation and cannot be manually reassigned`
   even though no confirmation existed.
3. The Proposal/Acceptance path produced an Accepted Service Arrangement before any
   introductory class was ever confirmed.
4. `Convert to Enrolment` then created a regular Enrolment with a Term and 12 sessions —
   skipping the intro entirely.
5. A manual intro creation attempt failed with
   `Introductory lesson was not created. … Reference: EWI-596C02139489`.
6. Closing the coordination case (recorded as declined) did **not** remove the request from
   the Enrolment or Overview surfaces; one screen kept saying “needs coordination” while
   another kept saying “needs conversion”.

The user asked us to stop shipping and audit the class-handling workflow. This document
records the findings and the implementation plan.

## 2. The core problem

The system has several parallel state machines for one learner journey, and there is **no
single journey-status model**:

| Authority | Records | What it decides |
| --- | --- | --- |
| Booking intake | `booking_requests`, `booking_request_requested_times` | request exists, unresolved |
| Allocation | `booking_allocation_offers`, `booking_request_teacher_deliveries` | which Teacher is offered, 24h response window, expiry/rotation |
| Coordination | `coordination_cases`, `coordination_case_candidates` | human review, candidates |
| Teacher agreement | `teacher_availability_assent_*` | attributable Teacher agreement |
| Proposal/acceptance | `proposal_families`, `proposal_options`, `proposal_versions`, `accepted_service_arrangements` | student-facing offer and final arrangement |
| Intro confirmation | `booking_request_confirmations`, provider projection | the introductory class itself |
| Outcome/post-intro | `booking_request_intro_outcomes`, `post_intro_preparations` | what happened and continuation |
| Regular course | `enrolments`, `terms`, `lessons`, canonical schedules | the ongoing course |

Each authority computes its own “next action”. The admin screens concatenate those
actions without reconciling them, so one page can offer four different next steps for the
same request.

## 3. Findings

### F1 — No ordering guard between the intro and the regular course (critical)

`CoordinationCaseController` `convert_enrolment` calls `EnrolmentConversionService::convert`
whenever a final `accepted_service_arrangement` exists. That path creates the canonical
Enrolment, and the follow-up actions create the Term and the 12 standard sessions. Nothing
checks that:

- an intro confirmation exists, and
- an intro outcome (`delivered` or an explicit skip) was recorded.

Result: a student can be enrolled in the regular course without ever taking the introductory
class — exactly what happened here.

Files: `platform/src/Admin/Controller/CoordinationCaseController.php`,
`platform/src/Core/Application/EnrolmentConversionService.php`.

### F2 — Closing a case does not resolve the booking request (critical)

Closing or declining a coordination case only updates `coordination_cases`. The booking
request remains `lifecycle_status=submitted`, `resolution_state=unresolved`, so every active
queue continues to show it. `CoordinationCaseService::transition()` also invalidates the
Teacher Availability Assent on case closure, which makes the Enrolment stage recompute
**backwards** to “Awaiting coordination” — the opposite of what the operator intended.

Files: `platform/src/Core/Application/CoordinationCaseService.php`,
`platform/src/Core/Application/EnrolmentWorkspaceReadService.php::bookingStage`.

### F3 — Two screens disagree about the same request (high)

- Enrolment stage logic (`EnrolmentWorkspaceReadService::bookingStage`) derives the stage
  from the confirmation/coordination/assent records.
- The coordination panel derives its recommended action from proposals and
  `accepted_service_arrangements` (e.g. “Convert the accepted arrangement”).
- The Overview pipeline (`AcademyJourneyReadService::stageCounts`,
  `coordinationQueue`) derives counts from yet another set of predicates.

After the operator closed the case, Enrolment said “needs coordination” while the
coordination panel said “needs conversion”. Both are correct against their own records and
both are wrong as a single journey view.

### F4 — Admin manual paths are stacked, not consolidated (high)

The intro case page renders, for one request:

- “Coordinate Teacher and acceptance”,
- “Total scheduling override” (added this session),
- “Manual Teacher match” (standard constrained path),
- the embedded coordination workflow with its own actions,
- proposal/acceptance actions,
- conversion and assignment actions.

Each requires different capabilities and different evidence fields. The workflow has no
single primary action per state, which is the “too many hoops and red tape” complaint.

### F5 — The manual-match refusal was misleading (fixed for one case, symptom remains)

Before the fix in this session, `AdministratorBookingMatchService::confirm` treated any
non-pending delivery as “already has a Teacher confirmation” even with no confirmation row.
That one case is fixed (PR #719) and a total override now exists (PR #720), but the deeper
symptom remains: the same failure message text is reused for genuinely different states,
and the error detail (`EWI-…`) is only in the host error log, not visible to the operator.

### F6 — The total override is an escape hatch, not a workflow (medium)

`BookingTeacherResponseService::administratorTotalOverrideConfirmDelivery` bypasses
eligibility/availability/occupancy/notice/readiness and is audited — which is what was
asked for. But it adds a fifth form instead of simplifying the journey. It also does not
reconcile the coordination case or the accepted arrangement, so the surrounding panels
continue to contradict each other after the override runs.

### F7 — Error evidence is invisible to the operator (medium)

The EWI reference for the failed intro creation points at a server-side `error_log` line.
There is no safe way for the operator (or the release record) to see the exception class and
message without host access. Host SSH itself has been intermittent from the GitHub runner
today, so even the read-only diagnostic cannot always reach the log.

### F8 — “Declined” has three meanings (medium)

“Decline” exists as:

- a Teacher declining one offer (`booking_request_teacher_declines`),
- a coordination candidate being marked not available,
- the operator closing the coordination case with a reason.

None of them resolves the booking request. An operator who “records declined” at the case
level believes the request is closed; the booking authority disagrees.

## 4. Design principles for the fix

1. **One status, many views.** One read model derives a single journey status; Enrolment,
   the case page, the Overview pipeline and the attention cards all render from it.
2. **One primary action per status.** The page shows the single action that moves the
   journey forward; other actions live behind a clearly labelled “More actions”.
3. **Every write has one owner.** Each authority keeps owning its table; a new thin
   journey command layer coordinates cross-authority transitions and audit trails.
4. **Closure propagates.** “Close/decline request” must set the request resolution,
   retire open offers/notifications, close the case, and take the request out of every
   active surface in one audited command.
5. **Ordering is enforced.** The regular-course conversion is gated on a recorded intro
   outcome (or one explicit, audited “skip intro” decision).
6. **Failures are visible.** Safe error codes are stored with the journey status and shown
   to the operator; the server log remains the deep-dive source.

## 5. Target journey (proposed)

```
New request
 └─ Teacher resolution (offer/claim/manual) ────────────────▶ Awaiting teacher / agreed
     ├─ (any state) Total override: Teacher + date/time + reason + ack ─▶ Intro confirmed
 └─ Decline request (reason) ─▶ Declined (request closed; student archiving is separate)

Intro confirmed
 └─ provider projection ─▶ Trial scheduled / provisioning
     └─ Record outcome ─▶ delivered | not delivered | skipped (audited)
         ├─ delivered / skipped ─▶ Continuation decision + payment ─▶ Accepted arrangement ─▶ Enrolment + Term
         └─ not delivered / closed ─▶ Closed with reason

Enrolled
 └─ Assignment / Terms / Lessons ─▶ regular course management (existing screens)
```

The total override becomes the **only** manual “confirm the intro now” action; the
constrained manual-match form and the allocation recovery forms fold into “More actions”.
The override capability remains owner- and staff-delegable.

## 6. Implementation plan

Each phase is its own PR with a self-review, green required checks, and its own production
dispatch (or explicit “docs-only” note). No phase merges around a failing check.

### Phase 0 — Evidence and safe failure visibility (no behaviour change)

- Add `BookingJourneyReadService::status(int $requestId)` as a **read-only** projection
  that returns the per-authority facts side by side (request, allocation, coordination,
  assent, proposal, arrangement, confirmation, outcome, post-intro, enrolment).
- Add a safe, operator-visible `failure_code` column/summary for journey commands that fail
  (mirroring the existing `EWI-` reference but readable in the UI/diagnostics).
- Extend `deployment/qa/production-release-diagnostic.php` to include a **redacted** tail of
  recent journey failures, so EWI references can be resolved without ad-hoc host access.
- Deliverable: diagnostics PR + doc update. No production dispatch if diagnostic-only.

### Phase 1 — One journey status, everywhere (read model + admin UX)

- Implemented against `docs/ADMIN-CLASS-WORKFLOW-UX-SPEC.md`: one status vocabulary, one
  primary action per state, and every advanced/constrained action behind “More actions”.
- Replace the case-page stack with a single `Resolve this request` panel rendered from
  `status()`: exactly one primary action + “More actions”.
- Migrate `EnrolmentWorkspaceView::caseActions`, the Enrolment queue stage labels, and the
  Overview pipeline/attention counts to the same status model.
- Keep every existing command path unchanged in this phase (status only affects display).
- Contract tests: a matrix of synthetic journeys asserts one primary action per state and
  identical labels on Enrolment and Overview.

### Phase 2 — Close/decline propagation

- New `CloseBookingRequestService` (thin command layer):
  - requires the coordination capability,
  - sets `booking_requests.lifecycle_status`/`resolution_state` to the declined outcome,
  - retires the active allocation offer and cancels its notifications,
  - supersedes active teacher declines for the request,
  - retires any Accepted Service Arrangement that was never converted, so the Overview
    “Accepted arrangements awaiting enrolment” card stops counting it,
  - closes the coordination case with the same reason,
  - writes one audit event and one timeline event,
  - is idempotent by command key.
- Student archiving stays a separate action and is not triggered by a decline.
- Replace the coordination “close case” action with this command for unresolved requests,
  so closing always propagates.

### Phase 3 — Ordering guards and the skip-intro option

- In `EnrolmentConversionService::convert` (and the proposal finalisation path), refuse
  conversion while the request has no recorded intro outcome, unless either a delivered
  outcome exists or an explicit **skip intro** decision was recorded by an authorised
  operator (audited, with reason).
- Implement the skip-intro decision as its own command (capability-gated, audited, shown in
  the timeline) so the ordering guard has exactly one bypass instead of many implicit ones.
- Add the reverse guard in the total override/confirm path: refuse to create an intro
  confirmation when a final accepted arrangement already exists, unless the operator
  explicitly overrides with a reason (so we cannot produce the F1 state again).
- Runtime contract: intro → outcome → conversion ordering is enforced both ways, and
  skip-intro is the only permitted shortcut.

### Phase 4 — Consolidate the manual actions

- Make `Total scheduling override` the single “Confirm intro now” primary action.
- Fold the constrained manual match, allocation recovery and re-offer controls into
  “More actions”, each still going through its own authority.
- Remove duplicated “next action” text; the status model decides the copy.

### Phase 5 — Data reconciliation for existing mixed states

- Build a **dry-run** reconciliation report (read-only) listing every request in a mixed
  state: accepted arrangement without intro outcome, closed case with unresolved request,
  enrolment without intro outcome, expired delivery without confirmation, and requests
  shown by one surface but not the other.
- For `REQ-01M7NS5PF2ND3ZXJXC3C5RAHF2`: verified that **no Enrolment/Term/12-session block
  was created**, so there is nothing to remove; the item to repair is the lingering Accepted
  Service Arrangement plus the unresolved booking request, handled by the Phase 2 decline
  command once it exists.
- The owner reviews the report and decides per request (confirm intro, skip intro, or
  close). Only then do operator-approved, idempotent repair commands run through the new
  journey command layer — never an ad-hoc SQL edit.

### Phase 6 — Tests, contracts and release

- Add runtime scenarios to the PR gate for: override confirm, close-propagation,
  ordering guards, one-primary-action rendering, Overview/Enrolment label parity.
- Update `AGENTS.md` §2/§6 in the same commit when the gate changes.
- Roll out one phase at a time; every deploy records the exact main SHA and smoke evidence.

## 7. What we deliberately will not do

- No more ad-hoc escape-hatch forms per incident.
- No merging around failing required checks.
- No auto-repairing production rows; reconciliation is owner-reviewed.
- No removing the audit trail: every override and closure keeps its actor and reason.

## 8. Owner decisions (2026-11-19)

1. **Decline means decline this booking request.** Declining closes that specific booking
   (usually an intro). Archiving a student is a separate, later action and is not triggered
   by a decline.
2. **Skip intro is a real option.** We will implement an explicit, audited “skip intro”
   decision so a booking can move to the regular course without an intro when needed.
3. **No mistaken Term was created.** Verified on the live student profile: no Enrolment,
   Term or 12-session block exists for `REQ-01M7NS5PF2ND3ZXJXC3C5RAHF2`. If future
   verification ever shows one, remove it during reconciliation.
4. **Override rights.** The schedule-override capability stays with the owner and must
   remain delegatable to staff as an ordinary permission (it is already a registered
   capability; keep it exposed in the Staff Access / delegated-access UI).

### Consequences recorded in this plan

- Phase 2 close/decline resolves the booking request and must also retire a lingering
  Accepted Service Arrangement, because the Overview still shows
  “Accepted arrangements awaiting enrolment” for a declined request.
- Phase 3 adds the audited skip-intro option as the only permitted bypass of the
  intro-outcome ordering guard.
- Phase 5 reconciliation treats “accepted arrangement without an intro and without an
  enrolment” as the current inconsistency to repair for this request.

## 9. Immediate state of `REQ-01M7NS5PF2ND3ZXJXC3C5RAHF2`

As of this writing: no intro confirmation; an Accepted Service Arrangement exists but was
never converted (no Enrolment/Term/lessons were created); the coordination case is closed as
Declined; the booking request is still unresolved, so Enrolment shows “Awaiting
coordination” and the Overview shows “Accepted arrangements awaiting enrolment”. The exact
`EWI-596C02139489` error line still needs to be read from the host log before a final
decision on this single request.
