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
$planClass = $read('src/Integrations/GoogleWorkspace/MeetReconciliationPlan.php');
$retryRule = $read('src/Integrations/GoogleWorkspace/MeetClassroomRetryRule.php');
$cli = $read('src/Admin/Cli/MeetMigrationCommand.php');
$cliPolicy = $read('src/Admin/Cli/MeetCommandPolicy.php');
$postIntro = $read('src/Core/Application/PostIntroActivationService.php');
$rescheduleCalendar = $read('src/Integrations/LessonRescheduleCalendarService.php');
$runbook = $read('docs/GOOGLE-MEET-RECONCILIATION-2026-11-04.md');
$worker = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceWorker.php');
$flags = $read('src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php');
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

$check('preview and apply share one planner', str_contains($reconciler, 'public function plan(') && str_contains($reconciler, '$plan=$this->plan($limit,$discover)') && substr_count($reconciler, '->plan(') >= 2);
$check('preview performs no provider call', str_contains($reconciler, "if(\$dryRun){") && strpos($reconciler, 'if($dryRun){') < strpos($reconciler, '$this->execute($operation)'));
$check('plan deduplicates, orders and applies one total limit', str_contains($reconciler, 'MeetReconciliationPlan::normalise') && str_contains($planClass, 'array_slice($operations,0,$limit)') && str_contains($planClass, "strcmp((string)(\$a['starts_at_utc']"));
$check('plan digest binds the exact operation set', str_contains($planClass, 'function digest(') && str_contains($planClass, "'fingerprint'") && str_contains($planClass, 'function assertApproved('));
$check('apply checks the approved digest', str_contains($reconciler, 'MeetReconciliationPlan::assertApproved') && str_contains($cliPolicy, 'plan_required') && str_contains($cli, 'MeetCommandPolicy::repair'));
$check('apply revalidates every operation immediately before mutation', str_contains($reconciler, 'function isCurrent(') && str_contains($reconciler, '$current=$this->isCurrent($operation)') && str_contains($reconciler, "reason_code']='stale_plan'"));
$check('reconciler provisions lesson rows', str_contains($reconciler, 'ensureForLesson((int)$c'));
$check('reconciler provisions booking rows', str_contains($reconciler, 'ensureForBooking((int)$c'));
$check('reconciler patches stale schedules', str_contains($reconciler, 'rescheduleLesson((int)$c'));
$check('reconciler patches stale booking instants', str_contains($reconciler, 'rescheduleForBooking('));
$check('reconciler repairs teacher mismatch', str_contains($reconciler, 'replaceTeacher((int)$c'));
$check('reconciler retries accepted bookings', str_contains($reconciler, 'BookingCalendarProjectionService') && str_contains($reconciler, 'dispatch((int)$c'));
$check('automatic discovery is opt-in', str_contains($flags, 'DZN_MEET_AUTO_RECONCILE') && str_contains($flags, "AUTO_RECONCILE_OPTION='dzn_meet_auto_reconcile'") && str_contains($flags, 'get_option($option, null)') && str_contains($flags, "'effective'=>\$default"));
$check('discovery flag gates missing rows but not existing-row repair', (bool)preg_match('/if\(\$discover\)\{.*?missingLessonClassrooms.*?missingBookingClassrooms.*?\}.*?staleLessonClassrooms/s', $reconciler));
$check('worker invokes reconciliation and a single-run lock', str_contains($worker, 'GoogleWorkspaceClassroomReconciler') && str_contains($worker, 'GET_LOCK') && str_contains($worker, 'automaticEnabled()'));
$check('worker honours the emergency provisioning flag', str_contains($worker, 'GoogleWorkspaceClassroomService::enabled()'));
$check('direct provisioning is blocked at the emergency pause', (bool)preg_match('/function ensureForLesson.*?if\(!self::enabled\(\)\)/s', $classrooms) && (bool)preg_match('/function ensureForBooking.*?if\(!self::enabled\(\)\)/s', $classrooms));
$check('recording and attendance reconciliation continue while provisioning is paused', str_contains($worker, "dzn_meet_provisioning_paused") && str_contains($worker, 'dueReconciliation(10)') && str_contains($worker, 'MeetRecordingService'));
$check('worker converges Calendar reminder suppression for upcoming classrooms', str_contains($worker, 'suppressEventReminders') && substr_count($worker, 'upcoming(25)') >= 2 && str_contains($repository, 'classroomEventExists'));
$check('worker converges participant removal so the Meet link stays out of participant calendars', str_contains($worker, 'detachCalendarAttendees') && str_contains($classrooms, 'calendar_attendees_detached'));
$check('reminder suppression audit is one-shot per classroom', str_contains($repository, 'result_state IN') && str_contains($repository, 'meet_classroom_events'));
$check('worker cron is registered and lock released in finally', str_contains($worker, 'add_action(self::HOOK') && str_contains($worker, 'wp_schedule_event') && str_contains($worker, 'finally') && str_contains($worker, 'self::releaseLock()'));
$check('manual worker discovery cannot apply without repair', str_contains($cli, 'MeetCommandPolicy::worker') && str_contains($cliPolicy, 'discovery_requires_repair') && str_contains($cli, '$decision[\'mode\']===\'rejected\''));
$check('manual worker apply passes the approved digest', str_contains($cli, 'run($limit,false,false,(string)$decision[\'plan\'])'));
$check('mutating manual commands require an administrator', substr_count($cli, 'self::requireOperator()') >= 8 && str_contains($cliPolicy, 'function operatorAllowed('));
$check('post-intro provisioning does not fall back while paused', str_contains($postIntro, 'meet_classroom_paused') && str_contains($postIntro, 'meet_organizer_not_connected'));
$check('reschedule handoff does not fall back while paused', str_contains($rescheduleCalendar, 'meet_classroom_paused') && str_contains($rescheduleCalendar, 'meet_organizer_not_connected'));
$check('booking worker cron is registered and lock released in finally', str_contains($bookingWorker, 'add_action(self::HOOK') && str_contains($bookingWorker, 'wp_schedule_event') && str_contains($bookingWorker, 'if($central){$work();return;}') && str_contains($bookingWorker, 'ProviderProjectionWorkerContext'));
$check('booking worker honours the emergency provisioning flag', str_contains($bookingWorker, 'GoogleWorkspaceClassroomService::enabled()'));
$check('worker no longer skips booking rows by lesson_id', !str_contains($worker, '(int)$classroom->lesson_id<1||(int)$classroom->schedule_version_id<1'));

