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
$assert(str_contains($bridge, "'teacher_booking_class_v1' === ( \$row['version'] ?? '' )") && str_contains($bridge, 'dzn_theme_platform_within_join_window') && str_contains($bridge, 'ParticipantJoinWindow'), 'Teacher and Student presentation must use the shared 30-minute participant join window.');
$assert(str_contains($classroomActions, 'confirmation_uid') && str_contains($studentTemplate, 'classroom-actions') && str_contains($teacherTemplate, 'classroom-actions'), 'Both participant UIs must submit the booking confirmation identity.');
$assert(!str_contains($bridge, "'join_url' => 'https://meet.google.com"), 'The Theme must never fabricate a Meet URL.');

/*
 * One introductory session, one row.
 *
 * The confirmed booking and the introductory Lesson provisioned for it are two projections of the
 * same class. The Student card must show it once, with the booking's Teacher and join semantics and
 * the Lesson's delivery/recording facts. Load the real Theme adapter and exercise the merge.
 */
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!function_exists('add_filter')) { function add_filter(...$args) { return true; } }
require_once dirname($root) . '/theme/inc/platform-bridge.php';
$row = static function (array $overrides): array {
    return array_merge(array(
        'kind' => 'intro', 'lesson_kind' => 'introductory', 'starts_at_utc' => '2026-11-17 13:00:00',
        'course' => 'سه تار', 'teacher' => 'مدرس جدید', 'outcome_label' => 'وضعیت برگزاری ثبت نشده',
        'outcome_tone' => 'muted', 'delivery_state' => '', 'attendance_state' => '',
        'lesson_uid' => '', 'schedule_version_uid' => '', 'recording_uid' => '',
        'recording_available' => false, 'recording_status_label' => '', 'recording_state' => '',
    ), $overrides);
};
$booked = $row(array('kind' => 'intro', 'join_available' => true));
$lesson = $row(array('kind' => 'term', 'teacher' => '', 'course' => 'کلاس معارفه سه تار', 'outcome_label' => 'برگزار شد', 'outcome_tone' => 'success', 'delivery_state' => 'delivered', 'lesson_uid' => 'LSN-1', 'recording_available' => true, 'recording_uid' => 'REC-1'));
$merged = dzn_theme_platform_merge_intro_rows(array($lesson, $booked));
$assert(count($merged) === 1, 'The confirmed booking and its introductory Lesson must render as one class row.');
$assert('intro' === $merged[0]['kind'] && 'مدرس جدید' === $merged[0]['teacher'] && $merged[0]['join_available'] === true, 'The surviving row must keep the booking Teacher and join availability.');
$assert('برگزار شد' === $merged[0]['outcome_label'] && 'delivered' === $merged[0]['delivery_state'] && 'LSN-1' === $merged[0]['lesson_uid'] && $merged[0]['recording_available'] === true, 'The surviving row must carry the Lesson delivery, attendance and recording facts.');
$assert(count(dzn_theme_platform_merge_intro_rows(array($lesson, $row(array('lesson_kind' => 'standard', 'kind' => 'term'))))) === 2, 'A regular Lesson that merely starts at the same instant must not be folded into a booking.');
$assert(count(dzn_theme_platform_merge_intro_rows(array($lesson))) === 1, 'An introductory Lesson without a confirmed booking must remain visible.');
$double = dzn_theme_platform_merge_intro_rows(array($row(array('kind' => 'term', 'lesson_uid' => 'A')), $row(array('kind' => 'term', 'lesson_uid' => 'B')), $booked));
$assert(count($double) === 2 && 'intro' === $double[1]['kind'], 'One booking must consume exactly one introductory Lesson.');
$assert(count(dzn_theme_platform_merge_intro_rows(array($booked))) === 1, 'A booking with no provisioned Lesson must remain visible on its own.');

/*
 * A delivered introductory outcome is the attendance fact for its legacy Lesson.
 *
 * The booking-linked introductory Lesson is legacy_phase1 and has no Phase-O delivery outcome or
 * Phase-P attendance case. The Teacher-recorded `delivered` outcome must project onto the class,
 * student-history and portal reads as delivered/attended instead of "No evidence".
 */
require_once $root . '/src/Core/Application/IntroLessonOutcomeProjection.php';
$projection = '\\Delnavazan\\Platform\\Core\\Application\\IntroLessonOutcomeProjection';
$assert($projection::isDelivered('legacy_phase1', 'introductory', 91, 'delivered'), 'A delivered booking-linked introductory Lesson must project as delivered.');
$assert(!$projection::isDelivered('legacy_phase1', 'introductory', 0, 'delivered'), 'A standalone introductory Lesson has no booking outcome to project.');
$assert(!$projection::isDelivered('canonical_term_lesson_v1', 'standard', 91, 'delivered'), 'A canonical Term Lesson must never use the introductory projection.');
$assert(!$projection::isDelivered('legacy_phase1', 'introductory', 91, 'student_no_show'), 'A non-delivered outcome must not mark attendance.');
$assert($projection::attendanceState('legacy_phase1', 'introductory', 91, 'delivered') === 'attended', 'The delivered outcome must project canonical attended vocabulary.');
$assert($projection::deliveryStateSummary('legacy_phase1', 'introductory', 91, 'delivered') === 'delivered' && $projection::attendanceStateSummary('legacy_phase1', 'introductory', 91, 'delivered') === 'settled', 'The portal projection must expose delivered and settled.');

$enrolmentRead = (string) file_get_contents($root . '/src/Core/Application/EnrolmentWorkspaceReadService.php');
$studentRead = (string) file_get_contents($root . '/src/Core/Application/StudentWorkspaceReadService.php');
$portalRead = (string) file_get_contents($root . '/src/Portals/PortalAuthenticatedReadService.php');
$teacherRead = (string) file_get_contents($root . '/src/Core/Application/TeacherWorkspaceReadService.php');
foreach (array('enrolment' => $enrolmentRead, 'student' => $studentRead, 'portal' => $portalRead, 'teacher' => $teacherRead) as $surface => $source) {
    $assert(str_contains($source, 'booking_request_intro_outcomes') && str_contains($source, 'IntroLessonOutcomeProjection'), 'The ' . $surface . ' read surface must join the introductory outcome and use the projection.');
}
$assert(str_contains($portalRead, "'delivery_state_summary' => \$introDelivered ? 'delivered'") && str_contains($portalRead, "'attendance_state_summary' => \$introDelivered ? IntroLessonOutcomeProjection::SETTLED"), 'The legacy portal Lesson must expose a delivered introductory outcome as delivered and settled.');
$assert(str_contains($enrolmentRead, "\$row->attendance_source='intro_outcome'") && str_contains($enrolmentRead, 'Recorded by the introductory outcome.'), 'The class record must attribute projected attendance to the introductory outcome.');
$assert(str_contains($studentRead, 'Attended · recorded introductory outcome'), 'The student history must show the projected introductory attendance.');
echo "Booking intro portal contract passed\n";
