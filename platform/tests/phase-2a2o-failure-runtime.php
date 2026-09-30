<?php
/** Disposable Phase-O failure injection: every material write boundary must roll back completely. */
if(getenv('DZN_PHASE_2A2O_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-O failure runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_of_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_of_key(string $label): string { return 'dzn-2a2of-' . $label . '-' . wp_generate_uuid4(); }
function dzn_of_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_of_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $delivery = new CanonicalLessonDeliveryService();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$available = null;
foreach ($fixture['sources'] as $candidate) {
    $candidateId = (int) $candidate['enrolment_id'];
    $state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $candidateId));
    $terms = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'", $candidateId));
    if ($state === 'authorised' && $terms === 0) { $available = $candidateId; break; }
}
dzn_of_assert($available !== null, 'no_available_source');
$enrolmentId = (int) $available;
$enrolmentService->activate($enrolmentId, 'authorised', dzn_of_evidence('activate'), dzn_of_key('activate'));
$term = $termService->create($enrolmentId, null, null, dzn_of_evidence('term'), dzn_of_key('term'));
$termService->activate((int) $term['term_id'], 'authorised', dzn_of_evidence('term-active'), dzn_of_key('term-active'));
$assignment = $assignmentService->assignInitial($enrolmentId, dzn_of_key('assignment'));
$courseId = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d", $enrolmentId));
$teacherId = (int) (new TeacherService())->create(array('display_name' => 'Synthetic OF Teacher', 'email' => 'of-' . wp_generate_uuid4() . '@phase-2a2o.invalid'));
$acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_failure'));
$availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_failure'));
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_failure'));
}
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_failure'));
$moved = $assignmentService->replace($enrolmentId, $teacherId, array('expected_assignment_id' => (int) $assignment['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'isolated-failure-' . $teacherId, 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_of_key('isolate'));
$assignmentId = (int) $moved['assignment_id'];
/** Keep every synthetic interval inside one UTC day (the provisioned full-day availability rules
 *  meet at midnight, where a genuine one-second coverage gap exists). */
$fitLead = static function (int $durationMinutes, int $minLeadSeconds = 2): string {
    $midnight = strtotime('tomorrow UTC');
    $start = time() + $minLeadSeconds;
    if ($start + $durationMinutes * 60 + 5 >= $midnight) $start = $midnight + 30;
    return '@' . $start;
};
$occurrence = function (string $label) use ($lessonService, $scheduleService, $wpdb, $p, $term, $assignmentId, $fitLead): array {
    $lessonId = (int) $lessonService->createStandard((int) $term['term_id'], $assignmentId, dzn_of_evidence($label), dzn_of_key($label))['lesson_id'];
    $wall = gmdate('Y-m-d H:i:s', strtotime($fitLead(1)));
    $scheduled = $scheduleService->schedule($lessonId, $assignmentId, array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($wall, 0, 10), 'local_wall_time' => substr($wall, 11), 'duration_minutes' => 1, 'reason_code' => 'synthetic_schedule') + dzn_of_evidence('schedule-' . $label), dzn_of_key('schedule-' . $label));
    $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $scheduled['schedule_version_id']));
    $scheduleService->release($lessonId, array('expected_schedule_version_id' => (int) $version->id, 'reason_code' => 'synthetic_schedule_release') + dzn_of_evidence('release-' . $label), dzn_of_key('release-' . $label));
    return array('lesson_id' => $lessonId, 'ends_at_utc' => (string) $version->ends_at_utc);
};
// Create every synthetic occurrence first, then wait once for all of them to end.
$occurrencePool = array();
$latestEnd = 0;
foreach (array('fail-outcome', 'fail-command', 'fail-lock') as $label) {
    $occurrencePool[$label] = $occurrence($label);
    $latestEnd = max($latestEnd, strtotime((string) $occurrencePool[$label]['ends_at_utc'] . ' UTC'));
}
while (time() < $latestEnd + 2) sleep(1);
$occurrence = static function (string $label) use ($occurrencePool): int {
    if (!isset($occurrencePool[$label])) throw new RuntimeException('Synthetic occurrence unavailable: ' . $label);
    return (int) $occurrencePool[$label]['lesson_id'];
};
$outcomeCount = static function (int $lessonId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d", $lessonId));
};
$commandCount = static function (string $key) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_commands WHERE command_key_digest=%s", hash_hmac('sha256', 'canonical_lesson_delivery_key:' . $key, wp_salt('dzn_canonical_lesson_delivery'))));
};
$obligationCount = static function (int $lessonId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d", $lessonId));
};
$inject = static function (string $hook): void {
    add_action($hook, static function () use ($hook): void { throw new RuntimeException('injected:' . $hook); });
};
$clear = static function (string $hook): void { remove_all_actions($hook); };

// 1. Outcome insert boundary.
$lessonA = $occurrence('fail-outcome');
$keyA = dzn_of_key('fail-outcome');
$inject('dzn_phase_2a2o_after_outcome_insert');
$caught = false;
try { $delivery->record($lessonA, 'authorised', array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('a'), $keyA); }
catch (RuntimeException $exception) { $caught = $exception->getMessage() === 'injected:dzn_phase_2a2o_after_outcome_insert'; }
$clear('dzn_phase_2a2o_after_outcome_insert');
dzn_of_assert($caught, 'outcome-insert failure injection was not observed');
dzn_of_assert($outcomeCount($lessonA) === 0 && $commandCount($keyA) === 0, 'outcome-insert failure left orphan delivery evidence');
$recovered = $delivery->record($lessonA, 'authorised', array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('a'), $keyA);
dzn_of_assert(!empty($recovered['created']) && $outcomeCount($lessonA) === 1, 'outcome-insert failure left a falsely replayable command');
$existingOutcome = (int) $recovered['outcome_id'];

