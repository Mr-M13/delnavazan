<?php
/** Prepare one deterministic gated Phase-N scheduling/authority race on synthetic production-path chains. */
if (getenv('DZN_PHASE_2A2N_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-N concurrency setup refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2N_MODE');
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || count($fixture['sources'] ?? array()) < 2) throw new RuntimeException('Phase-J fixture required');
$enrolmentService = new CanonicalEnrolmentLifecycleService(); $termService = new CanonicalTermAuthorityService();
$assignmentService = new TeacherAssignmentService(); $lessonService = new CanonicalLessonAuthorityService();
$scheduleService = new CanonicalLessonScheduleService(); $availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$evidence = static fn(string $reference): array => array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s'));
$key = static fn(string $label): string => 'dzn-2a2n-race-' . $label . '-' . wp_generate_uuid4();
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
    return array('enrolment_id' => $enrolmentId, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$provision = function (int $teacherId) use ($availabilityService, $acceptingService): void {
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_race'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_race'));
    }
};
$lesson = static function (array $chain, string $label) use ($lessonService, $evidence, $key): int {
    return (int) $lessonService->createStandard($chain['term_id'], $chain['assignment_id'], $evidence($label), $key($label))['lesson_id'];
};
$teach = static function (int $enrolmentId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1", $enrolmentId));
};
// Each mode owns a distinct day so sequential matrix runs cannot interfere through Teacher occupancy.
$order = array('capacity_first','capacity_prepared','same_key','different_keys','revise_stale','revise_release','buffer_adjacency','unrelated_teachers','availability_race','override_race','schedule_pause','pause_schedule','schedule_close','close_schedule','schedule_term_close','term_close_schedule','schedule_term_cancel','term_cancel_schedule','schedule_complete','complete_schedule','schedule_cancel','cancel_schedule','schedule_replace','replace_schedule','schedule_archive','archive_schedule');
$offset = array_search($mode, $order, true);
$date = gmdate('Y-m-d', strtotime('+' . (7 + ($offset === false ? 0 : (int) $offset)) . ' days'));
$slot = static fn(string $time, ?int $duration = null): array => array('schedule_timezone' => 'UTC', 'local_wall_date' => $date, 'local_wall_time' => $time) + ($duration === null ? array() : array('duration_minutes' => $duration));

$one = $chain($allocate());
$teacherOne = $teach($one['enrolment_id']);
$provision($teacherOne);
$state = array('mode' => $mode, 'at' => gmdate('Y-m-d H:i:s'), 'date' => $date, 'chain' => $one, 'teacher_id' => $teacherOne,
    'keys' => array('w1' => $key('w1'), 'w2' => $key('w2')), 'references' => array('w1' => 'race-w1', 'w2' => 'race-w2'),
    'slots' => array('w1' => $slot('10:00:00'), 'w2' => $slot('10:00:00')));

