<?php
$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/src/Core/Application/BookingTeacherDeliveryService.php');
$allocation = (string) file_get_contents($root . '/src/Core/Application/BookingAllocationService.php');
$repo = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$submission = (string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php');
$migrator = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$plugin = (string) file_get_contents($root . '/delnavazan-platform.php');
$dashboard = (string) file_get_contents($root . '/src/Admin/Controller/AcademyOperationsController.php');
$bridge = (string) file_get_contents(dirname($root) . '/theme/inc/platform-bridge.php');
$attention = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/attention.php');
$teacher_js = (string) file_get_contents(dirname($root) . '/theme/assets/js/teacher-portal.js');
$teacher_css = (string) file_get_contents(dirname($root) . '/theme/assets/css/teacher-portal.css');
$allocation_notice = (string) file_get_contents($root . '/src/Integrations/BookingAllocationNotificationWorker.php');
$catalogue = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php');
$response_service = (string) file_get_contents($root . '/src/Core/Application/BookingTeacherResponseService.php');
$open_repo = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherOpenBookingRequestRepository.php');
$open_read = (string) file_get_contents($root . '/src/Core/Application/TeacherOpenBookingRequestReadService.php');
$claim_controller = (string) file_get_contents($root . '/src/Portals/TeacherBookingClaimController.php');
$open_template = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/open-requests.php');
$intro_card = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/intro-request-card.php');
$shell = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/shell.php');
$home = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/home.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema) && (int) $schema[1] >= 44, 'Booking response recovery migration must retain the delivery schema and advance the platform schema.');
foreach (array('booking_request_teacher_deliveries', 'UNIQUE KEY booking_request_id', 'KEY teacher_state') as $needle) {
    $assert(str_contains($migrator, $needle), 'Booking delivery migration contract missing: ' . $needle);
}
$assert(str_contains($submission, 'createTime') && str_contains($submission, 'BookingTeacherDeliveryService'), 'Booking intake must persist requested times and invoke Teacher delivery.');
$timeWrite = strpos($submission, 'createTime');
$deliveryWrite = strpos($submission, 'BookingTeacherDeliveryService())->deliver');
$transactionalBody = substr($submission, strpos($submission, 'public function submitWithinTransaction'));
$assert($timeWrite !== false && $deliveryWrite > $timeWrite && str_contains($submission, '$this->submitWithinTransaction( $input, $idempotencyKey, $newSubmissionGate, $studentId )') && str_contains($transactionalBody, 'completeSubmissionKey'), 'Delivery must be created after requested times and inside the intake transaction.');
foreach (array('eligibleTeachers($courseId', 'effective($teacherId', 'overlapping(', 'assertNoActiveHold', "'pending_request'") as $needle) {
    $assert(str_contains($service.$allocation, $needle), 'Teacher matching authority missing: ' . $needle);
}
$assert(str_contains($repo, 'WHERE d.teacher_id=%d') && str_contains($repo, "d.state='pending_request'"), 'Teacher inbox reads must be scoped to the signed-in Teacher and pending state.');
$assert(str_contains($repo, "r.resolution_state='unresolved'") && str_contains($repo, 'r.privacy_erased_at IS NULL'), 'Resolved or erased requests must not remain in the Teacher inbox.');
$assert(str_contains($repo, 'function requestedTimes') && !str_contains($repo, 'ORDER BY sequence_number ASC LIMIT 3'), 'Teacher inbox must retain every ranked requested time without truncation.');
$assert(str_contains($repo, 'r.id booking_request_id') && str_contains($service, '(int) $delivery->booking_request_id'), 'Teacher preference projection must carry the owning booking request identifier.');
$assert(str_contains($service, 'requested_times = $this->deliveries->requestedTimes') && str_contains($bridge, "'requested_preferences'=>\$preferenceModels"), 'Teacher presentation must show all ranked preferences without changing the authoritative selected-time action.');
$assert(!str_contains($service, 'new CanonicalLesson') && !str_contains($service, 'new Google') && !str_contains($service, '->project('), 'Intake delivery must not create lessons or provider projections.');
$assert(str_contains($bridge, 'ownPending()') && str_contains($bridge, "'authoritative_request'=>true"), 'Teacher portal must use the teacher-scoped delivery read model.');
$assert(str_contains($repo, 'offer.deadline_at response_deadline_at') && str_contains($service, 'response_deadline_utc') && str_contains($service, 'response_remaining_seconds'), 'The Teacher inbox must expose the active offer deadline and remaining seconds.');
$assert(str_contains($bridge, 'response_deadline_iso') && str_contains($bridge, 'response_remaining_label') && str_contains($intro_card, 'data-dzn-response-deadline') && str_contains($teacher_js, 'data-dzn-response-deadline') && str_contains($teacher_css, '.dzn-tp-response-window'), 'The Teacher portal must render a live countdown from the authoritative response deadline without duplicating a 24-hour label.');
$assert(str_contains($allocation_notice, "'response_window'") && str_contains($allocation_notice, 'مدرس در دسترس بعدی') && str_contains($catalogue, 'response_window') && str_contains($catalogue, 'response_deadline'), 'The booking notification must state the 24-hour window and reassignment consequence.');
$assert(str_contains($intro_card, 'درخواست جدید کلاس آشنایی') && str_contains($intro_card, 'زمان‌های پیشنهادی هنرجو') && str_contains($intro_card, 'تأیید درخواست'), 'Teacher portal must distinguish a request from a confirmed class and support direct acceptance.');
$assert(str_contains($dashboard, "current_user_can('dzn_manage_onboarding')") && str_contains($dashboard, "state='pending_review'"), 'Academy approval alert must be narrowly capability-gated and count pending review submissions.');

