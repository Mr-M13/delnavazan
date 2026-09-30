<?php
/** Disposable Schema 13 -> 14 migration, classification and uniqueness proof. */
if (getenv('DZN_PHASE_2A2H_RUNTIME_TEST') !== 'migration' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    fwrite(STDERR, "Phase 2A.2-H migration runtime refused.\n"); exit(1);
}
if ((string) DZN_PLATFORM_SCHEMA_VERSION !== '14' || DZN_PLATFORM_BUILD_ID !== 'phase2a2h-canonical-enrolment-foundation-20260913.1') throw new RuntimeException('Exact Phase H candidate required');

use Delnavazan\Platform\Core\Application\{ArchiveService, CatalogueService, EnrolmentApplicabilityService, EnrolmentService, LessonService, StudentService, TermService};
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentRepository;

function dzn_2a2h_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $fixture = get_option('dzn_phase_2a2h_schema13_fixture');
dzn_2a2h_assert(is_array($fixture), 'Schema 13 fixture missing');
$legacy = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}enrolments WHERE id=%d", $fixture['enrolment_id']));
dzn_2a2h_assert($legacy && $legacy->record_model === 'legacy_phase1', 'Legacy record model not preserved');
dzn_2a2h_assert($legacy->status === $fixture['status'], 'Legacy status changed');
dzn_2a2h_assert((int) $legacy->teacher_id === (int) $fixture['teacher_id'], 'Historical legacy Teacher changed');
dzn_2a2h_assert($legacy->lifecycle_state === null && $legacy->applicable_slot === null && $legacy->accepted_service_arrangement_id === null, 'Legacy row contaminated by canonical fields');

$done = (array) get_option('dzn_platform_completed_migrations', []);
dzn_2a2h_assert((string) get_option('dzn_platform_schema_version') === '14' && count(array_keys($done, '014_canonical_enrolment_foundation', true)) === 1, 'Schema 14 migration ledger invalid');
$teacherColumn = $wpdb->get_row("SHOW COLUMNS FROM {$p}enrolments LIKE 'teacher_id'");
dzn_2a2h_assert($teacherColumn && $teacherColumn->Null === 'YES', 'Canonical Teacher context is not nullable');

