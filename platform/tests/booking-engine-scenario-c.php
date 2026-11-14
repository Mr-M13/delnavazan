<?php
/**
 * Scenario C — mixed-instrument pressure and cross-portfolio workload.
 *
 * Ten Teachers with overlapping capabilities receive 500 introductory requests
 * unevenly split across Tar, Setar and Santur. Every offer is accepted through
 * the real response authority so confirmed lessons can be measured too.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$suffix = dzn_stress_suffix('scenario-c');
$tar = dzn_stress_course($suffix, 'tar-c', 'Tar');
$setar = dzn_stress_course($suffix, 'setar-c', 'Setar');
$santur = dzn_stress_course($suffix, 'santur-c', 'Santur');
$student = dzn_stress_student($suffix, 'Scenario C Student');
$base = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 days')->setTime(0, 0);

$teachers = array();
$roles = array();
for ($i = 1; $i <= 4; $i++) { $t = dzn_stress_teacher($suffix, 'C Tar ' . $i, array($tar['course_id'])); $teachers[$t['teacher_id']] = $t; $roles[$t['teacher_id']] = 'tar_only'; }
for ($i = 1; $i <= 3; $i++) { $t = dzn_stress_teacher($suffix, 'C Setar ' . $i, array($setar['course_id'])); $teachers[$t['teacher_id']] = $t; $roles[$t['teacher_id']] = 'setar_only'; }
for ($i = 1; $i <= 2; $i++) { $t = dzn_stress_teacher($suffix, 'C TarSetar ' . $i, array($tar['course_id'], $setar['course_id'])); $teachers[$t['teacher_id']] = $t; $roles[$t['teacher_id']] = 'tar_setar'; }
$t = dzn_stress_teacher($suffix, 'C AllThree', array($tar['course_id'], $setar['course_id'], $santur['course_id'])); $teachers[$t['teacher_id']] = $t; $roles[$t['teacher_id']] = 'all_three';

$plan = array(
    'tar' => array('course' => $tar, 'count' => 260, 'offset' => 0, 'step' => 45),
    'setar' => array('course' => $setar, 'count' => 160, 'offset' => 12000, 'step' => 45),
    'santur' => array('course' => $santur, 'count' => 80, 'offset' => 20000, 'step' => 45),
);
$perTeacherInstrument = array();
$perTeacherTotal = array();
foreach (array_keys($teachers) as $id) { $perTeacherInstrument[$id] = array('tar' => 0, 'setar' => 0, 'santur' => 0); $perTeacherTotal[$id] = 0; }
$referred = 0; $errors = 0; $accepted = 0; $acceptErrors = 0; $latencies = array(); $acceptLatencies = array();
$firstIds = array(); $lastIds = array();
foreach ($plan as $key => $spec) {
    $course = $spec['course'];
    for ($i = 0; $i < $spec['count']; $i++) {
        $start = $base->modify('+' . ($spec['offset'] + $i * $spec['step']) . ' minutes');
        $time = dzn_stress_time($start->format('Y-m-d H:i:s'));
        $request = dzn_stress_request($student, $course['instrument_id'], $course['course_id'], array($time), $suffix, 'C-' . $key . '-' . $i);
        if (!isset($firstIds[$key])) $firstIds[$key] = $request['request_id'];
        $lastIds[$key] = $request['request_id'];
        $t0 = microtime(true);
        try {
            $offer = dzn_stress_allocate($request['request_id'], $course['course_id'], dzn_stress_times_for_allocation($request['request_id']));
        } catch (Throwable $error) {
            $errors++; $offer = null;
        }
        $latencies[] = microtime(true) - $t0;
        if (!$offer) { $referred++; continue; }
        $teacherId = (int) $offer['teacher_id'];
        $perTeacherInstrument[$teacherId][$key]++;
        $perTeacherTotal[$teacherId]++;
        // Accept immediately through the real Teacher-owned authority.
        $delivery = dzn_stress_delivery($request['request_id']);
        if (!$delivery) { $acceptErrors++; continue; }
        $t1 = microtime(true);
        try {
            dzn_stress_accept((int) $teachers[$teacherId]['user_id'], (string) $delivery->uid, $suffix . '-accept-' . $request['request_id']);
            $accepted++;
        } catch (Throwable $error) {
            $acceptErrors++;
        }
        $acceptLatencies[] = microtime(true) - $t1;
    }
}

$perInstrumentFairness = array();
foreach ($plan as $key => $spec) {
    $counts = array();
    foreach ($teachers as $id => $unused) if ($perTeacherInstrument[$id][$key] > 0 || $roles[$id] === 'all_three' || ($key === 'tar' && in_array($roles[$id], array('tar_only', 'tar_setar'), true)) || ($key === 'setar' && in_array($roles[$id], array('setar_only', 'tar_setar'), true))) $counts[$id] = $perTeacherInstrument[$id][$key];
    $perInstrumentFairness[$key] = dzn_stress_fairness($counts);
}
$globalFairness = dzn_stress_fairness($perTeacherTotal);
$confirmed = array();
$placeholders = implode(',', array_fill(0, count($teachers), '%d'));
$rows = (array) $wpdb->get_results($wpdb->prepare(
    "SELECT c.teacher_id,COUNT(*) total FROM {$wpdb->prefix}dzn_booking_request_confirmations c WHERE c.state NOT IN ('failed','cancelled') AND c.teacher_id IN ($placeholders) GROUP BY c.teacher_id",
    array_keys($teachers)
), ARRAY_A);
foreach ($rows as $row) $confirmed[(int) $row['teacher_id']] = (int) $row['total'];

dzn_stress_assert($errors === 0, 'Scenario C allocation errors: ' . $errors);
dzn_stress_assert($referred === 0, 'Scenario C unexpected referrals: ' . $referred);
dzn_stress_assert($acceptErrors === 0, 'Scenario C acceptance errors: ' . $acceptErrors);
dzn_stress_assert($globalFairness['spread'] > 1, 'The per-instrument policy is expected to produce unequal total workload across portfolios');
dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Scenario C produced a Teacher double booking');
dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Scenario C produced overlapping confirmations');

$rowsOut = array();
foreach ($teachers as $id => $teacher) {
    $rowsOut[] = array(
        'teacher_id' => $id, 'role' => $roles[$id],
        'tar' => $perTeacherInstrument[$id]['tar'],
        'setar' => $perTeacherInstrument[$id]['setar'],
        'santur' => $perTeacherInstrument[$id]['santur'],
        'total_offers' => $perTeacherTotal[$id],
        'confirmed' => $confirmed[$id] ?? 0,
    );
}
dzn_stress_emit('C_mixed_instrument_pressure', array(
    'pass' => true,
    'requests' => array('tar' => 260, 'setar' => 160, 'santur' => 80, 'total' => 500),
    'accepted' => $accepted, 'referred' => $referred, 'errors' => $errors,
    'accept_errors' => $acceptErrors,
    'per_teacher' => $rowsOut,
    'fairness_per_instrument' => $perInstrumentFairness,
    'fairness_global_workload' => $globalFairness,
    'double_bookings' => dzn_stress_teacher_double_bookings(),
    'capacity_violations' => dzn_stress_capacity_violations(),
    'allocation_latency' => dzn_stress_latency($latencies),
    'acceptance_latency' => dzn_stress_latency($acceptLatencies),
));
