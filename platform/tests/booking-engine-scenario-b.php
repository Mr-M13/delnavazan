<?php
/**
 * Scenario B — multi-instrument Teachers and the scope of workload fairness.
 *
 * Allocation now equalises weighted workload across every introductory
 * instrument, so a Teacher who teaches Tar and Setar does not receive one
 * independent turn per instrument. This scenario proves the new scope: an idle
 * specialist is preferred over a multi-instrument Teacher who has just been
 * offered (or confirmed) work, while the total spread stays within one booking.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

$suffix = dzn_stress_suffix('scenario-b');
$tar = dzn_stress_course($suffix, 'tar-b', 'Tar');
$setar = dzn_stress_course($suffix, 'setar-b', 'Setar');
$student = dzn_stress_student($suffix, 'Scenario B Student');
$base = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+4 days')->setTime(0, 0);
$now = gmdate('Y-m-d H:i:s');

$run = static function (string $label, bool $multiFirst) use ($suffix, $tar, $setar, $student, $base, $now): array {
    $ids = array();
    if ($multiFirst) {
        $a = dzn_stress_teacher($suffix, $label . ' A both', array($tar['course_id'], $setar['course_id']), array('timezone' => 'UTC'));
        $b = dzn_stress_teacher($suffix, $label . ' B setar', array($setar['course_id']), array('timezone' => 'UTC'));
        $c = dzn_stress_teacher($suffix, $label . ' C tar', array($tar['course_id']), array('timezone' => 'UTC'));
    } else {
        $b = dzn_stress_teacher($suffix, $label . ' B setar', array($setar['course_id']), array('timezone' => 'UTC'));
        $c = dzn_stress_teacher($suffix, $label . ' C tar', array($tar['course_id']), array('timezone' => 'UTC'));
        $a = dzn_stress_teacher($suffix, $label . ' A both', array($tar['course_id'], $setar['course_id']), array('timezone' => 'UTC'));
    }
    $sequence = array();
    $alloc = static function (array $course, int $slot, string $tag) use ($student, $suffix, $tar, $setar, $base): array {
        $start = $base->modify('+' . ($slot * 600) . ' minutes');
        $time = dzn_stress_time($start->format('Y-m-d H:i:s'));
        $request = dzn_stress_request($student, $course['instrument_id'], $course['course_id'], array($time), $suffix, $tag);
        $offer = dzn_stress_allocate($request['request_id'], $course['course_id'], dzn_stress_times_for_allocation($request['request_id']));
        return array('request_id' => $request['request_id'], 'teacher_id' => $offer['teacher_id'] ?? null);
    };
    // Sequence 1: Tar then Setar.
    $t1 = $alloc($tar, 0, $label . ' tar1');
    $s1 = $alloc($setar, 1, $label . ' setar1');
    // Sequence 2: another Tar then Setar after the cursors have advanced once.
    $t2 = $alloc($tar, 2, $label . ' tar2');
    $s2 = $alloc($setar, 3, $label . ' setar2');
    $sequence = array('tar' => array($t1['teacher_id'], $t2['teacher_id']), 'setar' => array($s1['teacher_id'], $s2['teacher_id']));
    $roles = array('A_multi' => $a['teacher_id'], 'B_setar' => $b['teacher_id'], 'C_tar' => $c['teacher_id']);
    $totals = array();
    foreach ($roles as $role => $id) $totals[$role] = 0;
    foreach ($sequence as $ids) foreach ($ids as $id) foreach ($roles as $role => $rid) if ($id === $rid) $totals[$role]++;
    return array('roles' => $roles, 'sequence' => $sequence, 'totals' => $totals, 'multi_received_lessons' => $totals['A_multi']);
};

$multiFirst = $run('multi-first', true);
$multiLast = $run('multi-last', false);
// Cross-instrument policy: the first Setar offer goes to the idle Setar
// specialist, not to the multi-instrument Teacher who already holds the Tar offer.
dzn_stress_assert($multiFirst['sequence']['tar'][0] === $multiFirst['roles']['A_multi'], 'The first Tar offer goes to the lowest-id strong Teacher while every workload is equal: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiFirst['sequence']['setar'][0] === $multiFirst['roles']['B_setar'], 'The first Setar offer must go to the idle specialist, not to the multi-instrument Teacher who already holds work: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiFirst['sequence']['tar'][1] === $multiFirst['roles']['C_tar'], 'The second Tar offer must go to the idle Tar specialist rather than the multi-instrument Teacher: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiFirst['totals']['A_multi'] === 2 && $multiFirst['totals']['B_setar'] === 1 && $multiFirst['totals']['C_tar'] === 1, 'Two cycles of Tar and Setar leave every Teacher within one booking of the others: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiLast['sequence']['tar'][0] === $multiLast['roles']['C_tar'], 'When A has the highest id, the first Tar offer still goes to the lowest-id strong Teacher');
dzn_stress_assert($multiLast['sequence']['setar'][0] === $multiLast['roles']['B_setar'], 'When A has the highest id, the first Setar offer goes to the Setar specialist');
dzn_stress_assert($multiLast['sequence']['tar'][1] === $multiLast['roles']['A_multi'] && $multiLast['sequence']['setar'][1] === $multiLast['roles']['A_multi'], 'A then receives the second lesson at each slot only once it is the least-loaded eligible Teacher');
foreach (array('multi_first_created' => $multiFirst, 'multi_last_created' => $multiLast) as $label => $result) {
    $totals = array_values($result['totals']);
    dzn_stress_assert(max($totals) - min($totals) <= 1, 'Cross-instrument workload spread exceeds one booking for ' . $label . ': ' . wp_json_encode($totals));
}
dzn_stress_emit('B_multi_instrument_scope', array(
    'pass' => true,
    'policy_observed' => 'weighted workload is equalised across every introductory instrument, then the per-course cursor breaks the tie',
    'multi_first_created' => $multiFirst,
    'multi_last_created' => $multiLast,
    'implication' => 'Total introductory workload is equalised across Teachers with different instrument portfolios; a multi-instrument Teacher is not given one independent turn per instrument',
));
