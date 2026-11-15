# Task 02: Portal read failure investigation (in progress)

## Confirmed root cause
`PortalAuthenticatedReadService::student()` catches all exceptions from post-intro and pending reschedule readers and replaces the result with an empty list. The student theme adapter consumes those lists without health metadata; a failed read therefore looks like a legitimate empty state. The portal-level teacher reader generally fails closed instead; other teacher-side optional read catches require separate verification before extending this fix.

## Scoped correction
The two independent student projections now emit `read_health` statuses (`ok` or `unavailable`), retaining the existing list fields for compatibility. Failed reads carry a random correlation ID, and a sanitised operational event/log includes service, timestamp, severity and exception class, never SQL or exception message. The theme displays one Persian RTL retry warning per failed projection, preserves successful sections and prevents an all-empty failed read from becoming a legitimate `no_data` page. Retry reloads the current authenticated page. No database schema, booking, payment or enrolment state changes.

## Compatibility and risk
- Existing consumers still receive `post_intro` and `reschedule_proposals` arrays.
- `read_health` is additive and absent in older fixtures; the theme defaults to the previous rendering.
- Teacher optional reads, payment history and other independently caught failures are not yet comprehensively covered; avoid claiming full Task 02 acceptance.
- Production release must not occur before full regression testing and review.

## Validation
- PHP lint on changed files: pass.
- `portal-read-health-contract.php`: pass.
- `portal-read-health-unit.php`: pass (sanitised diagnostic, correlation, repeated failure).
- `phase-student-portal-state-contract.php`: pass.
- `teacher-portal-phase2-contract.php`: pass.

## Remaining before release
Exercise actual database failures and recovery in disposable WordPress/MariaDB, validate student and teacher ownership boundaries, verify browser RTL/error/retry and ensure all other portal read paths do not silently report absence. Run exact-head CI, review and protected deployment/rollback gates. Do not merge or deploy until complete.