// Every Student preference is selectable and the originally offered time keeps strict availability.
$assert(str_contains($service, "['coverage']"), 'The Teacher inbox must state each preference availability coverage.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_preference_models') && str_contains($bridge, "'coverage_label'") && str_contains($intro_card, 'name="requested_time_id"') && str_contains($intro_card, 'dzn-tp-preference__coverage'), 'The Teacher inbox must render a selectable radio for every Student preference with its coverage label.');
$assert(str_contains($response_service, '$allowOverride=$selectedTimeId!==$offeredTimeId;') && str_contains($response_service, 'switchOfferedTime') && str_contains($response_service, 'teacher_booking_response_availability_override'), 'Only a different explicitly selected preference may override availability, and every override must be audited.');
$assert(str_contains($response_service, 'assertAcceptable($delivery,$now,$selectedTimeId,$allowOverride)'), 'The original offered time must keep the strict availability revalidation path.');

// Unmatched requests for the Teacher's own eligible courses.
foreach (array(
    "r.lifecycle_status='submitted'", "r.resolution_state='unresolved'", 'r.privacy_erased_at IS NULL',
    "identity.outcome='resolved'", 'teacher_course_eligibilities', "offer.active_slot=1",
    "confirmation.state<>'failed'", "existing.state IN ('accepting','confirmed')",
    'prior.teacher_id=%d', 'INTERVAL 3600 SECOND', 'ORDER BY rt0.sequence_number ASC',
) as $needle) {
    $assert(str_contains($open_repo, $needle), 'Unmatched request query must exclude every matched, erased or already-offered request: ' . $needle);
}
$assert(str_contains($open_read, 'openForTeacher') && str_contains($open_read, 'coverage(') && str_contains($open_read, "'outside'"), 'Unmatched requests must expose every preference with its explicit outside-availability state.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_open_booking_requests()') && str_contains($bridge, "'open_requests' => \$openRequestAttention"), 'The Teacher view model must include the unmatched requests section.');

// Claim endpoint and its action/nonce, plus explicit override revalidation and audit.
$assert(str_contains($plugin, 'TeacherBookingClaimController') && str_contains($claim_controller, "'dzn_teacher_booking_claim'"), 'The unmatched claim endpoint must be registered.');
$assert(str_contains($claim_controller, 'check_admin_referer(self::ACTION)') && str_contains($claim_controller, 'requested_time_id') && str_contains($claim_controller, 'claim_key'), 'The claim endpoint must require its nonce, request identity, preference and idempotency key.');
$assert(str_contains($response_service, 'public function claim(') && str_contains($response_service, 'assertAcceptable($locked,$now,$requestedTimeId,true)') && str_contains($response_service, "'teacher_claim_availability_override'"), 'A claim must explicitly override availability, still revalidate occupancy, and audit the override.');
$assert(str_contains($intro_card, 'claim_action_url') && str_contains($intro_card, 'name="requested_time_id"') && str_contains($intro_card, 'name="claim_key"') && str_contains($intro_card, 'name="request_uid"'), 'The unmatched section must post the claim nonce, request identity, key and selected preference.');
$classes_position = strpos($shell, "dzn_theme_teacher_portal_component( 'classes'");
$open_position = strpos($shell, "dzn_theme_teacher_portal_component( 'open-requests'");
$assert(str_contains($shell, "'classes' === \$screen") && false !== $classes_position && false !== $open_position && $open_position > $classes_position, 'Unmatched opportunities must render at the bottom of the default classes screen.');
$assert(!str_contains($home, "dzn_theme_teacher_portal_component( 'open-requests'"), 'Unmatched opportunities must not be duplicated on the home screen.');
echo "Booking teacher delivery contract passed\n";
