# Enrolment Desk — administration redesign

Status: proposed · 2026-11-23 · owner review required before implementation

## 1. Why

A single returning Student, one agreed weekly slot, one payment link, took an
operator, two engineers and most of a night. Nothing was broken in the domain:
the booking was confirmed, the money rules held, and the audit trail is intact.
The cost came from the **admin surface**.

One journey is currently spread across five screens and roughly ten objects:

```
booking request → coordination case → candidate → availability assent
→ proposal family → option → version → provisional acceptance
→ final acceptance → arrangement → enrolment conversion → term
→ slot authority → pattern → offer → purchase → entitlement
→ post-intro preparation → activation job → lessons → attendance → finance
```

Every one of those objects is legitimate. None of them is a decision the person
enrolling a Student should have to make.

Evidence from the live case (2026-11-22/23):

| What the operator saw | What it meant |
|---|---|
| "No Teacher candidate has been considered" | the advisory candidate list is a coordination artefact |
| "Service arrangements awaiting acceptance" | the term agreement does not exist yet; the intro was already confirmed |
| "2 open blockers" | a failed intake notification and a missing candidate, neither blocking the intro |
| `EWI-…` with no readable log | an unmapped PHP `Error` hiding a one-letter method-name typo |
| "Create intro" refused at 18:45 | the picker only offered `:00`/`:30` |

## 1.1 Related documents and status

`docs/ADMIN-CLASS-WORKFLOW-AUDIT-2026-11-19.md` and
`docs/ADMIN-CLASS-WORKFLOW-UX-SPEC.md` already established the same principles — one
status, one primary action, plain language, no dead ends — for the **class** workflow.
This document extends them to the **enrolment** journey and supersedes the
enrolment-specific parts of the older spec: the status vocabulary, the single next
action, and the coordination wizard as a required stop.

Where the two disagree about enrolment, this document wins. The 2026-11-19 UX spec
stays authoritative for class-delivery detail, and §6 here only adds the weekly
attendance list on top of it. There is no third UX specification.

## 2. Goals

1. One list, one detail page, one next action per person.
2. Status expressed in the operator's language, derived from the records.
3. Every refusal is a sentence that names the control, with the reference only
   as a secondary detail.
4. No screen requires the operator to understand coordination cases, proposals,
   acceptances or capacity claims.
5. Nothing is weakened: payment-before-classes, capacity, identity, finance and
   attendance evidence keep their current authorities and contracts.

### Non-goals

- No rewrite of the domain services, schema or contracts.
- No deletion of audit records (coordination cases, arrangements, acceptances
  stay; they stop being *screens*).
- No change to the student/teacher portals in this phase.
- No new payment, notification or calendar provider paths.

## 3. The Desk

### 3.1 List

One row per person, newest activity first. Columns: person, instrument, status,
next action, owner, last activity.

```
Learner                    Instrument   Status                Next action              Updated
بهجت السادات اردستانی      دف           Intro booked (24 Nov) Record outcome            2h
رضا محمدی                  تنبک         Waiting for payment   Resend payment link       1d
سارا کریمی                 دف           Active term (7/12)    —                        3d
```

Statuses, in plain language, derived (never stored as a free field):

| Status | Derived from |
|---|---|
| New request | request submitted, no confirmation |
| Needs a Teacher | request open, no viable candidate/agreement |
| Intro booked | confirmation state `confirmed`, occurrence in the future |
| Outcome due | confirmation ended, no outcome |
| Waiting for payment | prepared/checkout_pending, no settled payment |
| Active term | enrolment current, term funded, lessons scheduled |
| Paused / Finished | enrolment paused/completed, term closed |

### 3.2 Detail: one vertical flow

Finished steps collapse to a single read-only line. Only the current step has
controls, and it always shows **what happens next**.

```
بهجت السادات اردستانی · دف · Veghel NL                         [ Intro booked ]
──────────────────────────────────────────────────────────────────────────────
1  Contact & identity          ✓ verified · sadat.sa1973@gmail.com  (+1616121505)
2  Teacher & schedule          ✓ ادیب رحمانی · Tue 18:45 Europe/Berlin
3  Price & plan                ✓ Full term · EUR 0 outstanding (no intro fee)
4  Payment                     ○ nothing due until the term is confirmed
5  Term                        ○ starts Tue 1 Dec 18:45 · 12 sessions

Next: the intro is on 24 Nov 21:15 Tehran. After it ends, record the outcome.
      [ Record outcome ]   [ Reschedule ]   [ Cancel ]
──────────────────────────────────────────────────────────────────────────────
Needs attention
  ! Student notification "requested" failed (invitation_stale)   [ Retry ]  [ Ignore ]
```

Step 2 when nothing is agreed yet:

```
2  Teacher & schedule
   Teacher      [ ادیب رحمانی ▾ ]        eligibility and availability checked on save
   Weekly slot  [ Tue ▾ ] [ 18:45 ▾ ] [ Europe/Berlin ▾ ]
   ☐ Hold an introductory lesson first
        (suggested off when the Student has any prior enrolment)
   [ Confirm booking ]   — one click; the refusal, if any, appears here in one line
```

Step 4:

```
4  Payment
   Amount   EUR 360 · Full term
   [ Send payment link ]    [ Record offline payment ] (finance capability)
   Status   link sent 23 Nov 04:12 · opened? unknown · paid? no
```

## 4. Object mapping (step → authority)

The Desk is a projection and a command surface. Every action calls an existing
service; no new writes.

