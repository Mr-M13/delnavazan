# Task 02 — Silent portal read failures: source investigation and correction

## Confirmed root cause
The student authenticated reader previously caught exceptions from the post-intro and reschedule projections and returned empty arrays without status. A separate repository path (PostIntroRepository::preparationsForStudent) also returns [] after a failed database read; without checking `$wpdb->last_error`, no exception was thrown. Core lesson/enrolment/booking SELECTs similarly cast failed reads to empty arrays. The theme then treated these arrays as genuine absence of invitations/classes.

The teacher authenticated reader fails closed for core lessons/assignments. However, the teacher theme composed multiple optional task readers using catch-and-empty patterns: booking inbox, unassigned opportunities, absences, pending/declined reschedules and post-intro outcomes. Their false empty state is confirmed by source inspection. Teacher finance already exposes separate `unavailable` states; notification work belongs to a separate active PR.

## Correction
- Student: additive `read_health` metadata with `ok` or `unavailable`, keeping all existing owned list contracts and legitimate empty lists intact.
- Detect failed SELECTs before converting SQL results to empty lists, including post-intro preparation retrieval and core student/teacher aggregate discovery.
- Teacher: independent optional task health statuses, retaining validated records when a later row fails; the existing canonical teacher core reader and owner-scoped services remain authoritative.
- Display a single accessible Persian RTL unavailable notice per portal page (aggregating affected sections), with a reload link. The existing full-portal error state remains for fatal core reads.
- Operational failures log/hook only a random correlation ID, affected service, severity, timestamp and exception class; neither SQL/error message nor PII is transmitted. Read-only execution; no change to booking, finance, enrolment, schema, permissions, retry workers or migration history.
- Backward compatibility: existing `post_intro` and `reschedule_proposals` array fields remain; theme views default to prior behaviour if `read_health` is absent. Legacy teacher adapters remain in place. A guarded diagnostic helper tolerates simplified test stubs.

## Source files and risks
- `platform/src/Portals/PortalAuthenticatedReadService.php`: ownership-preserving core and optional read guards; structured diagnostics.
- `platform/src/Portals/PostIntroStudentReadService.php`: database-error detection before an empty post-intro response.
- `theme/inc/platform-bridge.php`: student/teacher projection status and partial rendering.
- `theme/template-parts/portal/shell.php` and `theme/template-parts/teacher-portal/shell.php`: accessible Persian retry notices.
- Regression tests in `platform/tests/`; existing student portal state contract invokes the added tests in the established PHP CI job. The existing post-intro disposable runtime injects a read-only missing-table SELECT to prove actual MariaDB failure and recovery.
- Remaining risk: other independent optional portal surfaces, especially teacher induction, availability and notification-specific readers, are not globally refactored; no speculative changes to adjacent Codex or DeepSeek work. Recheck rollout after every future provider/query contract change.

## Testing and rollout
1. Full tracked PHP 8.3 lint, whitespace, exact-head PHP source contracts and security checks.
2. Synthetic successful/empty/error/timeout/partial/retry/ownership tests and student/teacher Persian RTL rendering.
3. Existing post-intro disposable WordPress/MariaDB runtime, including read-only missing-table SQL fault injection and verified recovery; no schema mutations outside disposable fixture bootstrap.
4. Review every changed file and PR comment, verify exact-head CI and branch compatibility.
5. Merge only after required checks and independent review; protected production deployment uses existing release workflow, backups and migration gates. No new migration is needed.
6. Verify deployed SHA, production health, student invitation, teacher requests, Persian retry notice (synthetic staging only; no production fault injection), and rollback readiness.

## Rollback
Use the previous validated production commit through the approved protected deployment mechanism, retaining database state and avoiding direct DB operations. The change is additive, with no migration or persistent state change; rollback reverts only read metadata and UI warnings.
