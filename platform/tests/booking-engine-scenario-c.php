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
// Measured on the disposable stack: min 37, max 125, spread 88, Gini 0.1832
// against the audited per-instrument baseline of min 27, max 143, spread 116,
// Gini 0.297.
//
// The residual spread is structural, not algorithmic. 80 of the 500 requests are
// Santur and exactly one Teacher is eligible for Santur, so that Teacher carries
// a hard floor of 80 whatever the ordering does. A greedy least-loaded policy
// cannot pre-empt a bottleneck that only appears in a later phase of the stream,
// so an absolute spread target here would demand foresight the policy does not
// have. The assertions below therefore pin (a) improvement over the audited
// defect and (b) the cause of the maximum, instead of an unachievable constant.
dzn_stress_assert($globalFairness['gini'] < 0.297, 'Cross-instrument Gini must improve on the audited 0.297 baseline: ' . wp_json_encode($globalFairness));
dzn_stress_assert($globalFairness['spread'] < 116, 'Cross-instrument spread must improve on the audited 116 baseline: ' . wp_json_encode($globalFairness));

// The heaviest Teacher must be the one eligibility forces. If a Teacher with an
// interchangeable portfolio were heaviest, the least-loaded ordering would be at
// fault and this assertion names that instead of hiding it in a spread margin.
$santurTeachers = array();
foreach ($roles as $id => $role) if ($role === 'all_three') $santurTeachers[] = $id;
$heaviest = array_keys($perTeacherTotal, max($perTeacherTotal), true);
dzn_stress_assert(count($santurTeachers) === 1 && $heaviest === $santurTeachers, 'The heaviest Teacher must be the sole Teacher eligible for the scarcest instrument, otherwise the ordering rather than eligibility caused the imbalance: ' . wp_json_encode(array('heaviest' => $heaviest, 'santur_eligible' => $santurTeachers, 'totals' => $perTeacherTotal)));

// Within one interchangeable portfolio the least-loaded rule is the only thing
// deciding, so totals must stay within one booking. This is the property the
// ordering actually controls, and it is asserted per portfolio rather than being
// diluted into the global spread.
$portfolioFairness = array();
foreach (array('tar_only', 'setar_only', 'tar_setar') as $portfolio) {
    $totals = array();
    foreach ($roles as $id => $role) if ($role === $portfolio) $totals[$id] = $perTeacherTotal[$id];
    $portfolioFairness[$portfolio] = dzn_stress_fairness($totals);
    dzn_stress_assert($portfolioFairness[$portfolio]['spread'] <= 1, 'Teachers sharing the ' . $portfolio . ' portfolio must stay within one booking: ' . wp_json_encode($portfolioFairness[$portfolio]));
}
dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Scenario C produced a Teacher double booking');
dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Scenario C produced a capacity violation (confirmed or live-offer overlap)');

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
    'policy' => array(
        'weights' => Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::WEIGHTS,
        'completed_window_days' => Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::COMPLETED_WINDOW_DAYS,
        'order' => 'match strength, then weighted cross-instrument workload, then per-course cursor round-robin',
    ),
    'accepted' => $accepted, 'referred' => $referred, 'errors' => $errors,
    'accept_errors' => $acceptErrors,
    'per_teacher' => $rowsOut,
    'baseline' => array('min' => 27, 'max' => 143, 'spread' => 116, 'gini' => 0.297, 'source' => 'platform/docs/BOOKING-ENGINE-STRESS-AUDIT-20261115.md'),
    'fairness_structural' => array(
        'santur_requests' => $plan['santur']['count'],
        'santur_eligible_teachers' => count($santurTeachers),
        'forced_floor' => $plan['santur']['count'],
        'explanation' => 'santur is servable by exactly one Teacher, so the maximum cannot fall below the santur request count',
    ),
    'fairness_per_portfolio' => $portfolioFairness,
    'fairness_per_instrument' => $perInstrumentFairness,
    'fairness_global_workload' => $globalFairness,
    'double_bookings' => dzn_stress_teacher_double_bookings(),
    'capacity_violations' => dzn_stress_capacity_violations(),
    'allocation_latency' => dzn_stress_latency($latencies),
    'acceptance_latency' => dzn_stress_latency($acceptLatencies),
));