$check('retry-classroom validates before mutating', str_contains($classrooms, 'function retryClassroom(') && str_contains($classrooms, 'MeetClassroomRetryRule::lessonEligibility') && str_contains($classrooms, 'MeetClassroomRetryRule::classroomSelection'));
$check('retry-classroom is locked and version checked', str_contains($classrooms, 'dzn_meet_retry_lesson_') && str_contains($classrooms, "'version'=>(int)\$active->version") && str_contains($classrooms, "'meet_classroom_retry_conflict'"));
$check('retry-classroom CLI has no direct classroom mutation', !str_contains($cli, '$wpdb->update($p.\'meet_classrooms\''));
$check('retry-classroom never revives cancelled history', str_contains($retryRule, "reject_history") && str_contains($retryRule, "array('cancelled','retired')"));
$check('retry-classroom rejects a stale provider schedule', str_contains($classrooms, "'meet_classroom_schedule_stale'"));
$check('retry-classroom requires a documented administrator reason', str_contains($retryRule, 'reasonRequired') && str_contains($cli, 'an audited --reason (12+ chars)'));
$check('retry-classroom preserves provider identifiers', !preg_match('/retryClassroom.*?(event_id_digest|meet_uri_digest)\s*=/s', $classrooms));
$check('blocked booking recovery reuses the latest classroom row', str_contains($classrooms, 'bookingSelection') && str_contains($classrooms, 'resetBookingClassroomForRetry') && str_contains($classrooms, 'latestClassroomForBooking'));
$check('blocked term-lesson retry reuses the latest provider row', str_contains($classrooms, 'RESET_LATEST&&$latest!==null') && str_contains($classrooms, 'latestClassroomForLesson') && str_contains($classrooms, 'hasProviderEvidence'));
$check('missing lesson reference fails before provider creation', str_contains($classrooms, 'blockMissingClassroomReference') && str_contains($classrooms, "'meet_classroom_reference_missing'"));
$check('booking recovery never duplicates a blocked historical row', str_contains($retryRule, 'function bookingSelection') && str_contains($retryRule, 'RESET_LATEST'));
$check('in-flight booking retries preserve the bounded attempt count', str_contains($retryRule, 'RETRY_EXISTING') && str_contains($classrooms, 'MeetClassroomRetryRule::RETRY_EXISTING'));
$check('ended or cancelled booking history is not revived', str_contains($classrooms, 'meet_booking_ended') && str_contains($classrooms, 'meet_classroom_history_not_retryable') && str_contains($rule, 'meet_booking_ended') && str_contains($rule, 'meet_classroom_history_not_retryable'));
$check('space lookup failures persist and throw the same bounded code', str_contains($classrooms, "spaceFor(array \$references,?object \$classroom=null)") && str_contains($classrooms, "\$failure=\$this->recordProviderFailure(\$classroom,\$response,'space_lookup'") && str_contains($classrooms, 'throw new \\RuntimeException($failure)'));
$check('rollback guidance separates pause and full rollback', str_contains($runbook, 'Pause automatic recovery') && str_contains($runbook, 'Pause new provisioning') && str_contains($runbook, 'Full organizer rollback'));

