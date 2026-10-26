# Interim Attendance & Completion Policy

Status: source candidate; not deployed, not merged, and not approved for production financial release.

This slice implements the temporary policy that applies while Google Meet is still in use and before
Delnavazan Live Class replaces it. It deliberately does **not** build Google Meet attendance, calendar
participant tracking, or any Live Class runtime.

## Policy

| Fact | Behaviour |
| --- | --- |
| Scheduled | A canonical Lesson remains `authorised` until its canonical UTC occurrence end. A future or currently running occurrence is never completed. |
| Presumed completion | After the latest canonical schedule version has ended, silence may append a `completed` lifecycle event with reason `canonical_lesson_presumed_completed`. No Phase-O `delivered` outcome is created. |
| Verified completion | An administrator may record `delivered`, `student_no_show` or `interruption` through the existing Phase-O authority. A presumed completion plus a verified outcome is reported with both facts; only the outcome is attendance truth. |
| Not delivered | An administrator corrects a completed occurrence through the existing O-D8 append-only reconciliation, preserving the completion event and establishing the academy obligation. |
| Disputed | An administrator may append a `review_required` outcome linked to the historical completion event. A dispute blocks payability and statement issuance until it is resolved. |
| Corrections | Every correction is an append-only Phase-O command/outcome with actor, UTC timestamp, reason code and prior outcome lineage. No lifecycle or attendance evidence is deleted. |

Presumed completion is **not** independently verified attendance. Finance therefore derives
`pending / presumed_completion_review` from a presumed completion with no verified outcome. A pending
payability blocks statement issuance; no irreversible payment is released from silence alone.

## Automation

`LessonCompletionAutomationWorker` is a WP-Cron worker registered through the existing scheduled-worker
infrastructure. It asks `CanonicalLessonAuthorityService::presume()` for ended `authorised` Lessons.
The authority guard runs under the canonical enrolment-root and Lesson locks and refuses when:

- the latest canonical schedule has not ended or has not started;
- any Phase-O outcome already exists;
- an attendance case is `open`, `ready_for_review` or `settlement_pending`;
- an unresolved conflict receipt exists;
- the Lesson/schedule/delivery aggregate fails its owning integrity gate.

Evidence is anchored to the exact schedule version and its canonical UTC end, so repeated runs and
exact command replays converge without duplicating lifecycle events. The worker never contacts Google
or any other provider.

## Manual reports

- Teachers can report delivered or an issue through the Teacher Portal; these are Phase-P claims, not
  settlement.
- Students can give an advance absence notice, or report after class that it did not take place / is
  disputed.
- Reports are principal-scoped, idempotent per principal + Lesson + schedule version + reason, and
  remain in the administrator review queue until resolved.
- Administrators can record a first outcome, confirm delivery, reconcile non-delivery, or mark a
  completion disputed from the canonical Class record.

## Provider independence and Live Class

The policy consumes only canonical Lesson, schedule, Phase-O delivery and Phase-P attendance facts.
A future Delnavazan Live Class can submit authenticated join/leave evidence as Phase-P claims through
the same intake boundary. It does not need a new attendance table, a new completion authority, or any
Google-specific dependency.

## Validation

- `tests/interim-attendance-completion-contract.php` — source contract.
- `tests/interim-attendance-completion-runtime.php` — disposable WordPress/MariaDB runtime covering
  presumed completion, duplicate worker runs, manual verified delivery, non-delivery reconciliation,
  disputes, late reports, cross-user denial, idempotent reports, future/running refusal, DST offsets
  and the financial safeguard.
- The existing post-intro runtime is run in the same disposable job to prove the 12-Lesson enrolment,
  scheduling and instalment-funding rules remain intact.
- `tests/phase-2a2o-contract.php`, `tests/phase-2a2p-contract.php` and the existing Finance contracts
  remain green.