$catalogue = new CatalogueService();
function dzn_2a2h_student(string $label): int { return (new StudentService())->create(['display_name' => $label, 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected']); }
function dzn_2a2h_course(int $instrumentId, string $label): int { return (new CatalogueService())->course(['instrument_id' => $instrumentId, 'name_fa' => $label, 'name_en' => $label, 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15]); }
function dzn_2a2h_arrangement(int $studentId, int $courseId, int $teacherId, int $sequence): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s'); $digest = hash('sha256', 'phase-h-' . $sequence);
    $data = ['uid' => str_pad('HARR' . $sequence, 26, '0'), 'reference_code' => null, 'booking_request_id' => 700000 + $sequence, 'coordination_case_id' => 710000 + $sequence, 'proposal_family_id' => 720000 + $sequence, 'proposal_option_id' => 730000 + $sequence, 'proposal_version_id' => 740000 + $sequence, 'provisional_acceptance_event_id' => 750000 + $sequence, 'student_id' => $studentId, 'teacher_id' => $teacherId, 'family_uid' => str_pad('HFAM' . $sequence, 26, '0'), 'option_uid' => str_pad('HOPT' . $sequence, 26, '0'), 'version_uid' => str_pad('HVER' . $sequence, 26, '0'), 'version_number' => 1, 'version_fingerprint' => hash('sha256', 'version-' . $sequence), 'prospective_subject_ref' => 'synthetic-h-' . $sequence, 'course_id' => $courseId, 'unresolved_course_spec' => null, 'delivery_mode' => 'online', 'location_scope' => 'synthetic', 'frequency_per_week' => 1, 'expected_duration_minutes' => 30, 'schedule_constraints' => 'synthetic only', 'commencement_window_start' => $now, 'commencement_window_end' => gmdate('Y-m-d H:i:s', time() + 86400), 'timezone' => 'Australia/Brisbane', 'conditions_code' => null, 'arrangement_fingerprint' => hash('sha256', 'arrangement-' . $sequence), 'identity_resolution_event_id' => 760000 + $sequence, 'identity_resolution_sequence' => 1, 'capacity_classification_id' => 770000 + $sequence, 'capacity_classification' => 'adult', 'authority_route' => 'adult_self', 'accepting_wordpress_user_id' => get_current_user_id(), 'principal_link_id' => null, 'principal_link_version' => null, 'guardian_grant_id' => null, 'guardian_grant_version' => null, 'confirmation_value' => 'affirmed', 'confirmation_channel' => 'synthetic_fixture', 'confirmation_evidence_reference' => hash('sha256', 'evidence-' . $sequence), 'confirmed_at' => $now, 'accepted_at' => $now, 'accepted_by' => get_current_user_id(), 'command_key_digest' => $digest, 'command_payload_digest' => hash('sha256', 'payload-' . $sequence), 'created_at' => $now, 'created_by' => get_current_user_id()];
    dzn_2a2h_assert($wpdb->insert($p . 'accepted_service_arrangements', $data) === 1, 'Synthetic arrangement insert failed: ' . $wpdb->last_error); return (int) $wpdb->insert_id;
}
function dzn_2a2h_enrolment(int $studentId, int $courseId, ?int $teacherId, string $state, ?int $slot, int $arrangementId, int $sequence, ?int $predecessor = null, ?string $meaning = null): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    $data = ['uid' => str_pad('HENR' . $sequence, 26, '0'), 'reference_code' => null, 'student_id' => $studentId, 'teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'canonical', 'record_model' => 'canonical_student_course_v1', 'accepted_service_arrangement_id' => $arrangementId, 'lifecycle_state' => $state, 'applicable_slot' => $slot, 'predecessor_enrolment_id' => $predecessor, 'lineage_meaning' => $meaning, 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id()];
    dzn_2a2h_assert($wpdb->insert($p . 'enrolments', $data) === 1, 'Synthetic canonical Enrolment insert failed: ' . $wpdb->last_error); return (int) $wpdb->insert_id;
}
function dzn_2a2h_legacy_bootstrap(int $studentId, int $teacherId, int $courseId, int $sequence, string $status = 'active'): int {
    $now = gmdate('Y-m-d H:i:s');
    return (new EnrolmentRepository())->insertLegacyBootstrap(['uid' => str_pad('HLGC' . $sequence, 26, '0'), 'reference_code' => null, 'student_id' => $studentId, 'teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => $status, 'record_model' => 'legacy_phase1', 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id()]);
}
function dzn_2a2h_duplicate(callable $operation): bool { global $wpdb; $wpdb->suppress_errors(true); $result = $operation(); $error = strtolower((string) $wpdb->last_error); $wpdb->suppress_errors(false); return $result === false && str_contains($error, 'duplicate'); }

$instrumentId = (int) $fixture['instrument_id']; $teacherId = (int) $fixture['teacher_id']; $classification = new EnrolmentApplicabilityService();
$noneStudent = dzn_2a2h_student('Synthetic H None'); $noneCourse = dzn_2a2h_course($instrumentId, 'Synthetic H None Course');
dzn_2a2h_assert($classification->classify($noneStudent, $noneCourse) === 'none', 'none classification failed');
dzn_2a2h_assert($classification->classify((int) $fixture['student_id'], (int) $fixture['course_id']) === 'legacy_review_required', 'legacy_review_required classification failed');

$appStudent = dzn_2a2h_student('Synthetic H Applicable'); $appCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Applicable Course'); $appArrangement = dzn_2a2h_arrangement($appStudent, $appCourse, $teacherId, 1);
$appEnrolment = dzn_2a2h_enrolment($appStudent, $appCourse, $teacherId, 'authorised', 1, $appArrangement, 1);
dzn_2a2h_assert($classification->classify($appStudent, $appCourse) === 'canonical_applicable', 'canonical_applicable classification failed');
dzn_2a2h_assert($classification->classify($appStudent, $appCourse, $appArrangement) === 'already_linked_source', 'already_linked_source classification failed');
$genericCreationRejected = false; try { (new EnrolmentService())->create([]); } catch (RuntimeException $exception) { $genericCreationRejected = $exception->getMessage() === 'Generic Enrolment creation is disabled'; }
dzn_2a2h_assert($genericCreationRejected, 'Generic Enrolment creation remained available');
$termRejected = false; try { (new TermService())->create(['enrolment_id' => $appEnrolment]); } catch (InvalidArgumentException $exception) { $termRejected = str_contains($exception->getMessage(), 'canonical Enrolment grants no Term authority'); }
dzn_2a2h_assert($termRejected, 'Canonical Enrolment granted Term authority');
$lessonRejected = false; try { (new LessonService())->create(['student_id' => $appStudent, 'teacher_id' => $teacherId, 'course_id' => $appCourse, 'lesson_type' => 'introductory', 'status' => 'draft', 'enrolment_id' => $appEnrolment]); } catch (InvalidArgumentException $exception) { $lessonRejected = str_contains($exception->getMessage(), 'Canonical Enrolment grants no Lesson or Teacher Assignment authority'); }
dzn_2a2h_assert($lessonRejected, 'Canonical Enrolment granted Lesson authority or failed for an unrelated prerequisite');
$archiveRejected = false; try { (new ArchiveService())->archive('enrolment', $appEnrolment); } catch (InvalidArgumentException $exception) { $archiveRejected = str_contains($exception->getMessage(), 'not mutable through legacy archive'); }
dzn_2a2h_assert($archiveRejected, 'Canonical Enrolment accepted legacy archive mutation');
$repository = new EnrolmentRepository();
$directArchiveRejected = false; try { $repository->archive($appEnrolment, gmdate('Y-m-d H:i:s'), get_current_user_id()); } catch (InvalidArgumentException $exception) { $directArchiveRejected = str_contains($exception->getMessage(), 'Legacy Enrolment archive/restore target required'); }
dzn_2a2h_assert($directArchiveRejected, 'Direct repository archive mutated canonical Enrolment');
$restoreStudent = dzn_2a2h_student('Synthetic H Restore Guard'); $restoreCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Restore Guard Course'); $restoreArrangement = dzn_2a2h_arrangement($restoreStudent, $restoreCourse, $teacherId, 6);
$restoreCanonical = dzn_2a2h_enrolment($restoreStudent, $restoreCourse, null, 'closed', null, $restoreArrangement, 6); $restoreAt = gmdate('Y-m-d H:i:s');
dzn_2a2h_assert($wpdb->update($p . 'enrolments', ['status' => 'archived', 'archived_at' => $restoreAt], ['id' => $restoreCanonical]) === 1, 'Canonical restore guard fixture preparation failed');
$directRestoreRejected = false; try { $repository->restore($restoreCanonical, 'canonical', gmdate('Y-m-d H:i:s'), get_current_user_id()); } catch (InvalidArgumentException $exception) { $directRestoreRejected = str_contains($exception->getMessage(), 'Legacy Enrolment archive/restore target required'); }
dzn_2a2h_assert($directRestoreRejected && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}enrolments WHERE id=%d", $restoreCanonical)) === 'archived', 'Direct repository restore mutated canonical Enrolment');

$legacyCompatibilityStudent = dzn_2a2h_student('Synthetic H Legacy Compatibility'); $legacyCompatibilityCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Legacy Compatibility Course');
$actorId = get_current_user_id(); wp_set_current_user(0); $unauthorizedLegacyRejected = false;
try { dzn_2a2h_legacy_bootstrap($legacyCompatibilityStudent, $teacherId, $legacyCompatibilityCourse, 10); } catch (RuntimeException $exception) { $unauthorizedLegacyRejected = $exception->getMessage() === 'Unauthorized'; } finally { wp_set_current_user($actorId); }
dzn_2a2h_assert($unauthorizedLegacyRejected, 'Unauthorised legacy/bootstrap repository creation was accepted');
$genericRepositoryRejected = false; try { $repository->insert(['record_model' => 'canonical_student_course_v1']); } catch (RuntimeException $exception) { $genericRepositoryRejected = $exception->getMessage() === 'Generic Enrolment repository insertion is disabled'; }
dzn_2a2h_assert($genericRepositoryRejected, 'Generic canonical repository creation was accepted');
$canonicalBootstrapRejected = false; try { $repository->insertLegacyBootstrap(['record_model' => 'canonical_student_course_v1']); } catch (InvalidArgumentException $exception) { $canonicalBootstrapRejected = $exception->getMessage() === 'Legacy Enrolment record model required'; }
dzn_2a2h_assert($canonicalBootstrapRejected, 'Legacy/bootstrap seam accepted canonical creation');
$legacyCompatibility = dzn_2a2h_legacy_bootstrap($legacyCompatibilityStudent, $teacherId, $legacyCompatibilityCourse, 10, 'paused');
dzn_2a2h_assert($classification->classify($legacyCompatibilityStudent, $legacyCompatibilityCourse) === 'legacy_review_required', 'Controlled legacy/bootstrap compatibility insert failed');
$repository->archive($legacyCompatibility, gmdate('Y-m-d H:i:s'), $actorId); $archivedLegacy = $repository->find($legacyCompatibility);
dzn_2a2h_assert($archivedLegacy && $archivedLegacy->status === 'archived' && $archivedLegacy->archived_at !== null, 'Direct legacy repository archive failed');
$repository->restore($legacyCompatibility, 'paused', gmdate('Y-m-d H:i:s'), $actorId); $restoredLegacy = $repository->find($legacyCompatibility);
dzn_2a2h_assert($restoredLegacy && $restoredLegacy->status === 'paused' && $restoredLegacy->archived_at === null && (int) $restoredLegacy->teacher_id === $teacherId, 'Direct legacy repository restore failed');

$mixedApplicableLegacy = dzn_2a2h_legacy_bootstrap($appStudent, $teacherId, $appCourse, 11);
dzn_2a2h_assert($classification->classify($appStudent, $appCourse) === 'data_integrity_conflict', 'Mixed legacy + applicable canonical state was not rejected');
dzn_2a2h_assert($classification->classify($appStudent, $appCourse, $appArrangement) === 'data_integrity_conflict', 'Legacy concealed represented canonical source conflict');

$closedStudent = dzn_2a2h_student('Synthetic H Closed'); $closedCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Closed Course'); $closedArrangement = dzn_2a2h_arrangement($closedStudent, $closedCourse, $teacherId, 2);
$closedEnrolment = dzn_2a2h_enrolment($closedStudent, $closedCourse, $teacherId, 'closed', null, $closedArrangement, 2);
dzn_2a2h_assert($classification->classify($closedStudent, $closedCourse) === 'canonical_closed_history', 'canonical_closed_history classification failed');
$mixedClosedLegacy = dzn_2a2h_legacy_bootstrap($closedStudent, $teacherId, $closedCourse, 12);
dzn_2a2h_assert($classification->classify($closedStudent, $closedCourse) === 'data_integrity_conflict', 'Mixed legacy + closed canonical history was not rejected');

$lineageStudent = dzn_2a2h_student('Synthetic H Lineage'); $lineageCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Lineage Course'); $lineageClosedArrangement = dzn_2a2h_arrangement($lineageStudent, $lineageCourse, $teacherId, 7);
$lineageClosed = dzn_2a2h_enrolment($lineageStudent, $lineageCourse, null, 'closed', null, $lineageClosedArrangement, 7); $successorArrangement = dzn_2a2h_arrangement($lineageStudent, $lineageCourse, $teacherId, 8);
dzn_2a2h_enrolment($lineageStudent, $lineageCourse, null, 'current', 1, $successorArrangement, 8, $lineageClosed, 'successor');
dzn_2a2h_assert($classification->classify($lineageStudent, $lineageCourse) === 'canonical_applicable', 'Valid closed-history successor lineage failed');

$badStudent = dzn_2a2h_student('Synthetic H Integrity'); $badCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Integrity Course'); $now = gmdate('Y-m-d H:i:s');
dzn_2a2h_assert($wpdb->insert($p . 'enrolments', ['uid' => str_pad('HBAD', 26, '0'), 'student_id' => $badStudent, 'teacher_id' => $teacherId, 'course_id' => $badCourse, 'status' => 'active', 'record_model' => 'legacy_phase1', 'lifecycle_state' => 'authorised', 'created_at' => $now, 'updated_at' => $now]) === 1, 'Contaminated fixture insert failed');
dzn_2a2h_assert($classification->classify($badStudent, $badCourse) === 'data_integrity_conflict', 'data_integrity_conflict classification failed');

$secondArrangement = dzn_2a2h_arrangement($appStudent, $appCourse, $teacherId, 3);
dzn_2a2h_assert(dzn_2a2h_duplicate(fn() => $wpdb->insert($p . 'enrolments', ['uid' => str_pad('HDUPSC', 26, '0'), 'student_id' => $appStudent, 'teacher_id' => $teacherId, 'course_id' => $appCourse, 'status' => 'canonical', 'record_model' => 'canonical_student_course_v1', 'accepted_service_arrangement_id' => $secondArrangement, 'lifecycle_state' => 'current', 'applicable_slot' => 1, 'created_at' => $now, 'updated_at' => $now])), 'Student + Course applicable uniqueness failed');
$otherStudent = dzn_2a2h_student('Synthetic H Independent'); $otherCourse = dzn_2a2h_course($instrumentId, 'Synthetic H Independent Course');
dzn_2a2h_assert($classification->classify($otherStudent, $otherCourse, $appArrangement) === 'data_integrity_conflict', 'Cross-target source linkage was not rejected');
dzn_2a2h_assert(dzn_2a2h_duplicate(fn() => $wpdb->insert($p . 'enrolments', ['uid' => str_pad('HDUPAR', 26, '0'), 'student_id' => $otherStudent, 'teacher_id' => null, 'course_id' => $otherCourse, 'status' => 'canonical', 'record_model' => 'canonical_student_course_v1', 'accepted_service_arrangement_id' => $appArrangement, 'lifecycle_state' => 'authorised', 'applicable_slot' => 1, 'created_at' => $now, 'updated_at' => $now])), 'Accepted arrangement provenance uniqueness failed');
$otherArrangement = dzn_2a2h_arrangement($otherStudent, $otherCourse, $teacherId, 4); dzn_2a2h_enrolment($otherStudent, $otherCourse, null, 'paused', 1, $otherArrangement, 4);
dzn_2a2h_assert($classification->classify($otherStudent, $otherCourse) === 'canonical_applicable', 'Unrelated Student/Course key was not independent');

dzn_2a2h_assert($wpdb->insert($p . 'enrolment_lifecycle_events', ['uid' => str_pad('HEVT1', 26, '0'), 'enrolment_id' => $appEnrolment, 'event_sequence' => 1, 'from_state' => null, 'to_state' => 'authorised', 'lineage_meaning' => null, 'reason_code' => 'synthetic_fixture', 'evidence_channel' => 'runtime_test', 'evidence_reference' => 'phase-2a2h-runtime', 'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => get_current_user_id(), 'created_at' => $now, 'created_by' => get_current_user_id()]) === 1, 'Lifecycle event insert failed');
$before = ['legacy' => (array) $wpdb->get_row($wpdb->prepare("SELECT status,teacher_id,record_model,lifecycle_state,applicable_slot,accepted_service_arrangement_id FROM {$p}enrolments WHERE id=%d", $fixture['enrolment_id']), ARRAY_A), 'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}enrolment_lifecycle_events"), 'enrolments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}enrolments")];
Migrator::maybe_upgrade();
$after = ['legacy' => (array) $wpdb->get_row($wpdb->prepare("SELECT status,teacher_id,record_model,lifecycle_state,applicable_slot,accepted_service_arrangement_id FROM {$p}enrolments WHERE id=%d", $fixture['enrolment_id']), ARRAY_A), 'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}enrolment_lifecycle_events"), 'enrolments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}enrolments")]; $done = (array) get_option('dzn_platform_completed_migrations', []);
dzn_2a2h_assert($before === $after && count(array_keys($done, '014_canonical_enrolment_foundation', true)) === 1, 'Repeated upgrade changed history or duplicated ledger');
echo "schema_13_to_14=pass\nlegacy_status_preserved={$legacy->status}\nlegacy_teacher_preserved={$legacy->teacher_id}\nclassifications=none,canonical_applicable,canonical_closed_history,legacy_review_required,already_linked_source,data_integrity_conflict\nmixed_applicable=data_integrity_conflict\nmixed_closed=data_integrity_conflict\nrepresented_mixed=data_integrity_conflict\nlineage_validation=pass\ncross_target_source_rejection=pass\narrangement_uniqueness=pass\nstudent_course_applicable_uniqueness=pass\nunrelated_key_independence=pass\ngeneric_creation_rejection=pass\nlegacy_bootstrap_compatibility=pass\ncanonical_term_authority_rejection=pass\ncanonical_lesson_authority_rejection=pass\ncanonical_service_archive_rejection=pass\ndirect_canonical_archive_rejection=pass\ndirect_canonical_restore_rejection=pass\nlegacy_archive_restore=pass\nrepeat_upgrade=pass\nPhase 2A.2-H migration runtime passed\n";
