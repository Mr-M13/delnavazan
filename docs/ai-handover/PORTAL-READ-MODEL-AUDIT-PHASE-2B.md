# Portal read-model and workflow visibility audit — Phase 2B (2026-11-12)

**Scope:** Read-only trace on handover snapshot `251461bf`. Source-backed observations, not verified live incidents or an exhaustive audit.

## Confirmed backend → portal contracts
- `platform/src/Portals/PortalAuthenticatedReadService.php:12-47` resolves a canonical student principal, builds owned lessons, enrolments, booking requests and upcoming booking classes, then adds `post_intro` and `reschedule_proposals` projections. `theme/template-parts/portal/shell.php:110-160` consumes `schedule`, `payments`, `booking_requests`, `post_intro` and `notifications` by named keys.
- `platform/src/Portals/PostIntroStudentReadService.php:18-109` projects preparation states `ready`, `checkout_pending`, `payment_received`, `activated`, `expired` and `exception`; it only presents payment actions for ready/pending, and derives survey eligibility from authoritative continuation, hold and feedback state.
- `platform/src/Core/Application/PostIntroPreparationService.php:33-104` validates teacher identity, confirmation ownership, timing and outcome; handles duplicate outcome replay by resuming preparation rather than creating duplicate commitments.
- `platform/src/Integrations/LessonCompletionAutomationWorker.php:18-104` schedules five-minute **presumed** lesson completion for ended authorised canonical lessons. It explicitly does not claim verified attendance. Do not conflate this term-lesson worker with introductory-lesson outcome confirmation.

## Concrete UX/observability findings
**PORTAL-01 (medium): A failed post-intro read is indistinguishable from no post-intro work in the student portal.** `PortalAuthenticatedReadService.php:20-26` catches all exceptions from `PostIntroStudentReadService::forStudent()`, emits an action hook and returns an empty array. `theme/template-parts/portal/shell.php:150` renders the empty list without a dedicated unavailable state. The fail-soft behaviour protects the entire portal, but students may see no payment/continuation invitation during an outage. **Recommendation:** return a safe projection-health flag and show an explicit “continuation details temporarily unavailable” notice with refresh/support; keep server diagnostics correlated, without leaking internal details.

**PORTAL-02 (medium): The same empty-array fallback exists for reschedule proposals.** `PortalAuthenticatedReadService.php:27-33` catches all read exceptions and substitutes `[]`. `shell.php:120,137` consumes the result. An outage could hide a pending reschedule action. Recommendation: independent, accessible warning and recovery affordance while retaining the fail-soft portal.

**PORTAL-03 (medium, verify): Teacher progress aggregates completed/cancelled from lesson lifecycle state.** `PortalAuthenticatedReadService.php:53-90` computes total/completed/cancelled/remaining from term lesson rows. Verify that UI labels distinguish “scheduled but not completed”, “presumed completed”, “verified delivered” and “cancelled”, and that a funded term with missing future slots is flagged instead of looking like ordinary remaining lessons. Test against half-term instalment activation and student_id 7 scenario, using isolated test data.

**OPS-01 (medium): Finance completion side-effect errors are intentionally swallowed in multiple paths.** `LessonCompletionAutomationWorker.php:100`, `TeacherDeliveryConfirmationService.php:98`, `CanonicalAttendanceSettlementService.php:101` and admin completion paths call `LessonFinanceOrchestrationService::captureAfterCompletion()` with catch blocks that do not block lesson completion. Preserving the academic fact is correct, but verify finance retry/outbox and exception dashboard surface failures durably; otherwise a completed lesson may not trigger expected finance effects.

## Important distinctions
- A confirmed teacher intro outcome is processed by `PostIntroPreparationService`; canonical term lessons may use separate presumed completion automation. They must not be treated as the same event or paid/recorded equivalently.
- `PostIntroStudentReadService` already provides a useful status distinction for paid-but-not-activated vs activated. The primary concern is when its entire read fails and disappears.
- No claim is made that missing sessions, failed payment or finance events occurred in current production; these are code-supported failure-mode/UX findings.

## Proposed acceptance tests
1. Inject failure into post-intro read while other student portal sections load: portal remains usable **and** displays a clear continuation-unavailable notice.
2. Inject reschedule read failure: portal shows a warning, not “no pending reschedules”.
3. Set post-intro state `payment_received` with delayed activation: student sees accurate pending status, admin sees job age/retry.
4. Verify funded 6-of-12 and later instalment activation reconcile scheduled future sessions and progress labels.
5. Fail finance capture after a completed lesson: academic completion persists, finance retry/exception remains visible and recoverable.
6. Test student/teacher timezone and Jalali labels across every projected date field.

No source code changes, production changes or merges.