$check('permanent co-host block is never auto-retried', str_contains($classrooms, 'assertNotPermanentlyBlocked') && str_contains($classrooms, 'meet_cohost_assignment_blocked'));
$check('co-host retries are bounded', str_contains($classrooms, 'cohostAttemptExhausted') && str_contains($rule, 'GoogleWorkspaceClassroomRule::MAX_ATTEMPTS'));
$check('provider failures are persisted with HTTP evidence', str_contains($classrooms, 'recordProviderFailure') && str_contains($classrooms, 'last_provider_response_code'));
$check('transient and permanent provider failures are separated', str_contains($rule, 'classifyResponse') && str_contains($rule, 'meet_calendar_projection_refused') && str_contains($rule, 'meet_calendar_projection_retryable'));
$check('calendar event failures block permanently on refusal', str_contains($classrooms, "failureCode(\n                'calendar_projection'") || str_contains($classrooms, "'calendar_projection'"));
$check('co-host membership is verified before and after ambiguous failures', str_contains($classrooms, 'verifyCoHostMember') && substr_count($classrooms, 'verifyCoHostMember($space,$email)') >= 2);
$check('central booking reschedule reuses the event', str_contains($classrooms, 'rescheduleForBooking') && str_contains($classrooms, 'updateCalendarEvent'));
$check('teacher replacement keeps attendees gated by the calendar policy', str_contains($classrooms, 'dzn_schedule_version_id') && str_contains($classrooms, 'GoogleWorkspaceFeatureFlags::calendarAttendees()') && str_contains($classrooms, 'detachCalendarAttendees'));