switch ($mode) {
    case 'capacity_first':
        $state['lesson_id'] = $lesson($one, 'l1'); $state['second_lesson_id'] = $lesson($one, 'l2');
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array('w1' => $slot('10:00:00'), 'w2' => $slot('10:15:00'));
        break;
    case 'capacity_prepared':
        $state['lesson_id'] = $lesson($one, 'l1'); $state['second_lesson_id'] = $lesson($one, 'l2');
        $existing = $scheduleService->schedule($state['lesson_id'], $one['assignment_id'], array('reason_code' => 'synthetic_race') + $slot('10:00:00') + $evidence('existing'), $key('existing'));
        $state['expected_version_id'] = (int) $existing['schedule_version_id'];
        $state['actions'] = array('w1' => 'revise', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array('w1' => $slot('11:00:00'), 'w2' => $slot('11:15:00'));
        break;
    case 'same_key':
        $state['lesson_id'] = $lesson($one, 'l1');
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
        $state['keys']['w2'] = $state['keys']['w1']; $state['references']['w2'] = $state['references']['w1'];
        break;
    case 'different_keys':
        $state['lesson_id'] = $lesson($one, 'l1');
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
        break;
    case 'revise_stale':
        $state['lesson_id'] = $lesson($one, 'l1');
        $existing = $scheduleService->schedule($state['lesson_id'], $one['assignment_id'], array('reason_code' => 'synthetic_race') + $slot('10:00:00') + $evidence('existing'), $key('existing'));
        $state['expected_version_id'] = (int) $existing['schedule_version_id'];
        $state['actions'] = array('w1' => 'revise', 'w2' => 'revise');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
        $state['slots'] = array('w1' => $slot('11:00:00'), 'w2' => $slot('12:00:00'));
        break;
    case 'revise_release':
        $state['lesson_id'] = $lesson($one, 'l1');
        $existing = $scheduleService->schedule($state['lesson_id'], $one['assignment_id'], array('reason_code' => 'synthetic_race') + $slot('10:00:00') + $evidence('existing'), $key('existing'));
        $state['expected_version_id'] = (int) $existing['schedule_version_id'];
        $state['actions'] = array('w1' => 'revise', 'w2' => 'release');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
        $state['slots'] = array('w1' => $slot('11:00:00'), 'w2' => null);
        break;
    case 'buffer_adjacency':
        $state['lesson_id'] = $lesson($one, 'l1'); $state['second_lesson_id'] = $lesson($one, 'l2');
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array('w1' => $slot('10:00:00'), 'w2' => $slot('10:45:00'));
        break;
    case 'unrelated_teachers':
        $state['second_chain'] = $chain($allocate());
        $teacherTwo = (int) $fixture['teachers'][1];
        $provision($teacherTwo);
        $moved = $assignmentService->replace($state['second_chain']['enrolment_id'], $teacherTwo, array('expected_assignment_id' => $state['second_chain']['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'race-second-teacher', 'evidence_at' => gmdate('Y-m-d H:i:s')), $key('second-teacher'));
        $state['second_chain']['assignment_id'] = (int) $moved['assignment_id'];
        $state['lesson_id'] = $lesson($one, 'l1');
        $state['second_lesson_id'] = (int) $lessonService->createStandard($state['second_chain']['term_id'], $state['second_chain']['assignment_id'], $evidence('l2'), $key('l2'))['lesson_id'];
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array('w1' => $slot('10:00:00'), 'w2' => $slot('10:00:00'));
        break;
    case 'availability_race':
        // Worker 1 holds an available day; worker 2 contends for a day blocked by an exception.
        $state['lesson_id'] = $lesson($one, 'l1'); $state['second_lesson_id'] = $lesson($one, 'l2');
        $availabilityService->setDatedException(array('teacher_id' => $teacherOne, 'timezone' => 'UTC', 'local_date' => $date, 'all_day' => 1, 'state' => 'blocked', 'status' => 'active', 'reason_code' => 'synthetic_race_block'));
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array(
            'w1' => array('schedule_timezone' => 'UTC', 'local_wall_date' => gmdate('Y-m-d', strtotime($date . ' +1 day')), 'local_wall_time' => '10:00:00'),
            'w2' => array('schedule_timezone' => 'UTC', 'local_wall_date' => $date, 'local_wall_time' => '10:00:00'),
        );
        break;
    case 'override_race':
        $state['lesson_id'] = $lesson($one, 'l1'); $state['second_lesson_id'] = $lesson($one, 'l2');
        // A dedicated Teacher keeps the narrow availability window isolated from the shared fixture Teacher.
        $narrowTeacher = (int) (new TeacherService())->create(array('display_name' => 'Synthetic N Narrow Window', 'email' => 'n-narrow-' . wp_generate_uuid4() . '@phase-2a2n.invalid'));
        $acceptingService->set(array('teacher_id' => $narrowTeacher, 'state' => 'accepting', 'reason_code' => 'synthetic_race'));
        $availabilityService->setProfile(array('teacher_id' => $narrowTeacher, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_race'));
        $availabilityService->setRecurringRule(array('teacher_id' => $narrowTeacher, 'timezone' => 'UTC', 'weekday' => (int) gmdate('N', strtotime($date)), 'local_start_time' => '08:00:00', 'local_end_time' => '12:00:00', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_race'));
        $movedNarrow = $assignmentService->replace($one['enrolment_id'], $narrowTeacher, array('expected_assignment_id' => $one['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'race-narrow-teacher', 'evidence_at' => gmdate('Y-m-d H:i:s')), $key('narrow-teacher'));
        $state['chain']['assignment_id'] = (int) $movedNarrow['assignment_id'];
        $state['lesson_id'] = (int) $lessonService->createStandard($one['term_id'], (int) $movedNarrow['assignment_id'], $evidence('l1'), $key('l1'))['lesson_id'];
        $state['second_lesson_id'] = (int) $lessonService->createStandard($one['term_id'], (int) $movedNarrow['assignment_id'], $evidence('l2'), $key('l2'))['lesson_id'];
        $state['teacher_id'] = $narrowTeacher; $teacherOne = $narrowTeacher;
        $state['actions'] = array('w1' => 'schedule', 'w2' => 'schedule_override');
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'second_lesson_id');
        $state['slots'] = array('w1' => $slot('13:00:00'), 'w2' => $slot('13:00:00'));
        break;
    default:
        $pairs = array(
            'schedule_pause' => array('schedule', 'pause'), 'pause_schedule' => array('pause', 'schedule'),
            'schedule_close' => array('schedule', 'close'), 'close_schedule' => array('close', 'schedule'),
            'schedule_term_close' => array('schedule', 'term_close'), 'term_close_schedule' => array('term_close', 'schedule'),
            'schedule_term_cancel' => array('schedule', 'term_cancel'), 'term_cancel_schedule' => array('term_cancel', 'schedule'),
            'schedule_complete' => array('schedule', 'complete'), 'complete_schedule' => array('complete', 'schedule'),
            'schedule_cancel' => array('schedule', 'cancel'), 'cancel_schedule' => array('cancel', 'schedule'),
            'schedule_replace' => array('schedule', 'replace'), 'replace_schedule' => array('replace', 'schedule'),
            'schedule_archive' => array('schedule', 'archive'), 'archive_schedule' => array('archive', 'schedule'),
        );
        if (!isset($pairs[$mode])) throw new RuntimeException('Unknown race mode: ' . $mode);
        $ordered = $pairs[$mode];
        $lessonId = $lesson($one, 'l1');
        $state['lesson_id'] = $lessonId;
        $state['actions'] = array('w1' => $ordered[0], 'w2' => $ordered[1]);
        $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
        $state['slots'] = array('w1' => $slot('10:00:00'), 'w2' => $slot('10:00:00'));
        if (in_array('replace', $ordered, true)) { $state['new_teacher_id'] = (int) $fixture['teachers'][1]; $provision((int) $fixture['teachers'][1]); }
        if ($mode === 'close_schedule') {
            // Close-first must genuinely permit Enrolment closure: terminal Lesson, closed Term, ended Assignment.
            $lessonService->complete($lessonId, 'authorised', $evidence('pre-complete'), $key('pre-complete'));
            $termService->close($one['term_id'], 'current', $evidence('pre-term-close'), $key('pre-term-close'));
            $assignmentService->end($one['enrolment_id'], array('expected_assignment_id' => $one['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'race-pre-end', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), $key('pre-end'));
        }
        if ($mode === 'schedule_close') { $state['close_expected'] = 'current'; }
        $state['archive_teacher_id'] = $teacherOne;
        if ($mode === 'archive_schedule') {
            // A dedicated Teacher keeps archiving reachable: other fixture Enrolments share the default Teacher.
            $archivalTeacher = (int) (new TeacherService())->create(array('display_name' => 'Synthetic N Archival Race', 'email' => 'n-race-archival-' . wp_generate_uuid4() . '@phase-2a2n.invalid'));
            $movedArchival = $assignmentService->replace($one['enrolment_id'], $archivalTeacher, array('expected_assignment_id' => $one['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'race-archival-teacher', 'evidence_at' => gmdate('Y-m-d H:i:s')), $key('archival-teacher'));
            $state['chain']['assignment_id'] = (int) $movedArchival['assignment_id'];
            $state['lesson_id'] = (int) $lessonService->createStandard($one['term_id'], (int) $movedArchival['assignment_id'], $evidence('l1'), $key('l1'))['lesson_id'];
            $state['targets'] = array('w1' => 'lesson_id', 'w2' => 'lesson_id');
            $assignmentService->end($one['enrolment_id'], array('expected_assignment_id' => (int) $movedArchival['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'race-pre-end', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), $key('pre-end'));
            $state['archive_teacher_id'] = $archivalTeacher;
        }
        break;
}
update_option('dzn_phase_2a2n_concurrency_state', $state, false);
echo 'mode=' . $mode . ' teacher=' . $teacherOne . " state=" . wp_json_encode(array('mode' => $mode, 'actions' => $state['actions'] ?? null, 'targets' => $state['targets'] ?? null)) . "\n";
