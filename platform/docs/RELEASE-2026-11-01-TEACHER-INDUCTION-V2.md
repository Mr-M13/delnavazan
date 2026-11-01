# Release 2026-11-01 — Teacher induction v2

## Scope

This release adds a progressive, non-blocking Teacher induction experience on top of the existing
invitation-led account onboarding. It does not change teacher identity, scheduling, absence,
replacement, finance or notification authority.

## Account onboarding

- Five honest steps: welcome, personal/professional details, availability, review/submission and
  staff-decision status.
- The welcome step explains Delnavazan, the Teacher Portal, required information, submission and
  administrator review.
- The profile step reuses existing fields, keeps optional fields optional, prepopulates known
  values and provides save / save-and-continue with server-side validation.
- Availability remains positive-only, uses `TeacherAvailabilityService`, keeps recurring rules,
  day offs, copy-day and Golden Hours, and never moves booked lessons.
- Review lists missing requirements with direct links. Google is stated as required because the
  authoritative readiness policy requires a usable connection; a connection still does not prove
  Meet works.
- Status distinguishes pending, returned, rejected, approved and offboarded. Returned applications
  receive a safe, mapped correction note; raw internal reason codes are never echoed.
- All lifecycle transitions, capability checks and audit events remain unchanged.

## Teacher induction

Seven versioned modules: portal, availability, everyday responsibilities, schedule changes,
absence/replacement, finance and escalation. Content lives in `platform/content/induction/*.php`;
rendering is separate. A compact dashboard card links to a dedicated `teacher-view=induction` hub.
Completion, skipping and revisiting never affect activation or access. Completed modules stay
available. Material policy changes can raise an explicit `acknowledgement_version` without
changing canonical Teacher activation.

Progress is stored in additive table `{prefix}dzn_teacher_induction_progress` with a unique
`(teacher_id,module_id)` key, content version, acknowledged version, completion time, last-visited
time and standard audit columns. Submission is nonce-protected; the Teacher is resolved from the
authenticated principal; cross-teacher access fails closed; repeated completion is idempotent.

## Contextual guidance

Inline, dismissible `<details>` guidance appears on availability, rescheduling, absence,
notifications and finance. It links to the full module and does not duplicate or overlap the
existing portal tour. The old tour no longer auto-opens when the induction card is present.

## Verification

- `php platform/tests/teacher-induction-unit.php`
- `php platform/tests/teacher-induction-contract.php`
- `php platform/tests/teacher-induction-presentation.php`
- Existing onboarding, readiness, portal, reschedule, replacement, finance and notification
  contracts.
- PHP lint over all changed PHP.
- The PR runtime gate.

## Migration and rollback

Migration `064_teacher_induction_progress` is additive and idempotent. It creates one table and
performs no backfill or update to existing onboarding, lesson, attendance, finance or notification
data. Schema identity remains 57, consistent with the additive 061–063 migrations.

A code-only rollback is safe; the table may remain in place and is ignored by older code. Dropping
the table removes educational progress only and never touches canonical Teacher onboarding or
access authority.
