<?php
/**
 * Disposable WordPress/MariaDB proof for the Students administration workspace.
 *
 * Run after the Phase-J production fixture using:
 *   DZN_STUDENT_WORKSPACE_RUNTIME=authority wp eval-file .../student-workspace-runtime.php --user=1
 */
if (getenv('DZN_STUDENT_WORKSPACE_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) {
    fwrite(STDERR, "Student workspace runtime refused.\n");
    exit(1);
}
if (!current_user_can('dzn_manage_students')) {
    fwrite(STDERR, "Student workspace runtime requires student administration authority.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{
    CanonicalEnrolmentLifecycleService,
    CanonicalLessonAuthorityService,
    CanonicalLessonScheduleService,
    CanonicalTermAuthorityService,
    StudentAdministrationService,
    StudentService,
    StudentWorkspaceFilters,
    StudentWorkspaceReadService,
    TeacherAssignmentService,
    TeacherAvailabilityService
};
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentRepository;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$actor = (int) get_current_user_id();
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);

function dzn_ws_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Student workspace runtime: ' . $message);
}
function dzn_ws_user(string $label, string $email, string $role = 'subscriber'): int {
    $id = wp_insert_user(array('user_login' => 'dzn-ws-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $email, 'display_name' => 'Workspace ' . ucfirst($label), 'role' => $role));
    if (is_wp_error($id)) throw new RuntimeException('Student workspace runtime user creation failed: ' . $id->get_error_message());
    return (int) $id;
}
function dzn_ws_key(string $label): string { return 'dzn-student-workspace-' . $label . '-' . wp_generate_uuid4(); }
function dzn_ws_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_ws_anomaly(array $profile, string $code): bool {
    foreach (($profile['identity']['anomalies'] ?? array()) as $anomaly) {
        if ((string) ($anomaly['code'] ?? '') === $code) return true;
    }
    return false;
}
function dzn_ws_rejected(callable $call, string $expected, string $message): void {
    $caught = null;
    try { $call(); } catch (Throwable $error) { $caught = $error; }
    dzn_ws_assert($caught !== null, $message . ' was accepted');
    dzn_ws_assert($caught->getMessage() === $expected, $message . ' rejected with ' . $caught->getMessage());
}

wp_set_current_user(1);
$admin = new StudentAdministrationService();
$read = new StudentWorkspaceReadService();
$repo = new StudentRepository();

// 1. Add Student duplicate protection and default directory priority.
$email = 'workspace-' . $suffix . '@synthetic.invalid';
$input = array(
    'display_name' => 'Workspace Active Student', 'first_name' => 'Workspace', 'last_name' => 'Active',
    'email' => $email, 'phone' => '+61 400 ' . substr($suffix, 0, 3) . ' 123',
    'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
    'timezone_source' => 'admin_selected', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active',
);
$activeId = $admin->createFromAdmin($input, 'workspace-form-' . $suffix);
dzn_ws_rejected(fn() => $admin->createFromAdmin($input, 'workspace-form-duplicate-' . $suffix), 'student_identity_already_exists', 'duplicate Add Student');
$active = $repo->find($activeId);
dzn_ws_assert($active && (int) $active->id === $activeId, 'created student was not persisted');

$inactiveId = $admin->createFromAdmin(array_merge($input, array('display_name' => 'Workspace Inactive Student', 'email' => 'workspace-inactive-' . $suffix . '@synthetic.invalid', 'phone' => '+61 400 999 001')), 'inactive-form-' . $suffix);
$admin->deactivate($inactiveId);
$inactive = $repo->find($inactiveId);
dzn_ws_assert($inactive && (string) $inactive->status === 'inactive', 'deactivate did not set inactive');

$archivedId = $admin->createFromAdmin(array_merge($input, array('display_name' => 'Workspace Archived Student', 'email' => 'workspace-archived-' . $suffix . '@synthetic.invalid', 'phone' => '+61 400 999 002')), 'archived-form-' . $suffix);
$admin->archive($archivedId);
$archived = $repo->find($archivedId);
dzn_ws_assert($archived && (string) $archived->status === 'archived' && $archived->archived_at !== null, 'archive did not set archived state');

$filters = StudentWorkspaceFilters::normalize(array('q' => 'Workspace Active Student', 'student_page' => 1));
$directory = $read->directory($filters);
dzn_ws_assert($directory['total'] >= 1, 'active directory search did not find the active student');
$all = StudentWorkspaceFilters::normalize(array('q' => 'Workspace Inactive Student', 'student_status' => 'inactive'));
dzn_ws_assert($read->directory($all)['total'] >= 1, 'inactive student was not findable through the inactive filter');
$archivedFilter = StudentWorkspaceFilters::normalize(array('q' => 'Workspace Archived Student', 'student_status' => 'archived'));
dzn_ws_assert($read->directory($archivedFilter)['total'] >= 1, 'archived student was not findable through the archived filter');
$default = $read->directory(StudentWorkspaceFilters::normalize(array('student_page' => 1)));
dzn_ws_assert($default['rows'] !== array(), 'default directory returned no rows');
dzn_ws_assert($default['rows'][0]->last_activity_at >= $default['rows'][count($default['rows']) - 1]->last_activity_at, 'default directory is not recent-activity ordered');
dzn_ws_assert(StudentWorkspaceFilters::normalize(array())['status'] === 'active', 'default directory filter is not active');

// 2. Returning student reuses identity, archive/restore/reactivate preserve history and account.
$returnEmail = 'workspace-return-' . $suffix . '@synthetic.invalid';
$returnId = $admin->createFromAdmin(array_merge($input, array('display_name' => 'Workspace Returning Student', 'email' => $returnEmail, 'phone' => '+61 400 999 003')), 'return-form-' . $suffix);
$returnUser = dzn_ws_user('return', $returnEmail);
$admin->linkAccount($returnId, $returnUser);
$profile = $read->profile($returnId);
dzn_ws_assert(($profile['account']['status'] ?? '') === 'linked', 'verified account link was not visible');
$admin->deactivateLinkedAccount($returnId);
$profile = $read->profile($returnId);
dzn_ws_assert(($profile['account']['status'] ?? '') === 'not_linked', 'account deactivation did not revoke the principal link');
dzn_ws_assert(get_userdata($returnUser) !== false, 'account deactivation deleted the WordPress user');
$admin->linkAccount($returnId, $returnUser);

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_ws_assert(is_array($fixture) && !empty($fixture['sources']), 'Phase-J fixture is required for rich student data');
$teacherId = (int) $fixture['teachers'][0];
$courseId = (int) $fixture['sources'][0]['course_id'];
$now = gmdate('Y-m-d H:i:s');
$wpdb->insert($p . 'recording_decisions', array(
    'uid' => '01WS' . strtoupper(substr(hash('sha256', $suffix), 0, 22)), 'subject_type' => 'student', 'subject_id' => $returnId,
    'purpose_code' => 'lesson_recording', 'decision_code' => 'allow', 'notice_version_id' => 1,
    'notice_uid' => '01WS' . strtoupper(substr(hash('sha256', 'notice' . $suffix), 0, 22)),
    'notice_audience_code' => 'student_lesson_recording', 'notice_language_code' => 'en', 'notice_version_number' => 1,
    'notice_text_digest' => hash('sha256', 'notice'), 'evidence_key_version' => 'runtime', 'evidence_digest' => hash('sha256', 'decision'),
    'decided_at' => $now, 'decided_by_user_id' => $returnUser, 'created_at' => $now,
));
$lessonId = (int) $wpdb->insert_id;
$wpdb->insert($p . 'lessons', array(
    'uid' => '01WS' . strtoupper(substr(hash('sha256', 'lesson' . $suffix), 0, 22)), 'reference_code' => null,
    'student_id' => $returnId, 'teacher_id' => $teacherId, 'course_id' => $courseId,
    'enrolment_id' => null, 'term_id' => null, 'lesson_type' => 'introductory', 'status' => 'completed',
    'sequence_number' => null, 'current_schedule_version_id' => null, 'replacement_for_lesson_id' => null,
    'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
    'cancelled_at' => null, 'completed_at' => $now, 'archived_at' => null, 'archived_by' => null,
    'record_model' => 'legacy_phase1', 'lifecycle_state' => null,
));
$historyLessonId = (int) $wpdb->insert_id;
$wpdb->insert($p . 'commercial_payment_evidence', array(
    'uid' => '01WS' . strtoupper(substr(hash('sha256', 'payment' . $suffix), 0, 22)), 'reference_code' => null,
    'provider_key' => 'manual', 'provider_account_digest' => null, 'evidence_reference_digest' => hash('sha256', 'payment-ref'),
    'evidence_fact_digest' => hash('sha256', 'payment-fact'), 'evidence_kind' => 'success', 'amount_minor' => 1000,
    'currency' => 'AUD', 'obligation_reference_digest' => null, 'provider_occurred_at' => $now, 'ingested_at' => $now,
    'processing_state' => 'accepted', 'reason_code' => 'runtime_fixture', 'offer_id' => null, 'purchase_id' => null,
    'obligation_id' => null, 'created_at' => $now, 'created_by' => $actor,
));
$historyPaymentId = (int) $wpdb->insert_id;
$wpdb->insert($p . 'canonical_attendance_cases', array(
    'uid' => '01WS' . strtoupper(substr(hash('sha256', 'case' . $suffix), 0, 22)), 'lesson_id' => $historyLessonId,
    'schedule_version_id' => 1, 'enrolment_id' => 1, 'term_id' => 1, 'student_id' => $returnId, 'teacher_id' => $teacherId,
    'teacher_assignment_id' => 1, 'occurrence_start_utc' => $now, 'occurrence_end_utc' => $now, 'window_end_utc' => $now,
    'rule_version' => 'runtime', 'cutover_policy_id' => 1, 'state' => 'settled', 'case_version' => 1,
    'latest_decision_id' => null, 'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor,
));
$historyCaseId = (int) $wpdb->insert_id;

$admin->deactivate($returnId);
$admin->reactivate($returnId);
$admin->archive($returnId);
$admin->restore($returnId);
$admin->reactivate($returnId);
$returned = $repo->find($returnId);
dzn_ws_assert($returned && (int) $returned->id === $returnId && (string) $returned->status === 'active', 'returning student did not reuse/reactivate the same active identity');
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}students WHERE LOWER(email)=LOWER(%s) AND archived_at IS NULL", $returnEmail)) === 1, 'returning student created a duplicate identity');
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type='student' AND subject_id=%d", $returnId)) >= 1, 'consent history was not preserved');
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE id=%d", $historyLessonId)) === 1, 'lesson history was not preserved');
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE id=%d", $historyPaymentId)) === 1, 'payment history was not preserved');
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_cases WHERE id=%d", $historyCaseId)) === 1, 'attendance history was not preserved');
dzn_ws_assert(get_userdata($returnUser) !== false, 'lifecycle actions deleted the WordPress user');
$profile = $read->profile($returnId);
$account = $profile['account'];
dzn_ws_assert(($account['status'] ?? '') === 'linked', 'archive/restore lost the existing account link');

// Dependency refusal: a student with a canonical enrolment cannot be archived.
$fixtureSource = $fixture['sources'][0];
$fixtureStudentId = (int) $fixtureSource['student_id'];
dzn_ws_rejected(fn() => $admin->archive($fixtureStudentId), 'Archive conflict: dependent operational records exist', 'archive with an operational enrolment');

// 3. Guardian and minor-authority rules.
$guardianStudent = $admin->createFromAdmin(array_merge($input, array('display_name' => 'Workspace Minor Student', 'email' => 'workspace-minor-' . $suffix . '@synthetic.invalid', 'phone' => '+61 400 999 004')), 'minor-form-' . $suffix);
$profile = $read->profile($guardianStudent);
dzn_ws_assert(dzn_ws_anomaly($profile, 'capacity_unknown'), 'unknown capacity anomaly was not shown');
$admin->classifyCapacity($guardianStudent, 'minor');
$profile = $read->profile($guardianStudent);
dzn_ws_assert(dzn_ws_anomaly($profile, 'guardian_authority_missing'), 'minor without guardian grant did not show the guardian anomaly');
$guardianActions = \Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry::forRecordSelected('student', $repo->find($guardianStudent), array('student-guardian-grant','student-classify-capacity'));
dzn_ws_assert(isset($guardianActions['student-guardian-grant'], $guardianActions['student-classify-capacity']), 'guardian anomaly did not expose valid in-profile actions');
dzn_ws_assert($profile['guardians'] === array(), 'guardian authority was inferred from age without a grant');
$guardianOne = dzn_ws_user('guardian-one', 'guardian-one-' . $suffix . '@synthetic.invalid');
$admin->grantGuardian($guardianStudent, $guardianOne);
$profile = $read->profile($guardianStudent);
dzn_ws_assert(count($profile['guardians']) === 1 && $profile['guardians'][0]['active'], 'guardian grant was not visible');
dzn_ws_assert(!dzn_ws_anomaly($profile, 'guardian_authority_missing'), 'guardian anomaly remained after a valid grant');
$guardianTwo = dzn_ws_user('guardian-two', 'guardian-two-' . $suffix . '@synthetic.invalid');
$admin->supersedeGuardian($guardianStudent, (int) $profile['guardians'][0]['grant_id'], $guardianTwo);
$profile = $read->profile($guardianStudent);
$activeGuardians = array_values(array_filter($profile['guardians'], static fn(array $g): bool => $g['active']));
dzn_ws_assert(count($activeGuardians) === 1 && (int) $activeGuardians[0]['wordpress_user_id'] === $guardianTwo, 'guardian supersession did not leave one verified active guardian');
$admin->revokeGuardian($guardianStudent, (int) $activeGuardians[0]['grant_id']);
$profile = $read->profile($guardianStudent);
dzn_ws_assert(dzn_ws_anomaly($profile, 'guardian_authority_missing'), 'revoking guardian authority did not restore the required-authority anomaly');

// 4. Identity anomaly evidence and in-profile resolution.
$duplicateId = (new StudentService())->create(array_merge($input, array('display_name' => 'Workspace Duplicate Candidate', 'email' => $returnEmail, 'phone' => '+61 400 999 005')));
$profile = $read->profile($returnId);
dzn_ws_assert(dzn_ws_anomaly($profile, 'duplicate_identity_candidate'), 'duplicate identity candidate was not detected');
dzn_ws_assert(!empty($profile['identity']['anomalies'][0]['evidence']), 'identity anomalies do not expose specific evidence');

$conflictUser = dzn_ws_user('conflict', 'conflict-' . $suffix . '@synthetic.invalid');
$wpdb->update($p . 'students', array('wordpress_user_id' => $conflictUser), array('id' => $returnId));
$profile = $read->profile($returnId);
dzn_ws_assert(dzn_ws_anomaly($profile, 'principal_link_conflict'), 'principal-link conflict was not detected');
$conflictActions = \Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry::forRecordSelected('student', $repo->find($returnId), array('student-supersede-account'));
dzn_ws_assert(isset($conflictActions['student-supersede-account']), 'principal-link conflict did not expose an in-profile resolution action');
$admin->supersedeLinkedAccount($returnId, $conflictUser);
$profile = $read->profile($returnId);
dzn_ws_assert(!dzn_ws_anomaly($profile, 'principal_link_conflict'), 'principal-link conflict was not resolved through the canonical supersession');

$mismatchUser = dzn_ws_user('mismatch', 'mismatch-' . $suffix . '@synthetic.invalid');
$admin->supersedeLinkedAccount($returnId, $mismatchUser);
$profile = $read->profile($returnId);
dzn_ws_assert(dzn_ws_anomaly($profile, 'account_email_mismatch'), 'account email mismatch was not detected');
$admin->supersedeLinkedAccount($returnId, $returnUser);
$profile = $read->profile($returnId);
dzn_ws_assert(!dzn_ws_anomaly($profile, 'account_email_mismatch'), 'account email mismatch was not resolved');

$admin->classifyCapacity($returnId, 'adult');
$profile = $read->profile($returnId);
dzn_ws_assert(!dzn_ws_anomaly($profile, 'capacity_unknown'), 'capacity anomaly remained after a reviewed classification');

// 5. Matching unresolved introductory booking can be resolved in the student profile.
$bookingEmail = 'workspace-booking-' . $suffix . '@synthetic.invalid';
$instrumentId = (int) $wpdb->get_var("SELECT id FROM {$p}instruments ORDER BY id LIMIT 1");
$introCourseId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}courses WHERE instrument_id=%d ORDER BY id LIMIT 1", $instrumentId));
$booking = (new Delnavazan\Platform\Core\Application\BookingRequestSubmissionService())->submitPublic(array(
    'requested_instrument_id' => $instrumentId, 'selected_intro_course_id' => $introCourseId,
    'full_name' => 'Workspace Booking Match', 'email' => $bookingEmail, 'mobile' => '0400999006',
    'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'communication_language' => 'en',
    'whatsapp_same_as_mobile' => true, 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
    'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+14 days')), 'local_start_time' => '09:00', 'timezone' => 'UTC')),
), dzn_ws_key('booking'));
$bookingId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $booking['request_reference']));
$bookingStudent = $admin->createFromAdmin(array_merge($input, array('display_name' => 'Workspace Booking Student', 'email' => $bookingEmail, 'phone' => '+61 400 999 006')), 'booking-student-form-' . $suffix);
$profile = $read->profile($bookingStudent);
dzn_ws_assert(dzn_ws_anomaly($profile, 'booking_identity_candidate'), 'matching unresolved booking was not shown as an identity anomaly');
$bookingActions = \Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry::forRecordSelected('student', $repo->find($bookingStudent), array('student-resolve-booking-identity','student-booking-identity-outcome'));
dzn_ws_assert(isset($bookingActions['student-resolve-booking-identity'], $bookingActions['student-booking-identity-outcome']), 'booking identity anomaly did not expose valid in-profile actions');
$matches = $read->pendingBookingIdentityMatches($bookingStudent);
dzn_ws_assert(count($matches) === 1 && (int) $matches[0]->id === $bookingId, 'matching booking request was not exposed to the resolution action');
$admin->resolveBookingIdentity($bookingStudent, $bookingId, dzn_ws_key('resolve-booking'));
dzn_ws_assert((int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}booking_requests WHERE id=%d", $bookingId)) === $bookingStudent, 'booking identity resolution did not link the verified student');
$profile = $read->profile($bookingStudent);
dzn_ws_assert(!dzn_ws_anomaly($profile, 'booking_identity_candidate'), 'booking identity anomaly remained after resolution');

// 6. Canonical enrolment/term/lesson history and pagination.
$enrolmentId = (int) $fixtureSource['enrolment_id'];
(new CanonicalEnrolmentLifecycleService())->activate($enrolmentId, 'authorised', dzn_ws_evidence('workspace-activate'), dzn_ws_key('activate'));
$term = (new CanonicalTermAuthorityService())->create($enrolmentId, null, null, dzn_ws_evidence('workspace-term'), dzn_ws_key('term'));
$termId = (int) $term['term_id'];
(new CanonicalTermAuthorityService())->activate($termId, 'authorised', dzn_ws_evidence('workspace-term-active'), dzn_ws_key('term-active'));
$assignmentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1 LIMIT 1", $enrolmentId));
if ($assignmentId < 1) {
    $assignment = (new TeacherAssignmentService())->assignInitial($enrolmentId, dzn_ws_key('assignment'));
    $assignmentId = (int) $assignment['assignment_id'];
}
$availability = new TeacherAvailabilityService();
$availability->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'workspace_runtime'));
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'workspace_runtime'));
}
$lessonAuthority = new CanonicalLessonAuthorityService();
$createdLessons = array();
for ($i = 0; $i < 12; $i++) {
    $createdLessons[] = (int) $lessonAuthority->createStandard($termId, $assignmentId, dzn_ws_evidence('workspace-lesson-' . $i), dzn_ws_key('lesson-' . $i))['lesson_id'];
}
$studentIdForFixture = $fixtureStudentId;
$lessonActions = \Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry::forRecordSelected('student', $repo->find($studentIdForFixture), array('student-lesson-reschedule','student-enrolment-transition','student-term-transition'));
dzn_ws_assert(isset($lessonActions['student-lesson-reschedule'], $lessonActions['student-enrolment-transition'], $lessonActions['student-term-transition']), 'student-specific enrolment, Term and Lesson actions were not available in the profile');
$paged = $read->profile($studentIdForFixture, 1, 10);
dzn_ws_assert((int) $paged['lessons']['total'] === 12 && (int) $paged['lessons']['pages'] === 2, 'lesson history did not report its real total and page count');
dzn_ws_assert(count($paged['lessons']['rows']) === 10, 'first lesson history page did not honour the page size');
$paged2 = $read->profile($studentIdForFixture, 2, 10);
dzn_ws_assert(count($paged2['lessons']['rows']) === 2, 'second lesson history page did not expose the remaining records');

