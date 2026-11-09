<?php
/**
 * Source/runtime-contract guard for the redesigned Teacher introductory-request card and the
 * unassigned opportunity board. It proves the UI reuses the canonical acceptance authorities and
 * that no second confirmation workflow or request-cancelling decline was introduced.
 */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static fn(string $path): string => (string) file_get_contents($path);
$files = array(
    'plugin' => $read($root . '/delnavazan-platform.php'),
    'migration' => $read($root . '/src/Core/Infrastructure/Migration/Migrator.php'),
    'response' => $read($root . '/src/Core/Application/BookingTeacherResponseService.php'),
    'response_repo' => $read($root . '/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php'),
    'open_repo' => $read($root . '/src/Core/Infrastructure/Repository/TeacherOpenBookingRequestRepository.php'),
    'open_read' => $read($root . '/src/Core/Application/TeacherOpenBookingRequestReadService.php'),
    'claim_controller' => $read($root . '/src/Portals/TeacherBookingClaimController.php'),
    'response_controller' => $read($root . '/src/Portals/TeacherBookingResponseController.php'),
    'bridge' => $read($theme . '/inc/platform-bridge.php'),
    'attention' => $read($theme . '/template-parts/teacher-portal/attention.php'),
    'intro_card' => $read($theme . '/template-parts/teacher-portal/intro-request-card.php'),
    'open' => $read($theme . '/template-parts/teacher-portal/open-requests.php'),
    'shell' => $read($theme . '/template-parts/teacher-portal/shell.php'),
    'home' => $read($theme . '/template-parts/teacher-portal/home.php'),
    'js' => $read($theme . '/assets/js/teacher-portal.js'),
    'css' => $read($theme . '/assets/css/teacher-portal.css'),
);
$fail = array();
$assert = static function (bool $ok, string $message) use (&$fail): void { if (!$ok) { $fail[] = $message; } };
$all = implode("\n", $files);

$assert(preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $files['plugin'], $schema) && (int) $schema[1] >= 61, 'Schema must include the Teacher decline evidence migration.');
foreach (array('074_teacher_booking_declines', 'install_teacher_booking_declines', 'verify_teacher_booking_declines_schema', 'booking_request_teacher_declines', "state='active'") as $needle) {
    $assert(str_contains($files['migration'], $needle), 'Teacher decline migration missing: ' . $needle);
}

// One reusable card for both sources, with the required Persian content.
foreach (array('درخواست جدید کلاس آشنایی', 'زمان‌های پیشنهادی هنرجو', 'زمان‌های سبز با برنامهٔ زمانی شما هماهنگ هستند', 'می‌توانید آن‌ها را نیز انتخاب و تأیید کنید', 'تأیید درخواست', 'رد درخواست', 'دلیل رد درخواست', 'زمان‌های پیشنهادی مناسب نیست', 'در حال حاضر ظرفیت ندارم', 'امکان تدریس به این هنرجو را ندارم', 'دلیل دیگر') as $needle) {
    $assert(str_contains($files['intro_card'], $needle), 'Reusable card missing required copy: ' . $needle);
}
$assert(str_contains($files['intro_card'], "'available' : 'outside'") && str_contains($files['intro_card'], 'dzn-tp-preference--') && str_contains($files['intro_card'], 'requested_time_id') && str_contains($files['intro_card'], 'data-dzn-tp-preference-input'), 'Every Student preference must be a selectable green/yellow option.');
$assert(!str_contains($files['intro_card'], '<dialog') && !str_contains($files['intro_card'], 'aria-haspopup="dialog"'), 'Acceptance/rejection must not open a second confirmation dialog.');
$assert(str_contains($files['intro_card'], 'response_remaining_label') && !str_contains($files['intro_card'], 'مهلت پاسخ: ۲۴ ساعت'), 'The card must show the dynamic countdown without a duplicated 24-hour deadline label.');
$assert(str_contains($files['intro_card'], 'data-dzn-tp-accept') && str_contains($files['intro_card'], 'disabled>تأیید درخواست') && str_contains($files['intro_card'], 'برای تأیید، یکی از زمان‌های پیشنهادی را انتخاب کنید.'), 'Acceptance must start disabled until a preference is selected.');
$assert(str_contains($files['intro_card'], 'فرصت آزاد · هنوز مدرسی برای این درخواست تعیین نشده است') && str_contains($files['intro_card'], 'وضعیت: فرصت آزاد و قابل پذیرش') && preg_match('/if \( \$opportunity \).*?else :/s', $files['intro_card']) === 1, 'Opportunity cards must use the shared card while keeping the response countdown inside the assigned-request branch.');