| Step | Action | Service |
|---|---|---|
| 1 | intake (student self-service) | `BookingRequestSubmissionService::submitPublic()` |
| 2 | create booking | `AdministratorIntroBookingService::create()` |
| 2 | confirm agreement offline | `AdministratorAgreementService::execute()` |
| 2 | skip the introduction | `SkipIntroBookingService::skip()` |
| 2 | record intro as completed without a class | `PostIntroPreparationService::recordAdministrativeCompletion()` |
| 4 | issue offer + checkout | `PostIntroContinuationService::continueAsAdministrator()` |
| 4 | offline settlement | `ManualPaymentSettlementService::markStandardPaid()` |
| 5 | activation on verified payment | `PostIntroActivationService::activate()` |
| 5 | term/lesson creation | canonical lesson + schedule authorities |
| — | attendance | `CanonicalLessonDeliveryService::record()` |

The coordination case, candidate, assent, proposal, acceptance, arrangement and
conversion records are still produced by those services. The Desk never asks the
operator to visit them; they appear as timeline lines:

```
23 Nov 03:10  Request received
23 Nov 03:11  Intro confirmed · ادیب رحمانی · Tue 24 Nov 18:45
23 Nov 04:12  Payment link queued (email)
```

## 5. Exceptions, not blockers

Anything needing a human becomes one card inside the person's page, with one
sentence and one action. The card names the object that failed, the consequence,
and the single permitted action. No counts of "open blockers"; no separate
coordination queue.

Categories kept: failed notification, real Teacher/Student collision, missing
provider projection, payment mismatch, capacity hold expiry, identity ambiguity.

## 6. Class admin

One list for the working week:

```
Tue 24 Nov  18:45  بهجت السادات اردستانی · ادیب رحمانی    [ Delivered ] [ No-show ] [ Cancel ]
Tue 24 Nov  19:30  رضا محمدی · سارا کریمی                  [ Delivered ] [ No-show ] [ Cancel ]
```

Attendance records feed finance through the existing delivery authority. A
class detail page stays available from the row, but is not a required stop.

## 7. Invariants this design must not disturb

- **Payment before classes**: no lesson exists for an unfunded term.
- **`first_regular_slot_not_after_introduction`**: the term cannot take the
  intro's instant; the Desk must never imply otherwise (show it in the copy).
- **Teacher/Student occupancy**: including committed confirmations; a real
  collision always refuses.
- **No false attendance**: delivered requires the delivery authority's evidence.
  An unattended class is a no-show/absence, never "attended".
- **Audit**: every command keeps its existing idempotency key and audit event.
- **Capabilities**: step 4 offline settlement requires the finance capability;
  coordination commands keep their current capability guards.

## 8. Screens to hide (after parity, never before)

Hidden = removed from the menu and the nav, **not deleted**. A capability-gated
direct URL keeps them reachable for one release.

| Menu entry | Slug | Folded into | Phase |
|---|---|---|---|
| Class admin (coordination) | `dzn-booking-request-coordination` | Desk timeline + exceptions | 3 |
| Booking requests | `dzn-booking-requests` | Desk list | 3 |
| Bookings and enrolments | `dzn-enrolment-workspace` | Desk | 3 |
| Enrolments | `dzn-enrolment` | Desk list/detail | 3 |
| Attendance and post-intro | `dzn-attendance-workspace` | Desk exceptions + Class admin | 3 |

Kept unchanged: Students, CRM, Teachers, Courses, Instruments, Terms (advanced
views), Finance, Notifications, Integrations, Settings, Exceptions, Diagnostics.

## 9. Rollout

| Phase | Deliverable | Exit criteria |
|---|---|---|
| 1 | This spec | owner approval |
| 2 | Desk list + detail, steps 1–4, capability `dzn_use_enrolment_desk` | a new request can reach "waiting for payment" in ≤5 actions, with no coordination screen |
| 3 | Step 5 (activation) + hide the five screens behind a flag | one week side-by-side with no fallback use; flag flipped per role |
| 4 | Class admin attendance list | weekly attendance recorded without opening a class page |

Rollback: the flag stays a setting; flipping it restores the current menus. The
`AGENTS.md` sync is required in the phase that changes menus, per §6.

## 10. Acceptance criteria

- From "New request", an operator reaches "Waiting for payment" without leaving
  the Desk and without opening a coordination case.
- Every refusal names the control in one sentence; the `EWI-` reference appears
  only as a secondary detail.
- The Desk writes nothing directly: each command goes through the service listed
  in §4, proven by a source contract that asserts the call, not the SQL.
- Payment gate proven: no lesson row exists before settlement; the Desk shows
  "no class until paid" in the step, not only in a flash message.
- Attendance cannot be recorded as delivered without the delivery authority.
- Contract coverage for: list status derivation, step gating, command mapping,
  exception cards, and the hidden-screen capability gate.

## 11. Open decisions for the owner

1. **Intro default**: suggest "skip" automatically when the Student has a prior
   enrolment, or always ask? (spec assumes: suggested on, operator confirms)
2. **Offline payment**: which role may record it — finance only, or the Desk
   operator with a finance attestation?
3. **Coordination evidence visibility**: timeline lines only, or a collapsed
   "evidence" drawer with the proposal/acceptance records?
4. **Who owns the Desk**: a new capability granted to the current coordination
   role, or reuse `dzn_manage_booking_request_coordination`?
5. **Terms/Enrolments advanced screens**: keep them menu-visible for finance and
   reporting, or move them under "Advanced"?

## 12. Effort

| Phase | Estimate |
|---|---|
| 1 spec | 0.5 day |
| 2 list + detail + steps 1–4 | 3–4 days |
| 3 step 5 + screen hiding | 2 days |
| 4 class attendance list | 2 days |

Assumes no schema change. The only schema ever needed here would be a status
cache, and the spec deliberately avoids one.
