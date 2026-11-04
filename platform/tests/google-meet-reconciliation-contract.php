<?php
/** Source contract for existing-lesson recovery, booking retry and truthful join readiness. */
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string) file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('Google Meet reconciliation contract failed: ' . $label);
};

$repository = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceRepository.php');
$reconciler = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomReconciler.php');
$worker = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceWorker.php');
$classrooms = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomService.php');
$rule = $read('src/Integrations/GoogleWorkspace/MeetProvisioningRule.php');
$join = $read('src/Integrations/GoogleWorkspace/MeetClassroomJoinService.php');
$bookingWorker = $read('src/Integrations/BookingCalendarProjectionWorker.php');
$oauth = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceOAuthService.php');
$booking = $read('src/Integrations/BookingCalendarProjectionService.php');
$teacherJoin = $read('src/Integrations/TeacherClassJoinService.php');
$studentJoin = $read('src/Integrations/StudentClassJoinService.php');
$bookingJoin = $read('src/Integrations/BookingClassJoinService.php');
$introLifecycle = $read('src/Core/Application/IntroLessonLifecycleService.php');
$postIntroWorker = $read('src/Integrations/PostIntroActivationWorker.php');
$retraction = $read('src/Integrations/BookingCancellationRetractionWorker.php');
$controller = $read('src/Portals/TeacherBookingResponseController.php');
$bridge = (string) file_get_contents(dirname($root) . '/theme/inc/platform-bridge.php');

$check('future missing lessons are discoverable', str_contains($repository, 'missingLessonClassrooms') && str_contains($repository, "l.lifecycle_state='authorised'") && str_contains($repository, 'v.starts_at_utc>=%s') && str_contains($repository, 'c.id IS NULL'));
$check('confirmed missing bookings are discoverable', str_contains($repository, 'missingBookingClassrooms') && str_contains($repository, "c.state='confirmed'") && str_contains($repository, 'm.id IS NULL'));
$check('stale schedule projections are discoverable', str_contains($repository, 'staleLessonClassrooms') && str_contains($repository, 'c.schedule_version_id<>v.id'));
$check('stale booking instants are discoverable', str_contains($repository, 'staleBookingClassrooms') && str_contains($repository, 'm.starts_at_utc<>c.starts_at_utc'));
$check('teacher replacement is discoverable', str_contains($repository, 'teacherMismatchClassrooms') && str_contains($repository, 'c.teacher_id<>l.teacher_id'));
$check('accepted but unconfirmed bookings are discoverable', str_contains($repository, 'unconfirmedBookingProjections') && str_contains($repository, "c.state IN ('pending_projection','retryable','awaiting_conference')"));
$check('failed bookings are visible for administrator recovery', str_contains($repository, 'failedBookingProjections') && str_contains($repository, "c.state='failed'"));

$check('reconciler produces a dry-run report', str_contains($reconciler, 'function report(') && str_contains($reconciler, 'function run(') && str_contains($reconciler, 'dryRun'));
$check('dry-run returns before provider calls', str_contains($reconciler, "if(\$dryRun)return \$action;"));
$check('reconciler provisions lesson rows', str_contains($reconciler, 'ensureForLesson((int)$row->lesson_id'));
$check('reconciler provisions booking rows', str_contains($reconciler, 'ensureForBooking($id)'));
$check('reconciler patches stale schedules', str_contains($reconciler, 'rescheduleLesson((int)$row->lesson_id'));
$check('reconciler patches stale booking instants', str_contains($reconciler, 'rescheduleForBooking('));
$check('reconciler repairs teacher mismatch', str_contains($reconciler, 'replaceTeacher((int)$row->lesson_id'));
$check('reconciler retries accepted bookings', str_contains($reconciler, 'BookingCalendarProjectionService') && str_contains($reconciler, 'dispatch($id)'));
$check('automatic discovery is opt-in', str_contains($reconciler, 'DZN_MEET_AUTO_RECONCILE') && str_contains($reconciler, "get_option('dzn_meet_auto_reconcile','0')"));
$check('worker invokes reconciliation and a single-run lock', str_contains($worker, 'GoogleWorkspaceClassroomReconciler') && str_contains($worker, 'GET_LOCK') && str_contains($worker, 'automaticEnabled()'));
$check('worker no longer skips booking rows by lesson_id', !str_contains($worker, '(int)$classroom->lesson_id<1||(int)$classroom->schedule_version_id<1'));

