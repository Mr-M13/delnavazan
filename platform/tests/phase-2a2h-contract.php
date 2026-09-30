<?php
/** Phase 2A.2-H canonical Enrolment foundation static contract. */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/EnrolmentRepository.php');
$service = file_get_contents($root . '/src/Core/Application/EnrolmentApplicabilityService.php');
$creator = file_get_contents($root . '/src/Core/Application/EnrolmentService.php');
$screen = file_get_contents($root . '/src/Admin/Controller/ScreenController.php');
$archive = file_get_contents($root . '/src/Core/Application/ArchiveService.php');
$term = file_get_contents($root . '/src/Core/Application/TermService.php');
$lesson = file_get_contents($root . '/src/Core/Application/LessonService.php');
$teacher = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherRepository.php');
$student = file_get_contents($root . '/src/Core/Infrastructure/Repository/StudentRepository.php');
$course = file_get_contents($root . '/src/Core/Infrastructure/Repository/CourseRepository.php');
$runtime = file_get_contents($root . '/tests/phase-2a2h-migration-runtime.php');

/* Compatibility markers are minimum-schema plus the canonical build identity shape, so a later
   phase cannot silently satisfy or invalidate the Phase H foundation by renumbering. */
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<14)throw new RuntimeException('Missing compatible Phase H+ schema identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase H+ build identity');
foreach (['014_canonical_enrolment_foundation', 'install_canonical_enrolment_foundation', 'verify_canonical_enrolment_schema', 'record_model', 'legacy_phase1', 'accepted_service_arrangement_id', 'lifecycle_state', 'applicable_slot', 'predecessor_enrolment_id', 'lineage_meaning', 'enrolment_lifecycle_events', 'ENGINE=InnoDB'] as $fragment) {
    if (!str_contains($migration, $fragment)) throw new RuntimeException('Missing Phase H migration foundation: ' . $fragment);
}
foreach (['UNIQUE ', 'student_course_applicable', 'student_id,course_id,applicable_slot', 'accepted_service_arrangement_id', 'MODIFY teacher_id bigint unsigned NULL'] as $fragment) {
    if (!str_contains($migration, $fragment)) throw new RuntimeException('Missing canonical identity/uniqueness boundary: ' . $fragment);
}
$lifecycleStart = strpos($migration, 'CREATE TABLE {$p}enrolment_lifecycle_events');
$lifecycleEnd = strpos($migration, 'private static function ', $lifecycleStart);
$lifecycleSlice = substr($migration, $lifecycleStart, $lifecycleEnd === false ? null : $lifecycleEnd - $lifecycleStart);
if (str_contains($lifecycleSlice, 'updated_at datetime')) throw new RuntimeException('Lifecycle history is mutable');
foreach (['none', 'canonical_applicable', 'canonical_closed_history', 'legacy_review_required', 'already_linked_source', 'data_integrity_conflict'] as $classification) {
    if (!str_contains($service, "'{$classification}'")) throw new RuntimeException('Missing applicability classification: ' . $classification);
}
foreach (['canonical_student_course_v1', 'authorised', 'current', 'paused', 'closed', 'successor', 'return_after_closure', 'correction', 'distinct_concurrent_service', 'forStudentCourse', 'forAcceptedArrangement', 'arrangementById'] as $fragment) {
    if (!str_contains($service . $repository, $fragment)) throw new RuntimeException('Missing applicability invariant: ' . $fragment);
}
if (!str_contains($creator, 'Generic Enrolment creation is disabled') || !str_contains($repository, 'Generic Enrolment repository insertion is disabled') || str_contains($screen, "'create_enrolment' =>") || str_contains($screen, "'enrolment' => ['student_id'")) {
    throw new RuntimeException('Generic/manual Enrolment creation remains exposed');
}
foreach (['insertLegacyBootstrap', "current_user_can('dzn_manage_enrolments')", 'Legacy Enrolment record model required', 'Canonical Enrolment fields are prohibited', 'Complete legacy Student, Teacher and Course identity required'] as $fragment) {
    if (!str_contains($repository, $fragment)) throw new RuntimeException('Controlled legacy/bootstrap compatibility missing: ' . $fragment);
}
foreach (['public function archive(', 'public function restore(', 'requireLegacyMutationTarget', 'Legacy Enrolment archive/restore target required'] as $fragment) {
    if (!str_contains($repository, $fragment)) throw new RuntimeException('Repository-level canonical archive/restore guard missing: ' . $fragment);
}
$mixed = strpos($service, 'if ($legacy && $canonical) return self::DATA_INTEGRITY_CONFLICT;');
$represented = strpos($service, 'if ($sourceRows) {');
$legacyOnly = strpos($service, 'if ($legacy) return self::LEGACY_REVIEW_REQUIRED;');
if ($mixed === false || $represented === false || $legacyOnly === false || $mixed > $represented || $represented > $legacyOnly) throw new RuntimeException('Applicability aggregate precedence is unsafe');
if (!str_contains($repository, 'lifecycleHistory') || !str_contains($screen, 'Canonical lifecycle history')) throw new RuntimeException('Protected internal Enrolment history surface missing');
foreach (['Canonical Enrolment lifecycle is not mutable through legacy archive', 'Canonical Enrolment lifecycle is not mutable through legacy restore'] as $fragment) {
    if (!str_contains($archive, $fragment)) throw new RuntimeException('Canonical legacy-mutation guard missing');
}
if (!str_contains($repository, "record_model = 'legacy_phase1'") || !str_contains($term, 'canonical Enrolment grants no Term authority') || !str_contains($lesson, 'Canonical Enrolment grants no Lesson or Teacher Assignment authority')) {
    throw new RuntimeException('Canonical downstream authority exclusion missing');
}
if (!str_contains($teacher, "record_model = 'legacy_phase1'") || str_contains($teacher, 'canonical_student_course_v1')) throw new RuntimeException('Canonical Teacher context became assignment authority');
foreach ([$student, $course] as $parentRepository) if (!str_contains($parentRepository, "applicable_slot = 1")) throw new RuntimeException('Applicable canonical parent protection missing');
foreach (['convert', 'conversion', 'createCanonical', 'transitionCanonical', 'teacher_assignments', 'amelia_', 'wp_amelia', 'platform_payments', 'platform_outbox', 'register_rest_route'] as $forbidden) {
    if (preg_match('/function\s+' . preg_quote($forbidden, '/') . '/i', $service . $repository . $creator)) throw new RuntimeException('Phase H exceeded foundation authority: ' . $forbidden);
}
foreach (['new LessonService()', 'insertLegacyBootstrap', '->archive($appEnrolment', '->restore($restoreCanonical', 'mixed_applicable=data_integrity_conflict', 'mixed_closed=data_integrity_conflict', 'represented_mixed=data_integrity_conflict', 'legacy_archive_restore=pass'] as $fragment) {
    if (!str_contains($runtime, $fragment)) throw new RuntimeException('Focused runtime evidence missing: ' . $fragment);
}
echo "Phase 2A.2-H source contract passed\n";
