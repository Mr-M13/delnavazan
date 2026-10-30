<?php
$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$student = (string) file_get_contents($root . '/src/Portals/StudentUpcomingBookingReadService.php');
$join = (string) file_get_contents($root . '/src/Integrations/BookingClassJoinService.php');
$bridge = (string) file_get_contents(dirname($root) . '/theme/inc/platform-bridge.php');
$studentTemplate = (string) file_get_contents(dirname($root) . '/theme/template-parts/portal/upcoming-lesson.php');
$teacherTemplate = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/classes.php');
$classroomActions = (string) file_get_contents(dirname($root) . '/theme/template-parts/components/classroom-actions.php');
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (array(
    "r.student_id=%d", 'identity.id=r.current_identity_resolution_id', "identity.outcome='resolved'",
    "c.state='confirmed'", "d.state='confirmed'", "r.lifecycle_status='closed'", "r.resolution_state='teacher_confirmed'",
) as $needle) $assert(str_contains($repository, $needle), 'Student booking ownership/truth guard missing: ' . $needle);
$assert(str_contains($student, "resolve('student')") && str_contains($student, "'student_booking_class_v1'"), 'Student booking projection lost principal authorization or typing.');
$assert(str_contains($bridge, '! $booking_classes') && str_contains($bridge, "'free_introductory'") && str_contains($bridge, 'dzn_student_join_booking_class'), 'Free intro remains incorrectly gated by enrolment or lacks its join action.');
$assert(str_contains($join, "resolve(\$role)") && str_contains($join, 'confirmedForParticipant') && str_contains($join, "open('booking_calendar'"), 'Booking join must re-prove the participant and open only its vaulted reference.');
$assert(str_contains($join, "'meet.google.com'") && str_contains($join, "'https'"), 'Booking join must restrict decrypted redirect targets to HTTPS Google Meet URLs.');
$assert(!str_contains((string) file_get_contents($root . '/src/Portals/BookingUpcomingReadProjector.php'), '->open('), 'Portal reads must not decrypt Meet material before an authenticated join action.');
$assert(str_contains($repository, 'c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR'), 'Started classes must remain visible throughout the late join window.');
$assert(str_contains($repository, 'd.requested_time_id=c.requested_time_id'), 'Booking join ownership must retain the accepted requested-time binding.');
$assert(str_contains($bridge, "'teacher_booking_class_v1' === ( \$row['version'] ?? '' )") && str_contains($bridge, '$end + ( 2 * HOUR_IN_SECONDS ) >= $now'), 'Teacher presentation must retain started booking classes throughout the late join window.');
$assert(str_contains($classroomActions, 'confirmation_uid') && str_contains($studentTemplate, 'classroom-actions') && str_contains($teacherTemplate, 'classroom-actions'), 'Both participant UIs must submit the booking confirmation identity.');
$assert(!str_contains($bridge, "'join_url' => 'https://meet.google.com"), 'The Theme must never fabricate a Meet URL.');
echo "Booking intro portal contract passed\n";