$futureDate = (new DateTimeImmutable('tomorrow 12:00:00', new DateTimeZone('UTC')))->format('Y-m-d');
$futureTime = '12:00';
$scheduled = (new CanonicalLessonScheduleService())->schedule($createdLessons[0], $assignmentId, array(
    'schedule_timezone' => 'UTC', 'local_wall_date' => $futureDate, 'local_wall_time' => $futureTime . ':00',
    'duration_minutes' => 30, 'reason_code' => 'workspace_schedule', 'evidence_channel' => 'staff_record',
    'evidence_reference' => 'workspace-schedule', 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_ws_key('schedule'));
$admin->rescheduleLesson($studentIdForFixture, $createdLessons[0], $futureDate, '14:00', 30, 'workspace reschedule', dzn_ws_key('reschedule'));
$newStart = (string) $wpdb->get_var($wpdb->prepare("SELECT starts_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1 AND superseded_at IS NULL", $createdLessons[0]));
dzn_ws_assert(str_contains($newStart, '14:00:00'), 'in-profile lesson reschedule did not use the canonical schedule authority');

// Enrolment pause/resume is an in-profile canonical action.
$admin->transitionEnrolment($studentIdForFixture, $enrolmentId, 'pause', 'workspace pause', dzn_ws_key('pause'));
dzn_ws_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId)) === 'paused', 'enrolment pause did not reach canonical state');
$admin->transitionEnrolment($studentIdForFixture, $enrolmentId, 'resume', 'workspace resume', dzn_ws_key('resume'));
dzn_ws_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId)) === 'current', 'enrolment resume did not reach canonical state');

echo "Student workspace runtime passed\n";