// Model: student identity, country name, deadline sentence, green/yellow advisory matching.
foreach (array("'student_name'", "'instrument'", "'country_label'", "'deadline_sentence'", "'response_remaining_label'", "'tone'", "'هماهنگ با برنامهٔ شما'", "'خارج از برنامهٔ زمانی شما'", 'dzn_theme_platform_country_label', 'dzn_theme_platform_teacher_deadline_sentence') as $needle) {
    $assert(str_contains($files['bridge'], $needle), 'Teacher view model missing: ' . $needle);
}
$assert(str_contains($files['bridge'], "'request_country'") || str_contains($files['bridge'], 'request_country'), 'The Teacher model must read the registered country rather than an ISO fallback.');
$assert(str_contains($files['bridge'], 'dzn_theme_platform_calendar_time') && str_contains($files['bridge'], 'teacher_timezone'), 'Deadline and preference labels must use the Teacher timezone/calendar.');
$assert(str_contains($files['bridge'], "'AU' => 'استرالیا'") && str_contains($files['bridge'], 'Locale::getDisplayRegion'), 'Country codes must be shown as Persian full names, not raw ISO codes.');
$assert(str_contains($files['bridge'], 'function dzn_theme_platform_teacher_deadline_sentence') && str_contains($files['bridge'], "'، ساعت '") && str_contains($files['bridge'], 'فرصت دارید به این درخواست پاسخ دهید'), 'The deadline sentence must be assembled from the Teacher-local date and start time.');

// The board is bottom-of-classes only and uses the same card.
$assert(str_contains($files['open'], 'فرصت‌های کلاس آشنایی') && str_contains($files['open'], 'درخواست‌های بدون مدرس برای سازهایی که تدریس می‌کنید') && str_contains($files['open'], "component( 'intro-request-card'"), 'Opportunity board must reuse the one card with the required section copy.');
$classes_position = strpos($files['shell'], "component( 'classes'");
$open_position = strpos($files['shell'], "component( 'open-requests'");
$assert(false !== $classes_position && false !== $open_position && $open_position > $classes_position, 'Opportunity board must be at the bottom of the Classes screen.');
$assert(!str_contains($files['home'], "component( 'open-requests'"), 'Opportunity board must not be duplicated on the home screen.');
$assert(str_contains($files['css'], 'dzn-tp-intro-card.is-opportunity') && str_contains($files['css'], 'dzn-color-warning') && str_contains($files['css'], 'dzn-tp-preference--outside'), 'Opportunity cards need a yellow highlight and yellow outside-availability rows.');

// Eligibility and concurrency are enforced by the existing query + claim authority.
foreach (array("r.lifecycle_status='submitted'", "r.resolution_state='unresolved'", 'r.privacy_erased_at IS NULL', 'offer.active_slot=1', "confirmation.state<>'failed'", "existing.state IN ('accepting','confirmed')", 'declined.state=\'active\'', 'teacher_course_eligibilities') as $needle) {
    $assert(str_contains($files['open_repo'], $needle), 'Opportunity eligibility query missing: ' . $needle);
}
$assert(!str_contains($files['open_repo'], 'ORDER BY sequence_number ASC LIMIT 3') && !str_contains($files['open_read'], 'array_slice'), 'Every submitted preference must be projected without truncation.');
$assert(str_contains($files['response'], 'public function declineOpportunity(') && str_contains($files['response'], 'teacherEligibleForCourse') && str_contains($files['response'], 'declineForTeacher') && str_contains($files['response'], 'recordDecline'), 'Opportunity decline must be Teacher-scoped, eligibility-checked and durable.');
$assert(substr_count($files['response'], 'confirmationForRequest') >= 2 && str_contains($files['response'], 'activeAllocationOffer') && str_contains($files['response'], 'assertAcceptable('), 'Claim acceptance must revalidate request status, concurrent assignment and hard scheduling conflicts.');
$assert(str_contains($files['response'], 'acceptedLocked(') && str_contains($files['response'], 'dispatchProjection(') && str_contains($files['response'], 'confirmDelivery('), 'Opportunity acceptance must use the canonical confirmation/projection pipeline.');
$assert(!str_contains($files['response'], "->closeRequest("), 'A Teacher decline must never close or cancel the Student request.');
$assert(str_contains($files['response'], 'DECLINE_REASONS') && str_contains($files['response'], "'reason_text'") && str_contains($files['response_repo'], 'booking_request_teacher_declines'), 'Rejection reason must be validated and persisted against the request/Teacher.');

// Controllers keep nonce authority and route both sources through existing services.
$assert(str_contains($files['response_controller'], 'check_admin_referer') && str_contains($files['response_controller'], 'reason_code') && str_contains($files['response_controller'], 'reason_text'), 'Assigned response must accept and validate a rejection reason.');
$assert(str_contains($files['claim_controller'], "'dzn_teacher_booking_claim'") && str_contains($files['claim_controller'], 'check_admin_referer(self::ACTION)') && str_contains($files['claim_controller'], 'declineOpportunity') && str_contains($files['claim_controller'], '->claim('), 'Opportunity accept/decline must use the canonical claim authority with nonce validation.');
$assert(str_contains($files['js'], 'data-dzn-tp-preference-input') && str_contains($files['js'], 'data-dzn-tp-reject-toggle') && str_contains($files['js'], 'data-dzn-tp-other-reason-input'), 'The card needs keyboard-accessible selection and inline rejection behaviour.');
$assert(str_contains($files['js'], 'مهلت پاسخ به پایان رسیده است') && str_contains($files['js'], 'control.disabled = true'), 'Expired cards must become non-actionable without a page reload.');

if ($fail) { throw new RuntimeException('Teacher intro opportunity board contract failed: ' . implode('; ', $fail)); }
echo 'Teacher intro opportunity board contract passed (' . count($files) . " files).\n";
