<?php
/** Disposable Schema 16 -> 17 migration and canonical Term foundation proof. */
if (getenv('DZN_PHASE_2A2K_RUNTIME_TEST') !== 'migration' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-K migration runtime refused.\n"); exit(1);
}
if ((string) DZN_PLATFORM_SCHEMA_VERSION !== '17' || DZN_PLATFORM_BUILD_ID !== 'phase2a2k-canonical-term-foundation-20260916.1') throw new RuntimeException('Exact Phase K candidate required');

use Delnavazan\Platform\Core\Application\{ArchiveService,CanonicalTermReadService,CatalogueService,LessonService,StudentService,TeacherService,TermApplicabilityAssessment,TermService};
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Infrastructure\Repository\{EnrolmentRepository,TermRepository};
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_2a2k_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_2a2k_index(string $table, string $name, bool $unique, array $columns): bool {
    global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $name));
    if (!$rows || ($unique && (int) $rows[0]->Non_unique !== 0)) return false;
    usort($rows, static fn($a, $b) => (int) $a->Seq_in_index <=> (int) $b->Seq_in_index);
    return array_map(static fn($row) => (string) $row->Column_name, $rows) === $columns;
}
function dzn_2a2k_duplicate(callable $operation): bool {
    global $wpdb; $previous = $wpdb->suppress_errors(true); $result = $operation(); $error = strtolower((string) $wpdb->last_error); $wpdb->suppress_errors($previous);
    return $result === false && str_contains($error, 'duplicate');
}
function dzn_2a2k_legacy_enrolment(int $student, int $teacher, int $course, string $label): int {
    $now = gmdate('Y-m-d H:i:s');
    return (new EnrolmentRepository())->insertLegacyBootstrap(array('uid' => Identifier::uid(), 'reference_code' => null, 'student_id' => $student, 'teacher_id' => $teacher, 'course_id' => $course, 'status' => 'active', 'record_model' => 'legacy_phase1', 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id()));
}
function dzn_2a2k_canonical_enrolment(int $student, int $course, int $source): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    dzn_2a2k_assert($wpdb->insert($p . 'enrolments', array('uid' => Identifier::uid(), 'reference_code' => null, 'student_id' => $student, 'teacher_id' => null, 'course_id' => $course, 'status' => 'canonical', 'record_model' => 'canonical_student_course_v1', 'accepted_service_arrangement_id' => $source, 'lifecycle_state' => 'authorised', 'applicable_slot' => 1, 'predecessor_enrolment_id' => null, 'lineage_meaning' => null, 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id())) === 1, 'Canonical Enrolment fixture failed: ' . $wpdb->last_error);
    return (int) $wpdb->insert_id;
}
function dzn_2a2k_term(int $enrolment, int $sequence, string $state, ?int $slot): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s'); $reference = sprintf('DZN-TRM-9%06d%03d', $enrolment, $sequence);
    dzn_2a2k_assert($wpdb->insert($p . 'terms', array('uid' => Identifier::uid(), 'reference_code' => $reference, 'enrolment_id' => $enrolment, 'sequence_number' => $sequence, 'status' => 'canonical', 'lesson_allocation' => 12, 'replacement_allowance' => 2, 'starts_at' => null, 'ends_at' => null, 'activated_at' => null, 'completed_at' => null, 'payment_state' => 'not_applicable', 'record_model' => 'canonical_enrolment_term_v1', 'lifecycle_state' => $state, 'applicable_slot' => $slot, 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id())) === 1, 'Canonical Term fixture failed: ' . $wpdb->last_error);
    return (int) $wpdb->insert_id;
}
function dzn_2a2k_event(int $term, int $sequence, ?string $from, string $to): void {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    dzn_2a2k_assert($wpdb->insert($p . 'term_lifecycle_events', array('uid' => Identifier::uid(), 'term_id' => $term, 'event_sequence' => $sequence, 'from_state' => $from, 'to_state' => $to, 'reason_code' => $sequence === 1 ? 'foundation_origin' : 'synthetic_transition', 'evidence_channel' => 'runtime_test', 'evidence_reference_digest' => hash('sha256', "term:{$term}:{$sequence}:{$to}"), 'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => get_current_user_id(), 'created_at' => $now, 'created_by' => get_current_user_id())) === 1, 'Term lifecycle fixture failed: ' . $wpdb->last_error);
}

$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);
$teacher = (new TeacherService())->create(array('display_name' => 'Synthetic K Legacy Teacher', 'email' => "teacher-{$suffix}@phase-2a2k.invalid"));
$student = (new StudentService())->create(array('display_name' => 'Synthetic K Legacy Student', 'email' => "student-{$suffix}@phase-2a2k.invalid", 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$catalogue = new CatalogueService();
$instrument = $catalogue->instrument(array('slug' => "phase-2a2k-{$suffix}", 'name_fa' => 'Synthetic', 'name_en' => 'Synthetic K Instrument', 'status' => 'active'));
$course = $catalogue->course(array('instrument_id' => $instrument, 'name_fa' => 'Synthetic', 'name_en' => 'Synthetic K Course', 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$legacyEnrolment = dzn_2a2k_legacy_enrolment($student, $teacher, $course, 'base');
$legacyTerm = (new TermService())->create(array('enrolment_id' => $legacyEnrolment, 'sequence_number' => 1, 'status' => 'active', 'lesson_allocation' => 9, 'replacement_allowance' => 1, 'payment_state' => 'paid'));
$legacyBefore = (array) $wpdb->get_row($wpdb->prepare("SELECT status,lesson_allocation,replacement_allowance,payment_state,archived_at FROM {$p}terms WHERE id=%d", $legacyTerm), ARRAY_A);

// Reconstruct the exact Schema 16 database shape while preserving a representative legacy row.
$wpdb->query("DROP TABLE IF EXISTS {$p}term_lifecycle_events");
foreach (array('enrolment_applicable', 'record_model', 'lifecycle_state') as $index) if ($wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$p}terms WHERE Key_name=%s", $index))) $wpdb->query("ALTER TABLE {$p}terms DROP INDEX {$index}");
foreach (array('applicable_slot', 'lifecycle_state', 'record_model') as $column) if ($wpdb->get_row("SHOW COLUMNS FROM {$p}terms LIKE '{$column}'")) $wpdb->query("ALTER TABLE {$p}terms DROP COLUMN {$column}");
$done = array_values(array_filter((array) get_option('dzn_platform_completed_migrations', array()), static fn($id) => $id !== '017_canonical_term_foundation'));
update_option('dzn_platform_completed_migrations', $done, false); update_option('dzn_platform_schema_version', '16', false); update_option('dzn_platform_capability_version', '2a2j', false);
$admin = get_role('administrator'); dzn_2a2k_assert($admin !== null, 'Administrator role missing'); $admin->remove_cap('dzn_manage_terms');

Migrator::maybe_upgrade();
dzn_2a2k_assert((string) get_option('dzn_platform_schema_version') === '17', 'Schema 16 -> 17 failed');
$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_2a2k_assert(count(array_keys($done, '017_canonical_term_foundation', true)) === 1, 'Migration 017 ledger invalid');
dzn_2a2k_assert($admin->has_cap('dzn_manage_terms') && (string) get_option('dzn_platform_capability_version') === '2a2k', 'Term capability repair failed');
$model = $wpdb->get_row("SHOW COLUMNS FROM {$p}terms LIKE 'record_model'");
dzn_2a2k_assert($model && $model->Null === 'NO' && $model->Default === 'legacy_phase1', 'Term record-model default invalid');
foreach (array('record_model', 'lifecycle_state', 'applicable_slot') as $column) dzn_2a2k_assert((bool) $wpdb->get_row("SHOW COLUMNS FROM {$p}terms LIKE '{$column}'"), 'Missing terms.' . $column);
dzn_2a2k_assert(dzn_2a2k_index($p . 'terms', 'enrolment_sequence', true, array('enrolment_id', 'sequence_number')), 'Term sequence identity invalid');
dzn_2a2k_assert(dzn_2a2k_index($p . 'terms', 'enrolment_applicable', true, array('enrolment_id', 'applicable_slot')), 'Term applicability index invalid');
dzn_2a2k_assert(dzn_2a2k_index($p . 'term_lifecycle_events', 'term_sequence', true, array('term_id', 'event_sequence')), 'Term history sequence invalid');
dzn_2a2k_assert(strcasecmp((string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p . 'term_lifecycle_events')), 'InnoDB') === 0, 'Term history is not InnoDB');
dzn_2a2k_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}term_lifecycle_events LIKE 'updated_at'") && !$wpdb->get_row("SHOW COLUMNS FROM {$p}terms LIKE 'teacher_id'"), 'Mutable history or Term Teacher authority found');
$legacyAfter = (array) $wpdb->get_row($wpdb->prepare("SELECT status,lesson_allocation,replacement_allowance,payment_state,archived_at FROM {$p}terms WHERE id=%d", $legacyTerm), ARRAY_A);
dzn_2a2k_assert($legacyBefore === $legacyAfter && $wpdb->get_var($wpdb->prepare("SELECT record_model FROM {$p}terms WHERE id=%d", $legacyTerm)) === 'legacy_phase1', 'Legacy Term changed during migration');
dzn_2a2k_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}terms WHERE record_model='canonical_enrolment_term_v1'") === 0, 'Migration backfilled canonical Terms');

$legacySecond = (new TermService())->create(array('enrolment_id' => $legacyEnrolment, 'sequence_number' => 2));
(new ArchiveService())->archive('term', $legacySecond); (new ArchiveService())->restore('term', $legacySecond);
$lesson = (new LessonService())->create(array('student_id' => $student, 'teacher_id' => $teacher, 'course_id' => $course, 'enrolment_id' => $legacyEnrolment, 'term_id' => $legacySecond, 'lesson_type' => 'standard', 'status' => 'draft'));
dzn_2a2k_assert($lesson > 0 && (new TermRepository())->usable($legacySecond) !== null, 'Legacy Term/Lesson compatibility failed');

$studentCanonical = (new StudentService())->create(array('display_name' => 'Synthetic K Canonical Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$canonicalEnrolment = dzn_2a2k_canonical_enrolment($studentCanonical, $course, 810001);
$term = dzn_2a2k_term($canonicalEnrolment, 1, 'authorised', 1); dzn_2a2k_event($term, 1, null, 'authorised');
$assessment = new TermApplicabilityAssessment(); $read = new CanonicalTermReadService();
dzn_2a2k_assert($assessment->inspect($canonicalEnrolment)['classification'] === 'canonical_applicable', 'Applicable canonical Term classification failed');
$zeroStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Zero Term Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$zeroEnrolment = dzn_2a2k_canonical_enrolment($zeroStudent, $course, 810006);
dzn_2a2k_assert($assessment->inspect($zeroEnrolment)['classification'] === 'none', 'Zero-Term classification failed');
$view = $read->forEnrolment($canonicalEnrolment);
dzn_2a2k_assert($view['classification'] === 'canonical_applicable' && count($view['terms']) === 1 && count($view['terms'][0]) === 9 && !array_key_exists('payment_state', $view['terms'][0]) && !array_key_exists('teacher_id', $view['terms'][0]), 'Privacy-minimised canonical read failed');
dzn_2a2k_assert(count($read->history($term)) === 1 && !array_key_exists('evidence_reference_digest', $read->history($term)[0]), 'Privacy-minimised history read failed');
dzn_2a2k_assert(dzn_2a2k_duplicate(static fn() => $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->prefix . 'dzn_terms', array('uid' => Identifier::uid(), 'reference_code' => 'DZN-TRM-999998', 'enrolment_id' => $canonicalEnrolment, 'sequence_number' => 2, 'status' => 'canonical', 'lesson_allocation' => 12, 'replacement_allowance' => 2, 'payment_state' => 'not_applicable', 'record_model' => 'canonical_enrolment_term_v1', 'lifecycle_state' => 'current', 'applicable_slot' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')))), 'Database accepted second applicable canonical Term');
$wpdb->query("ALTER TABLE {$p}terms DROP INDEX enrolment_applicable");
$duplicateApplicable = dzn_2a2k_term($canonicalEnrolment, 2, 'current', 1); dzn_2a2k_event($duplicateApplicable, 1, null, 'authorised'); dzn_2a2k_event($duplicateApplicable, 2, 'authorised', 'current');
dzn_2a2k_assert($assessment->inspect($canonicalEnrolment)['classification'] === 'data_integrity_conflict', 'Malformed duplicate applicability was silently selected');
$wpdb->delete($p . 'term_lifecycle_events', array('term_id' => $duplicateApplicable)); $wpdb->delete($p . 'terms', array('id' => $duplicateApplicable));
dzn_2a2k_assert($wpdb->query("ALTER TABLE {$p}terms ADD UNIQUE KEY enrolment_applicable(enrolment_id,applicable_slot)") !== false, 'Applicability index restoration failed');

$terminalStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Terminal Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$terminalEnrolment = dzn_2a2k_canonical_enrolment($terminalStudent, $course, 810002);
$terminal = dzn_2a2k_term($terminalEnrolment, 1, 'closed', null); dzn_2a2k_event($terminal, 1, null, 'authorised'); dzn_2a2k_event($terminal, 2, 'authorised', 'current'); dzn_2a2k_event($terminal, 3, 'current', 'closed');
dzn_2a2k_assert($wpdb->update($p . 'enrolments', array('lifecycle_state' => 'closed', 'applicable_slot' => null), array('id' => $terminalEnrolment)) === 1, 'Closed terminal-history Enrolment fixture preparation failed');
dzn_2a2k_assert($assessment->inspect($terminalEnrolment)['classification'] === 'canonical_terminal_history', 'Terminal canonical history classification failed');

$closedAuthorisedStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Closed Authorised Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$closedAuthorisedEnrolment = dzn_2a2k_canonical_enrolment($closedAuthorisedStudent, $course, 810007);
$closedAuthorisedTerm = dzn_2a2k_term($closedAuthorisedEnrolment, 1, 'authorised', 1); dzn_2a2k_event($closedAuthorisedTerm, 1, null, 'authorised');
dzn_2a2k_assert($wpdb->update($p . 'enrolments', array('lifecycle_state' => 'closed', 'applicable_slot' => null), array('id' => $closedAuthorisedEnrolment)) === 1, 'Closed/authorised Enrolment fixture preparation failed');
dzn_2a2k_assert($assessment->inspect($closedAuthorisedEnrolment)['classification'] === 'data_integrity_conflict', 'Closed Enrolment exposed authorised Term authority');

$closedCurrentStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Closed Current Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$closedCurrentEnrolment = dzn_2a2k_canonical_enrolment($closedCurrentStudent, $course, 810008);
$closedCurrentTerm = dzn_2a2k_term($closedCurrentEnrolment, 1, 'current', 1); dzn_2a2k_event($closedCurrentTerm, 1, null, 'authorised'); dzn_2a2k_event($closedCurrentTerm, 2, 'authorised', 'current');
dzn_2a2k_assert($wpdb->update($p . 'enrolments', array('lifecycle_state' => 'closed', 'applicable_slot' => null), array('id' => $closedCurrentEnrolment)) === 1, 'Closed/current Enrolment fixture preparation failed');
dzn_2a2k_assert($assessment->inspect($closedCurrentEnrolment)['classification'] === 'data_integrity_conflict', 'Closed Enrolment exposed current Term authority');

$mixedStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Mixed Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$mixedEnrolment = dzn_2a2k_canonical_enrolment($mixedStudent, $course, 810003);
$mixedCanonical = dzn_2a2k_term($mixedEnrolment, 1, 'authorised', 1); dzn_2a2k_event($mixedCanonical, 1, null, 'authorised');
dzn_2a2k_assert($wpdb->insert($p . 'terms', array('uid' => Identifier::uid(), 'reference_code' => null, 'enrolment_id' => $mixedEnrolment, 'sequence_number' => 2, 'status' => 'draft', 'lesson_allocation' => 12, 'replacement_allowance' => 2, 'payment_state' => 'not_required', 'record_model' => 'legacy_phase1', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'))) === 1, 'Mixed fixture insert failed');
dzn_2a2k_assert($assessment->inspect($mixedEnrolment)['classification'] === 'data_integrity_conflict', 'Mixed legacy/canonical state was selected');

$missingStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Missing History Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$missingEnrolment = dzn_2a2k_canonical_enrolment($missingStudent, $course, 810004); $missingTerm = dzn_2a2k_term($missingEnrolment, 1, 'authorised', 1);
dzn_2a2k_assert($assessment->inspect($missingEnrolment)['classification'] === 'data_integrity_conflict', 'Missing history was accepted');
$missingHistoryRejected = false; try { $read->history($missingTerm); } catch (RuntimeException $e) { $missingHistoryRejected = $e->getMessage() === 'Canonical Term integrity conflict'; }
dzn_2a2k_assert($missingHistoryRejected, 'History read bypassed aggregate integrity');

$invalidEnrolmentStudent = (new StudentService())->create(array('display_name' => 'Synthetic K Invalid Enrolment Student', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected'));
$invalidEnrolment = dzn_2a2k_canonical_enrolment($invalidEnrolmentStudent, $course, 810005);
$invalidEnrolmentTerm = dzn_2a2k_term($invalidEnrolment, 1, 'authorised', 1); dzn_2a2k_event($invalidEnrolmentTerm, 1, null, 'authorised');
dzn_2a2k_assert($wpdb->update($p . 'enrolments', array('lifecycle_state' => 'closed', 'applicable_slot' => 1), array('id' => $invalidEnrolment)) === 1, 'Invalid Enrolment fixture preparation failed');
dzn_2a2k_assert($assessment->inspect($invalidEnrolment)['classification'] === 'data_integrity_conflict', 'Invalid canonical Enrolment lifecycle was accepted');

$wrongCanonical = dzn_2a2k_term($legacyEnrolment, 3, 'closed', null); dzn_2a2k_event($wrongCanonical, 1, null, 'authorised'); dzn_2a2k_event($wrongCanonical, 2, 'authorised', 'cancelled');
dzn_2a2k_assert($assessment->inspect($legacyEnrolment)['classification'] === 'data_integrity_conflict', 'Canonical Term accepted legacy Enrolment');

$repository = new TermRepository();
$genericRejected = false; try { $repository->insert(array('record_model' => 'canonical_enrolment_term_v1')); } catch (RuntimeException $e) { $genericRejected = $e->getMessage() === 'Generic Term repository insertion is disabled'; }
$legacyInjectionRejected = false; try { $repository->insertLegacyBootstrap(array('record_model' => 'canonical_enrolment_term_v1')); } catch (InvalidArgumentException $e) { $legacyInjectionRejected = $e->getMessage() === 'Legacy Term record model required'; }
$legacyServiceRejected = false; try { (new TermService())->create(array('enrolment_id' => $canonicalEnrolment, 'sequence_number' => 2)); } catch (InvalidArgumentException $e) { $legacyServiceRejected = str_contains($e->getMessage(), 'canonical Enrolment grants no Term authority'); }
dzn_2a2k_assert($genericRejected && $legacyInjectionRejected && $legacyServiceRejected, 'Canonical Term write boundary failed');
$archiveRejected = false; try { (new ArchiveService())->archive('term', $term); } catch (InvalidArgumentException $e) { $archiveRejected = str_contains($e->getMessage(), 'Canonical Term lifecycle is not mutable'); }
$directArchiveRejected = false; try { $repository->archive($term, gmdate('Y-m-d H:i:s'), get_current_user_id()); } catch (InvalidArgumentException $e) { $directArchiveRejected = $e->getMessage() === 'Legacy Term archive/restore target required'; }
dzn_2a2k_assert($archiveRejected && $directArchiveRejected, 'Canonical Term archive boundary failed');
dzn_2a2k_assert($wpdb->update($p . 'terms', array('status' => 'archived', 'archived_at' => gmdate('Y-m-d H:i:s')), array('id' => $term)) === 1, 'Restore fixture preparation failed');
$restoreRejected = false; try { (new ArchiveService())->restore('term', $term); } catch (InvalidArgumentException $e) { $restoreRejected = str_contains($e->getMessage(), 'Canonical Term lifecycle is not mutable'); }
$directRestoreRejected = false; try { $repository->restore($term, 'canonical', gmdate('Y-m-d H:i:s'), get_current_user_id()); } catch (InvalidArgumentException $e) { $directRestoreRejected = $e->getMessage() === 'Legacy Term archive/restore target required'; }
dzn_2a2k_assert($restoreRejected && $directRestoreRejected, 'Canonical Term restore boundary failed');

$actor = get_current_user_id(); wp_set_current_user(0); $unauthorized = false; try { $read->forEnrolment($canonicalEnrolment); } catch (RuntimeException $e) { $unauthorized = $e->getMessage() === 'Unauthorized'; } finally { wp_set_current_user($actor); }
dzn_2a2k_assert($unauthorized, 'Canonical Term read was not capability protected');

$before = array('terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}terms"), 'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}term_lifecycle_events"), 'legacy' => $legacyAfter);
Migrator::maybe_upgrade();
$after = array('terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}terms"), 'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}term_lifecycle_events"), 'legacy' => (array) $wpdb->get_row($wpdb->prepare("SELECT status,lesson_allocation,replacement_allowance,payment_state,archived_at FROM {$p}terms WHERE id=%d", $legacyTerm), ARRAY_A));
dzn_2a2k_assert($before === $after && count(array_keys((array) get_option('dzn_platform_completed_migrations', array()), '017_canonical_term_foundation', true)) === 1, 'Repeated upgrade changed state');

echo "schema_16_to_17=pass\nlegacy_term_preservation=pass\nlegacy_term_lesson_compatibility=pass\nzero_term_classification=pass\ncanonical_applicability=pass\nclosed_enrolment_applicability_guard=pass\ncanonical_terminal_history=pass\ndatabase_applicability_uniqueness=pass\nhistory_integrity=pass\ncanonical_enrolment_relationship=pass\ncanonical_write_boundary=pass\ncanonical_archive_restore_boundary=pass\nprivacy_minimised_read=pass\nno_teacher_payment_authority=pass\ncapability_install_repair=pass\nrepeat_upgrade=pass\nPhase 2A.2-K migration runtime passed\n";
