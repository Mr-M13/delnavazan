<?php
// WordPress/MySQL behavioural-test scaffold. Run only in a controlled test
// database with an authenticated administrator; it is not run by source checks.
//
// Exception cases: trusted-record allowlist; entity type/positive-ID validation;
// bounded fingerprint_key differentiation; same active fingerprint dedupes and
// updates last_seen; closed recurrence creates a new record; concurrent reports
// serialize through the fingerprint advisory lock; acknowledge leaves all
// resolution fields SQL NULL; resolve/dismiss set resolution audit; invalid and
// zero-row guarded transitions fail; retry-unavailable fails; a permitted retry
// increments exactly one row.
//
// Archive cases: Instrument audit update omits nonexistent updated_by; Teacher,
// Student, Instrument, Course, Enrolment, and Term dependency conflicts reject;
// scheduled/completed/cancelled Lessons reject; restore requires every specified
// parent and validates Lesson identity, Term ownership, and replacement original;
// no operation cascades or mutates identity/reference/history. A retained valid
// current Schedule Version restores a Lesson to scheduled; no history/pointer
// restores draft; missing, foreign, superseded, pointer-mismatched, or multiple
// current Schedule Versions reject as archive conflicts.
$root = dirname(__DIR__);
foreach (['ExceptionService', 'ArchiveService'] as $service) {
    if (!is_file("{$root}/src/Core/Application/{$service}.php")) throw new RuntimeException("Missing {$service}");
}
echo "Phase 1D WordPress/MySQL behavioural scaffold loaded; not executed\n";