$check('central booking retries do not require the legacy principal', str_contains($bookingWorker, '$central') && str_contains($bookingWorker, 'if($central){$work();return;}') && str_contains($bookingWorker, 'ProviderProjectionWorkerContext'));
$check('transient OAuth refresh failure keeps the organizer active', str_contains($oauth, "'connection_state'=>'connected','active_slot'=>1") && str_contains($oauth, "'failure_reason_code'=>'credential_refresh_failed'"));
$check('booking dispatch is serialised', str_contains($booking, 'GET_LOCK') && str_contains($booking, 'booking_projection_busy'));
$check('terminal booking failure has explicit recovery', str_contains($booking, 'function retryFailed') && str_contains($booking, "'state'=>'retryable'"));
$check('central booking reschedule is selected before legacy vault', str_contains($booking, 'rescheduleForBooking') && str_contains($booking, 'GoogleWorkspaceClassroomService::organizerConnected()'));
$check('central booking path never falls back after installation', str_contains($booking, '$centralInstalled') && str_contains($booking, 'meet_organizer_not_connected') && str_contains($booking, "'meet_classroom_paused'"));
$check('central booking cancellation is selected before legacy vault', str_contains($booking, 'cancelForBooking') && str_contains($booking, 'GoogleWorkspaceClassroomService::organizerConnected()'));
$check('post-intro activation uses the shared Meet retry vocabulary', str_contains($postIntroWorker, 'MeetProvisioningRule::isRetryableBookingFailure'));
$check('intro cancellation queues central retraction', str_contains($introLifecycle, 'centralClassroom') && str_contains($introLifecycle, 'needsProviderRetraction'));
$check('retraction worker cancels the central classroom', str_contains($retraction, 'cancelForBooking'));
$check('retraction is not acknowledged while the organizer is unavailable', str_contains($retraction, 'latestClassroomForBooking') && str_contains($retraction, 'meet_organizer_not_connected'));

$check('join window is the shared 30/30 policy', str_contains($join, 'ParticipantJoinWindow::contains') && !str_contains($join, 'EARLY_SECONDS=3600') && !str_contains($join, 'LATE_SECONDS=7200'));
$check('readiness has a controlled state and reason', str_contains($join, 'function readinessForRow') && str_contains($join, "'classroom_provisioning'") && str_contains($join, "'reference_missing'"));
$check('blocked central rows cannot fall through to legacy links', str_contains($teacherJoin, 'hasActiveClassroomForLessonUid') && str_contains($studentJoin, 'hasActiveClassroomForLessonUid') && str_contains($bookingJoin, 'hasActiveClassroomForBookingUid') && str_contains($join, "classroom_state<>'cancelled'"));

