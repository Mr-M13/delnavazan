<?php
/** Regression contract for confirmed introductory bookings in the Teacher portal. */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme/inc/platform-bridge.php';
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$read = (string) file_get_contents($root . '/src/Portals/TeacherUpcomingBookingReadService.php');
$projector = (string) file_get_contents($root . '/src/Portals/BookingUpcomingReadProjector.php');
$portal = (string) file_get_contents($root . '/src/Portals/PortalAuthenticatedReadService.php');
$bridge = (string) file_get_contents($theme);
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

foreach (array(
    "c.teacher_id=%d",
    "d.teacher_id=c.teacher_id",
    "c.state='confirmed'",
    "d.state='confirmed'",
    "r.lifecycle_status='closed'",
    "r.resolution_state='teacher_confirmed'",
    'r.privacy_erased_at IS NULL',
    "secret.mapping_kind='booking_calendar'",
    "secret.state='active'",
    'secret.active_slot=1',
    'c.provider_event_digest IS NOT NULL',
    'c.confirmed_at IS NOT NULL',
    'c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR',
) as $needle) $assert(str_contains($repository, $needle), 'Confirmed booking query lost truth/ownership guard: ' . $needle);

$assert(str_contains($read, "current_user_can('dzn_view_own_portal_schedule')"), 'Confirmed booking reads must retain portal schedule authorization.');
$assert(str_contains($read, "resolve('teacher')") && str_contains($read, "principal['id'] !== \$teacherId"), 'Confirmed booking reads must re-prove the owning Teacher principal.');
$assert(str_contains($read, "'teacher_booking_class_v1'") && str_contains($projector, "'version' => \$version") && !str_contains($projector, "'provider_event_digest' =>"), 'The portal projection must be typed and must not expose provider evidence.');
$assert(str_contains($portal, "'booking_classes' => \$bookingClasses") && str_contains($portal, 'TeacherUpcomingBookingReadService'), 'Authenticated Teacher reads must include the confirmed-booking projection.');
$assert(str_contains($bridge, "'teacher_booking_class_v1'") && str_contains($bridge, "'confirmation_state'") && str_contains($bridge, "'details_available' => ! \$is_booking"), 'The Theme must render confirmed bookings without inventing canonical Lesson actions.');
$assert(str_contains($repository, 'LEFT JOIN {$this->prefix}provider_projection_secrets secret'), 'A missing provider reference must not hide an otherwise confirmed class.');
echo "Booking upcoming classes contract passed\n";