// 2. Supersession boundary: a half-superseded authority must never survive.
$keyB = dzn_of_key('fail-correction');
$inject('dzn_phase_2a2o_after_outcome_supersede');
$caught = false;
try { $delivery->correct($lessonA, $existingOutcome, array('outcome_code' => 'interruption', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('b'), $keyB); }
catch (RuntimeException $exception) { $caught = $exception->getMessage() === 'injected:dzn_phase_2a2o_after_outcome_supersede'; }
$clear('dzn_phase_2a2o_after_outcome_supersede');
dzn_of_assert($caught, 'supersession failure injection was not observed');
$after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", $existingOutcome));
dzn_of_assert($outcomeCount($lessonA) === 1 && (int) $after->applicable_slot === 1 && $after->superseded_at === null && $after->superseded_by_outcome_id === null, 'supersession failure left a half-superseded authority');
dzn_of_assert($commandCount($keyB) === 0, 'supersession failure persisted command evidence');
$corrected = $delivery->correct($lessonA, $existingOutcome, array('outcome_code' => 'interruption', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('b'), $keyB);
dzn_of_assert(!empty($corrected['created']) && $outcomeCount($lessonA) === 2, 'supersession failure did not permit a clean retry');

// 3. Command-evidence boundary.
$lessonC = $occurrence('fail-command');
$keyC = dzn_of_key('fail-command');
$inject('dzn_phase_2a2o_after_command_insert');
$caught = false;
try { $delivery->record($lessonC, 'authorised', array('outcome_code' => 'teacher_non_delivery', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('c'), $keyC); }
catch (RuntimeException $exception) { $caught = $exception->getMessage() === 'injected:dzn_phase_2a2o_after_command_insert'; }
$clear('dzn_phase_2a2o_after_command_insert');
dzn_of_assert($caught, 'command-insert failure injection was not observed');
dzn_of_assert($outcomeCount($lessonC) === 0 && $commandCount($keyC) === 0, 'command-insert failure left orphan outcome or command rows');
$final = $delivery->record($lessonC, 'authorised', array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('c'), $keyC);
dzn_of_assert(!empty($final['created']) && $outcomeCount($lessonC) === 1, 'command failure did not permit a clean retry');
$phantom = false;
try { $delivery->record($lessonC, 'authorised', array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('c-alt'), $keyC); }
catch (Throwable $exception) { $phantom = in_array($exception->getMessage(), array('Idempotency conflict', 'delivery_outcome_exists'), true); }
dzn_of_assert($phantom && $outcomeCount($lessonC) === 1, 'rolled-back command key accepted different intent after retry');

// 4. Lock-boundary failure must not create partial authority either.
$lessonD = $occurrence('fail-lock');
$keyD = dzn_of_key('fail-lock');
$inject('dzn_phase_2a2o_delivery_locks_held');
$caught = false;
try { $delivery->record($lessonD, 'authorised', array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_of_evidence('d'), $keyD); }
catch (RuntimeException $exception) { $caught = str_starts_with($exception->getMessage(), 'injected:'); }
$clear('dzn_phase_2a2o_delivery_locks_held');
dzn_of_assert($caught && $outcomeCount($lessonD) === 0 && $commandCount($keyD) === 0, 'lock-boundary failure left delivery evidence behind');

// 5. O-D8 reconciliation + academy obligation boundary: no half-reconciled authority and no
//    phantom entitlement may survive a failed reconciliation.
$lessonService = new CanonicalLessonAuthorityService();
$lessonService->complete($lessonD, 'authorised', dzn_of_evidence('d-complete'), dzn_of_key('d-complete'));
$keyE = dzn_of_key('fail-reconcile');
$inject('dzn_phase_2a2o_after_obligation_insert');
$caught = false;
$reconcileEvidence = dzn_of_evidence('d-reconcile') + array('reason_code' => 'reconciliation_confirmed_non_delivery');
try { $delivery->reconcile($lessonD, $reconcileEvidence, $keyE); }
catch (RuntimeException $exception) { $caught = $exception->getMessage() === 'injected:dzn_phase_2a2o_after_obligation_insert'; }
$clear('dzn_phase_2a2o_after_obligation_insert');
dzn_of_assert($caught, 'reconciliation obligation failure injection was not observed');
dzn_of_assert($outcomeCount($lessonD) === 0 && $obligationCount($lessonD) === 0 && $commandCount($keyE) === 0, 'reconciliation failure left a half-reconciled authority or phantom entitlement');
dzn_of_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $lessonD)) === 'completed', 'reconciliation failure altered the historical lifecycle');
$reconciled = $delivery->reconcile($lessonD, $reconcileEvidence, $keyE);
dzn_of_assert(!empty($reconciled['reconciled']) && $outcomeCount($lessonD) === 1 && $obligationCount($lessonD) === 1, 'reconciliation failure did not permit a clean retry');

echo "failure_injection_boundaries=5\nrollback_complete=pass\nno_orphan_evidence=pass\nno_half_superseded_authority=pass\nno_phantom_entitlement=pass\nno_falsely_replayable_command=pass\nPhase 2A.2-O failure runtime passed\n";
