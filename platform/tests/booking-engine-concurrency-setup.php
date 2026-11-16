<?php
/**
 * Build isolated concurrency fixtures for submission, acceptance and idempotency.
 *
 * State is stored in a WordPress option and each level has its own Course,
 * Teacher, students and timeslot so levels cannot interfere.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$suffix = dzn_stress_suffix('concurrency');
$levels = array(2, 5, 10, 25, 50, 100);
$base = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+12 days')->setTime(0, 0);
$state = array('suffix' => $suffix, 'levels' => array(), 'idempotency' => null);

$makeTeacher = static function (int $courseId, string $label) use ($suffix): array {
    return dzn_stress_teacher($suffix, $label, array($courseId), array('timezone' => 'UTC'));
};

foreach ($levels as $levelIndex => $level) {
    $offsetDays = $levelIndex * 3;
    // Submission fixture.
    $course = dzn_stress_course($suffix, 'conc-sub-' . $level, 'Conc Sub ' . $level);
    $teacher = $makeTeacher($course['course_id'], 'Conc Sub Teacher ' . $level);
    $start = $base->modify('+' . $offsetDays . ' days')->setTime(10, 0)->format('Y-m-d H:i:s');
    $time = dzn_stress_time($start, 30, 15);
    $students = array(); $keys = array(); $inputs = array();
    for ($i = 0; $i < $level; $i++) {
        $students[$i] = dzn_stress_student($suffix, 'Conc Sub ' . $level . ' S' . $i);
        $keys[$i] = $suffix . '-sub-' . $level . '-' . $i;
        $inputs[$i] = array(
            'requested_instrument_id' => $course['instrument_id'],
            'selected_intro_course_id' => $course['course_id'],
            'full_name' => 'Synthetic Concurrency ' . $level . ' ' . $i,
            'email' => 'conc-' . $suffix . '-' . $level . '-' . $i . '@example.invalid',
            'mobile' => '+6140000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
            'communication_language' => 'en', 'whatsapp_same_as_mobile' => true,
            'whatsapp_number' => '', 'privacy_notice_accepted' => true,
            'privacy_notice_version' => '2026-09-05',
            'requested_times' => array(array('local_date' => substr($start, 0, 10), 'local_start_time' => '10:00', 'timezone' => 'UTC')),
        );
    }
    $state['levels']['submit_' . $level] = array(
        'level' => $level, 'course_id' => $course['course_id'], 'instrument_id' => $course['instrument_id'],
        'teacher_id' => $teacher['teacher_id'], 'teacher_user_id' => $teacher['user_id'],
        'start_utc' => $time['starts_at_utc'], 'occupied_end_utc' => $time['occupied_ends_at_utc'],
        'student_ids' => $students, 'keys' => $keys, 'inputs' => $inputs,
    );

    // Acceptance fixture: fault-injected multiple pending offers to the same Teacher/time.
    $acceptCourse = dzn_stress_course($suffix, 'conc-acc-' . $level, 'Conc Acc ' . $level);
    $acceptTeacher = $makeTeacher($acceptCourse['course_id'], 'Conc Acc Teacher ' . $level);
    $acceptStart = $base->modify('+' . ($offsetDays + 1) . ' days')->setTime(10, 0)->format('Y-m-d H:i:s');
    $acceptTime = dzn_stress_time($acceptStart, 30, 15);
    $acceptRequests = array();
    for ($i = 0; $i < $level; $i++) {
        $student = dzn_stress_student($suffix, 'Conc Acc ' . $level . ' S' . $i);
        $request = dzn_stress_request($student, $acceptCourse['instrument_id'], $acceptCourse['course_id'], array($acceptTime), $suffix, 'conc-acc-' . $level . '-' . $i);
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($p . 'booking_request_teacher_deliveries', array(
            'uid' => Delnavazan\Platform\Core\Support\Identifier::uid(),
            'booking_request_id' => $request['request_id'], 'requested_time_id' => $request['time_ids'][0],
            'teacher_id' => $acceptTeacher['teacher_id'], 'state' => 'pending_request', 'match_strength' => 'strong',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $deliveryId = (int) $wpdb->insert_id;
        $deliveryUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}booking_request_teacher_deliveries WHERE id=%d", $deliveryId));
        $wpdb->insert($p . 'booking_allocation_offers', array(
            'booking_request_id' => $request['request_id'], 'delivery_id' => $deliveryId,
            'teacher_id' => $acceptTeacher['teacher_id'], 'requested_time_id' => $request['time_ids'][0],
            'offer_sequence' => 1, 'state' => 'pending', 'active_slot' => 1, 'match_strength' => 'strong',
            'offered_at' => $now, 'deadline_at' => gmdate('Y-m-d H:i:s', strtotime($now . ' UTC +24 hours')),
            'responded_at' => null, 'reason_code' => null,
        ));
        $acceptRequests[$i] = array('request_id' => $request['request_id'], 'delivery_uid' => $deliveryUid, 'key' => $suffix . '-acc-' . $level . '-' . $i);
    }
    $state['levels']['accept_' . $level] = array(
        'level' => $level, 'course_id' => $acceptCourse['course_id'], 'instrument_id' => $acceptCourse['instrument_id'],
        'teacher_id' => $acceptTeacher['teacher_id'], 'teacher_user_id' => $acceptTeacher['user_id'],
        'start_utc' => $acceptTime['starts_at_utc'], 'occupied_end_utc' => $acceptTime['occupied_ends_at_utc'],
        'requests' => $acceptRequests,
    );
}

// Idempotent replay of one public submission under one key.
$idemCourse = dzn_stress_course($suffix, 'conc-idem', 'Conc Idem');
$idemTeacher = $makeTeacher($idemCourse['course_id'], 'Conc Idem Teacher');
$idemStart = $base->modify('+30 days')->setTime(10, 0)->format('Y-m-d H:i:s');
$idemStudent = dzn_stress_student($suffix, 'Conc Idem Student');
$state['idempotency'] = array(
    'level' => 25, 'course_id' => $idemCourse['course_id'], 'instrument_id' => $idemCourse['instrument_id'],
    'teacher_id' => $idemTeacher['teacher_id'], 'teacher_user_id' => $idemTeacher['user_id'],
    'student_id' => $idemStudent, 'key' => $suffix . '-idem-key',
    'input' => array(
        'requested_instrument_id' => $idemCourse['instrument_id'],
        'selected_intro_course_id' => $idemCourse['course_id'],
        'full_name' => 'Synthetic Idempotent Student',
        'email' => 'idem-' . $suffix . '@example.invalid',
        'mobile' => '+61400999999', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '',
        'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
        'requested_times' => array(array('local_date' => substr($idemStart, 0, 10), 'local_start_time' => '10:00', 'timezone' => 'UTC')),
    ),
);


// Partially overlapping simultaneous submissions: two starts 15 minutes apart.
$partialCourse = dzn_stress_course($suffix, 'conc-partial', 'Conc Partial');
$partialTeacher = $makeTeacher($partialCourse['course_id'], 'Conc Partial Teacher');
$partialDay = $base->modify('+27 days')->format('Y-m-d');
$partialStudents = array(); $partialKeys = array(); $partialInputs = array();
for ($i = 0; $i < 25; $i++) {
    $clock = $i % 2 === 0 ? '10:00' : '10:15';
    $partialStudents[$i] = dzn_stress_student($suffix, 'Conc Partial S' . $i);
    $partialKeys[$i] = $suffix . '-partial-' . $i;
    $partialInputs[$i] = array(
        'requested_instrument_id' => $partialCourse['instrument_id'],
        'selected_intro_course_id' => $partialCourse['course_id'],
        'full_name' => 'Synthetic Partial ' . $i,
        'email' => 'partial-' . $suffix . '-' . $i . '@example.invalid',
        'mobile' => '+6140001' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => true,
        'whatsapp_number' => '', 'privacy_notice_accepted' => true,
        'privacy_notice_version' => '2026-09-05',
        'requested_times' => array(array('local_date' => $partialDay, 'local_start_time' => $clock, 'timezone' => 'UTC')),
    );
}
$state['partial_25'] = array(
    'level' => 25, 'course_id' => $partialCourse['course_id'], 'instrument_id' => $partialCourse['instrument_id'],
    'teacher_id' => $partialTeacher['teacher_id'], 'teacher_user_id' => $partialTeacher['user_id'],
    'student_ids' => $partialStudents, 'keys' => $partialKeys, 'inputs' => $partialInputs,
);


// Crash-recovery fixture: five simultaneous submitters, one of which is killed.
$crashCourse = dzn_stress_course($suffix, 'conc-crash', 'Conc Crash');
$crashTeacher = $makeTeacher($crashCourse['course_id'], 'Conc Crash Teacher');
$crashStart = $base->modify('+29 days')->setTime(10, 0)->format('Y-m-d H:i:s');
$crashTime = dzn_stress_time($crashStart, 30, 15);
$crashStudents = array(); $crashKeys = array(); $crashInputs = array();
for ($i = 0; $i < 5; $i++) {
    $crashStudents[$i] = dzn_stress_student($suffix, 'Conc Crash S' . $i);
    $crashKeys[$i] = $suffix . '-crash-' . $i;
    $crashInputs[$i] = array(
        'requested_instrument_id' => $crashCourse['instrument_id'],
        'selected_intro_course_id' => $crashCourse['course_id'],
        'full_name' => 'Synthetic Crash ' . $i,
        'email' => 'crash-' . $suffix . '-' . $i . '@example.invalid',
        'mobile' => '+6140002' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => true,
        'whatsapp_number' => '', 'privacy_notice_accepted' => true,
        'privacy_notice_version' => '2026-09-05',
        'requested_times' => array(array('local_date' => substr($crashStart, 0, 10), 'local_start_time' => '10:00', 'timezone' => 'UTC')),
    );
}
$state['crash_5'] = array(
    'level' => 5, 'course_id' => $crashCourse['course_id'], 'instrument_id' => $crashCourse['instrument_id'],
    'teacher_id' => $crashTeacher['teacher_id'], 'teacher_user_id' => $crashTeacher['user_id'],
    'start_utc' => $crashTime['starts_at_utc'], 'occupied_end_utc' => $crashTime['occupied_ends_at_utc'],
    'student_ids' => $crashStudents, 'keys' => $crashKeys, 'inputs' => $crashInputs,
);

// Two-worker processing fixture: an expired offer must advance exactly once.
$processCourse = dzn_stress_course($suffix, 'conc-process', 'Conc Process');
$processA = $makeTeacher($processCourse['course_id'], 'Conc Process A');
$processB = $makeTeacher($processCourse['course_id'], 'Conc Process B');
$processStudent = dzn_stress_student($suffix, 'Conc Process Student');
$processStart = $base->modify('+31 days')->setTime(10, 0)->format('Y-m-d H:i:s');
$processTime = dzn_stress_time($processStart, 30, 15);
$processRequest = dzn_stress_request($processStudent, $processCourse['instrument_id'], $processCourse['course_id'], array($processTime), $suffix, 'conc-process');
$processOffer = dzn_stress_allocate($processRequest['request_id'], $processCourse['course_id'], dzn_stress_times_for_allocation($processRequest['request_id']));
if (!$processOffer) throw new RuntimeException('Process fixture allocation failed');
$wpdb->update($p . 'booking_allocation_offers', array('deadline_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('booking_request_id' => $processRequest['request_id'], 'active_slot' => 1));
$state['process_2'] = array(
    'level' => 2, 'course_id' => $processCourse['course_id'], 'instrument_id' => $processCourse['instrument_id'],
    'teacher_ids' => array($processA['teacher_id'], $processB['teacher_id']),
    'request_id' => $processRequest['request_id'], 'start_utc' => $processTime['starts_at_utc'],
);

update_option('dzn_booking_stress_concurrency_v1', $state, false);
echo 'AUDIT_RESULT ' . wp_json_encode(array('scenario' => 'concurrency_setup', 'pass' => true, 'suffix' => $suffix, 'levels' => $levels, 'submission_students' => array_sum($levels), 'acceptance_requests' => array_sum($levels))) . "\n";
