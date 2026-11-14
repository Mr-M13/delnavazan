<?php
/**
 * Scenario B — multi-instrument Teachers and the instrument scope of fairness.
 *
 * The implementation keeps one cursor per introductory Course. A Teacher who is
 * eligible for two instruments therefore has one independent turn per instrument.
 * This scenario proves the actual scope rather than assuming a policy.
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
dzn_stress_assert($multiFirst['sequence']['tar'][0] === $multiFirst['roles']['A_multi'], 'Tar should first go to the lowest-id eligible Teacher (A): '.wp_json_encode($multiFirst));
dzn_stress_assert($multiFirst['sequence']['setar'][0] === $multiFirst['roles']['A_multi'], 'Setar cursor is independent of Tar and also starts at A: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiFirst['totals']['A_multi'] === 2 && $multiFirst['totals']['B_setar'] === 1 && $multiFirst['totals']['C_tar'] === 1, 'Two cycles of Tar and Setar should give A two and the specialists one each: '.wp_json_encode($multiFirst));
dzn_stress_assert($multiLast['sequence']['tar'][0] === $multiLast['roles']['C_tar'], 'When A has the highest id, Tar starts at C');
dzn_stress_assert($multiLast['sequence']['setar'][0] === $multiLast['roles']['B_setar'], 'When A has the highest id, Setar starts at B');
dzn_stress_assert($multiLast['sequence']['tar'][1] === $multiLast['roles']['A_multi'] && $multiLast['sequence']['setar'][1] === $multiLast['roles']['A_multi'], 'A then receives the second lesson in each independent instrument cycle');
dzn_stress_emit('B_multi_instrument_scope', array(
    'pass' => true,
    'policy_observed' => 'per-course cursor; fairness is measured separately within each introductory instrument',
    'multi_first_created' => $multiFirst,
    'multi_last_created' => $multiLast,
    'implication' => 'Total introductory workload is not equalised across Teachers with different instrument portfolios',
));