$check('permanent co-host block is never auto-retried', str_contains($classrooms, 'assertNotPermanentlyBlocked') && str_contains($classrooms, 'meet_cohost_assignment_blocked'));
$check('co-host retries are bounded', str_contains($classrooms, 'cohostAttemptExhausted') && str_contains($rule, 'GoogleWorkspaceClassroomRule::MAX_ATTEMPTS'));
$check('provider failures are persisted with HTTP evidence', str_contains($classrooms, 'recordProviderFailure') && str_contains($classrooms, 'last_provider_response_code'));
$check('transient and permanent provider failures are separated', str_contains($rule, 'classifyResponse') && str_contains($rule, 'meet_calendar_projection_refused') && str_contains($rule, 'meet_calendar_projection_retryable'));
$check('calendar event failures block permanently on refusal', str_contains($classrooms, "failureCode(\n                'calendar_projection'") || str_contains($classrooms, "'calendar_projection'"));
$check('co-host membership is verified before and after ambiguous failures', str_contains($classrooms, 'verifyCoHostMember') && substr_count($classrooms, 'verifyCoHostMember($space,$email)') >= 2);
$check('central booking reschedule reuses the event', str_contains($classrooms, 'rescheduleForBooking') && str_contains($classrooms, 'updateCalendarEvent'));
$check('teacher replacement updates Calendar attendees', str_contains($classrooms, 'dzn_schedule_version_id') && str_contains($classrooms, 'attendees'));

$check('central booking retries do not require the legacy principal', str_contains($bookingWorker, '$central') && str_contains($bookingWorker, 'if($central){$work();return;}') && str_contains($bookingWorker, 'ProviderProjectionWorkerContext'));
$check('transient OAuth refresh failure keeps the organizer active', str_contains($oauth, "'connection_state'=>'connected','active_slot'=>1") && str_contains($oauth, "'failure_reason_code'=>'credential_refresh_failed'"));
$check('booking dispatch is serialised', str_contains($booking, 'GET_LOCK') && str_contains($booking, 'booking_projection_busy'));
$check('terminal booking failure has explicit recovery', str_contains($booking, 'function retryFailed') && str_contains($booking, "'state'=>'retryable'"));
$check('central booking reschedule is selected before legacy vault', str_contains($booking, 'rescheduleForBooking') && str_contains($booking, 'GoogleWorkspaceClassroomService::configured()'));
$check('central booking cancellation is selected before legacy vault', str_contains($booking, 'cancelForBooking') && str_contains($booking, 'GoogleWorkspaceClassroomService::configured()'));
$check('post-intro activation uses the shared Meet retry vocabulary', str_contains($postIntroWorker, 'MeetProvisioningRule::isRetryableBookingFailure'));
$check('intro cancellation queues central retraction', str_contains($introLifecycle, 'centralClassroom') && str_contains($introLifecycle, 'needsProviderRetraction'));
$check('retraction worker cancels the central classroom', str_contains($retraction, 'cancelForBooking'));

$check('join window is the shared 30/30 policy', str_contains($join, 'ParticipantJoinWindow::contains') && !str_contains($join, 'EARLY_SECONDS=3600') && !str_contains($join, 'LATE_SECONDS=7200'));
$check('readiness has a controlled state and reason', str_contains($join, 'function readinessForRow') && str_contains($join, "'classroom_provisioning'") && str_contains($join, "'reference_missing'"));
$check('blocked central rows cannot fall through to legacy links', str_contains($teacherJoin, 'hasActiveClassroomForLessonUid') && str_contains($studentJoin, 'hasActiveClassroomForLessonUid') && str_contains($bookingJoin, 'hasActiveClassroomForBookingUid') && str_contains($join, "classroom_state<>'cancelled'"));
$check('portal shows localized recovery copy', str_contains($bridge, 'dzn_theme_platform_meet_readiness_message') && str_contains($bridge, 'دسترسی هم‌میزبان') && str_contains($bridge, 'کلاس Google Meet هنوز ساخته نشده است'));
$check('teacher acceptance reports retryable provisioning honestly', str_contains($controller, 'در صف تلاش مجدد') && str_contains($controller, 'بدون پذیرش دوباره قابل بازیابی است'));

$combined = $repository . $reconciler . $classrooms . $booking . $join;
$check('no raw Meet URL, token or client secret is persisted by repair code', !str_contains($combined, 'client_secret') && !str_contains($combined, 'refresh_token') && !str_contains($combined, "'join_uri_reference'=>\$uri"));

echo "Google Meet reconciliation contract passed\n";