// Recording companion: every authenticated portal join requests exactly one organizer web join.
$recorderQueue = $read('src/Integrations/GoogleWorkspace/MeetRecorderQueue.php');
$recorderController = $read('src/Integrations/GoogleWorkspace/MeetRecorderController.php');
$teacherJoinController = $read('src/Integrations/TeacherClassJoinController.php');
$studentJoinController = $read('src/Integrations/StudentClassJoinController.php');
$bookingJoinController = $read('src/Integrations/BookingClassJoinController.php');
$adminJoinController = $read('src/Integrations/GoogleWorkspace/MeetAdministratorJoinController.php');
$godModeController = $read('src/Admin/Controller/GodModeController.php');
$godModeTeacherController = $read('src/Admin/Controller/GodModeTeacherController.php');
$platformPlugin = $read('delnavazan-platform.php');
$repoRoot = dirname($root);
$readRepo = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing repository file: ' . $relative);
    return (string) file_get_contents($path);
};
$recorderAgent = $readRepo('tools/pi/meet-recorder/meet-recorder-agent.py');
$recorderService = $readRepo('tools/pi/meet-recorder/dzn-meet-recorder.service');
$recorderEnv = $readRepo('tools/pi/meet-recorder/dzn-meet-recorder.env.example');
$recorderRunbook = $readRepo('tools/pi/meet-recorder/README.md');
$check('every teacher join requests one recorder companion join', str_contains($teacherJoinController, 'MeetRecorderQueue::enqueueForLessonUid') && str_contains($bookingJoinController, "=== 'teacher'") && str_contains($bookingJoinController, 'MeetRecorderQueue::enqueueForBookingUid') && !str_contains($studentJoinController, 'MeetRecorderQueue::'));
$check('administrator and God Mode joins request the companion', str_contains($adminJoinController, 'enqueueForBookingUid') && str_contains($adminJoinController, 'enqueueForLessonId') && str_contains($godModeController, 'enqueueForTestSession') && str_contains($godModeTeacherController, 'enqueueForTestSession'));
$check('recorder endpoints are registered and token protected', str_contains($platformPlugin, 'MeetRecorderController::register') && str_contains($recorderController, 'hash_equals') && str_contains($recorderController, '/meet/recorder/next') && str_contains($recorderController, '/meet/recorder/result') && str_contains($recorderController, "'status'=>503"));
$recorderPlan = $read('src/Integrations/GoogleWorkspace/MeetRecorderPlan.php');
$check('the recorder queue is locked, coalesced, recording-aware and expires', str_contains($recorderQueue, 'GET_LOCK') && str_contains($recorderQueue, 'recording_state') && str_contains($recorderQueue, 'join_uri_reference') && str_contains($recorderQueue, 'MeetRecorderPlan::prune') && str_contains($recorderPlan, 'const TTL') && str_contains($recorderPlan, 'const CLAIM_TTL') && str_contains($recorderQueue, 'DZN_MEET_RECORDER_TOKEN'));
$check('the companion agent claims the job, joins over CDP and reports the result', str_contains($recorderAgent, '/meet/recorder/next') && str_contains($recorderAgent, '/meet/recorder/result') && str_contains($recorderAgent, 'Target.createTarget') && str_contains($recorderAgent, 'Join') && str_contains($recorderService, 'dzn-meet-recorder.env'));
// A steady poller must never lock itself out: the limiter window advances on its own clock.
$check('the recorder rate limiter uses a fixed window that traffic cannot extend', str_contains($recorderController, '(int)floor(time()/60)') && str_contains($recorderController, 'set_transient($key,$count+1,120)'));
// The first live attempt reported recorder_call_not_started: a cold Pi needs longer and a retry.
$check('the companion tolerates a slow Meet boot and retries a swallowed click', str_contains($recorderAgent, 'RETRY_CLICK_AFTER') && str_contains($recorderAgent, 'DZN_JOIN_TIMEOUT", "75"') && str_contains($recorderAgent, 'DISMISS_JS') && str_contains($recorderEnv, 'DZN_JOIN_TIMEOUT=75'));
// Joining, leaving and failing are all auditable, and no stale tab survives a job.
$check('the companion reports leaving and clears stale tabs before each job', str_contains($recorderAgent, 'recorder_left_call') && substr_count($recorderAgent, 'close_stale_meet_tabs()') >= 2 && str_contains($recorderQueue, "'joined','left','failed'") && str_contains($recorderController, "'joined','left','failed'"));
$check('the companion runbook documents the token and the class-never-blocks rule', str_contains($recorderEnv, 'DZN_RECORDER_TOKEN') && str_contains($recorderRunbook, 'dzn_meet_recorder_token') && str_contains($recorderRunbook, 'never depends on it'));

// Attendance auto-mapping: the portal claim is the only thing that binds an opaque Meet account.
$claimService = $read('src/Integrations/GoogleWorkspace/MeetAttendanceClaimService.php');
$attendanceService = $read('src/Integrations/GoogleWorkspace/MeetAttendanceService.php');
$attendanceRule = $read('src/Core/Application/CanonicalAttendanceRule.php');
$check('every portal join records an attendance claim', str_contains($teacherJoinController, 'MeetAttendanceClaimService::forLessonUid') && str_contains($studentJoinController, "forLessonUid((string)(\$_POST['lesson_uid']??''),'student')") && str_contains($bookingJoinController, 'MeetAttendanceClaimService::forBookingUid'));
$check('claims bind only on a unique, clearly nearest participant arrival', str_contains($claimService, 'TOLERANCE_SECONDS=180') && str_contains($claimService, 'DISTINCT_SECONDS=30') && str_contains($claimService, "'signedin'"));
$check('an account bound to another participant is never rebound', str_contains($claimService, 'participant_id===$participantId)return false;') && str_contains($claimService, "'provider_code'=>'google_meet'"));
$check('the organizer has no portal claim, so it can never become a mapped participant', !str_contains($claimService, 'recorder') && str_contains($claimService, "array('teacher','student')"));
$check('auto-mapping runs before attendance is assessed', str_contains($attendanceService, 'autoMap($classroom)'));
$check('the attendance window allows early and late classes', str_contains($attendanceRule, "RULE_VERSION='canonical_attendance_overlap_v2'") && str_contains($attendanceRule, 'PRE_GRACE_SECONDS=3600') && str_contains($attendanceRule, 'POST_GRACE_SECONDS=3600') && str_contains($attendanceRule, 'THRESHOLD_SECONDS=1200'));

