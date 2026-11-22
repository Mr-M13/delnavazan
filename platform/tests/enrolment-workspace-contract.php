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
$deliveryRepo = $read('src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$intro = $read('src/Core/Application/AdministratorIntroBookingService.php');
$import = $read('src/Core/Application/HistoricalEnrolmentImportService.php');
$postIntro = $read('src/Core/Application/PostIntroNotificationAdministrationService.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$responseRepo = $read('src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php');
$journey = $read('src/Core/Application/BookingJourneyReadService.php');
$decline = $read('src/Core/Application/DeclineBookingRequestService.php');
$skip = $read('src/Core/Application/SkipIntroBookingService.php');
$conversion = $read('src/Core/Application/EnrolmentConversionService.php');
$conversionRepo = $read('src/Core/Infrastructure/Repository/EnrolmentConversionRepository.php');
$css = $read('src/Admin/Assets/admin.css');
$bootstrap = $read('delnavazan-platform.php');
$worker = $read('src/Integrations/BookingStudentNotificationWorker.php');
$allocationRepo = $read('src/Core/Infrastructure/Repository/BookingAllocationRepository.php');
$adminJs = $read('src/Admin/Assets/admin.js');

$expect(1 === preg_match_all("/\\['Enrolment', 'dzn-enrolment-workspace'/", $shell), 'Enrolment must expose one primary destination.');
$expect(!str_contains($shell, "['Courses & instruments', 'dzn-catalogue-workspace'"), 'The visible catalogue subtab must be removed.');
$contains($shell, array(
    "['Enrolment', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "'dzn-catalogue-workspace' => 'enrolment'",
), 'Catalogue management must remain under Enrolment, not Settings → General.');
$general = $read('src/Admin/Controller/GeneralSettingsController.php');
$notContains($general, array('dzn-catalogue-workspace', 'Open courses and instruments'), 'General must not own catalogue management.');
$notContains($shell, array("['Classes & Schedules'", "['Attendance & Post-intro'", "['Bookings & Enrolments'"), 'The former Enrolment sub-tabs must be gone.');
$contains($menu, array(
    "['Booking requests', 'dzn_view_booking_requests', 'dzn-booking-requests'",
    "['Class admin', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination'",
    "['Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment'",
    "['Terms', 'dzn_manage_terms', 'dzn-term'",
    "['Classes', 'dzn_manage_lessons', 'dzn-lesson'",
    "['Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review'",
    "['Courses', 'dzn_manage_courses', 'dzn-course'",
    "['Instruments', 'dzn_manage_courses', 'dzn-instrument'",
), 'Every protected legacy route and its original capability must remain registered.');
$contains($bootstrap, array('EnrolmentWorkspaceController::register()', 'MeetAdministratorJoinController::register()'), 'The workspace and administrator classroom boundaries must be registered.');

$contains($view, array(
    'public static function dashboard(',
    "'intro_paged'",
    "'class_paged'",
    'sectionPagination($filters,$introQueue',
    'sectionPagination($filters,$classQueue',
    'Overview snapshot',
    'Scheduled',
    'Converted',
    'Completed, elapsed',
    'Active terms',
    'Renewed terms',
    'Almost ending',
    'Needs attention first',
    'Create standard class',
    'Open Class admin',
    'Retry Google Meet',
    'Post-intro survey results',
    'Class history',
    'History',
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
$notContains($view, array('nav-tab-wrapper', 'dzn-enrolment__tabs', "\$tabs['new']", "\$tabs['review']", "\$tabs['enrolled']"), 'Enrolment must not render or keep sub-tabs.');
$contains($view, array(
    'dzn-unified-table',
    'dzn-term-table',
    'dzn-create-instrument',
    "'dzn-unified-row', 'dzn-unified-row--'",
    'is-attention',
    'Needs attention only',
    'Search everything',
    'Intro stage',
    'Teacher',
    'Student',
    'Instrument',
    'Term',
), 'The unified queue must provide colour classes and strong filters.');
$contains($controller, array('create_standard_class', 'term_assignment', 'legacy_phase1', 'LessonService', 'LessonScheduleService', "requireCap('dzn_manage_canonical_lesson_schedules')"), 'Manual standard class creation must support canonical and historical Enrolments through their owning authorities.');
$contains($readService, array("'legacy_phase1' record_model", 'record_model', 'assignment_id'), 'Enrolment options must expose both canonical and historical Enrolments for manual class allocation.');
$contains($css, array(
    '.dzn-enrolment-snapshot',
    '.dzn-snapshot-stat--danger',
    '.dzn-unified-table',
    '.dzn-unified-row--intro',
    '.dzn-unified-row.is-attention',
    '.dzn-task-chip--danger',
), 'The Enrolment landing must style the snapshot, attention queue and task chips.');

$contains($manual, array(
    "CAPABILITY = 'dzn_manage_booking_request_coordination'",
    "ASSENT_CAPABILITY = 'dzn_manage_teacher_availability_assent'",
    'Resolve the student identity before confirming a Teacher match',
    '->overlapping($teacherId, $start, $occupiedEnd, 0, $requestId)',
    'CanonicalContinuationCapacityAuthority::assertNoActiveHold',
    'availability_override',
    'agreement_reference_digest',
    'booking_request.manual_teacher_match',
    'administratorConfirmDelivery',
), 'Manual match must bypass only the availability preference and record an audited explicit override.');
$contains($deliveryRepo, array('public function reassignTerminal(', "d.state IN ('expired','declined','capacity_lost')", "o.state='superseded'", "reason_code='administrator_agreement'"), 'A terminal delivery without a confirmation must be repointable by the authorised manual-match path.');
$contains($manual, array('if ($confirmation) throw', "array('expired', 'declined', 'capacity_lost')", 'reassignTerminal('), 'Manual match must refuse only a live confirmation, not a stale expired delivery with no confirmation.');
$contains($response, array('public function administratorConfirmDelivery', 'pending_projection', 'insertConfirmation', 'insertNotification'), 'Administrator match must reuse the same confirmation and notification state as the Teacher path.');
$contains($response, array(
    'public function administratorTotalOverrideConfirmDelivery',
    'bool $totalOverride=false',
    '!$totalOverride&&$this->repository->studentOccupancyConflicts',
    '!$totalOverride&&$this->repository->teacherOccupancyConflicts',
    'booking_request.total_scheduling_override',
    'administrator_total_override',
), 'The total override must bypass only the named scheduling checks, remain capability-gated and record an audit event.');
$contains($responseRepo, array('teacherForOverride(', 'appendRequestedTime(', 'reassignDeliveryForOverride(', 'retireActiveOfferForRequest('), 'The override needs explicit storage for a new requested time, delivery reassignment and offer retirement.');
$contains($controller, array("case 'total_override_intro':", "dzn_override_canonical_lesson_schedule_availability", "override_ack", "override_local_date"), 'The override command must require the high-trust schedule capability and the explicit acknowledgement.');
$contains($readService, array('public function overrideTeacherOptions('), 'The override picker must read active Teachers without requiring unrelated directory capabilities.');
$contains($view, array('totalOverrideForm(', 'overrideTeacherOptions(', 'Total scheduling override', 'total_override_intro', 'override_ack', 'override_local_date', 'override_local_start_time', 'override_schedule_timezone'), 'The case view must expose the simple Teacher + date/time total override form.');
$contains($view, array("input('override_schedule_timezone', 'Local timezone', 'text', \$timezone, true", 'default is the Student request timezone'), 'The override timezone must accept the Student request timezone (for example Asia/Tehran), not only the standard service-area options.');
$contains($journey, array('public function status(', 'public const STATUSES', "'needs_review' => array('Needs review', 'danger')", "'awaiting_teacher' => array('Waiting for teacher', 'gold')"), 'One journey-status read model must define the shared vocabulary and one primary action per status.');
$contains($journey, array('public static function statusForStage(', "default => 'new'", "'teacher_match', 'teacher_agreed' => 'awaiting_teacher'"), 'The queue must map legacy stage keys onto the same status vocabulary without a per-row query.');
$contains($readService, array("statusForStage((string) \$case['stage_key'])", "'status_key'", "'status_label'", "'status_tone'"), 'The Enrolment queue rows must carry the shared status label and tone.');
$contains($view, array('BookingJourneyReadService', 'primaryJourneyAction(', 'dzn-journey-status', 'More actions', "esc_attr((string) \$journey['status_tone'])", 'badgeTone(', '$primaryOperation', 'This request is closed; review the history below.'), 'The case page must render the single journey status, keep one primary action and hide inapplicable forms for closed requests.');
$contains($decline, array('public function decline(', "'resolution_state' => 'declined'", "'booking_request.declined'", "'booking_declined'", 'invalidateRecordedForCaseRetirement'), 'Declining a request must close the booking request, retire offers/notifications, close the case and invalidate assents in one audited command.');
$contains($controller, array("case 'decline_request':", "dzn_manage_booking_request_coordination", 'Student archiving remains a separate action.'), 'The decline command must be capability-gated and keep student archiving separate.');
$contains($view, array('declineRequestForm(', 'Decline this request', "'decline_request'"), 'The case page must expose the single audited decline action.');
$contains($skip, array('public function skip(', "'booking_request.skip_intro'", "'reason_digest'"), 'Skip intro must be one audited decision and change no other records.');
$contains($conversion, array('intro_not_completed', 'introCompletedForBooking('), 'Enrolment conversion must refuse while the intro is incomplete and read the ordering guard from the booking journey.');
$contains($conversionRepo, array('public function introCompletedForBooking(', 'booking_request.skip_intro', "outcome='delivered'"), 'The conversion guard must accept a delivered outcome or an audited skip-intro decision.');
$contains($response, array('acceptedArrangementForRequest(', 'Confirm the arrangement override before creating the intro.', 'arrangement_guard'), 'The total override must refuse when an accepted arrangement exists without an explicit acknowledgement.');
$contains($controller, array("case 'skip_intro':", 'Skip-intro recorded'), 'The skip-intro command must be dispatched through the workspace controller.');
$contains($view, array('skipIntroForm(', 'Skip the intro', 'arrangement_ack', "'skip_intro'"), 'The case page must expose the skip-intro decision and the arrangement acknowledgement on the override.');
$contains($readService, array('public function reconciliationReport(', "'arrangement_without_intro'", "'closed_case_open_request'", "'enrolment_without_intro'", "'expired_delivery_without_confirmation'"), 'A read-only reconciliation report must enumerate every mixed-state class without mutating records.');
$contains($view, array('reconciliationPanel(', 'Reconciliation report'), 'The Enrolment workspace must render the reconciliation report.');
$contains($controller, array("=== 'reconciliation')"), 'The workspace controller must route the reconciliation report view.');
$contains($intro, array(
    'BookingRequestSubmissionService',
    'StudentIdentityResolutionService',
    'resolveExisting',
    '$this->match->confirm(',
    'The Student needs a verified email, phone, country, city and timezone',
    'The Teacher already has a class at that time',
), 'Guided introductory booking must reuse canonical intake, identity and match authorities and preview conflicts.');
$contains($controller, array(
    'create_standard_class',
    'CanonicalLessonAuthorityService',
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
    'public function enrolmentQueue(',
    'public function enrolmentSnapshot(',
    'public function surveyInsights(',
    'public function enrolmentOptions(',
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
$contains($readService, array('pendingDelivery(', "d.state='pending_request'", "'Awaiting teacher response'", "'Monitor the Teacher response'", "'Awaiting coordination'"), 'The workspace must show the pending Teacher delivery as a real match, not as an unselected Teacher.');
$contains($readService, array(
    "'intros_new'",
    "'intros_new_aging'",
    "'intros_new_urgent'",
    'first_start',
    'NOT EXISTS (SELECT 1 FROM {$this->p}coordination_cases',
), 'The Enrolment snapshot must count new requests and classify their age/priority.');
$contains($view, array(
    "snapshotStat('New requests'",
    '$newTone = $newUrgent > 0 ? \'danger\' : ($newAging > 0 ? \'warn\' : \'ok\')',
    '$displayTone = $value > 0 ? $tone : \'neutral\'',
    'is-zero',
), 'Snapshot highlights must be conditional on pending work and the new-request stat must move green → yellow → red with priority.');
$contains($allocationRepo, array('public function offerSummaries(', 'offered_teacher_count', 'COUNT(DISTINCT teacher_id)', 'response_deadline_at'), 'Offer summaries must expose the authoritative deadline and distinct Teachers already offered.');
$contains($readService, array('offerSummaryUnavailable', "catch (\\Throwable \$error)", 'Delnavazan offer summary', 'Delnavazan intro teacher responses', 'Delnavazan enrolment snapshot'), 'Optional offer evidence must fail closed without taking the Enrolment or Overview screen down.');
$expect(!str_contains($readService, "static fn(int \$id): bool => \$id > 0 && !array_key_exists(\$id, \$this->offerSummaryCache)"), 'The offer-summary cache filter must not be a static closure using $this.');
$contains($view, array('Offer data unavailable', "empty(\$offer['unavailable'])"), 'The queue and case facts must state that offer evidence is unavailable instead of inventing a zero.');
$contains($readService, array(
    'public function pendingTeacherResponses(',
    "'teacher_offer'",
    "'offered_teacher_count'",
    "'response_remaining_seconds'",
    "'response_remaining_label'",
    "'response_deadline_iso'",
    'remainingLabel(',
), 'Intro rows must carry the remaining acceptance time and the number of Teachers already offered.');
$contains($readService, array(
    "'teacher_response' => (string) (\$case['teacher_response'] ?? '')",
    "'teacher_offer' => (array) (\$case['teacher_offer'] ?? array())",
), 'The intro queue row projection must copy the composed Teacher response and offer evidence.');
$contains($view, array(
    '<th>Teacher response</th>',
    'teacherOfferCell(',
    'Teachers offered',
    'data-dzn-offer-deadline',
    'data-dzn-offer-countdown',
    'Offered to ',
), 'Enrolment must show the Teacher response window and offer count in the intro queue and case facts.');
$contains($adminJs, array('data-dzn-offer-deadline', 'offerRemainingLabel', 'updateOfferCountdowns', "classList.toggle('is-expired'"), 'The admin queue must refresh the remaining acceptance time from the authoritative UTC deadline.');
$contains($css, array('.dzn-offer-window', '.dzn-offer-window.is-expired'), 'The response window and expired state need an explicit visual treatment.');
$contains($css, array('.dzn-enrolment__hero', '.dzn-booking-table', '.dzn-class-table', '.dzn-case-grid', '.dzn-timeline', '@media (max-width: 782px)'), 'The workspace needs responsive desktop/tablet/mobile styling.');
$expect(!str_contains($readService, 'SELECT * FROM {$this->p}booking_request_contact_snapshots'), 'The read projection must not leak full contact snapshots into unrelated rows.');

$agreement = $read('src/Core/Application/AdministratorAgreementService.php');
$finalAcceptance = $read('src/Core/Application/FinalAcceptanceService.php');
$preparationService = $read('src/Core/Application/PostIntroPreparationService.php');
$continuationService = $read('src/Core/Application/PostIntroContinuationService.php');
$overridePolicy = $read('src/Core/Application/IntroRecurringSlotOverridePolicy.php');
$lifecycleService = $read('src/Core/Application/IntroLessonLifecycleService.php');
$contains($agreement, array(
    'public function execute(',
    'OUTCOME_INTRO',
    'OUTCOME_FULL_TERM',
    'administratorTotalOverrideConfirmDelivery',
    'recordAdministrativeCompletion',
    'continueAsAdministrator',
    'administrator_agreement_executed',
    'original_requested_time',
    'BookingTeacherOccupancyReadService',
    'studentOccupancyConflicts',
    "include_open_requests",
    'attestation',
    'administratorId',
), 'The administrator agreement executor must sequence the existing authorities, preserve the original time and refuse real collisions.');
$contains($agreement, array('Full term', 'Intro'), 'The administrator execution path must offer Intro and Full term only.');
$expect(!str_contains($agreement, 'single_class'), 'The single-class option must not be introduced.');
$contains($controller, array("case 'execute_agreement':", 'AdministratorAgreementService', 'agreement_outcome', 'attestation'), 'The Execute agreement command must be dispatched through the workspace controller.');
$contains($view, array('executeAgreement(', 'id="dzn-execute-agreement"', "'execute_agreement'", 'agreement_outcome', 'value="intro"', 'value="full_term"', 'attestation', 'Agreement note'), 'The case page must expose the single Execute agreement card with Intro, Full term and one agreement note.');
$expect(strpos($view, 'self::executeAgreement($case);') < strpos($view, 'self::caseActions($case);'), 'The Execute agreement card must render before the advanced action stack.');
$contains($finalAcceptance, array('public function acceptAttested(', "'administrator_attested'", 'attestationNoteDigest', 'Attesting administrator does not exist'), 'The administrator-attested acceptance must record the administrator route while preserving the strict Student/guardian route.');
$contains($preparationService, array('public function recordAdministrativeCompletion(', "'administrator_completion'", 'Administrative completion:', 'intro_booking_not_confirmed', 'intro_outcome_already_recorded'), 'Administrative completion must be a distinct, audited source that triggers the normal preparation.');
$contains($continuationService, array('public function continueAsAdministrator(', 'acceptAttested', 'offline_paid', 'payment_received', 'student_acceptance_capacity_unresolved', 'ManualPaymentSettlementService'), 'The administrator continuation must record acceptance, create the normal offer/payment and leave classes gated on verified payment.');
$contains($overridePolicy, array('ADMIN_BASIS', 'administrator_attested_outside_availability', "in_array(\$actorType, array('teacher', 'administrator')"), 'An administrator-rescheduled slot outside published availability must be recorded as an auditable recurring-slot override.');
$contains($lifecycleService, array('bool $administratorOverride = false', 'IntroRecurringSlotOverridePolicy::ADMIN_BASIS', '$administratorOverride && $actorType === \'administrator\''), 'The reschedule authority must allow the administrator override without changing the Teacher route.');

echo "Enrolment administration workspace contract passed\n";
