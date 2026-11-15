<?php
/**
 * Scenario G — failure injection and recovery.
 *
 * Covers a failed database write during intake, a failed Teacher-assignment
 * write, notification recording failure/retry, and acceptance replay after a
 * downstream projection failure. No provider, mail or payment call is made.
 */
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$suffix = dzn_stress_suffix('scenario-g');
$out = array();

$publicInput = static function (array $course, string $email, string $start, int $index) use ($suffix): array {
    return array(
        'requested_instrument_id' => $course['instrument_id'],
        'selected_intro_course_id' => $course['course_id'],
        'full_name' => 'Synthetic Failure ' . $index,
        'email' => $email, 'mobile' => '+6140022' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => true,
        'whatsapp_number' => '', 'privacy_notice_accepted' => true,
        'privacy_notice_version' => '2026-09-05',
        'requested_times' => array(array('local_date' => substr($start, 0, 10), 'local_start_time' => substr($start, 11, 5), 'timezone' => 'UTC')),
    );
};
$countByEmail = static function (string $email) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}booking_request_contact_snapshots WHERE email=%s", $email
    ));
};

// 1. The offer notification obligation fails after the delivery; the whole intake must roll back.
$course1 = dzn_stress_course($suffix, 'g-notify', 'G Notify');
$teacher1 = dzn_stress_teacher($suffix, 'G Notify Teacher', array($course1['course_id']));
$student1 = dzn_stress_student($suffix, 'G Notify Student');
$start1 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+20 days')->setTime(10, 0)->format('Y-m-d H:i:s');
$email1 = 'rollback-offer-' . $suffix . '@example.invalid';
$failOfferNotification = static function (string $sql) use ($p): string|bool {
    return str_contains($sql, "INSERT INTO `{$p}booking_allocation_notifications`") ? false : $sql;
};
add_filter('query', $failOfferNotification, 10, 1);
$error1 = '';
try {
    (new Delnavazan\Platform\Core\Application\BookingRequestSubmissionService())->submitPublic(
        $publicInput($course1, $email1, $start1, 1), $suffix . '-idempotency-g1-20261115', null, $student1
    );
} catch (Throwable $error) { $error1 = $error->getMessage(); }
remove_filter('query', $failOfferNotification, 10);
dzn_stress_assert($error1 !== '', 'G1 expected the injected offer-notification failure');
dzn_stress_assert($countByEmail($email1) === 0, 'G1 left a committed request after the notification failure');
dzn_stress_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries WHERE teacher_id=%d AND booking_request_id NOT IN (SELECT id FROM {$p}booking_requests WHERE student_id=%d)", (int) $teacher1['teacher_id'], $student1)) === 0, 'G1 left an orphan delivery');
$out['offer_notification_failure'] = array('error' => $error1, 'committed_requests' => $countByEmail($email1));

// 2. Delivery/assignment write fails before any offer; the request must also roll back.
$course2 = dzn_stress_course($suffix, 'g-delivery', 'G Delivery');
$teacher2 = dzn_stress_teacher($suffix, 'G Delivery Teacher', array($course2['course_id']));
$student2 = dzn_stress_student($suffix, 'G Delivery Student');
$start2 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+21 days')->setTime(10, 0)->format('Y-m-d H:i:s');
$email2 = 'rollback-delivery-' . $suffix . '@example.invalid';
$failDelivery = static function (string $sql) use ($p): string|bool {
    return str_contains($sql, "INSERT INTO `{$p}booking_request_teacher_deliveries`") ? false : $sql;
};
add_filter('query', $failDelivery, 10, 1);
$error2 = '';
try {
    (new Delnavazan\Platform\Core\Application\BookingRequestSubmissionService())->submitPublic(
        $publicInput($course2, $email2, $start2, 2), $suffix . '-idempotency-g2-20261115', null, $student2
    );
} catch (Throwable $error) { $error2 = $error->getMessage(); }
remove_filter('query', $failDelivery, 10);
dzn_stress_assert($error2 !== '', 'G2 expected the injected delivery failure');
dzn_stress_assert($countByEmail($email2) === 0, 'G2 left a committed request after the delivery failure');
$out['delivery_failure'] = array('error' => $error2, 'committed_requests' => $countByEmail($email2));

