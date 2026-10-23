<?php
/** Deterministic read-model regression for confirmed introductory bookings in Admin Classes/Academy. */
$GLOBALS['capabilities'] = array('dzn_manage_lessons' => true, 'dzn_view_booking_requests' => true);
function current_user_can(string $capability): bool { return !empty($GLOBALS['capabilities'][$capability]); }

require dirname(__DIR__) . '/src/Core/Application/ConfirmedIntroBookingReadPort.php';

final class BookingAdminVisibilityReadPort implements \Delnavazan\Platform\Core\Application\ConfirmedIntroBookingReadPort {
    public function __construct(private array $rows) {}
    public function confirmedUpcomingForOperator(int $limit = 80): array { return array_slice($this->rows, 0, $limit); }
}

require dirname(__DIR__) . '/src/Core/Application/ConfirmedIntroBookingReadService.php';

use Delnavazan\Platform\Core\Application\ConfirmedIntroBookingReadService as Service;

$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$base = static function (array $overrides = array()): object {
    $start = time() + 86400;
    return (object) ($overrides + array(
        'confirmation_uid' => str_repeat('A', 26),
        'confirmation_state' => 'confirmed',
        'delivery_state' => 'confirmed',
        'lifecycle_status' => 'closed',
        'resolution_state' => 'teacher_confirmed',
        'privacy_erased_at' => null,
        'provider_code' => 'google_calendar',
        'confirmed_at' => gmdate('Y-m-d H:i:s'),
        'booking_request_id' => 41,
        'request_reference' => 'DZN-REQ-41',
        'student_display_reference' => 'Synthetic Student',
        'teacher_display_reference' => 'Synthetic Teacher',
        'course_reference' => 'کلاس معارفه سه‌تار',
        'local_wall_date' => gmdate('Y-m-d', $start),
        'local_wall_time' => '10:00:00',
        'schedule_timezone' => 'Australia/Brisbane',
        'starts_at_utc' => gmdate('Y-m-d H:i:s', $start),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', $start + 1800),
        'projection_secret_id' => 5,
    ));
};

$valid = $base();
$later = $base(array('confirmation_uid' => str_repeat('B', 26), 'booking_request_id' => 42, 'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 172800), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 172800 + 1800)));
$invalid = array(
    $base(array('confirmation_state' => 'pending')),
    $base(array('delivery_state' => 'pending_request')),
    $base(array('lifecycle_status' => 'submitted')),
    $base(array('resolution_state' => 'unresolved')),
    $base(array('privacy_erased_at' => '2026-01-01 00:00:00')),
    $base(array('provider_code' => 'other')),
    $base(array('confirmed_at' => '')),
    $base(array('confirmation_uid' => 'bad')),
    $base(array('booking_request_id' => 0)),
    $base(array('teacher_display_reference' => '')),
    $base(array('course_reference' => '')),
    $base(array('local_wall_date' => 'not-a-date')),
    $base(array('local_wall_time' => '10:00')),
    $base(array('schedule_timezone' => 'Not/AZone')),
    $base(array('starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 10800), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 9000))),
    $base(array('ends_at_utc' => $base()->starts_at_utc)),
);
$service = new Service(new BookingAdminVisibilityReadPort(array_merge(array($later), $invalid, array($valid))));
$rows = $service->upcoming();
$expect(count($rows) === 2, 'only valid confirmed upcoming introductory bookings may reach the operator schedule');
$expect((int) $rows[0]->booking_request_id === 41 && (int) $rows[1]->booking_request_id === 42, 'operator intro rows must be ordered by their UTC start');
$row = $rows[0];
$expect($row->record_model === 'confirmed_intro_booking_v1', 'confirmed intro must be explicitly distinguished from a Lesson');
$expect((int) $row->id === 0, 'confirmed intro must not expose a fabricated Lesson identity');
$expect($row->class_status === 'Confirmed intro' && $row->next_action === 'Open booking', 'confirmed intro must lead the operator to the booking request');
$expect($row->reference_code === 'DZN-REQ-41' && (int) $row->booking_request_id === 41, 'confirmed intro must retain its booking-request identity');
$expect($row->schedule_label === gmdate('Y-m-d', time() + 86400) . ' · 10:00 Australia/Brisbane', 'confirmed intro must preserve the stored local wall clock and timezone');
$expect($row->has_join_reference === true, 'a present provider reference must be exposed only as a safe boolean');
foreach (array('projection_secret_id', 'provider_event_digest', 'privacy_erased_at', 'confirmation_state', 'delivery_state') as $forbidden) {
    $expect(!property_exists($row, $forbidden), 'confirmed intro must not expose internal/provider field: ' . $forbidden);
}

$GLOBALS['capabilities']['dzn_view_booking_requests'] = false;
$denied = null;
try { $service->upcoming(); } catch (RuntimeException $error) { $denied = $error; }
$expect($denied !== null && $denied->getMessage() === 'Unauthorized', 'booking-request visibility capability is required for confirmed intro rows');
$GLOBALS['capabilities']['dzn_view_booking_requests'] = true;
$GLOBALS['capabilities']['dzn_manage_lessons'] = false;
$denied = null;
try { $service->upcoming(); } catch (RuntimeException $error) { $denied = $error; }
$expect($denied !== null && $denied->getMessage() === 'Unauthorized', 'Classes capability is required for confirmed intro rows');

echo "PASS confirmed introductory bookings: admin read model, validation and capability boundary\n";
