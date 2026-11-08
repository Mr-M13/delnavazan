<?php
/**
 * Source contract for the Intro / Review enrolment changes and the shared
 * introductory cancellation/reschedule lifecycle.
 */
$root = dirname(__DIR__);
$repo = dirname($root);
$read = static function (string $path) use ($root, $repo): string {
    $file = $root . '/' . ltrim($path, '/');
    if (!is_file($file)) $file = $repo . '/' . ltrim($path, '/');
    if (!is_file($file)) throw new RuntimeException('Missing source file: ' . $path);
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$contains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};
$notContains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(!str_contains($haystack, $needle), $message . ': ' . $needle);
};

$view = $read('src/Admin/View/EnrolmentWorkspaceView.php');
$controller = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$readService = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$lifecycle = $read('src/Core/Application/IntroLessonLifecycleService.php');
$portalController = $read('src/Portals/IntroLessonLifecycleController.php');
$match = $read('src/Core/Application/AdministratorBookingMatchService.php');
$worker = $read('src/Integrations/BookingStudentNotificationWorker.php');
$studentSchedule = $read('theme/template-parts/portal/schedule.php');
$teacherClasses = $read('theme/template-parts/teacher-portal/classes.php');
$teacherActions = $read('theme/template-parts/teacher-portal/intro-actions.php');
$bootstrap = $read('delnavazan-platform.php');
$css = $read('src/Admin/Assets/admin.css');
$studentShell = $read('theme/template-parts/portal/shell.php');
$teacherShell = $read('theme/template-parts/teacher-portal/shell.php');
$teacherHome = $read('theme/template-parts/teacher-portal/home.php');
$banner = $read('theme/template-parts/components/notification-banner.php');
$themeCss = $read('theme/assets/css/theme.css');

// 1–2. Enrolment is one surface; intro review and conversion live inside it.
$contains($view, array('public static function dashboard(', '<h1>Enrolment</h1>', 'Overview snapshot'), 'Enrolment must be a single overview surface');
$notContains($view, array('Enrolment administration</p>', '<p class="dzn-eyebrow">Enrolment administration'), 'The old Enrolment administration card text must not remain');

// 3. Conversion overview carries student, course, teacher, date and elapsed time.
$contains($readService, array(
    's.full_name student_name',
    "'course' => (string) (\$row->course_name ?: '—')",
    "'teacher' => (string) (\$row->teacher_name ?: 'Not selected')",
    "'date' => \$row->intro_at",
    "'elapsed' => \$this->ageLabel(\$elapsed)",
), 'Conversion reporting must include student, course, teacher, date and elapsed time');
$contains($view, array('<th>Student</th><th>Course</th><th>Teacher</th><th>Class date</th><th>Time elapsed</th>'), 'Conversion overview table must show the requested columns');

// 4. Local start time and service-area timezone are controlled dropdowns.
$contains($view, array(
    'private static function timeSelect(',
    'private static function timezoneSelect(',
    "GoldenHourRule::defaults()",
    "self::timeSelect('local_start_time'",
    "self::timezoneSelect('schedule_timezone'",
), 'Create intro must use dropdowns for local start time and service-area timezone');
$contains($view, array("intdiv(\$minute, 60), \$minute % 60"), 'Local start time options must be generated from fixed clock values');

// 5. One Create action performs check and save; errors return to the form.
$contains($view, array('operation" value="create_intro"', '<button class="button button-primary">Create</button>'), 'The create form must use one Create button');
$notContains($view, array('Check this slot', 'preview_intro', 'Create introductory booking</button>'), 'The separate preview/check form and old button label must not remain');
$contains($controller, array(
    "set_transient('dzn_intro_form_'",
    "'dzn_form' => 'intro'",
    "'dzn-create-intro'",
    "private static function introFormValues",
), 'Create errors must preserve the form and return focus to it instead of the page top');
$contains($view, array('dzn-intro-form-error', 'Introductory lesson was not created.'), 'Create errors must render inside the form');

// 6. Administrator manual/intro match must dispatch the same projector as the teacher path.
$contains($match, array(
    'new BookingTeacherResponseService(null, $projector)',
    'BookingCalendarProjectionService',
    'confirmation never becomes portal-visible',
), 'Administrator manual matching must project the booking and queue its notifications');
$contains($worker, array(
    "==='cancelled')", "==='cancelled_teacher')", "==='rescheduled')", "==='rescheduled_teacher')",
    'booking.cancelled.student', 'booking.rescheduled.teacher',
), 'Booking notification worker must handle cancellation and reschedule for both audiences');

