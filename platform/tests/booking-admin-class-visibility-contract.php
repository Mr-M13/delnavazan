<?php
/** Confirmed introductory bookings must reach the operator Classes/Academy read model without Lesson fabrication. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $haystack, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$repository = $read('Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$service = $read('Core/Application/ConfirmedIntroBookingReadService.php');
$coreRead = $read('Core/Application/CoreReadService.php');
$screen = $read('Admin/Controller/ScreenController.php');
$academy = $read('Admin/Controller/AcademyOperationsController.php');

$contains($repository, array(
    'confirmedUpcomingForOperator',
    "c.state='confirmed'",
    "d.state='confirmed'",
    "r.lifecycle_status='closed'",
    "r.resolution_state='teacher_confirmed'",
    'r.privacy_erased_at IS NULL',
    "c.provider_code='google_calendar'",
    'c.provider_event_digest IS NOT NULL',
    'c.confirmed_at IS NOT NULL',
    'c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR',
    'NOT EXISTS',
    'l.booking_request_id=r.id',
    'l.archived_at IS NULL',
    'secret.id projection_secret_id',
), 'operator confirmed-booking projection must reuse the confirmation guards and stop after the canonical Lesson exists');
$operatorSql = substr($repository, (int) strpos($repository, 'function confirmedUpcomingForOperator'), (int) strpos($repository, 'function confirmedForParticipant') - (int) strpos($repository, 'function confirmedUpcomingForOperator'));
$expect(!str_contains($operatorSql, 'secret.ciphertext') && !str_contains($operatorSql, 'secret.nonce'), 'operator projection must never expose join secrets');
$expect(!str_contains($operatorSql, 'c.teacher_id=%d'), 'operator projection must not be restricted to one Teacher');

$contains($service, array(
    "current_user_can('dzn_manage_lessons')",
    "current_user_can('dzn_view_booking_requests')",
    "record_model = 'confirmed_intro_booking_v1'",
    "class_status = 'Confirmed intro'",
    "next_action = 'Open booking'",
    'booking_request_id',
    'has_join_reference',
    'end + 7200 < time()',
), 'confirmed-intro operator service must be capability-gated, bounded and operator-readable');
foreach (array('INSERT ', 'UPDATE ', 'DELETE ', 'LessonService', 'wp_insert', 'insertLesson', 'schedule(') as $forbidden) {
    $expect(!str_contains($service, $forbidden), 'confirmed-intro read service must not write or fabricate class truth: ' . $forbidden);
}

$contains($coreRead, array(
    'public function classOperatorRows(): array',
    'new LessonOperationsReadService())->recent()',
    'new ConfirmedIntroBookingReadService())->upcoming()',
    "current_user_can('dzn_view_booking_requests')",
), 'the composite Classes read seam must merge confirmed intros into the existing Lesson projection');
$contains($screen, array(
    'classOperatorRows()',
    'confirmed_intro_booking_v1',
    "page'=>'dzn-booking-requests'",
    'Confirmed introductory bookings are shown from their booking confirmation',
), 'Admin Classes must render confirmed intros with their booking-request handoff');
$contains($academy, array(
    'classOperatorRows()',
    'confirmed_intro_booking_v1',
    'classUrl',
    "page' => 'dzn-booking-requests'",
), 'Academy today/upcoming must reuse the same composite Classes projection');

echo "PASS confirmed introductory bookings in Admin Classes/Academy without Lesson fabrication\n";
