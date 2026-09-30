<?php
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$service = file_get_contents($root . '/src/Core/Application/TeacherAssignmentService.php');
$assessment = file_get_contents($root . '/src/Core/Application/TeacherAssignmentAssessment.php');
$readiness = file_get_contents($root . '/src/Core/Application/TeacherAssignmentReadinessService.php');
$read = file_get_contents($root . '/src/Core/Application/TeacherAssignmentReadService.php');
$idempotency = file_get_contents($root . '/src/Core/Application/TeacherAssignmentIdempotency.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherAssignmentRepository.php');
$teachers = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherRepository.php');
$archive = file_get_contents($root . '/src/Core/Application/ArchiveService.php');
$principal = file_get_contents($root . '/src/Core/Application/PrincipalInvitationService.php');
$runtime = file_get_contents($root . '/tests/phase-2a2j-isolated-runtime.php');
$migrationRuntime = file_get_contents($root . '/tests/phase-2a2j-migration-runtime.php');
$failureRuntime = file_get_contents($root . '/tests/phase-2a2j-failure-runtime.php');
$corruptionRuntime = file_get_contents($root . '/tests/phase-2a2j-corruption-runtime.php');
$race = file_get_contents($root . '/tests/phase-2a2j-concurrency-runner.sh') . file_get_contents($root . '/tests/phase-2a2j-concurrency-setup.php') . file_get_contents($root . '/tests/phase-2a2j-concurrency-worker.php') . file_get_contents($root . '/tests/phase-2a2j-concurrency-wait.php') . file_get_contents($root . '/tests/phase-2a2j-concurrency-verify.php');