// The queue's decisions are pure, so they are also proven as behaviour, not only as source shape.
require_once $root . '/src/Integrations/GoogleWorkspace/MeetRecorderPlan.php';
$planClass = 'Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\MeetRecorderPlan';
$now = 1_700_000_000;
$first = $planClass::request(array(), 41, 'lesson', 'portal_join', $now - 30, 'job-a');
$check('a new classroom creates exactly one pending request', count($first['jobs']) === 1 && ($first['jobs'][41]['state'] ?? '') === 'pending' && ($first['job']['uid'] ?? '') === 'job-a');
$repeat = $planClass::request($first['jobs'], 41, 'lesson', 'portal_join', $now, 'job-b');
$check('a repeated join refreshes the same request instead of adding one', count($repeat['jobs']) === 1 && ($repeat['jobs'][41]['uid'] ?? '') === 'job-a' && (int) ($repeat['jobs'][41]['requested_at'] ?? 0) === $now);
$second = $planClass::request($repeat['jobs'], 42, 'booking', 'portal_join', $now + 5, 'job-c');
$check('a second classroom is queued independently', count($second['jobs']) === 2);
$check('the oldest pending request is claimed first', $planClass::next($second['jobs']) === 41);
$claimed = $planClass::claim($second['jobs'], 41, $now);
$check('a claim is recorded and skipped while it is fresh', ($claimed[41]['state'] ?? '') === 'claimed' && $planClass::next($claimed) === 42);
$check('an expired request is dropped', !isset($planClass::prune($second['jobs'], $now + 5 + $planClass::TTL + 1)[42]));
$check('a fresh claim is held', ($planClass::prune($claimed, $now + $planClass::CLAIM_TTL - 1)[41]['state'] ?? '') === 'claimed');
$reclaimed = $planClass::prune($claimed, $now + $planClass::CLAIM_TTL);
$check('a vanished companion is reclaimed as pending', ($reclaimed[41]['state'] ?? '') === 'pending' && $reclaimed[41]['claimed_at'] === null && $planClass::next($reclaimed) === 41);
$check('a completed request leaves the queue', count($planClass::clear($reclaimed, 41)) === 1 && !isset($planClass::clear($reclaimed, 41)[41]));
$check('portal shows localized recovery copy', str_contains($bridge, 'dzn_theme_platform_meet_readiness_message') && str_contains($bridge, 'دسترسی هم‌میزبان') && str_contains($bridge, 'کلاس Google Meet هنوز ساخته نشده است'));
$check('teacher acceptance reports retryable provisioning honestly', str_contains($controller, 'در صف تلاش مجدد') && str_contains($controller, 'بدون پذیرش دوباره قابل بازیابی است'));

$combined = $repository . $reconciler . $classrooms . $booking . $join;
$check('no raw Meet URL, token or client secret is persisted by repair code', !str_contains($combined, 'client_secret') && !str_contains($combined, 'refresh_token') && !str_contains($combined, "'join_uri_reference'=>\$uri"));

echo "Google Meet reconciliation contract passed\n";