// 7. Review queue contains the required facts and the 14-day removal rule.
$contains($readService, array(
    'public function reviewCases()',
    "'payment_link_sent'",
    "'follow_up_completed'",
    "'reminder'",
    "'attended'",
    '14 * 86400',
    'auto_removed',
), 'Review queue must expose active intro facts and the fourteen-day removal rule');
$contains($view, array(
    '<th>Date</th><th>Instrument</th><th>Teacher</th><th>Student</th><th>Status</th><th>Country</th><th>Attended</th><th>Payment link sent</th><th>Reminder</th><th>Follow-up completed</th>',
    'removed from this active queue',
), 'Review table must show the requested columns and explain the removal rule');

// 8. Admin, Teacher and Student cancellation; Admin and Teacher reschedule.
$contains($lifecycle, array(
    'public function cancel(',
    'public function reschedule(',
    "if (!in_array(\$actorRole, array('admin', 'teacher'), true))",
    'PortalPrincipalResolver',
    'dzn_manage_booking_request_coordination',
    'CanonicalTeacherOccupancyReadService',
    'LessonRescheduleRepository',
    "queueNotification((int) \$confirmation->delivery_id, (int) \$confirmation->id, 'cancelled'",
    "'rescheduled_teacher'",
    'platform_audit_events',
), 'Intro lifecycle must enforce actor scope, real collisions, audit and notifications');
$contains($controller, array("case 'intro_reschedule':", "case 'intro_cancel':"), 'Admin Intro case must expose cancel and reschedule operations');
$contains($portalController, array(
    'TEACHER_CANCEL', 'TEACHER_RESCHEDULE', 'STUDENT_CANCEL',
    'dzn_teacher_cancel_intro', 'dzn_teacher_reschedule_intro', 'dzn_student_cancel_intro',
), 'Portal controller must register Teacher and Student intro actions');
$contains($bootstrap, array('IntroLessonLifecycleController::register()'), 'Portal intro controller must be registered');
$contains($read('src/Admin/Controller/AdminShellController.php'), array(
    "['Enrolment', 'dzn-enrolment-workspace'",
), 'The shared Enrolment area navigation must expose one Enrolment surface');
$contains($studentSchedule, array('intro_cancel_available', 'intro_cancel_action_url', 'لغو کلاس آشنایی'), 'Student schedule must render the intro cancel action');
$contains($teacherClasses, array("dzn_theme_teacher_portal_component( 'intro-actions'"), 'Teacher classes must render intro actions');
$contains($teacherActions, array('intro_cancel_action_url', 'intro_reschedule_action_url', 'intro_reschedule_nonce', 'منطقهٔ زمانی', 'تغییر زمان کلاس آشنایی', 'لغو کلاس آشنایی'), 'Teacher intro actions must offer cancel and reschedule');
$contains($css, array('.dzn-enrolment__page-head', '.dzn-review-table'), 'Layout must style the renamed page head and review table');

// Portal notification card is replaced by a narrow bottom banner, shown only when unread mail exists.
$notContains($studentShell, array("dzn_theme_portal_component(\n\t\t\t\t'notifications'", "'notifications',\n"), 'Student portal must not render the notification card');
$notContains($teacherHome, array("'notifications'"), 'Teacher home must not render the notification card');
$contains($studentShell, array("dzn_theme_portal_component( 'notification-banner'", "'role' => 'student'"), 'Student portal must render the shared notification banner');
$contains($teacherShell, array("dzn_theme_teacher_portal_component( 'notification-banner'", "'role' => 'teacher'"), 'Teacher portal must render the shared notification banner');
$contains($banner, array(
    'data-dzn-notification-banner',
    'data-dzn-notification-close',
    'if ( ! $item )',
    'is_unread',
    'خوانده شد',
    'بستن اعلان',
), 'Notification banner must be unread-only, linked and closable');
$contains($banner, array('type="submit" data-dzn-notification-close'), 'The close control must be the same action as acknowledging the notification');
$contains($banner, array('مشاهدهٔ جزئیات'), 'The notification banner must keep the link to the area it is about');
$contains($themeCss, array('.dzn-notification-banner{position:fixed', '.dzn-notification-banner[hidden]{display:none', '@media(max-width:47.99rem)'), 'Notification banner must sit at the bottom and hide on close');

echo "enrolment intro review contract passed\n";