foreach (array('016_teacher_assignment_foundation', 'teacher_assignments', 'teacher_assignment_lifecycle_events', 'teacher_assignment_commands', 'verify_teacher_assignment_schema', 'dzn_manage_teacher_assignments') as $needle) {
    if (!str_contains($plugin . $migration, $needle)) throw new RuntimeException('Missing Phase J identity/schema/capability: ' . $needle);
}
if (!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema) || (int) $schema[1] < 16) throw new RuntimeException('Missing compatible Phase J+ schema identity');
if (!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/", $plugin)) throw new RuntimeException('Missing compatible Phase J+ build identity');
foreach (array('assignInitial', 'replace', 'end', 'cancel', 'initial_final_arrangement', 'replacement_agreement', 'retained_final_arrangement_and_assent', 'staff_attested_teacher_agreement', 'authenticated_teacher_acceptance') as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Missing Teacher Assignment lifecycle/evidence behavior: ' . $needle);
}
foreach (array("array('authorised', 'current', 'paused')", 'enrolment_not_applicable', 'already_assigned', 'assignment_missing', 'teacher_not_current', 'initial_assignment_already_recorded', 'source_integrity_conflict', 'data_integrity_conflict') as $needle) {
    if (!str_contains($assessment . $readiness, $needle)) throw new RuntimeException('Missing Teacher Assignment readiness invariant: ' . $needle);
}
foreach (array('enrolment_applicable', 'enrolment_id,applicable_slot', 'predecessor_assignment_id', 'teacher_applicable', 'assignment_sequence', 'ENGINE=InnoDB') as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Missing Teacher Assignment database invariant: ' . $needle);
}
foreach (array('lockTeachers', 'sort($ids, SORT_NUMERIC)', 'assignmentsForEnrolment', 'expected_assignment_id', 'FOR UPDATE', 'dzn_phase_2a2j_after_predecessor_terminated', 'dzn_phase_2a2j_after_replacement_insert', 'dzn_phase_2a2j_after_command_insert') as $needle) {
    if (!str_contains($repository . $service, $needle)) throw new RuntimeException('Missing Teacher Assignment locking/atomicity invariant: ' . $needle);
}
if (!str_contains($service, 'assignment_changed')) throw new RuntimeException('Missing stale Assignment replacement arbitration');
foreach (array("wp_salt('dzn_teacher_assignment')", "wp_salt('dzn_teacher_assignment_evidence')", 'command_key_digest', 'command_payload_digest', 'IdempotencyConflictException', 'already_applied') as $needle) {
    if (!str_contains($idempotency . $service . $migration, $needle)) throw new RuntimeException('Missing Teacher Assignment idempotency invariant: ' . $needle);
}
foreach (array('hasApplicableTeacherAssignments', 'applicable Teacher Assignment exists') as $needle) {
    if (!str_contains($teachers . $archive, $needle)) throw new RuntimeException('Missing Teacher offboarding protection: ' . $needle);
}
foreach (array('assignment_uid', 'enrolment_id', 'teacher_id', 'assignment_sequence', 'state', 'assigned_at') as $needle) {
    if (!str_contains($read, $needle)) throw new RuntimeException('Missing stable privacy-minimised read field: ' . $needle);
}
foreach (array("'email' =>", "'phone' =>", "'full_name' =>", "'display_name' =>", "'persian_name' =>", "'english_name' =>", "'address' =>", 'contact_snapshot', 'createTerm', 'createLesson', 'payment_authority', 'notification_authority', 'calendar_authority', 'amelia_') as $prohibited) {
    if (stripos($service . $read . $repository, $prohibited) !== false) throw new RuntimeException('Teacher Assignment boundary contains prohibited data/authority: ' . $prohibited);
}
$commandSchema = substr($migration, strpos($migration, 'CREATE TABLE {$p}teacher_assignment_commands'));
$commandSchema = substr($commandSchema, 0, strpos($commandSchema, 'private static function private_digest'));
foreach (array('raw_key', 'updated_at', 'evidence_reference varchar', 'email', 'phone', 'full_name') as $prohibited) {
    if (stripos($commandSchema, $prohibited) !== false) throw new RuntimeException('Teacher Assignment command evidence is mutable or reconstructive: ' . $prohibited);
}
if (str_contains($service, "update(\$this->prefix . 'enrolments'") || str_contains($repository, 'UPDATE {$this->prefix}enrolments')) throw new RuntimeException('Teacher Assignment mutates Enrolment lifecycle');
foreach (array('schema_15_to_16=pass', 'zero_assignment_valid=pass', 'historical_assent_continuity=pass', 'replacement_authenticated_teacher=pass', 'database_uniqueness=pass', 'offboarding_guard=pass', 'no_enrolment_mutation=pass') as $needle) if (!str_contains($runtime . $migrationRuntime, $needle)) throw new RuntimeException('Missing Phase J runtime evidence: ' . $needle);
foreach (array('performance_schema.data_lock_waits', 'expected_aggregate', 'canonical Enrolment', 'outcome=assignment_changed', 'outcome=already_applied', 'race=', 'verifier=pass') as $needle) if (!str_contains($race, $needle)) throw new RuntimeException('Missing Phase J process-concurrency evidence: ' . $needle);
foreach (array('PrincipalInvitationService', 'offboard(', 'Teacher PRIMARY', 'teacher_status=active', 'onboarding=offboarded', 'principal_link=revoked', 'o1_initial_offboard','o2_offboard_initial','o3_staff_replace_offboard','o4_offboard_staff_replace','o5_authenticated_replace_offboard','o6_offboard_authenticated_replace') as $needle) if (!str_contains($race . $principal, $needle)) throw new RuntimeException('Missing J-5 principal offboarding concurrency evidence: ' . $needle);
foreach (array('duplicateConstraint', 'completeResult', 'matchingEvidence', 'terminal_duplicate_false_success_closed=pass') as $needle) if (!str_contains($service . $repository . $failureRuntime, $needle)) throw new RuntimeException('Missing fail-closed duplicate arbitration correction: ' . $needle);
if (str_contains($repository, "str_contains(strtolower(\$e->getMessage() . ' ' . \$wpdb->last_error), 'duplicate')")) throw new RuntimeException('Broad duplicate-text recovery remains');
foreach (array('after_initial_assignment_insert', 'after_initial_event_insert', 'after_predecessor_mutation', 'after_predecessor_lifecycle_event_insert', 'after_successor_assignment_insert', 'after_successor_lifecycle_event_insert', 'after_terminal_mutation', 'after_terminal_event_insert', 'after_command_insert') as $needle) if (!str_contains($service . $failureRuntime, $needle)) throw new RuntimeException('Missing write-boundary failure proof: ' . $needle);
foreach (array('command_corruption=pass', 'assignment_corruption=pass', 'lifecycle_corruption=pass', 'provenance_corruption=pass', 'read_replay_mutation_fail_closed=pass') as $needle) if (!str_contains($corruptionRuntime, $needle)) throw new RuntimeException('Missing corruption runtime proof: ' . $needle);
foreach (array('a_same_teacher_keys','c_replace_end','d1_replace_diff','d2_replace_same','e_initial_archive','e_replace_archive','e_archive_initial','e_archive_replace','f_initial_close','f_replace_close','f_close_initial','f_close_replace','o1_initial_offboard','o2_offboard_initial','o3_staff_replace_offboard','o4_offboard_staff_replace','o5_authenticated_replace_offboard','o6_offboard_authenticated_replace','u1_unrelated_roots','u2_shared_teacher','competing_initial_teacher=not_applicable_exact_source_only') as $needle) if (!str_contains($race, $needle)) throw new RuntimeException('Missing Phase J concurrency matrix scenario: ' . $needle);
echo "Phase 2A.2-J source contract passed\n";
