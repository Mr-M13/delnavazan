# Admin class-handling UX specification

Date: 2026-11-19 · Session: opencode/intro-offer-visibility-20261119
Companion to `docs/ADMIN-CLASS-WORKFLOW-AUDIT-2026-11-19.md`. This document fixes the
front-end and UX rules for the consolidation work so the admin surface stays easy to follow,
navigate and understand, while still giving the operator every action needed to manage
classes and anomalies.

## 1. UX principles

1. **One status, one primary action.** Every screen shows the same status words and the same
   single “do the next thing” button. Nothing else competes with it.
2. **Plain language.** Statuses and buttons use full sentences, not internal codes:
   “Confirm the intro now”, “Record the outcome”, “Convert to enrolment”, “Decline this
   request”.
3. **No dead ends.** Every status has an obvious next action, including “needs review” when
   the system itself detects mixed records.
4. **More actions, not fewer.** Administrative power is preserved; advanced or constrained
   actions (manual match, allocation recovery, notifications, lifecycle) live behind a
   clearly labelled **More actions** disclosure on the same page.
5. **Consistent vocabulary across screens.** Overview, Enrolment queue and the case page use
   the same status names and the same colours, so a request never “means” different things in
   different places.
6. **Confirm only irreversible changes.** Declining, cancelling, skipping the intro and the
   total override ask for a one-line reason and an explicit confirmation; everything else is
   one click.
7. **Keyboard and plain-HTML friendly.** Actions are real buttons/forms, never buried in
   hover-only or drawer-only controls.

## 2. The one set of statuses

Used verbatim on Overview, Enrolment and the case page:

| Status key | Label shown | Tone | Primary action |
| --- | --- | --- | --- |
| `identity` | Identity needed | warn | Resolve identity / record blocker |
| `new` | New request | teal | Open coordination |
| `awaiting_teacher` | Waiting for teacher | gold | Confirm intro now (override) |
| `trial_scheduled` | Intro scheduled | teal | Classroom / retry actions |
| `provisioning` | Creating classroom | gold | Retry or review projection |
| `outcome_due` | Outcome due | gold | Record the intro outcome |
| `continuation` | Decision & payment | gold | Convert / send payment link / skip intro |
| `enrolled` | Enrolled | ok | Open classes & term |
| `declined` | Declined | neutral | View history (request closed) |
| `closed` | Closed | neutral | View history |
| `needs_review` | Needs review | danger | Review mixed records (primary fix) |

`needs_review` is the system’s way of telling the operator “two authorities disagree about
this request” instead of showing two contradictory next steps.

## 3. Screen-by-screen design

### 3.1 Overview

- Keep the task-first “Needs Attention” cards, but each card is generated from the journey
  status model so its count always matches the Enrolment queue.
- Each card links to the Enrolment queue pre-filtered to that status.
- Keep Today’s classes and the Scheduled Introductory Classes panels; add the same status
  pill vocabulary to each row.

### 3.2 Enrolment queue

- One list. Columns: **Priority pill** (status colour), **Date/time**, **Student**,
  **Teacher**, **Status words**, **Next action** (a real button), **Open**.
- Filters at the top: status, teacher, student, instrument/course, date, and a search box.
- The **Next action** column shows the same primary action as the case page; clicking it
  performs or opens it directly where safe.

### 3.3 The case page (the main admin surface)

Top to bottom:

1. **Header:** student, instrument, timezone, status pill, next-action button.
2. **Decision strip:** the five-step progress (Identity → Teacher → Trial → Outcome →
   Enrolment) with the current step highlighted.
3. **Primary action card:** one card, titled with the status label, containing only the
   fields for the primary action. Examples:
   - *Confirm the intro now* (override): Teacher, date, time, timezone, reason, checkbox.
   - *Record the outcome*: delivered / not delivered + note.
   - *Convert to enrolment*: the accepted arrangement summary + Confirm.
   - *Decline this request*: reason + Confirm (closes the booking request and its records).
   - *Skip intro*: reason + Confirm (audited; enables conversion without an intro).
   - *Needs review*: a plain explanation of which records disagree + buttons to fix each.