// 3. A notification inbox write fails once; the alert remains unnotified and is retried.
$course3 = dzn_stress_course($suffix, 'g-alert', 'G Alert');
$student3 = dzn_stress_student($suffix, 'G Alert Student');
$time3 = dzn_stress_time((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+22 days')->setTime(10, 0)->format('Y-m-d H:i:s'));
$request3 = dzn_stress_request($student3, $course3['instrument_id'], $course3['course_id'], array($time3), $suffix, 'G3');
$offer3 = dzn_stress_allocate($request3['request_id'], $course3['course_id'], dzn_stress_times_for_allocation($request3['request_id']));
dzn_stress_assert($offer3 === null, 'G3 expected a referral with no eligible Teacher');
$alert3 = $wpdb->get_row($wpdb->prepare("SELECT id,notified_at FROM {$p}booking_allocation_alerts WHERE booking_request_id=%d AND kind='referral'", $request3['request_id']));
dzn_stress_assert($alert3 && $alert3->notified_at === null, 'G3 referral alert was already notified');
$wpdb->query($wpdb->prepare("UPDATE {$p}booking_allocation_alerts SET notified_at=%s WHERE kind='referral' AND id<>%d AND notified_at IS NULL", gmdate('Y-m-d H:i:s'), (int) $alert3->id));
$failInbox = static function (string $sql) use ($p): string|bool {
    return str_contains($sql, "INSERT INTO `{$p}portal_notifications`") ? false : $sql;
};
add_filter('query', $failInbox, 10, 1);
Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
remove_filter('query', $failInbox, 10);
$afterFail = $wpdb->get_row($wpdb->prepare("SELECT notified_at FROM {$p}booking_allocation_alerts WHERE id=%d", (int) $alert3->id));
dzn_stress_assert($afterFail && $afterFail->notified_at === null, 'G3 marked the alert notified despite notification failure');
Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
$afterRetry = $wpdb->get_row($wpdb->prepare("SELECT notified_at FROM {$p}booking_allocation_alerts WHERE id=%d", (int) $alert3->id));
dzn_stress_assert($afterRetry && $afterRetry->notified_at !== null, 'G3 did not retry the alert notification');
$inboxCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}portal_notifications WHERE source_kind='booking_allocation_alert' AND source_id=%d", (int) $alert3->id
));
dzn_stress_assert($inboxCount === 1, 'G3 created ' . $inboxCount . ' inbox rows');
Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
$inboxCount2 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}portal_notifications WHERE source_kind='booking_allocation_alert' AND source_id=%d", (int) $alert3->id
));
dzn_stress_assert($inboxCount2 === 1, 'G3 duplicated the inbox row on a second worker run');
$out['notification_failure_recovery'] = array('first_state' => $afterFail->notified_at, 'retry_state' => $afterRetry->notified_at, 'inbox_rows' => $inboxCount2);

// 4. Projection failure: confirmation is retryable, replay is idempotent, no duplicate confirmation.
$course4 = dzn_stress_course($suffix, 'g-projection', 'G Projection');
$teacher4 = dzn_stress_teacher($suffix, 'G Projection Teacher', array($course4['course_id']));
$student4 = dzn_stress_student($suffix, 'G Projection Student');
$time4 = dzn_stress_time((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+23 days')->setTime(10, 0)->format('Y-m-d H:i:s'));
$request4 = dzn_stress_request($student4, $course4['instrument_id'], $course4['course_id'], array($time4), $suffix, 'G4');
$offer4 = dzn_stress_allocate($request4['request_id'], $course4['course_id'], dzn_stress_times_for_allocation($request4['request_id']));
dzn_stress_assert($offer4 !== null, 'G4 expected a Teacher offer');
$delivery4 = dzn_stress_delivery($request4['request_id']);
$failing = new class {
    public function dispatch(int $confirmationId): array { throw new RuntimeException('synthetic_google_calendar_failure'); }
};
$first = dzn_stress_accept((int) $teacher4['user_id'], (string) $delivery4->uid, $suffix . '-g4-command', null, $failing);
$confirmation4 = (int) $first['confirmation_id'];
$state4 = (string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_confirmations WHERE id=%d", $confirmation4));
dzn_stress_assert(in_array($state4, array('pending_projection', 'retryable'), true), 'G4 confirmation is not retryable: ' . $state4);
$replay = dzn_stress_accept((int) $teacher4['user_id'], (string) $delivery4->uid, $suffix . '-g4-command', null, $failing);
dzn_stress_assert(!empty($replay['idempotent']) && (int) $replay['confirmation_id'] === $confirmation4, 'G4 acceptance replay was not idempotent');
$count4 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $request4['request_id']));
dzn_stress_assert($count4 === 1, 'G4 replay created ' . $count4 . ' confirmations');
$out['projection_failure_replay'] = array('confirmation_state' => $state4, 'confirmation_id' => $confirmation4, 'confirmations' => $count4, 'replay_idempotent' => !empty($replay['idempotent']));

dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Scenario G produced a Teacher double booking');
dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Scenario G produced a capacity violation (confirmed or live-offer overlap)');
dzn_stress_emit('G_failure_recovery', array('pass' => true, 'cases' => $out));
