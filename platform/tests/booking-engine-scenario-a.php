<?php
/**
 * Scenario A — same instrument, five identical Teachers, increasing request volume.
 *
 * Expected (from the implemented per-course cursor): deterministic cyclic order
 * over eligible Teacher ids, with counts differing by at most one.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$suffix = dzn_stress_suffix('scenario-a');
$setar = dzn_stress_course($suffix, 'setar', 'Setar');
$teachers = array();
for ($i = 1; $i <= 5; $i++) {
    $teachers[] = dzn_stress_teacher($suffix, 'Setar Teacher ' . $i, array($setar['course_id']), array('timezone' => 'UTC'));
}
$teacherIds = array_map(static fn(array $t): int => $t['teacher_id'], $teachers);
sort($teacherIds);
$student = dzn_stress_student($suffix, 'Scenario A Student');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$base = $now->modify('+3 days')->setTime(0, 0);

$results = array();
foreach (array(50, 100, 500, 1000) as $scaleIndex => $scale) {
    $blockOffset = array(0, 3000, 30000, 60000)[$scaleIndex];
    $course = $scaleIndex === 0 ? $setar : dzn_stress_course($suffix, 'setar-s' . $scale, 'Setar S' . $scale);
    if ($scaleIndex > 0) {
        foreach ($teacherIds as $teacherId) {
            $wpdb->insert($wpdb->prefix . 'dzn_teacher_course_eligibilities', array(
                'teacher_id' => $teacherId, 'course_id' => $course['course_id'], 'status' => 'active',
                'version' => 1, 'reason_code' => 'synthetic_audit', 'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 1, 'updated_by' => 1,
            ));
        }
    }
    $firstRequest = 0; $lastRequest = 0; $allocated = 0; $referred = 0; $latencies = array(); $errors = array();
    for ($i = 0; $i < $scale; $i++) {
        $start = $base->modify('+' . ($blockOffset + $i * 45) . ' minutes');
        $time = dzn_stress_time($start->format('Y-m-d H:i:s'));
        $request = dzn_stress_request($student, $setar['instrument_id'], $course['course_id'], array($time), $suffix, 'A-' . $scale . '-' . $i);
        if ($firstRequest === 0) $firstRequest = $request['request_id'];
        $lastRequest = $request['request_id'];
        $t0 = microtime(true);
        try {
            $offer = dzn_stress_allocate($request['request_id'], $course['course_id'], dzn_stress_times_for_allocation($request['request_id']));
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            $offer = null;
        }
        $latencies[] = microtime(true) - $t0;
        if ($offer === null) $referred++; else $allocated++;
    }
    $counts = dzn_stress_offer_distribution($course['course_id'], $firstRequest, $lastRequest);
    $fairness = dzn_stress_fairness($counts);
    $sequence = dzn_stress_offer_sequence($course['course_id'], $firstRequest);
    $expected = array();
    for ($i = 0; $i < $scale; $i++) $expected[] = $teacherIds[$i % 5];
    $sequenceMatches = $sequence === $expected;
    dzn_stress_assert($fairness['spread'] <= 1, 'Scenario A scale ' . $scale . ' spread ' . $fairness['spread'] . ' exceeds one');
    dzn_stress_assert($sequenceMatches, 'Scenario A scale ' . $scale . ' sequence diverges from the implemented cyclic order');
    $results['scale_' . $scale] = array(
        'requests' => $scale, 'allocated' => $allocated, 'referred' => $referred,
        'errors' => count($errors), 'distribution' => $counts, 'fairness' => $fairness,
        'sequence_matches_cycle' => $sequenceMatches, 'latency' => dzn_stress_latency($latencies),
    );
}
dzn_stress_emit('A_identical_setar_fairness', array('pass' => true, 'teacher_ids' => $teacherIds, 'results' => $results));
