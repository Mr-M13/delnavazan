<?php
/** Prepare one deterministic gated Phase-O delivery/lifecycle race on synthetic production-path chains. */
if (getenv('DZN_PHASE_2A2O_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-O concurrency setup refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2O_MODE');
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || count($fixture['sources'] ?? array()) < 2) throw new RuntimeException('Phase-J fixture required');
$enrolmentService = new CanonicalEnrolmentLifecycleService(); $termService = new CanonicalTermAuthorityService();
$assignmentService = new TeacherAssignmentService(); $lessonService = new CanonicalLessonAuthorityService();
$scheduleService = new CanonicalLessonScheduleService(); $delivery = new CanonicalLessonDeliveryService();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$evidence = static fn(string $reference): array => array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s'));
$key = static fn(string $label): string => 'dzn-2a2o-race-' . $label . '-' . wp_generate_uuid4();
$allocate = static function () use ($fixture, $wpdb, $p): int {
    foreach ($fixture['sources'] as $index => $source) {
        $enrolmentId = (int) $source['enrolment_id'];
        $state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
        $terms = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'", $enrolmentId));
        if ($state === 'authorised' && $terms === 0) return $enrolmentId;
    }
    throw new RuntimeException('no_available_source');
};
$chain = function (int $enrolmentId) use ($enrolmentService, $termService, $assignmentService, $key, $evidence): array {
    $enrolmentService->activate($enrolmentId, 'authorised', $evidence('activate'), $key('activate'));
    $term = $termService->create($enrolmentId, null, null, $evidence('term'), $key('term'));
    $termService->activate((int) $term['term_id'], 'authorised', $evidence('term-active'), $key('term-active'));
    $assignment = $assignmentService->assignInitial($enrolmentId, $key('assignment'));
    return array('enrolment_id' => $enrolmentId, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id'], 'predecessor_assignment_id' => (int) $assignment['assignment_id']);
};
/**
 * Give every race chain its own provisioned Teacher: Teacher occupancy is exclusive, so a shared
 * Teacher would make unrelated modes contend through capacity rather than the contended authority.
 */
$isolate = function (array $chain) use ($assignmentService, $acceptingService, $availabilityService, $wpdb, $p, $key, $evidence): array {
    $courseId = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d", (int) $chain['enrolment_id']));
    $teacherId = (int) (new TeacherService())->create(array('display_name' => 'Synthetic O Race Teacher', 'email' => 'o-race-' . wp_generate_uuid4() . '@phase-2a2o.invalid'));
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_race'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    }
    (new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_race'));
    $moved = $assignmentService->replace((int) $chain['enrolment_id'], $teacherId, array('expected_assignment_id' => (int) $chain['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'isolated-race-' . $teacherId, 'evidence_at' => gmdate('Y-m-d H:i:s')), $key('isolate'));
    $chain['assignment_id'] = (int) $moved['assignment_id'];
    unset($chain['predecessor_assignment_id']);
    return $chain;
};
$provision = function (int $teacherId) use ($availabilityService, $acceptingService): void {
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_race'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    }
};
$teach = static function (int $enrolmentId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1", $enrolmentId));
};
/**
 * Issue and schedule a synthetic occurrence, then wait until it has ENDED so the production rule
 * ("a final delivery fact requires an ended occurrence") is genuinely satisfied. The schedule is
 * released up front so the chain's dedicated Teacher is never doubly occupied; the modes that race
 * schedule release keep their applicable version.
 */
/** Keep every synthetic interval inside one UTC day (the provisioned full-day availability rules
 *  meet at midnight, where a genuine one-second coverage gap exists). */
$fitLead = static function (int $durationMinutes, int $minLeadSeconds = 2): string {
    $midnight = strtotime('tomorrow UTC');
    $start = time() + $minLeadSeconds;
    if ($start + $durationMinutes * 60 + 5 >= $midnight) $start = $midnight + 30;
    return '@' . $start;
};
$occurred = function (array $chain, string $label, bool $preRelease = true) use ($lessonService, $scheduleService, $wpdb, $p, $evidence, $key, $fitLead): array {
    $lessonId = (int) $lessonService->createStandard((int) $chain['term_id'], (int) $chain['assignment_id'], $evidence($label), $key($label))['lesson_id'];
    $wall = gmdate('Y-m-d H:i:s', strtotime($fitLead(1)));
    $scheduled = $scheduleService->schedule($lessonId, (int) $chain['assignment_id'], array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($wall, 0, 10), 'local_wall_time' => substr($wall, 11), 'duration_minutes' => 1, 'reason_code' => 'synthetic_race_schedule') + $evidence('schedule-' . $label), $key('schedule-' . $label));
    $versionId = (int) $scheduled['schedule_version_id'];
    $starts = (string) $wpdb->get_var($wpdb->prepare("SELECT starts_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", $versionId));
    $ends = (string) $wpdb->get_var($wpdb->prepare("SELECT ends_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", $versionId));
    if ($preRelease) $scheduleService->release($lessonId, array('expected_schedule_version_id' => $versionId, 'reason_code' => 'synthetic_race_release') + $evidence('release-' . $label), $key('release-' . $label));
    while (time() < strtotime($ends . ' UTC') + 2) sleep(1);
    return array('lesson_id' => $lessonId, 'version_id' => $versionId, 'starts_at_utc' => $starts, 'ends_at_utc' => $ends);
};
$outcomeInput = static fn(string $code): array => array('outcome_code' => $code, 'reason_code' => 'synthetic_race_outcome');

$one = $isolate($chain($allocate()));
$provision($teach((int) $one['enrolment_id']));
$state = array('mode' => $mode, 'at' => gmdate('Y-m-d H:i:s'), 'chain' => $one, 'keys' => array('w1' => $key('w1'), 'w2' => $key('w2')),
    'references' => array('w1' => 'race-w1', 'w2' => 'race-w2'), 'hooks' => array('w1' => null, 'w2' => null), 'actions' => array());
// The modes that race the schedule itself keep their applicable version.
$occurrence = $occurred($one, 'race-lesson', !in_array($mode, array('outcome_release', 'outcome_revise'), true));
$state['lesson_id'] = (int) $occurrence['lesson_id'];
$state['expected_version_id'] = (int) $occurrence['version_id'];

switch ($mode) {
    case 'outcome_first':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'teacher_non_delivery'), 'w2' => array('action' => 'complete'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'complete_first':
        $state['actions'] = array('w1' => array('action' => 'complete'), 'w2' => array('action' => 'record', 'code' => 'teacher_non_delivery'));
        $state['hooks']['w1'] = 'dzn_phase_2a2m_after_lesson_transition';
        break;
    case 'outcome_cancel':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'teacher_non_delivery'), 'w2' => array('action' => 'cancel'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'outcome_release':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'release'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'outcome_revise':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'revise'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        $state['revise_wall'] = gmdate('Y-m-d H:i:s', strtotime('+11 days 10:00:00'));
        break;
    case 'outcome_close':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'close'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'outcome_term_close':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'term_close'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'correction_correction':
        $existing = $delivery->record((int) $state['lesson_id'], 'authorised', $outcomeInput('student_no_show') + $evidence('existing'), $key('existing'));
        $state['existing_outcome_id'] = (int) $existing['outcome_id'];
        $state['actions'] = array('w1' => array('action' => 'correct', 'code' => 'interruption'), 'w2' => array('action' => 'correct', 'code' => 'delivered'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    case 'duplicate_assertion':
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'record', 'code' => 'student_no_show'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        $state['keys']['w2'] = $state['keys']['w1'];
        $state['references']['w2'] = $state['references']['w1'];
        break;
    case 'unrelated_lessons':
        $two = $isolate($chain($allocate()));
        $provision($teach((int) $two['enrolment_id']));
        $second = $occurred($two, 'race-lesson-two');
        $state['second_chain'] = $two;
        $state['second_lesson_id'] = (int) $second['lesson_id'];
        $state['actions'] = array('w1' => array('action' => 'record', 'code' => 'student_no_show'), 'w2' => array('action' => 'record', 'code' => 'student_no_show', 'target' => 'second_lesson_id'));
        $state['hooks']['w1'] = 'dzn_phase_2a2o_delivery_locks_held';
        break;
    default:
        throw new RuntimeException('Unknown Phase-O race mode: ' . $mode);
}
update_option('dzn_phase_2a2o_concurrency_state', $state, false);
echo 'prepared=' . $mode . ' lesson=' . $state['lesson_id'] . "\n";
