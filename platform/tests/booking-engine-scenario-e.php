<?php
/**
 * Scenario E — partial availability, boundaries, buffers, midnight, DST,
 * timezone conversion and Jalali/Gregorian equivalence.
 *
 * Each case gets an isolated Course and Teacher so one case cannot affect the
 * next. The authoritative availability read and the authoritative allocation
 * path are both checked.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\BookingLeadTimePolicy;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Support\PersianCalendar;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$suffix = dzn_stress_suffix('scenario-e');
$student = dzn_stress_student($suffix, 'Scenario E Student');
$availability = new TeacherAvailabilityService();
$results = array();

$case = static function (string $label, string $timezone, array $ranges, string $localDate, string $localTime, int $duration, int $buffer, ?string $requestTimezone = null) use ($suffix, $student, $availability, $p): array {
    $requestTimezone ??= $timezone;
    $course = dzn_stress_course($suffix, 'e-' . substr(md5($label), 0, 6), 'E ' . $label);
    $teacher = dzn_stress_teacher($suffix, 'E ' . $label, array($course['course_id']), array('timezone' => $timezone, 'ranges' => $ranges));
    $start = new DateTimeImmutable($localDate . ' ' . $localTime, new DateTimeZone($requestTimezone));
    $time = dzn_stress_time($start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $duration, $buffer, $requestTimezone);
    $coverage = $availability->coverage($teacher['teacher_id'], $time['starts_at_utc'], $time['occupied_ends_at_utc'], $course['instrument_id']);
    $request = dzn_stress_request($student, $course['instrument_id'], $course['course_id'], array($time), $suffix, 'E-' . substr(md5($label), 0, 8));
    $offer = dzn_stress_allocate($request['request_id'], $course['course_id'], dzn_stress_times_for_allocation($request['request_id']));
    return array(
        'label' => $label, 'teacher_id' => $teacher['teacher_id'], 'timezone' => $timezone,
        'ranges' => $ranges, 'start_utc' => $time['starts_at_utc'], 'occupied_end_utc' => $time['occupied_ends_at_utc'],
        'coverage' => $coverage, 'offered' => $offer !== null, 'offered_teacher' => $offer['teacher_id'] ?? null,
    );
};

// Allocation dates are derived from the run time so these cases stay beyond the
// 25-hour lead time in every future year. Fixed dates remain only for the pure
// DST-gap, DST-fold and Jalali conversion assertions further below.
$utcDayA = dzn_stress_allocation_date(4, 'UTC');
$utcDayB = dzn_stress_allocation_date(5, 'UTC');
$tehranDay = dzn_stress_allocation_date(6, 'Asia/Tehran');
$dstSpringDay = dzn_stress_dst_transition_date('America/New_York', true);
$dstFallDay = dzn_stress_dst_transition_date('America/New_York', false);

// Example 1: Teacher 10:00-10:15; Student 10:00-10:30 (+15 buffer).
$results[] = $case('E1-partial-tail', 'UTC', array(array('10:00:00', '10:15:00')), $utcDayA, '10:00:00', 30, 15);
// Example 2: Teacher 10:15-10:45; Student 10:00-10:30 (+15 buffer).
$results[] = $case('E2-partial-head', 'UTC', array(array('10:15:00', '10:45:00')), $utcDayA, '10:00:00', 30, 15);
// Example 3: Teacher 10:00-10:30; Student 10:15-10:45 (+15 buffer).
$results[] = $case('E3-partial-both', 'UTC', array(array('10:00:00', '10:30:00')), $utcDayA, '10:15:00', 30, 15);
// Example 4a: literal adjacency, buffer-free course facts (10:00-10:30 occupied).
$results[] = $case('E4-adjacent-no-buffer', 'UTC', array(array('10:00:00', '10:15:00'), array('10:15:00', '10:30:00')), $utcDayA, '10:00:00', 30, 0);
// Example 4b: adjacency covering the full 30+15 occupied interval.
$results[] = $case('E4b-adjacent-with-buffer', 'UTC', array(array('10:00:00', '10:15:00'), array('10:15:00', '10:45:00')), $utcDayA, '10:00:00', 30, 15);
// Example 5a: 30-minute availability cannot hold 30 lesson + 15 buffer.
$results[] = $case('E5-buffer-short', 'UTC', array(array('10:00:00', '10:30:00')), $utcDayA, '10:00:00', 30, 15);
// Example 5b: 45-minute availability holds 30 lesson + 15 buffer exactly.
$results[] = $case('E5b-buffer-exact', 'UTC', array(array('10:00:00', '10:45:00')), $utcDayA, '10:00:00', 30, 15);
// Exact end boundary: availability ends exactly at the occupied end.
$results[] = $case('E6-exact-end', 'UTC', array(array('10:00:00', '10:45:00')), $utcDayA, '10:00:00', 30, 15);
// One minute past the end.
$results[] = $case('E7-one-minute-past', 'UTC', array(array('10:00:00', '10:44:00')), $utcDayA, '10:00:00', 30, 15);
// Midnight crossing: 23:00+45 ends 23:45, covered by 22:00-24:00.
$results[] = $case('E8-midnight-inside', 'UTC', array(array('22:00:00', '24:00:00')), $utcDayB, '23:00:00', 30, 15);
// Midnight crossing: 23:30+45 ends 00:15 next day, outside 22:00-24:00.
$results[] = $case('E9-midnight-cross', 'UTC', array(array('22:00:00', '24:00:00')), $utcDayB, '23:30:00', 30, 15);
// DST spring-forward: the next real America/New_York transition, 01:00-04:00 local.
$results[] = $case('E10-dst-spring-valid', 'America/New_York', array(array('01:00:00', '04:00:00')), $dstSpringDay, '03:00:00', 30, 15);
// DST fall-back: the next real America/New_York transition, 00:00-03:00 local, 02:00 valid.
$results[] = $case('E11-dst-fall-valid', 'America/New_York', array(array('00:00:00', '03:00:00')), $dstFallDay, '02:00:00', 30, 15);
// Timezone conversion: 10:00 Asia/Tehran is 06:30 UTC; teacher UTC 06:30-07:30.
$results[] = $case('E12-timezone-conversion', 'UTC', array(array('06:30:00', '07:30:00')), $utcDayA, '10:00:00', 30, 15, 'Asia/Tehran');
// Jalali equivalence: full-day availability on a derived Tehran date.
$results[] = $case('E13-jalali-date', 'Asia/Tehran', array(array('00:00:00', '24:00:00')), $tehranDay, '10:00:00', 30, 15);

$byLabel = array();
foreach ($results as $row) $byLabel[$row['label']] = $row;
$expect = static function (string $label, bool $covered) use ($byLabel): void {
    $row = $byLabel[$label];
    dzn_stress_assert(($row['coverage'] !== null) === $covered, $label . ' coverage expected ' . ($covered ? 'yes' : 'no') . ', got ' . var_export($row['coverage'], true));
    dzn_stress_assert($row['offered'] === $covered, $label . ' allocation expected ' . ($covered ? 'offer' : 'referral') . ', got ' . ($row['offered'] ? 'offer' : 'referral'));
};
$expect('E1-partial-tail', false);
$expect('E2-partial-head', false);
$expect('E3-partial-both', false);
$expect('E4-adjacent-no-buffer', true);
$expect('E4b-adjacent-with-buffer', true);
$expect('E5-buffer-short', false);
$expect('E5b-buffer-exact', true);
$expect('E6-exact-end', true);
$expect('E7-one-minute-past', false);
$expect('E8-midnight-inside', true);
$expect('E9-midnight-cross', false);
$expect('E10-dst-spring-valid', true);
$expect('E11-dst-fall-valid', true);
$expect('E12-timezone-conversion', true);
$expect('E13-jalali-date', true);

// Nonexistent and ambiguous local wall times must fail closed before persistence.
// These two stay on fixed calendar dates on purpose: they assert a pure
// conversion for a known transition and never pass through allocation, so they
// remain correct in any future year.
$dstGap = false;
try { AvailabilityLocalTime::wall('2027-03-14', '02:30:00', 'America/New_York'); }
catch (Throwable $error) { $dstGap = $error instanceof Delnavazan\Platform\Core\Application\UnavailableLocalTimeException; }
$dstFold = false;
try { AvailabilityLocalTime::wall('2027-11-07', '01:30:00', 'America/New_York'); }
catch (Throwable $error) { $dstFold = $error instanceof Delnavazan\Platform\Core\Application\UnavailableLocalTimeException; }
dzn_stress_assert($dstGap, 'DST spring-forward gap 02:30 was not rejected');
dzn_stress_assert($dstFold, 'DST fall-back fold 01:30 was not rejected');

// Jalali conversion is presentation-only; the same UTC instant must behave identically.
$jalali = PersianCalendar::toJalali(2026, 12, 15);
dzn_stress_assert($jalali === array(1405, 9, 24), '2026-12-15 must convert to 1405-09-24, got ' . wp_json_encode($jalali));

$e13 = $byLabel['E13-jalali-date'];
$utcMirror = dzn_stress_time($e13['start_utc'], 30, 15, 'UTC');
// The mirror is a pure timestamp comparison; no second request is allocated.
dzn_stress_assert($utcMirror['starts_at_utc'] === $e13['start_utc'], 'Timezone conversion changed the UTC instant');

dzn_stress_emit('E_availability_boundaries', array(
    'pass' => true,
    'cases' => $results,
    'dates' => array('utc_day_a' => $utcDayA, 'utc_day_b' => $utcDayB, 'tehran_day' => $tehranDay, 'dst_spring' => $dstSpringDay, 'dst_fall' => $dstFallDay),
    'dst_gap_rejected' => $dstGap, 'dst_fold_rejected' => $dstFold,
    'jalali_2026_12_15' => $jalali,
    'notes' => 'Coverage and allocation use the full occupied interval (instructional duration + course buffer). Adjacent rules are merged by the availability resolver. Allocation dates are derived from the run time so the cases stay beyond the 25-hour lead time in every year; only the pure DST/Jalali conversion assertions keep a fixed date.',
));

// Existing confirmed class inside the requested interval must block the Teacher.
$blockDay = dzn_stress_allocation_date(7, 'UTC');
$blockCourse = dzn_stress_course($suffix, 'e14-block', 'E14 Blocker');
$blockTeacher = dzn_stress_teacher($suffix, 'E14 blocker teacher', array($blockCourse['course_id']), array('timezone' => 'UTC'));
dzn_stress_confirmation_blocker($student, $blockCourse['instrument_id'], $blockCourse['course_id'], $blockTeacher['teacher_id'], $blockDay . ' 10:15:00', $suffix);
$targetStart = $blockDay . ' 10:00:00';
$targetTime = dzn_stress_time($targetStart, 30, 15);
$targetCoverage = $availability->coverage($blockTeacher['teacher_id'], $targetTime['starts_at_utc'], $targetTime['occupied_ends_at_utc'], $blockCourse['instrument_id']);
$targetRequest = dzn_stress_request($student, $blockCourse['instrument_id'], $blockCourse['course_id'], array($targetTime), $suffix, 'E14-target');
$targetOffer = dzn_stress_allocate($targetRequest['request_id'], $blockCourse['course_id'], dzn_stress_times_for_allocation($targetRequest['request_id']));
$nonOverlapTime = dzn_stress_time($blockDay . ' 12:00:00', 30, 15);
$nonOverlapRequest = dzn_stress_request($student, $blockCourse['instrument_id'], $blockCourse['course_id'], array($nonOverlapTime), $suffix, 'E14-nonoverlap');
$nonOverlapOffer = dzn_stress_allocate($nonOverlapRequest['request_id'], $blockCourse['course_id'], dzn_stress_times_for_allocation($nonOverlapRequest['request_id']));
dzn_stress_assert($targetCoverage === 'preferred', 'E14 declared availability should cover the target time');
dzn_stress_assert($targetOffer === null, 'E14 existing confirmed class inside the interval must exclude the Teacher');
dzn_stress_assert($nonOverlapOffer !== null, 'E14 a non-overlapping time must still be offered');
echo 'AUDIT_RESULT ' . wp_json_encode(array('scenario' => 'E14_existing_lesson_occupancy', 'pass' => true, 'declared_coverage' => $targetCoverage, 'overlap_offer' => $targetOffer !== null, 'non_overlap_offer' => $nonOverlapOffer !== null)) . "\n";

// 15. Open-ended temporary unavailability excludes an otherwise fully available Teacher.
$leaveCourse = dzn_stress_course($suffix, 'e15-leave', 'E15 Leave');
$leaveTeacher = dzn_stress_teacher($suffix, 'E15 on leave', array($leaveCourse['course_id']), array('timezone' => 'UTC', 'leave' => true));
$leaveTime = dzn_stress_time(dzn_stress_allocation_date(8, 'UTC') . ' 10:00:00', 30, 15);
$leaveCoverage = $availability->coverage($leaveTeacher['teacher_id'], $leaveTime['starts_at_utc'], $leaveTime['occupied_ends_at_utc'], $leaveCourse['instrument_id']);
$leaveRequest = dzn_stress_request($student, $leaveCourse['instrument_id'], $leaveCourse['course_id'], array($leaveTime), $suffix, 'E15');
$leaveOffer = dzn_stress_allocate($leaveRequest['request_id'], $leaveCourse['course_id'], dzn_stress_times_for_allocation($leaveRequest['request_id']));
dzn_stress_assert($leaveCoverage === null && $leaveOffer === null, 'E15 an open-ended leave did not exclude the Teacher');

// 16. A limited accepting state remains eligible, but records a possible match.
$limitedCourse = dzn_stress_course($suffix, 'e16-limited', 'E16 Limited');
$limitedTeacher = dzn_stress_teacher($suffix, 'E16 limited', array($limitedCourse['course_id']), array('timezone' => 'UTC', 'accepting_state' => 'limited'));
$limitedTime = dzn_stress_time(dzn_stress_allocation_date(9, 'UTC') . ' 12:00:00', 30, 15);
$limitedRequest = dzn_stress_request($student, $limitedCourse['instrument_id'], $limitedCourse['course_id'], array($limitedTime), $suffix, 'E16');
$limitedOffer = dzn_stress_allocate($limitedRequest['request_id'], $limitedCourse['course_id'], dzn_stress_times_for_allocation($limitedRequest['request_id']));
dzn_stress_assert($limitedOffer !== null && $limitedOffer['match_strength'] === 'possible', 'E16 a limited-accepting Teacher was not offered with a possible match');

// 17. Initial offers require strictly more than the 24-hour response window plus the projection buffer.
$leadCourse = dzn_stress_course($suffix, 'e17-lead', 'E17 Lead');
$leadTeacher = dzn_stress_teacher($suffix, 'E17 lead time', array($leadCourse['course_id']), array('timezone' => 'UTC'));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$leadTimes = array();
foreach (array('24 hours 30 minutes', '25 hours', '26 hours') as $offset) {
    $start = $now->modify('+' . $offset);
    $time = dzn_stress_time($start->format('Y-m-d H:i:s'), 30, 15);
    $request = dzn_stress_request($student, $leadCourse['instrument_id'], $leadCourse['course_id'], array($time), $suffix, 'E17-' . md5($offset));
    $offer = dzn_stress_allocate($request['request_id'], $leadCourse['course_id'], dzn_stress_times_for_allocation($request['request_id']));
    $leadTimes[$offset] = array('start_utc' => $time['starts_at_utc'], 'offered' => $offer !== null, 'referral_reason' => dzn_stress_referral_reason($request['request_id']));
}
dzn_stress_assert($leadTimes['24 hours 30 minutes']['offered'] === false, 'E17 a request inside the 25-hour lead time was offered');
dzn_stress_assert($leadTimes['25 hours']['offered'] === false, 'E17 a request exactly at the 25-hour boundary was offered');
dzn_stress_assert($leadTimes['26 hours']['offered'] === true, 'E17 a request beyond the lead time was not offered');
// A refusal that is purely about notice must say so, not claim every Teacher was tried.
dzn_stress_assert($leadTimes['24 hours 30 minutes']['referral_reason'] === 'requested_time_inside_lead_time', 'E17 24.5h must be referred for the notice window, got ' . var_export($leadTimes['24 hours 30 minutes']['referral_reason'], true));
dzn_stress_assert($leadTimes['25 hours']['referral_reason'] === 'requested_time_inside_lead_time', 'E17 exactly 25h must be referred for the notice window, got ' . var_export($leadTimes['25 hours']['referral_reason'], true));

// 18. A request that passes intake can cross the boundary before the allocator
// runs (a decline/expiry rotation or an operator re-run). The outcome must be
// the same shared-policy classification: retryable lead time, never "no viable
// Teacher", because no Teacher could have been asked.
$crossCourse = dzn_stress_course($suffix, 'e18-crossed', 'E18 Crossed boundary');
$crossTeacher = dzn_stress_teacher($suffix, 'E18 crossed boundary', array($crossCourse['course_id']), array('timezone' => 'UTC'));
$intakeNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$crossStart = $intakeNow->modify('+26 hours');
$crossTime = dzn_stress_time($crossStart->format('Y-m-d H:i:s'), 30, 15);
dzn_stress_assert(BookingLeadTimePolicy::isStartAllowed($crossTime['starts_at_utc'], $intakeNow->format('Y-m-d H:i:s')), 'E18 the fixture start must pass intake at submission time');
$crossRequest = dzn_stress_request($student, $crossCourse['instrument_id'], $crossCourse['course_id'], array($crossTime), $suffix, 'E18-crossed');
$lateNow = $intakeNow->modify('+2 hours')->format('Y-m-d H:i:s');
$crossOffer = dzn_stress_allocate($crossRequest['request_id'], $crossCourse['course_id'], dzn_stress_times_for_allocation($crossRequest['request_id']), $lateNow);
$crossReason = dzn_stress_referral_reason($crossRequest['request_id']);
dzn_stress_assert($crossOffer === null, 'E18 a start inside the notice window was offered');
dzn_stress_assert($crossReason === 'requested_time_inside_lead_time', 'E18 the crossed-boundary refusal must name the lead time, got ' . var_export($crossReason, true));
// The same instant is still requestable at the intake moment: the difference is
// only that the boundary moved, so the refusal is retryable with later times.
$openRequest = dzn_stress_request($student, $crossCourse['instrument_id'], $crossCourse['course_id'], array($crossTime), $suffix, 'E18-open');
$openOffer = dzn_stress_allocate($openRequest['request_id'], $crossCourse['course_id'], dzn_stress_times_for_allocation($openRequest['request_id']), $intakeNow->format('Y-m-d H:i:s'));
dzn_stress_assert($openOffer !== null, 'E18 the same start must be offerable at the intake instant');
echo 'AUDIT_RESULT ' . wp_json_encode(array('scenario' => 'E18_crossed_lead_time_boundary', 'pass' => true, 'teacher_id' => $crossTeacher['teacher_id'], 'intake_now' => $intakeNow->format('Y-m-d H:i:s'), 'allocation_now' => $lateNow, 'crossed_offer' => $crossOffer !== null, 'crossed_referral_reason' => $crossReason, 'open_offer' => $openOffer !== null)) . "\n";

echo 'AUDIT_RESULT ' . wp_json_encode(array('scenario' => 'E15_E17_leave_limited_leadtime', 'pass' => true, 'leave_excluded' => $leaveCoverage === null, 'limited_offer' => $limitedOffer['match_strength'] ?? null, 'lead_times' => $leadTimes)) . "\n";
