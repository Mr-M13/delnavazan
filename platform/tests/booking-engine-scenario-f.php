<?php
/**
 * Scenario F — multiple preferences, match strength and explicit claim overrides.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$suffix = dzn_stress_suffix('scenario-f');
$student = dzn_stress_student($suffix, 'Scenario F Student');
$availability = new Delnavazan\Platform\Core\Application\TeacherAvailabilityService();
$out = array();

// Derived from the run time: every date below is passed through allocation, so a
// fixed calendar date would fall inside the 25-hour lead time in a later year.
$dayA = dzn_stress_allocation_date(4, 'UTC');
$dayB = dzn_stress_allocation_date(5, 'UTC');
$dayC = dzn_stress_allocation_date(6, 'UTC');
$dayD = dzn_stress_allocation_date(7, 'UTC');
$dayE = dzn_stress_allocation_date(8, 'UTC');

$preferenceCase = static function (string $label, string $date, array $teacherRanges, array $teacherStates, array $localTimes) use ($suffix, $student): array {
    $course = dzn_stress_course($suffix, 'f-' . substr(md5($label), 0, 6), 'F ' . $label);
    $teachers = array();
    foreach ($teacherRanges as $index => $ranges) {
        $teachers[] = dzn_stress_teacher($suffix, 'F ' . $label . ' T' . ($index + 1), array($course['course_id']), array(
            'timezone' => 'UTC', 'ranges' => $ranges, 'state' => $teacherStates[$index] ?? 'preferred',
        ));
    }
    $times = array();
    foreach ($localTimes as $time) $times[] = dzn_stress_time($date . ' ' . $time . ':00', 30, 15);
    $request = dzn_stress_request($student, $course['instrument_id'], $course['course_id'], $times, $suffix, 'F-' . substr(md5($label), 0, 8));
    $offer = dzn_stress_allocate($request['request_id'], $course['course_id'], dzn_stress_times_for_allocation($request['request_id']));
    return array(
        'label' => $label, 'teachers' => array_map(static fn(array $t): int => $t['teacher_id'], $teachers),
        'requested_time_ids' => $request['time_ids'], 'offered_time_id' => $offer['requested_time_id'] ?? null,
        'offered_teacher' => $offer['teacher_id'] ?? null, 'match_strength' => $offer['match_strength'] ?? null,
    );
};

// Teacher covers all three; the earliest preference wins.
$out['first_preference'] = $preferenceCase('first-preference', $dayA, array(array(array('00:00:00', '24:00:00'))), array('preferred'), array('09:00', '10:00', '11:00'));
// Teacher covers only the third preference; the engine must find it rather than refer.
$out['third_preference'] = $preferenceCase('third-preference', $dayA, array(array(array('11:00:00', '12:00:00'))), array('preferred'), array('09:00', '10:00', '11:00'));
// Teacher covers the first and third; order still selects the first.
$out['ordered_windows'] = $preferenceCase('ordered-windows', $dayA, array(array(array('09:00:00', '09:45:00'), array('11:00:00', '11:45:00'))), array('preferred'), array('09:00', '10:00', '11:00'));

// Teacher id ordering dominates the preference index across different Teachers.
$idCourse = dzn_stress_course($suffix, 'f-id', 'F Teacher Id');
$low = dzn_stress_teacher($suffix, 'F Low Teacher', array($idCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('09:00:00', '09:45:00'))));
$high = dzn_stress_teacher($suffix, 'F High Teacher', array($idCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('10:00:00', '10:45:00'))));
$idTimes = array(dzn_stress_time($dayB . ' 09:00:00', 30, 15), dzn_stress_time($dayB . ' 10:00:00', 30, 15));
$idRequest = dzn_stress_request($student, $idCourse['instrument_id'], $idCourse['course_id'], $idTimes, $suffix, 'F-id');
$idOffer = dzn_stress_allocate($idRequest['request_id'], $idCourse['course_id'], dzn_stress_times_for_allocation($idRequest['request_id']));
$out['teacher_id_vs_preference'] = array('low' => $low['teacher_id'], 'high' => $high['teacher_id'], 'offered_teacher' => $idOffer['teacher_id'] ?? null, 'offered_time_id' => $idOffer['requested_time_id'] ?? null);

// Match strength is the first ordering key: the higher-id strong match is
// selected over the lower-id possible match even though both are eligible.
$strengthCourse = dzn_stress_course($suffix, 'f-strength', 'F Match Strength');
$possible = dzn_stress_teacher($suffix, 'F Possible Teacher', array($strengthCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('10:00:00', '10:45:00')), 'state' => 'requestable'));
$strong = dzn_stress_teacher($suffix, 'F Strong Teacher', array($strengthCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('09:00:00', '09:45:00')), 'state' => 'preferred'));
$strengthTimes = array(dzn_stress_time($dayC . ' 09:00:00', 30, 15), dzn_stress_time($dayC . ' 10:00:00', 30, 15));
$strengthRequest = dzn_stress_request($student, $strengthCourse['instrument_id'], $strengthCourse['course_id'], $strengthTimes, $suffix, 'F-strength');
$strengthOffer = dzn_stress_allocate($strengthRequest['request_id'], $strengthCourse['course_id'], dzn_stress_times_for_allocation($strengthRequest['request_id']));
$out['match_strength'] = array('possible_teacher' => $possible['teacher_id'], 'strong_teacher' => $strong['teacher_id'], 'offered_teacher' => $strengthOffer['teacher_id'] ?? null, 'offered_match_strength' => $strengthOffer['match_strength'] ?? null);
dzn_stress_assert($out['first_preference']['offered_time_id'] === $out['first_preference']['requested_time_ids'][0], 'The earliest covered preference must be offered');
dzn_stress_assert($out['third_preference']['offered_time_id'] === $out['third_preference']['requested_time_ids'][2], 'A later covered preference must be used when earlier preferences are uncovered');
dzn_stress_assert($out['ordered_windows']['offered_time_id'] === $out['ordered_windows']['requested_time_ids'][0], 'Preference order must break ties within one Teacher');
dzn_stress_assert($out['teacher_id_vs_preference']['offered_teacher'] === $low['teacher_id'], 'Among equally strong candidates the deterministic round-robin tie-break selected the expected Teacher');
dzn_stress_assert($out['match_strength']['offered_teacher'] === $strong['teacher_id'], 'The stronger match must be offered before the lower-id possible match');
dzn_stress_assert($out['match_strength']['offered_match_strength'] === 'strong', 'The offer records the strong match strength');
dzn_stress_assert($out['match_strength']['offered_time_id'] === $out['match_strength']['requested_time_ids'][0], 'The strong match offers the preference its stronger coverage covers');

// Explicit claim of an unmatched request outside declared availability.
$claimCourse = dzn_stress_course($suffix, 'f-claim', 'F Claim');
$claimT1 = dzn_stress_teacher($suffix, 'F Claim T1', array($claimCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('15:00:00', '16:00:00'))));
$claimT2 = dzn_stress_teacher($suffix, 'F Claim T2', array($claimCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('15:00:00', '16:00:00'))));
$claimTime = dzn_stress_time($dayD . ' 09:00:00', 30, 15);
$claimRequest = dzn_stress_request($student, $claimCourse['instrument_id'], $claimCourse['course_id'], array($claimTime), $suffix, 'F-claim');
dzn_stress_resolve_identity($claimRequest['request_id'], $student, $suffix);
$claimReferral = dzn_stress_allocate($claimRequest['request_id'], $claimCourse['course_id'], dzn_stress_times_for_allocation($claimRequest['request_id']));
dzn_stress_assert($claimReferral === null, 'The claim request must first be unmatched');
$cursorBeforeClaim = (int) $wpdb->get_var($wpdb->prepare("SELECT last_teacher_id FROM {$p}booking_allocation_cursors WHERE course_id=%d", $claimCourse['course_id']));
$claimResult = dzn_stress_claim((int) $claimT1['user_id'], $claimRequest['uid'], (int) $claimRequest['time_ids'][0], $suffix . '-claim-1');
$cursorAfterClaim = (int) $wpdb->get_var($wpdb->prepare("SELECT last_teacher_id FROM {$p}booking_allocation_cursors WHERE course_id=%d", $claimCourse['course_id']));
dzn_stress_assert($cursorBeforeClaim === $cursorAfterClaim, 'A manual claim changed the allocation cursor');
dzn_stress_assert(($claimResult['state'] ?? '') !== 'failed', 'Claim did not confirm');
$claimAudit = $wpdb->get_row($wpdb->prepare(
    "SELECT event_type,reason_code,safe_detail FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='teacher_booking_claim' ORDER BY id DESC LIMIT 1",
    $claimRequest['request_id']
));
dzn_stress_assert($claimAudit && $claimAudit->reason_code === 'teacher_claim_availability_override', 'Claim override was not audited explicitly');
$claimBlocked = false;
try { dzn_stress_claim((int) $claimT2['user_id'], $claimRequest['uid'], (int) $claimRequest['time_ids'][0], $suffix . '-claim-2'); }
catch (Throwable $error) { $claimBlocked = true; }
dzn_stress_assert($claimBlocked, 'A second Teacher claimed an already-confirmed request');
$occupancyAfterClaim = (new Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService())->overlapping($claimT1['teacher_id'], $claimTime['starts_at_utc'], $claimTime['occupied_ends_at_utc']);
dzn_stress_assert($occupancyAfterClaim !== array(), 'An accepted out-of-availability claim did not reserve capacity');
$claimTime2 = dzn_stress_time($dayD . ' 09:00:00', 30, 15);
$claimRequest2 = dzn_stress_request($student, $claimCourse['instrument_id'], $claimCourse['course_id'], array($claimTime2), $suffix, 'F-claim-second');
$claimOffer2 = dzn_stress_allocate($claimRequest2['request_id'], $claimCourse['course_id'], dzn_stress_times_for_allocation($claimRequest2['request_id']));
dzn_stress_assert($claimOffer2 === null, 'A later request was offered to a Teacher already claimed at that interval');
$out['claim'] = array('teacher' => $claimT1['teacher_id'], 'second_teacher_blocked' => $claimBlocked, 'audit_reason' => $claimAudit->reason_code, 'later_offer' => $claimOffer2 !== null, 'cursor_before' => $cursorBeforeClaim, 'cursor_after' => $cursorAfterClaim);

// Unmatched opportunity board scoping and decline behaviour.
$boardCourse = dzn_stress_course($suffix, 'f-board', 'F Board');
$setar1 = dzn_stress_teacher($suffix, 'F Board Setar 1', array($boardCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('15:00:00', '16:00:00'))));
$setar2 = dzn_stress_teacher($suffix, 'F Board Setar 2', array($boardCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('15:00:00', '16:00:00'))));
$otherCourse = dzn_stress_course($suffix, 'f-board-other', 'F Board Other');
$tarOnly = dzn_stress_teacher($suffix, 'F Board Tar Only', array($otherCourse['course_id']), array('timezone' => 'UTC', 'ranges' => array(array('15:00:00', '16:00:00'))));
$boardTime = dzn_stress_time($dayE . ' 09:00:00', 30, 15);
$boardRequest = dzn_stress_request($student, $boardCourse['instrument_id'], $boardCourse['course_id'], array($boardTime), $suffix, 'F-board');
dzn_stress_resolve_identity($boardRequest['request_id'], $student, $suffix);
$boardReferral = dzn_stress_allocate($boardRequest['request_id'], $boardCourse['course_id'], dzn_stress_times_for_allocation($boardRequest['request_id']));
dzn_stress_assert($boardReferral === null, 'The board request must be unmatched');
$visible = array(
    'setar1_before' => in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar1['user_id']), true),
    'setar2_before' => in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar2['user_id']), true),
    'tar_only' => in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $tarOnly['user_id']), true),
);
dzn_stress_assert($visible['setar1_before'] && $visible['setar2_before'] && !$visible['tar_only'], 'Opportunity board eligibility is incorrect');
dzn_stress_decline_opportunity((int) $setar1['user_id'], $boardRequest['uid'], $suffix . '-opp-decline-1');
$visible['setar1_after_decline'] = in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar1['user_id']), true);
$visible['setar2_after_decline'] = in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar2['user_id']), true);
dzn_stress_assert(!$visible['setar1_after_decline'] && $visible['setar2_after_decline'], 'An opportunity decline must be Teacher-scoped only');
$boardClaim = dzn_stress_claim((int) $setar2['user_id'], $boardRequest['uid'], (int) $boardRequest['time_ids'][0], $suffix . '-opp-claim-1');
$visible['setar1_after_claim'] = in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar1['user_id']), true);
$visible['setar2_after_claim'] = in_array($boardRequest['request_id'], dzn_stress_opportunity_ids((int) $setar2['user_id']), true);
dzn_stress_assert(!$visible['setar1_after_claim'] && !$visible['setar2_after_claim'], 'A claimed request must leave the opportunity board');
$out['opportunity_board'] = $visible;

dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Scenario F produced a Teacher double booking');
dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Scenario F produced a capacity violation (confirmed or live-offer overlap)');
dzn_stress_emit('F_preferences_and_claim', array('pass' => true, 'cases' => $out));
