<?php
/** Source contract for the consolidated Enrolment administration workspace. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};
$notContains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(!str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $read('src/Admin/Controller/AdminShellController.php');
$menu = $read('src/Admin/Controller/Menu.php');
$controller = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$view = $read('src/Admin/View/EnrolmentWorkspaceView.php');
$readService = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$manual = $read('src/Core/Application/AdministratorBookingMatchService.php');
$intro = $read('src/Core/Application/AdministratorIntroBookingService.php');
$import = $read('src/Core/Application/HistoricalEnrolmentImportService.php');
$postIntro = $read('src/Core/Application/PostIntroNotificationAdministrationService.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$css = $read('src/Admin/Assets/admin.css');
$bootstrap = $read('delnavazan-platform.php');
$worker = $read('src/Integrations/BookingStudentNotificationWorker.php');

$expect(3 === preg_match_all("/\\['(?:Intro|Review|Enrolled classes)'/", $shell), 'Enrolment must expose exactly three sub-tabs.');
$contains($shell, array(
    "['Intro', 'dzn-enrolment-workspace', Menu::ENROLMENT_TRIALS_CAP, ['view' => 'new']]",
    "['Review', 'dzn-enrolment-workspace', Menu::ENROLMENT_TRIALS_CAP, ['view' => 'review']]",
    "['Enrolled classes', 'dzn-enrolment-workspace', Menu::ENROLLED_CLASSES_CAP, ['view' => 'enrolled']]",
    "'dzn-catalogue-workspace' => Menu::CATALOGUE_WORKSPACE_CAP",
), 'The three Enrolment destinations and the protected Settings catalogue route are required.');
$general = $read('src/Admin/Controller/GeneralSettingsController.php');
$contains($general, array("admin.php?page=dzn-catalogue-workspace", 'Open courses and instruments'), 'The catalogue must remain discoverable from General.');
$notContains($shell, array("['Classes & Schedules'", "['Attendance & Post-intro'", "['Bookings & Enrolments'", "['Courses & Instruments'"), 'The former four visible Enrolment destinations must be gone.');
$contains($menu, array(
    "['Booking requests', 'dzn_view_booking_requests', 'dzn-booking-requests'",
    "['Request coordination', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination'",
    "['Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment'",
    "['Terms', 'dzn_manage_terms', 'dzn-term'",
    "['Classes', 'dzn_manage_lessons', 'dzn-lesson'",
    "['Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review'",
    "['Courses', 'dzn_manage_courses', 'dzn-course'",
    "['Instruments', 'dzn_manage_courses', 'dzn-instrument'",
), 'Every protected legacy route and its original capability must remain registered.');
$contains($bootstrap, array('EnrolmentWorkspaceController::register()', 'LiveKitAdministratorClassroomController::register()'), 'The workspace and administrator classroom boundaries must be registered.');

$contains($view, array(
    'Action needed',
    'History',
    'Completed, cancelled, expired and archived cases',
    'Requested times',
    'Country / timezone',
    'Teacher response',
    'Match attempts',
    'Prior booking history',
    'Notification evidence',
    'Sent to transport',
    'Delivered',
    'click status unavailable',
    'Import existing enrolment',
    'Manual Teacher match',
    'Shared class action component',
    'Open administrator classroom',
    'Copy student portal link',
    'Copy teacher portal link',
    'Open backup Google Meet',
    'Attendance evidence',
    'Confirm attended',
    'Converted',
    'Awaiting decision or payment',
    'Did not convert',
    'Excluded / reviewed separately',
    'Diagnostics',
), 'The workspace must expose the required facts, states and actions.');
$notContains($view, array('A clicked link proves attendance', 'outbox proves delivery'), 'The workspace must not imply delivery or attendance from weak evidence.');

$contains($manual, array(
    "CAPABILITY = 'dzn_manage_booking_request_coordination'",
    "ASSENT_CAPABILITY = 'dzn_manage_teacher_availability_assent'",
    'Resolve the student identity before confirming a Teacher match',
    '->overlapping($teacherId, $start, $occupiedEnd)',
    'CanonicalContinuationCapacityAuthority::assertNoActiveHold',
    'availability_override',
    'agreement_reference_digest',
    'booking_request.manual_teacher_match',
    'administratorConfirmDelivery',
), 'Manual match must bypass only the availability preference and record an audited explicit override.');
$contains($response, array('public function administratorConfirmDelivery', 'pending_projection', 'insertConfirmation', 'insertNotification'), 'Administrator match must reuse the same confirmation and notification state as the Teacher path.');
$contains($intro, array(
    'BookingRequestSubmissionService',
    'StudentIdentityResolutionService',
    'resolveExisting',
    '$this->match->confirm(',
    'The Student needs a verified email, phone, country, city and timezone',
    'The Teacher already has a class at that time',
), 'Guided introductory booking must reuse canonical intake, identity and match authorities and preview conflicts.');
$contains($controller, array(
    'class_reschedule',
    'class_change_teacher',
    'class_cancel',
    'class_complete',
    'class_delivery',
    'class_attendance',
    'resend_payment_link',
    'send_reminder',
    'import_commit',
), 'The contextual controller must implement the requested class, attendance and post-intro actions.');
$contains($readService, array(
    "r.lifecycle_status<>'submitted'",
    'canonical_continuation_reservations',
    'Earlier of the first regular slot',
    'six days after the introductory lesson ends',
    'notificationCategory',
    'conversionClassification',
    'canonical_attendance_evidence',
    'lesson_schedule_versions',
    'canonical_lesson_schedule_versions',
), 'The read projection must separate history, expose evidence, hold expiry and conversion reasons.');
$contains($import, array(
    "ATTENDANCE_STATES = array('historical_status_unknown', 'evidence_provided')",
    'Past attendance is recorded as historical status unknown; no past lesson will be marked attended.',
    'Historical payment is recorded as unknown',
    'An active imported or legacy Enrolment already exists',
    'historical_enrolment_import',
    'LessonService',
    'LessonScheduleService',
    'TermService',
    'idempotency key',
), 'Import must be evidence-bounded, duplicate-checked, idempotent and represent the running course as future classes.');
$contains($postIntro, array(
    "CAPABILITY = 'dzn_manage_post_intro_automation'",
    "'prepared', 'checkout_pending', 'payment_received'",
    'student_action_at',
    'Verified payment already exists',
    'Delivery is not confirmed until transport evidence exists',
), 'Post-intro sends must be policy-bounded, idempotent and must not claim delivery.');
$contains($worker, array("post_intro_reminder", 'Reminder: complete your Delnavazan enrolment'), 'The notification worker must understand the distinct reminder kind.');
$contains($css, array('.dzn-enrolment__hero', '.dzn-booking-table', '.dzn-class-table', '.dzn-case-grid', '.dzn-timeline', '@media (max-width: 782px)'), 'The workspace needs responsive desktop/tablet/mobile styling.');
$expect(!str_contains($readService, 'SELECT * FROM {$this->p}booking_request_contact_snapshots'), 'The read projection must not leak full contact snapshots into unrelated rows.');

echo "Enrolment administration workspace contract passed\n";