4. **Facts:** learner, contact, instrument, requested times, offer history (read-only).
5. **More actions (collapsed):** manual match (constrained), allocation recovery, retry
   projection/Meet, reschedule/cancel, notifications, privacy erasure — each still
   capability-checked and going through its own authority.
6. **Timeline and notification evidence:** unchanged, appended with every journey command.

### 3.3.1 Owner execution path (offline agreement already reached)

When the owner has already spoken to the Teacher and the Student/guardian, the case page
opens with a single **Execute agreement** card above the advanced action stack. The
operator does not re-create the negotiation; the system records it.

The card asks only for:

- outcome: **Intro** or **Full term** (no single-class option),
- Teacher,
- local date, time and timezone,
- for Full term: payment plan (full / two instalments) and payment state
  (pending / already received with a receipt),
- one agreement note,
- one attestation that the Teacher and Student/guardian already agreed offline.

Every technical record is then written by the existing authorities and hidden from the
operator:

- Teacher candidate and Teacher assent (`administrator_attestation`),
- proposal, provisional acceptance and the immutable final arrangement with
  `authority_route = administrator_attested` and the administrator's WordPress ID,
- conversion to Enrolment, canonical Term and Teacher assignment,
- the normal post-intro invitation, commercial offer and activation job.

The original requested time is retained in the audit event
`booking_request.administrator_agreement_executed`; the administrator's chosen slot is
authoritative and is also written to the requested-time record. A real Teacher or Student
collision is still refused. The card never invents a paid state: offline payment requires
a receipt/reference.

For Full term, the confirmed intro is recorded as **administratively completed**
(`source = administrator_completion`) so the normal continuation payment and activation
pipeline runs. The Enrolment and Term stay `authorised`; no class is created until verified
payment activates them.

The formal coordination, proposal and acceptance path remains available under More actions
for exceptional cases. The owner execution path is the default for owner-operated work;
the formal path remains the authority model for delegated or future scaled operation.

### 3.4 Teacher and Student portals

- Teacher portal shows the intro request when it is actionable (offer) and the class when
  confirmed; no change to acceptance authority.
- Student portal shows the confirmed intro and the continuation steps; no new surface.

## 4. Admin duties covered end-to-end

| Duty | Where |
| --- | --- |
| See every open journey in one list | Enrolment queue |
| Match/confirm a teacher with a class time | Primary action: Execute agreement (Intro) |
| Execute an offline full-term agreement on the Student’s behalf | Primary action: Execute agreement (Full term) |
| Change the request time while keeping the original for audit | Execute agreement date/time, or More actions → Reschedule |
| Use the constrained match when rules must hold | More actions → Manual Teacher match |
| Reschedule/cancel an intro | More actions → Reschedule / Cancel |
| Record intro outcome | Primary action: Record outcome |
| Skip the intro for a reviewed reason | Primary action: Skip intro |
| Convert arrangement to enrolment + term | Primary action: Convert to enrolment |
| Decline/close a request | Primary action: Decline this request |
| Retry classroom/projection/Meet | Primary action (provisioning) or More actions |
| Review notifications and audit | Facts + Timeline + More actions |
| Fix a mixed-state anomaly | Primary action: Needs review |

## 5. Acceptance checklist for the implementation

- [ ] One primary action per status on the case page; no competing panels expanded by default.
- [ ] The same status label and colour appear on Overview, Enrolment queue and the case page.
- [ ] Every action has a capability check, an audit trail and a user-visible result message.
- [ ] No status is a dead end; `needs_review` appears for mixed records instead of
      contradictory prompts.
- [ ] Keyboard users can complete every primary action; drawer/hover-only paths are removed.
- [ ] Contract tests assert label parity and one-primary-action per synthetic state.
