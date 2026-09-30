<?php
// WordPress/MySQL administrator behavioural-test scaffold; not run by source checks.
// Verify each menu screen is capability-gated; every mutation rejects a missing/
// invalid nonce; create/list/detail works for Teacher, Student, Instrument,
// Course, Enrolment, Term and all three Lesson types; schedule/reschedule
// delegates preserve identity/history; archive/restore delegates have no delete;
// exception filtering/detail/transitions/retry work; diagnostics omit secrets and
// raw database errors; no public REST/AJAX mutation route exists. Create POSTs
// must exclude dzn_action, nonce, referrer, and any other control fields before
// persistence, while retaining only the entity's documented form fields.
$root = dirname(__DIR__);
foreach (['ScreenController', 'Menu'] as $class) {
    if (!is_file("{$root}/src/Admin/Controller/{$class}.php")) throw new RuntimeException("Missing {$class}");
}
echo "Phase 1E WordPress/MySQL behavioural scaffold loaded; not executed\n";
