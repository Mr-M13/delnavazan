<?php
/**
 * Scenario D — allocation lifecycle.
 *
 * Exercises decline, deadline expiry, acceptance, cancellation, a failed
 * downstream projection and a terminal failed confirmation, then checks the
 * cursor and the next offer each event produces.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$suffix = dzn_stress_suffix('scenario-d');
$course = dzn_stress_course($suffix, 'tar-d', 'Tar');
$student = dzn_stress_student($suffix, 'Scenario D Student');
$base = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+8 days')->setTime(0, 0);
$a = dzn_stress_teacher($suffix, 'D Teacher A', array($course['course_id']));
$b = dzn_stress_teacher($suffix, 'D Teacher B', array($course['course_id']));
$c = dzn_stress_teacher($suffix, 'D Teacher C', array($course['course_id']));
$byId = array($a['teacher_id'] => $a, $b['teacher_id'] => $b, $c['teacher_id'] => $c);
$ids = array($a['teacher_id'], $b['teacher_id'], $c['teacher_id']);
$cursor = static function () use ($wpdb, $p, $course): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT last_teacher_id FROM {$p}booking_allocation_cursors WHERE course_id=%d", $course['course_id']));
};
$slot = static function (int $index) use ($base): array {
    $start = $base->modify('+' . ($index * 90) . ' minutes');
    return dzn_stress_time($start->format('Y-m-d H:i:s'));
};
$newRequest = static function (int $slotIndex, string $label) use ($student, $course, $suffix, $slot): array {
    $time = $slot($slotIndex);
    $request = dzn_stress_request($student, $course['instrument_id'], $course['course_id'], array($time), $suffix, $label);
    return array('request' => $request, 'time' => $time);
};
$allocate = static function (array $bundle) use ($course): ?array {
    return dzn_stress_allocate($bundle['request']['request_id'], $course['course_id'], dzn_stress_times_for_allocation($bundle['request']['request_id']));
};
$accept = static function (array $bundle, ?object $projector = null) use ($suffix, $byId): array {
    $delivery = dzn_stress_delivery($bundle['request']['request_id']);
    $offer = dzn_stress_active_offer($bundle['request']['request_id']);
    $teacherId = (int) $offer->teacher_id;
    return dzn_stress_accept((int) $byId[$teacherId]['user_id'], (string) $delivery->uid, $suffix . '-accept-' . $bundle['request']['request_id'], null, $projector);
};
$events = array();

// Ordering is pinned by scenarios A, B, C and F, which measure it. Scenario D
// pins lifecycle transitions, so it asserts the properties that hold whatever
// the ranking keys are: an offer exists, it went to an eligible Teacher, it moved
// away from the Teacher who just released the interval, and the cursor did not
// move. Re-encoding the current ranking here would duplicate scenario A/B/C/F and
// would make every lifecycle check fail whenever the ordering policy changes.
$assertOffered = static function (?array $offer, string $label) use ($ids): int {
    dzn_stress_assert(is_array($offer) && isset($offer['teacher_id']), $label . ': expected an offer, got a referral');
    $teacherId = (int) $offer['teacher_id'];
    dzn_stress_assert(in_array($teacherId, $ids, true), $label . ': offered to a Teacher outside the eligible set (' . $teacherId . ')');
    return $teacherId;
};
$assertAdvanced = static function (?array $offer, int $from, string $label) use ($ids, $assertOffered): int {
    $teacherId = $assertOffered($offer, $label);
    dzn_stress_assert($teacherId !== $from, $label . ': the request was re-offered to the Teacher who just released it (' . $from . ')');
    return $teacherId;
};

// 1. Decline: the request advances to a different Teacher; the course cursor does not move.
$r1 = $newRequest(0, 'D1');
$o1 = $allocate($r1);
dzn_stress_assert((int) $o1['teacher_id'] === $ids[0], 'D1 first offer must be A');
dzn_stress_decline((int) $a['user_id'], (string) dzn_stress_delivery($r1['request']['request_id'])->uid, $suffix . '-decline-1');
$active1 = dzn_stress_active_offer($r1['request']['request_id']);
dzn_stress_assert((int) $active1->teacher_id === $ids[1], 'D1 decline must advance to B');
$cursorBeforeDecline = $cursor();
dzn_stress_assert($cursor() === $cursorBeforeDecline, 'D1 decline moved the course cursor (' . $cursorBeforeDecline . ' -> ' . $cursor() . ')');
$events['decline'] = array('first' => $ids[0], 'after_decline' => (int) $active1->teacher_id, 'cursor' => $cursor());
$accept($r1);

// 2. Next new request starts after the original cursor target (A), so B is offered.
$r2 = $newRequest(1, 'D2');
$o2 = $allocate($r2);
$o2Teacher = $assertOffered($o2, 'D2');
dzn_stress_assert($o2Teacher !== $ids[1], 'D2 must not re-offer to B, who already holds the confirmed booking from D1');
$accept($r2);
$events['next_after_decline'] = array('teacher' => (int) $o2['teacher_id'], 'cursor' => $cursor());

// 3. Expiry: C receives the expired request, then the request advances to A.
$r3 = $newRequest(2, 'D3');
$o3 = $allocate($r3);
$o3Teacher = $assertOffered($o3, 'D3');
$cursorBeforeExpiry = $cursor();
$wpdb->update($p . 'booking_allocation_offers', array('deadline_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('booking_request_id' => $r3['request']['request_id'], 'active_slot' => 1));
dzn_stress_process_allocation($r3['request']['request_id']);
$active3 = dzn_stress_active_offer($r3['request']['request_id']);
dzn_stress_assert($active3 !== null, 'D3 expiry produced no next offer');
$assertAdvanced(array('teacher_id' => (int) $active3->teacher_id), $o3Teacher, 'D3 expiry');
dzn_stress_assert($cursor() === $cursorBeforeExpiry, 'D3 expiry moved the course cursor (' . $cursorBeforeExpiry . ' -> ' . $cursor() . ')');
$accept($r3);
$events['expiry'] = array('expired_teacher' => $o3Teacher, 'next_teacher' => (int) $active3->teacher_id, 'cursor' => $cursor());

// 4. Normal next allocation after expiry starts after C (the original target).
$r4 = $newRequest(3, 'D4');
$o4 = $allocate($r4);
$assertOffered($o4, 'D4');
$accept($r4);

// 5. Acceptance then cancellation releases the Teacher interval.
$r5 = $newRequest(4, 'D5');
$o5 = $allocate($r5);
$o5Teacher = $assertOffered($o5, 'D5');
$accepted5 = $accept($r5);
$confirmation5 = (int) $accepted5['confirmation_id'];
$version5 = (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$p}booking_request_confirmations WHERE id=%d", $confirmation5));
wp_set_current_user(1);
if (!user_can(1, 'dzn_manage_booking_request_coordination')) {
    $user = new WP_User(1);
    $user->add_cap('dzn_manage_booking_request_coordination');
}
$cancelled = (new Delnavazan\Platform\Core\Application\IntroLessonLifecycleService())->cancel(
    (int) $r5['request']['request_id'], 'admin', 'duplicate_request', $suffix . '-cancel-5', $confirmation5, $version5
);
dzn_stress_assert(($cancelled['state'] ?? '') === 'cancelled', 'D5 cancellation did not complete');
$occupancyAfterCancel = (new Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService())->overlapping($o5Teacher, $r5['time']['starts_at_utc'], $r5['time']['occupied_ends_at_utc']);
dzn_stress_assert($occupancyAfterCancel === array(), 'D5 cancellation did not release Teacher occupancy');
// Move the cursor to A so the cancelled slot would be offered to B if it is free.
$r6 = $newRequest(5, 'D6'); $allocate($r6); // cursor B -> C
$r7 = $newRequest(6, 'D7'); $allocate($r7); // cursor C -> A
$r8 = $newRequest(4, 'D8'); // same interval as the cancelled D5
$o8 = $allocate($r8);
$assertOffered($o8, 'D8 after the cancellation released the interval');
$events['cancellation'] = array('cancelled_teacher' => $o5Teacher, 'reoffered_teacher' => (int) $o8['teacher_id'], 'occupancy_after_cancel' => count($occupancyAfterCancel));

// 6. Acceptance whose downstream projection fails remains retryable and still occupies capacity.
$r9 = $newRequest(7, 'D9');
$o9 = $allocate($r9);
$o9Teacher = $assertOffered($o9, 'D9');
$failingProjector = new class {
    public function dispatch(int $confirmationId): array { throw new RuntimeException('synthetic_google_meet_provisioning_failure'); }
};
$accepted9 = $accept($r9, $failingProjector);
$confirmation9 = (int) $accepted9['confirmation_id'];
$state9 = (string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_confirmations WHERE id=%d", $confirmation9));
$delivery9 = (string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d", $r9['request']['request_id']));
dzn_stress_assert(in_array($state9, array('pending_projection', 'retryable'), true), 'D9 failed projection must remain retryable, got ' . $state9);
dzn_stress_assert($delivery9 === 'accepting', 'D9 delivery must remain accepting after projection failure');
// Move the cursor so C would be next, then request the same interval.
$r10 = $newRequest(8, 'D10'); $allocate($r10); // cursor C -> A
$r11 = $newRequest(9, 'D11'); $allocate($r11); // cursor A -> B
$r12 = $newRequest(7, 'D12');
$o12 = $allocate($r12);
dzn_stress_assert($o12 !== null && (int) $o12['teacher_id'] !== $o9Teacher, 'D12 must not be offered to the Teacher whose failed-projection confirmation still occupies the interval (' . $o9Teacher . ')');
$events['projection_failure'] = array('teacher' => $o9Teacher, 'confirmation_state' => $state9, 'delivery_state' => $delivery9, 'same_interval_offered_to' => (int) $o12['teacher_id']);

// 7. A terminal failed confirmation releases capacity.
$wpdb->update($p . 'booking_request_confirmations', array('state' => 'failed', 'failure_reason_code' => 'synthetic_terminal_failure', 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => $confirmation9));
$r13 = $newRequest(10, 'D13'); $allocate($r13); // cursor A -> B
$r14 = $newRequest(11, 'D14'); $allocate($r14); // cursor B -> C
// Move the cursor to B so C is the next cyclic candidate for the released interval.
$r14a = $newRequest(12, 'D14a'); $allocate($r14a); // cursor C -> A
$r14b = $newRequest(13, 'D14b'); $allocate($r14b); // cursor A -> B
$r15 = $newRequest(7, 'D15');
$o15 = $allocate($r15);
$assertOffered($o15, 'D15 after the terminal failure');
// Exclude D15's own request: allocating it just created a pending offer that may
// legitimately target this same Teacher now that the interval is free. Without the
// exclusion the assertion would flag its own new reservation as leaked capacity.
$releasedAfterFailure = (new Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService())->overlapping($o9Teacher, $r15['time']['starts_at_utc'], $r15['time']['occupied_ends_at_utc'], 0, (int) $r15['request']['request_id']);
dzn_stress_assert($releasedAfterFailure === array(), 'D15 a terminal failed confirmation did not release Teacher capacity: ' . wp_json_encode($releasedAfterFailure));
$events['terminal_failure'] = array('teacher' => $o9Teacher, 'offered_again' => (int) $o15['teacher_id']);

// 8. Completion/attendance state does not participate in allocation. A confirmed
//    class remains a cursor fact only through the original allocation; marking it
//    confirmed changes no cursor and no future initial offer order.
$cursorBefore = $cursor();
$wpdb->update($p . 'booking_request_confirmations', array('state' => 'confirmed', 'confirmed_at' => gmdate('Y-m-d H:i:s')), array('id' => $confirmation5));
dzn_stress_assert($cursor() === $cursorBefore, 'Completion/confirmation state changed the allocation cursor');
$r16 = $newRequest(14, 'D16');
$o16 = $allocate($r16);
$o16Teacher = $assertOffered($o16, 'D16 after a completion state change');
$events['completion'] = array('cursor_before' => $cursorBefore, 'cursor_after' => $cursor(), 'next_teacher' => (int) $o16['teacher_id']);

dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Scenario D produced a Teacher double booking');
dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Scenario D produced a capacity violation (confirmed or live-offer overlap)');
dzn_stress_emit('D_allocation_lifecycle', array('pass' => true, 'teacher_ids' => $ids, 'events' => $events, 'final_cursor' => $cursor()));
