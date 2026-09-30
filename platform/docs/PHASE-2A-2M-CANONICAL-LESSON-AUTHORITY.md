# Phase 2A.2-M — Canonical Lesson Authority

Status: **COMPLETE — INDEPENDENTLY REVIEWED / MERGED / CLOSED**

The failed candidate `1d723e0d7b5ef73db7bc6c24683a73c62684432c`, tree `cb577b7ece33d8533b2e47dacd4b3894566376ec`, was corrected on `phase-2a2m-canonical-lesson-authority-correction1`. Independently approved candidate `f44b502509f5dcb9b5d0281cfda4329407abd3bf`, tree `85a8309bb6179a2061c86245ea1ad9a975c00c2e`, was merged as `ed11086ad8ddc65899c3b855248611b1eb9e09a4`; the implementation merge tree exactly matches the approved tree. Schema 20 / migration `020_canonical_lesson_authority` / build `phase2a2m-canonical-lesson-authority-20260917.1` are authoritative.

## Locked contract

Canonical Lessons use `record_model` `canonical_term_lesson_v1` with Term + server-owned canonical sequence identity. Immutable provenance records `enrolment_id`, `student_id`, `course_id`, `teacher_assignment_id` and `teacher_id`; classification is `standard` or `replacement` and replacement Lessons carry controlled replacement-origin lineage. Lifecycle is `authorised -> completed` or `authorised -> cancelled`; `authorised` means issued canonical authority only and never scheduled, delivered, attended, paid or calendarised.

Issuance requires a current canonical Enrolment, current canonical Term and the current Teacher Assignment; a paused Enrolment blocks new issuance. The caller never chooses `teacher_id`, and a replaced Assignment makes stale issuance attempts fail while historical Lessons keep their recorded Teacher provenance. Allocation is derived from issued rows — at most 12 standard and 2 compensatory replacement authorities — cancellation never restores allocation, and no mutable allocation counter exists. Replacement eligibility requires the original canonical Lesson in the same Term, standard, cancelled, with explicit controlled replacement-eligible non-delivery evidence; generic cancellation is insufficient.

Closure guards: an Enrolment cannot close while non-terminal canonical Lessons would be stranded, a Term cannot close or cancel while non-terminal canonical Lessons would be stranded, and nothing cascades. Capability `dzn_manage_canonical_lessons` is administrator only. No scheduling, capacity, calendar, attendance, payment, notification, payroll, CRM or provider authority is included.

## Correction round 1

Independent review found the candidate not merge-ready. Four findings were corrected without changing the locked contract:

1. **Canonical aggregate provenance validation.** `CanonicalLessonAuthorityValidator::valid()` is the single hydration and integrity gate used by issuance, Lesson lifecycle transition, the Enrolment closure guard and the Term close/cancel guard. It validates the Lesson↔Term enrolment link, Lesson↔Enrolment Student and Course identity, the recorded Assignment's structural belonging to the Lesson enrolment with its immutable Teacher, and complete replacement-origin lineage. A historical Lesson is not required to keep its historical Assignment current.
2. **Replay revalidation.** Idempotent replay revalidates domain, operation, enrolment, Term, expected Teacher Assignment, expected Lesson, expected from-state, replacement origin, result Lesson and result state against the operation facts, revalidates the result aggregate through the canonical validator, and requires the durable lifecycle evidence the command recorded.
3. **Committed deterministic concurrency harness.** `tests/phase-2a2m-concurrency-runner.sh` with `-setup`, `-worker`, `-wait` and `-verify` coordinates gated workers, consumes worker artefacts and fails non-zero on an invariant violation across same-key issuance, the 12-standard boundary, replacement key/origin/cap races, Lesson versus Enrolment pause/close, Lesson versus Term close/cancel and Lesson versus Assignment replacement in both orders, plus unrelated-root independence.
4. **Failure injection and rollback.** Boundaries cover standard creation, replacement creation, completion, cancellation, lifecycle/history evidence and command evidence.

Corruption regressions cover every immutable provenance relationship through all four consumers, and replay corruption covers standard issuance, replacement issuance, completion and cancellation.

## Validation performed

Disposable MariaDB 11.4.13 (intended runtime target); no production or external system was contacted.

- All 29 static/source contract tests pass.
- Phase F, G, I, J, L, M0 and M runtime suites pass, including a genuine Phase-H Schema-14 fixture database upgraded to Schema 20.
- The 16-mode gated process-level concurrency matrix passes deterministically.
- Phase M is closed. No deployment or external authority change occurred.

## Residual validation

MariaDB requires the `PROCESS` privilege for `information_schema.INNODB_TRX`/`INNODB_LOCKS`, so per-mode lock-wait attribution is recorded as `privilege_required` in this environment while the gated holder/contender artefacts and the verifier remain the deterministic evidence. The MySQL 8 Performance Schema attribution path is implemented and was previously exercised by the owner on MySQL 8.4.11 but was not re-executed in this round.
