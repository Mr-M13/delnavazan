<?php
/** Phase 2A.2-K canonical Term foundation static contract. */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/TermRepository.php');
$legacy = file_get_contents($root . '/src/Core/Application/TermService.php');
$assessment = file_get_contents($root . '/src/Core/Application/TermApplicabilityAssessment.php');
$read = file_get_contents($root . '/src/Core/Application/CanonicalTermReadService.php');
$archive = file_get_contents($root . '/src/Core/Application/ArchiveService.php');
$screen = file_get_contents($root . '/src/Admin/Controller/ScreenController.php');
$lesson = file_get_contents($root . '/src/Core/Application/LessonService.php');
$runtime = file_get_contents($root . '/tests/phase-2a2k-migration-runtime.php');

if (!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema) || (int) $schema[1] < 17) throw new RuntimeException('Missing compatible Phase K+ schema');
if (!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/", $plugin)) throw new RuntimeException('Missing compatible Phase K+ build identity');
if (!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/", $plugin)) throw new RuntimeException('Missing compatible Phase K+ build');
foreach (array( '017_canonical_term_foundation', 'install_canonical_term_foundation', 'verify_canonical_term_schema') as $needle) {
    if (!str_contains($plugin . $migration, $needle)) throw new RuntimeException('Missing Phase K identity/migration: ' . $needle);
}
foreach (array('record_model', 'legacy_phase1', 'canonical_enrolment_term_v1', 'lifecycle_state', 'applicable_slot', 'term_lifecycle_events', 'evidence_reference_digest', 'ENGINE=InnoDB') as $needle) {
    if (!str_contains($migration . $assessment, $needle)) throw new RuntimeException('Missing canonical Term foundation: ' . $needle);
}
foreach (array('enrolment_sequence', 'enrolment_id,sequence_number', 'enrolment_applicable', 'enrolment_id,applicable_slot', 'term_sequence', 'term_id,event_sequence') as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Missing canonical Term database arbitration: ' . $needle);
}
$eventSchema = substr($migration, strpos($migration, 'CREATE TABLE {$p}term_lifecycle_events'));
$eventSchema = substr($eventSchema, 0, strpos($eventSchema, 'private static function private_digest'));
foreach (array('updated_at', 'raw_key', 'email', 'phone', 'full_name', 'teacher_id') as $prohibited) if (stripos($eventSchema, $prohibited) !== false) throw new RuntimeException('Term evidence stores prohibited/mutable data: ' . $prohibited);
foreach (array('authorised', 'current', 'closed', 'cancelled') as $state) if (!str_contains($assessment, "'{$state}'")) throw new RuntimeException('Missing canonical Term lifecycle state: ' . $state);
foreach (array('none', 'canonical_applicable', 'canonical_terminal_history', 'legacy_review_required', 'data_integrity_conflict') as $classification) if (!str_contains($assessment, "'{$classification}'")) throw new RuntimeException('Missing Term integrity classification: ' . $classification);
foreach (array('validCanonicalEnrolment', 'canonicalEnrolmentIsApplicable', 'accepted_service_arrangement_id', 'validHistory', 'validTransition', "(int) \$row->lesson_allocation !== 12", "(int) \$row->replacement_allowance !== 2", "\$row->payment_state !== 'not_applicable'", "\$row->starts_at !== null") as $needle) if (!str_contains($assessment, $needle)) throw new RuntimeException('Missing canonical Term integrity rule: ' . $needle);
foreach (array('Generic Term repository insertion is disabled', 'insertLegacyBootstrap', 'Legacy Term record model required', 'Canonical Term fields are prohibited', 'requireLegacyMutationTarget', 'Legacy Term archive/restore target required') as $needle) if (!str_contains($repository, $needle)) throw new RuntimeException('Missing Term repository boundary: ' . $needle);
if (!str_contains($legacy, "record_model'] = 'legacy_phase1'") || !str_contains($legacy, 'canonical Enrolment grants no Term authority')) throw new RuntimeException('Legacy Term creation compatibility changed');
foreach (array('Canonical Term lifecycle is not mutable through legacy archive', 'Canonical Term lifecycle is not mutable through legacy restore') as $needle) if (!str_contains($archive, $needle)) throw new RuntimeException('Missing canonical Term archive guard');
foreach (array('term_id', 'term_uid', 'reference_code', 'enrolment_id', 'sequence_number', 'lifecycle_state', 'lesson_allocation', 'replacement_allowance', 'created_at', 'classification') as $needle) if (!str_contains($read, "'{$needle}'")) throw new RuntimeException('Missing privacy-minimised Term read field: ' . $needle);
if (!str_contains($read, 'Canonical Term integrity conflict')) throw new RuntimeException('History read can bypass canonical aggregate integrity');
foreach (array('payment_state', 'teacher_id', 'email', 'phone', 'starts_at', 'ends_at') as $prohibited) if (str_contains($read, "'{$prohibited}'")) throw new RuntimeException('Canonical Term read exposes prohibited authority: ' . $prohibited);
if (!str_contains($assessment . $read, "current_user_can('dzn_manage_terms')")) throw new RuntimeException('Canonical Term reads are not capability protected');
foreach (array('command_key_digest', 'command_payload_digest', 'IdempotencyConflictException', 'START TRANSACTION', 'FOR UPDATE', 'TeacherAssignmentService', 'LessonService', 'register_rest_route') as $prohibited) if (stripos($assessment . $read, $prohibited) !== false) throw new RuntimeException('Phase K exceeded read-only foundation boundary: ' . $prohibited);
if (!str_contains($lesson, 'Canonical Enrolment grants no Lesson or Teacher Assignment authority')) throw new RuntimeException('Canonical mutation/downstream boundary reopened');
foreach (array('schema_16_to_17=pass', 'legacy_term_preservation=pass', 'zero_term_classification=pass', 'canonical_applicability=pass', 'closed_enrolment_applicability_guard=pass', 'canonical_terminal_history=pass', 'database_applicability_uniqueness=pass', 'history_integrity=pass', 'canonical_write_boundary=pass', 'canonical_archive_restore_boundary=pass', 'privacy_minimised_read=pass', 'no_teacher_payment_authority=pass', 'repeat_upgrade=pass') as $needle) if (!str_contains($runtime, $needle)) throw new RuntimeException('Missing Phase K runtime evidence declaration: ' . $needle);
echo "Phase 2A.2-K source contract passed\n";
