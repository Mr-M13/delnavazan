# Missed-lesson replacement and absence self-service

## Root cause

The Student absence report correctly records Phase-P evidence without completing or cancelling the
Lesson. However, the reschedule workflow only accepted an `authorised` Lesson. Once an occurrence
had ended and the interim completion policy presumed it complete, the Teacher portal hid the
reschedule action and the backend returned `reschedule_lesson_not_eligible`. There was no
self-service replacement path for that state, so the only visible instruction was to contact an
administrator.

That refusal is correct for the *reschedule* lifecycle: changing the authoritative instant of an
already-completed occurrence would rewrite history. The defect was the missing, separately
authorised replacement lifecycle.

## Corrected lifecycle

One Teacher action, **تغییر زمان / جلسهٔ جبرانی**, is classified by the backend:

| Origin state | Lifecycle |
| --- | --- |
| `authorised`, occurrence not started | Reschedule; acceptance supersedes the schedule version |
| `authorised`, occurrence in progress | Refused (`reschedule_lesson_in_progress`) |
| `authorised`, occurrence ended without completion | Replacement, requiring an absence claim |
| `completed` | Replacement, requiring an absence claim |
| `cancelled` | Not offered from the portal; the existing operator replacement path applies |

Replacement creation is refused when:

- no Student absence claim exists for the exact occurrence (`replacement_attendance_unresolved`);
- the claim is disputed (`attendance_outcome_disputed` → administrator exception);
- the effective outcome is `delivered` (`replacement_not_owed_delivered` → exception);
- the effective outcome is `teacher_non_delivery` (`replacement_academy_owed` → exception; the
  academy-obligation lifecycle owns that case);
- the Term replacement allowance is exhausted (`replacement_entitlement_exhausted` → exception);
- a replacement already exists for the origin (`replacement_already_issued`);
- the origin is otherwise ineligible.

The Student must accept before any canonical authority changes. Acceptance issues the linked
replacement Lesson and its first schedule version in the same transaction, records the accepted
event, queues notifications and one calendar hand-off, and leaves the original Lesson, its
lifecycle events, schedule version and attendance evidence untouched.

A replacement Lesson is funded by the Term replacement allowance, never by a committed standard
session. It therefore does not bind, satisfy or reopen a standard protected-capacity interval; it
is still arbitrated against every other protected and concrete Teacher occupancy.

## Required system actors

- `dzn_reschedule_system_user_id`: existing reschedule actor with
  `dzn_manage_canonical_lesson_schedules` only.
- `dzn_replacement_system_user_id`: new actor with **only**
  `dzn_manage_canonical_lessons` and `dzn_manage_canonical_lesson_schedules`. It must not have
  `manage_options` or `dzn_manage_provider_integrations`.
- `dzn_reschedule_calendar_system_user_id`: existing calendar hand-off actor.

Missing or over-privileged actors fail closed. A missing replacement actor leaves the Student's
accepted proposal retryable rather than silently issuing a partial Lesson.

## Notifications and calendar

- Proposal: Student and admin notification rows.
- Acceptance: Student, Teacher and admin notification rows plus one idempotent calendar job.
- Rejection: Teacher and admin notification rows; a failed proposal also returns to the Teacher.
- Three rejections: durable `reschedule_negotiation_escalated` operational exception, visible in the
  existing admin exception queue and the Academy class-time-change history.
- Calendar hand-off creates the replacement occurrence through the existing provider projection
  authority; provider failure never reverses the committed canonical replacement.

## Portal behaviour

- Teacher: absence card offers one action, shows whether it is a reschedule or replacement, shows
  pending Student decisions, rejected/failed proposals and confirmed replacements.
- Student: pending proposals show the original occurrence and the proposed occurrence in the
  Student's own timezone/calendar, with explicit accept and reject actions.
- Admin: pending proposals and a collapsible history of confirmed, rejected, failed and expired
  proposals. Routine proposals do not require admin approval.

## Tests and evidence

- `platform/tests/lesson-replacement-service-unit.php`
- `platform/tests/missed-lesson-replacement-contract.php`
- `platform/tests/lesson-reschedule-service-unit.php`
- `platform/tests/lesson-reschedule-calendar-unit.php`
- `platform/tests/lesson-reschedule-notification-unit.php`
- `platform/tests/lesson-reschedule-migration-contract.php`
- `platform/tests/lesson-replacement-runtime.php` (run by the interim attendance runtime)
- Full PHP source-contract suite and PHP lint.

The runtime proof uses a presumed-completed synthetic occurrence, reports the missed class, has the
Teacher propose, has the Student accept, and verifies the linked replacement Lesson/schedule plus
the unchanged original Lesson and attendance evidence.

## Deployment

No schema migration is required (Schema 54). Before enabling replacement acceptance in production:

1. deploy the reviewed merge commit;
2. configure `dzn_replacement_system_user_id` with the two capabilities above;
3. verify `dzn_reschedule_system_user_id` and `dzn_reschedule_calendar_system_user_id` are still
   least-privilege;
4. run the read-only production smoke checks;
5. verify the deployed SHA and the platform schema/build identity.
