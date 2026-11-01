# Teacher delivery confirmation

**Status:** implemented behind the existing authenticated Teacher Portal action
**Scope:** Teacher-confirmed delivery for an ended canonical Lesson occurrence

## Problem

The Teacher Portal's "تأیید برگزاری و حضور" action previously submitted a Phase-P
`delivery_claim`. A claim is evidence only: it does not settle attendance, complete
the Lesson or make the occurrence payable. A teacher could therefore submit the
confirmation, still see the class in Today, and submit it again. The class also
remained reschedulable until an administrator reviewed the claim.

The automatic completion worker eventually presumes completion from elapsed
scheduled time. That is a lifecycle fact, not verified attendance. The portal
needed a narrow, authenticated path that records the Teacher's own confirmation
as canonical delivered truth and completes the Lesson, without creating a second
attendance authority.

## Contract

`TeacherDeliveryConfirmationService::confirm()` is the only new seam.

It requires:

1. an authenticated Teacher principal with active Teacher authority;
2. the exact canonical Lesson, owned by that Teacher;
3. the exact currently applicable schedule version UID;
4. a canonical occurrence whose end instant has passed;
5. no unresolved attendance case (`open`, `ready_for_review`, `settlement_pending`);
6. no conflicting effective delivery outcome. An existing `delivered` outcome is
   accepted idempotently.

It then:

1. records the controlled `delivered` outcome through
   `CanonicalLessonDeliveryService::recordTeacherConfirmedDelivery()` with
   `evidence_channel=authenticated_platform` and
   `reason_code=teacher_confirmed_delivery`;
2. completes the Lesson through
   `CanonicalLessonAuthorityService::completeTeacherConfirmedDelivery()`;
3. invokes the existing `LessonFinanceOrchestrationService` after completion;
4. re-reads the effective outcome and Lesson lifecycle before reporting success.

Deterministic command keys are derived from the Lesson, schedule version and
Teacher. A repeated POST converges on the same outcome and cannot create a second
delivery event, a second completion or a duplicate Finance capture.

## Boundaries

- The new methods re-check Lesson ownership under the canonical lock. The claim
  capability alone is not sufficient.
- A cancelled Lesson is refused. A future or running occurrence is refused.
- An unresolved Student absence/dispute is refused and stays with the existing
  administrator review workflow.
- Confirmation creates no replacement, absence entitlement, payout, invoice,
  statement, settlement or booking.
- The student's own exit from the classroom never calls this service.
- The existing `delivery_issue` report still uses `CanonicalAttendanceIntakeService`
  and remains non-settling evidence.

## Failure presentation

The controller maps controlled refusals to Persian Teacher-facing statuses:

| Status | Meaning |
| --- | --- |
| `class_completed` | Confirmation succeeded and the class left the actionable Today list. |
| `completion_too_early` | The scheduled occurrence has not ended. |
| `completion_not_allowed` | Ownership, schedule, review or conflicting outcome refusal. |
| `completion_unavailable` | Unexpected failure; the class is not removed. |

## Verification

- `platform/tests/teacher-delivery-confirmation-contract.php`
- `platform/tests/interim-attendance-completion-contract.php`
- `platform/tests/phase-2a2o-contract.php`

The WordPress/MariaDB runtime harness is required to exercise the real locks and
replay. It is not available in the source-only review environment; see the
integration report for the outstanding staging check.
