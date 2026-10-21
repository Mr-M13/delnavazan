<?php
/**
 * Source contract for the post-introductory enrolment automation.
 *
 * This guard is deliberately source-level: the full lifecycle is exercised in the disposable
 * WordPress runtime (platform/tests/post-intro-enrolment-automation-runtime.php), while this
 * contract prevents the durable invariants from silently disappearing during refactors.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_readable($full)) throw new RuntimeException('missing file: ' . $path);
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$contains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$migrator = $read('platform/src/Core/Infrastructure/Migration/Migrator.php');
$plugin = $read('platform/delnavazan-platform.php');
$preparation = $read('platform/src/Core/Application/PostIntroPreparationService.php');
$continuation = $read('platform/src/Core/Application/PostIntroContinuationService.php');
$activation = $read('platform/src/Core/Application/PostIntroActivationService.php');
$repository = $read('platform/src/Core/Infrastructure/Repository/PostIntroRepository.php');
$actor = $read('platform/src/Core/Application/PostIntroSystemActor.php');
$hook = $read('platform/src/Integrations/PostIntroSettlementHook.php');
$worker = $read('platform/src/Integrations/PostIntroActivationWorker.php');
$admin = $read('platform/src/Admin/Controller/PostIntroAdminController.php');
$studentRead = $read('platform/src/Portals/PostIntroStudentReadService.php');
$studentController = $read('platform/src/Portals/PostIntroContinuationController.php');
$teacherController = $read('platform/src/Portals/PostIntroTeacherOutcomeController.php');
$bookingValidation = $read('platform/src/Core/Application/BookingRequestValidationService.php');
$bookingForm = $read('theme/inc/booking.php');
$bookingJs = $read('theme/assets/js/booking.js');
$portalBridge = $read('theme/inc/platform-bridge.php');
$studentShell = $read('theme/template-parts/portal/shell.php');
$studentCard = $read('theme/template-parts/portal/post-intro.php');
$teacherCard = $read('theme/template-parts/teacher-portal/post-intro-outcomes.php');
$teacherHome = $read('theme/template-parts/teacher-portal/home.php');
$notificationWorker = $read('platform/src/Integrations/BookingStudentNotificationWorker.php');
$executionRule = $read('platform/src/Core/Application/PaymentExecution/PaymentExecutionRule.php');

// 1. Additive migration and existing-data compatibility.
$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '48'"), 'Schema 48 must be published');
$contains($migrator, array(
    '048_post_intro_enrolment_automation',
    "'adult_self_attested'",
    "adult_self_attested tinyint(1) NOT NULL DEFAULT 0",
    'booking_request_id bigint unsigned NULL',
    'ADD UNIQUE KEY booking_request_id(booking_request_id)',
    'intro_course_id bigint unsigned NULL',
    'post_intro_preparations',
    'post_intro_activation_jobs',
    'booking_request_intro_outcomes',
    'pattern_id',
    'teacher_notified_at',
    'verify_post_intro_enrolment_automation_schema',
), 'post-intro migration invariant missing');
$assert(str_contains($migrator, "get_row(\"SHOW COLUMNS FROM {\$lessons} LIKE 'booking_request_id'\")"), 'Lesson provenance must be added only when absent');
$assert(str_contains($migrator, "ADD COLUMN adult_self_attested tinyint(1) NOT NULL DEFAULT 0"), 'adult attestation must be additive with a safe default');

// 2. Booking intake self-attestation is explicit, backward compatible, and never inferred.
$contains($bookingForm, array('data-adult-attested', 'required'), 'booking form must require the adult self-attestation');
$assert(str_contains($bookingJs, 'adult_self_attested: Boolean('), 'booking submission must send the attestation');
$contains($bookingValidation, array("'adult_self_attested'", 'FILTER_VALIDATE_BOOLEAN', "'adult_self_attested' => \$adultAttested ? 1 : 0"), 'backend must accept and persist a boolean attestation');
$assert(!str_contains($bookingValidation, 'date_of_birth'), 'the automation must not invent a date-of-birth field');

// 3. The preparation sequence reuses every canonical authority and checkpoints each step.
$contains($preparation, array(
    'PostIntroSystemActor::run',
    'new LessonService())->create',
    "'lesson_type' => 'introductory'",
    "'booking_request_id' => \$bookingId",
    'recordConfirmedBookingOccurrence',
    'completeIntroductory',
    'recordFirstRegularSlot',
    "'authority_basis' => 'confirmed_booking_recurrence'",
    "'reason_code' => 'confirmed_booking_weekly_slot'",
    'new CoordinationCaseService())->open',
    "'confirmed_booking'",
    'recordConfirmedBookingAcceptance',
    "'confirmed_booking_teacher_acceptance'",
    'new ProposalService())->issueInitial',
    "'post_intro_invitation'",
), 'preparation must reuse the canonical authorities');
$assert(str_contains($repository, 'canonical_continuation_slot_authorities'), 'the preparation must read the persisted slot authority');
$assert(str_contains($preparation, 'assertSlotAvailable') && str_contains($preparation, "'teacher_unavailable'"), 'the committed slot must be covered by published Teacher availability');
$assert(str_contains($preparation, 'ELIGIBLE_FROM') && str_contains($preparation, 'intro_outcome_outside_window'), 'historical intros must not enter the automation queue');
$assert(str_contains($repository, 'activePatternForAnyTeacher') && str_contains($continuation, 'commercial_pattern_teacher_conflict'), 'a pattern for another Teacher must fail closed');
$assert(str_contains($worker, 'post_intro_job_lease_lost'), 'job release must be fenced by the lease token');
$assert(str_contains($preparation, 'standardCoursesForInstrument'), 'the ongoing Course must be resolved from the instrument');
$assert(str_contains($preparation, 'count($courses) !== 1'), 'ambiguous standard Courses must fail closed');
$assert(str_contains($preparation, 'commercial_region_unresolved'), 'an unresolved commercial region must be an exception');
$assert(str_contains($preparation, 'teacher_not_eligible_for_standard_course'), 'Teacher eligibility must be re-proved');
$assert(str_contains($preparation, "'state' => 'exception'"), 'preparation failures must become an explicit exception state');
$assert(str_contains($preparation, 'intro_student_no_show') && str_contains($preparation, 'intro_cancelled'), 'no-show and cancellation must never invite continuation');
$assert(str_contains($preparation, 'self::OUTCOME_DELIVERED'), 'only a delivered outcome may continue');

// 4. The Student action is owned by the Student and uses the existing commercial/checkout seam.
$contains($continuation, array(
    'PortalPrincipalResolver',
    "resolve('student')",
    '(int) $preparation->student_id !== $studentId',
    "'Unauthorized'",
    'adult_attestation_required',
    'guardian_required',
    'StudentAcceptanceAuthorityService',
    'ProposalAcceptanceService',
    'FinalAcceptanceService',
    'EnrolmentConversionService',
    'continueWithTeacher',
    'CommercialPatternService',
    'CommercialOfferService',
    'StudentCheckoutInitiationService',
    "'obligation_uid' => (string) \$first->uid",
), 'the Student continuation action must preserve ownership and reuse canonical services');
$assert(str_contains($continuation, 'post-intro-provisional-') && str_contains($continuation, 'post-intro-final-') && str_contains($continuation, 'post-intro-conversion-') && str_contains($continuation, 'post-intro-continuation-') && str_contains($continuation, 'post-intro-pattern-') && str_contains($continuation, 'post-intro-offer-'), 'every command must use a deterministic idempotency key');
$assert(str_contains($continuation, "state' => 'expired'") && str_contains($continuation, 'continuation_reservation_expired'), 'expired holds must become an explicit terminal state');
$assert(!str_contains($continuation, 'date_of_birth'), 'the Student action must not invent demographic authority');

// 5. Settlement enqueues exactly one durable job inside the verified transaction.
$contains($hook, array(
    "'dzn_phase_2a2r1_after_settlement'",
    'preparationByOffer',
    'activeJobForPurchase',
    'insertJob',
    "'state' => 'queued'",
    "'state' => 'payment_received'",
), 'settlement must enqueue a durable activation job');
$assert(str_contains($hook, 'throw $error'), 'job persistence failure must roll back settlement rather than lose the activation path');
$assert(str_contains($plugin, 'PostIntroSettlementHook::register()'), 'settlement hook must be registered');

// 6. Activation, retry, lease and idempotency.
$contains($activation, array(
    'PostIntroSystemActor::run',
    'CanonicalEnrolmentLifecycleService',
    "'authorised'",
    'handoffFromEntitlement',
    'bindEntitlementToTerm',
    'CanonicalTermAuthorityService',
    'assignInitial',
    'intervalsFor',
    'createStandard',
    'schedule(',
    'projectCalendarEvent',
    'GoogleCalendarProjectionService',
    "'post-intro-enrolment-activate-' . \$uid",
    "'post-intro-handoff-' . \$uid",
    "'post-intro-term-bind-' . \$uid",
    "'post-intro-term-activate-' . \$uid",
    "'post-intro-assignment-' . \$uid",
    "'post-intro-lesson-' . \$uid",
    "'post-intro-schedule-' . \$uid",
    "'post-intro-calendar-' . \$uid",
), 'activation must be replay-safe and canonical');
$assert(str_contains($activation, "if ((string) \$mapping->projection_state !== 'pending') return;"), 'a verified projection must not be dispatched twice');
$assert(!str_contains($activation, 'meet.google.com') && !str_contains($activation, 'join_uri_reference' . ' = '), 'activation must never fabricate a Meet URL');
$assert(str_contains($activation, 'provider_connection_required') || str_contains($activation, "projectCalendarEvent"), 'provider projection must go through the provider authority');
$contains($worker, array(
    'claimJob',
    "hash('sha256'",
    'MAX_ATTEMPTS',
    'retryable',
    'failed',
    'completed',
    'PostIntroActivationService',
), 'the worker must be leased and retry safe');
$assert(str_contains($worker, "releaseJob((int) \$job->id, 'completed'"), 'successful activation must close the job');

// 7. Retryable provider/notification failures never fabricate canonical truth.
$assert(str_contains($worker, "str_starts_with(\$message, 'provider_')"), 'provider failures must be retried');
$assert(str_contains($worker, "'teacher_notification_failed'"), 'notification failure must be retried');
$assert(str_contains($worker, "state' => 'exception'"), 'non-retryable failures must become an explicit exception');

// 8. Least-privilege automation principal.
$contains($actor, array(
    "'dzn_manage_booking_request_coordination'",
    "'dzn_manage_teacher_availability_assent'",
    "'dzn_issue_booking_request_proposals'",
    "'dzn_convert_service_arrangements_to_enrolments'",
    "'dzn_manage_canonical_enrolment_lifecycle'",
    "'dzn_manage_commercial_capacity'",
    "'dzn_issue_commercial_offers'",
    "'dzn_bind_commercial_term_funding'",
    "'dzn_manage_canonical_terms'",
    "'dzn_manage_teacher_assignments'",
    "'dzn_manage_canonical_lessons'",
    "'dzn_manage_canonical_lesson_schedules'",
    "'dzn_manage_provider_integrations'",
    'wp_set_current_user',
), 'the system actor must hold exactly the existing canonical capabilities');
$assert(!str_contains($actor, "'manage_options'"), 'the automation principal must not be an administrator');
$assert(!str_contains($actor, "'dzn_override_canonical_lesson_schedule_availability'"), 'the automation must not hold the schedule override capability');

// 9. Student and Teacher portal projections expose no internal identifiers or raw errors.
$contains($studentRead, array(
    'preparationsForStudent',
    'studentCapacity',
    'action_url',
    'wp_create_nonce',
    'ادامهٔ کلاس با همین مدرس',
), 'the Student card must be read-only and actionable');
$assert(!str_contains($studentRead, 'exception_detail'), 'the Student projection must not expose raw exception detail');
$contains($studentCard, array('dzn_post_intro_continue', 'preparation_uid', 'adult_attested', 'تکمیل پرداخت'), 'the Student card must render the payment action');
$contains($studentShell, array("dzn_theme_portal_component( 'post-intro'"), 'the Student shell must include the continuation card');
$contains($studentController, array('check_admin_referer', 'wp_redirect', 'safeStatus'), 'the Student action must verify the nonce and redirect safely');
$contains($teacherCard, array('dzn_post_intro_outcome', 'delivered', 'student_no_show', 'teacher_cancelled'), 'the Teacher card must record the real outcome');
$contains($teacherHome, array("dzn_theme_teacher_portal_component( 'post-intro-outcomes'"), 'the Teacher portal must include the outcome control');
$contains($teacherController, array('resolve(\'teacher\')', 'recordTeacherOutcome'), 'the Teacher outcome must be Teacher-owned');
$contains($portalBridge, array("'post_intro_outcomes'", 'pendingOutcomeConfirmationsForTeacher'), 'the Teacher model must carry pending outcomes');
$contains($portalBridge, array("'post_intro'", '$post_intro'), 'the Student model must carry post-intro state');

// 10. Admin intervention is exception-only and capability-gated.
$contains($admin, array(
    "'dzn_manage_post_intro_automation'",
    'exceptionPreparations',
    'failedJobs',
    'pendingOutcomeConfirmations',
    'retry_preparation',
    'retry_activation',
    'record_outcome',
    'check_admin_referer',
), 'the admin surface must be exception-only and nonce-protected');
$assert(str_contains($migrator, "add_cap('dzn_manage_post_intro_automation')"), 'the admin exception capability must be installed');
$assert(str_contains($plugin, 'PostIntroAdminController::register()'), 'the admin exception surface must be registered');

// 11. Notification delivery reuses the durable hand-off table and adds explicit kinds.
$contains($notificationWorker, array(
    "'post_intro_invitation'",
    "'post_intro_activated'",
    "home_url('/student-portal/')",
), 'post-intro email must reuse the durable notification worker');
$assert(str_contains($repository, "insertNotificationIfMissing"), 'notification enqueue must be idempotent');

// 12. No live execution or secret exposure.
$assert(str_contains($executionRule, 'LIVE_EXECUTION_PROVIDERS=array()') && str_contains($executionRule, 'PROVISIONABLE_PROVIDERS=array()'), 'live execution must stay closed');
$assert(!str_contains($activation, 'sk_live_') && !str_contains($continuation, 'sk_live_'), 'no live Stripe secret may appear');
$assert(!str_contains($admin, 'exception_detail') || str_contains($admin, 'esc_html'), 'admin detail must be escaped');

// 13. The runtime lifecycle test must exist and cover the required scenarios.
$runtime = $read('platform/tests/post-intro-enrolment-automation-runtime.php');
$contains($runtime, array(
    'DZN_POST_INTRO_RUNTIME_TEST',
    'delivered',
    'student_no_show',
    'teacher_cancelled',
    'duplicate',
    'concurrent',
    'payment',
    'expired',
    'provider',
    'cross_student',
), 'the runtime test must cover the required lifecycle and failure scenarios');

echo "Post-intro enrolment automation contract passed.\n";
