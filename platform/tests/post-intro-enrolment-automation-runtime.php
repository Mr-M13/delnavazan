<?php
/**
 * Disposable production-path post-introductory enrolment automation proof.
 *
 * Run inside the offline WordPress runtime after the Phase-J fixture exists:
 *   DZN_POST_INTRO_RUNTIME_TEST=authority wp eval-file platform/tests/post-intro-enrolment-automation-runtime.php
 *
 * Synthetic local data only. It never contacts Stripe or Google. The Calendar projection uses the
 * real pending-mapping and HTTP-transport path, with WordPress's local HTTP seam returning one
 * failure followed by acknowledged deterministic event writes.
 */
if (getenv('DZN_POST_INTRO_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Post-intro runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{BookingRequestSubmissionService,CanonicalTermAuthorityService,CatalogueService,CommercialCatalogueService,CommercialPatternService,CommercialPaymentService,CommercialPolicyService,CommercialSupport,InstalmentDeadlinePolicy,IntegrationSecretService,PostIntroContinuationService,PostIntroFulfilmentReadService,PostIntroPlanQuoteService,PostIntroPreparationService,PostIntroRecoveryService,PostIntroSystemActor,ProviderIntegrationRule,ProviderIntegrationService,StudentAcceptanceAuthorityService,StudentIdentityResolutionService,TeachingEligibilityService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Application\Checkout\{CheckoutSessionPort,StudentCheckoutInitiationService,StudentCommercialCheckoutReadService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,PostIntroRepository};
use Delnavazan\Platform\Integrations\{ContractProviderAdapters,PostIntroActivationWorker,PostIntroSettlementHook};
use Delnavazan\Platform\Portals\PortalAuthenticatedReadService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$actor = 1;
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || empty($fixture['sources'][0]) || empty($fixture['teacherUsers'][0])) throw new RuntimeException('Phase-J fixture required');
// The disposable runtime does not activate the production theme. Load only the presentation
// boundary under test so the teacher view model is exercised against the real canonical read.
if (!function_exists('dzn_theme_platform_teacher_model')) {
    require_once dirname(__DIR__, 2) . '/theme/inc/teacher-portal.php';
    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';
}
// This is a disposable, standalone runtime. Reset only derived automation/commercial/schedule
// storage so repeated runs are deterministic; the Phase-J accepted Enrolments themselves remain.
foreach (array(
    'post_intro_activation_jobs','post_intro_plan_choices','post_intro_preparations','booking_request_intro_outcomes',
    'provider_integration_commands','provider_calendar_event_mappings','provider_meeting_mappings','provider_identity_mappings',
    'canonical_lesson_schedule_commands','canonical_lesson_schedule_versions','canonical_lesson_commands','canonical_lesson_lifecycle_events','lessons',
    'teacher_assignment_commands','teacher_assignment_lifecycle_events','teacher_assignments',
    'term_commands','term_lifecycle_events','terms',
    'commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns',
    'commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans',
    'commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments',
    'commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments',
    'commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions',
    'canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations',
    'canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities',
) as $resetTable) {
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $resetTable)) === $p . $resetTable) {
        $wpdb->query("DELETE FROM {$p}{$resetTable}");
    }
}
$source = $fixture['sources'][0];
$teacherId = (int) $source['teacher_id'];
$teacherUser = (int) $fixture['teacherUsers'][0];
$introCourse = (int) $source['course_id'];
$instrumentId = (int) $wpdb->get_var($wpdb->prepare("SELECT instrument_id FROM {$p}courses WHERE id=%d", $introCourse));
if ($instrumentId < 1) throw new RuntimeException('Fixture instrument required');
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);
$now = gmdate('Y-m-d H:i:s');

function dzn_pia_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_pia_key(string $label): string { return 'dzn-post-intro-' . $label . '-' . wp_generate_uuid4(); }
function dzn_pia_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }

wp_set_current_user($actor);
add_filter('pre_wp_mail', static fn() => true);

// A. Actor identity is explicitly provisioned and ordinary runtime never creates or repairs it.
$expectActorFailure = static function (string $code, callable $attempt): void {
    $caught = null;
    try { $attempt(); } catch (Throwable $error) { $caught = $error; }
    dzn_pia_assert($caught !== null && $caught->getMessage() === $code, 'expected actor failure ' . $code . ', got ' . ($caught?->getMessage() ?? 'none'));
};
$bootstrapActorId = (int) get_option(PostIntroSystemActor::USER_OPTION);
dzn_pia_assert($bootstrapActorId > 0, 'the controlled bootstrap must provision the actor before runtime');

// This runtime is a genuine WP-CLI process, so the real-context bootstrap may provision. The
// impersonation regression (a web subscriber supplying an administrator ID/channel) is exercised
// by post-intro-system-actor-unit.php, where the non-WP-CLI context can be represented exactly.
$actorId = PostIntroSystemActor::provision();
dzn_pia_assert($actorId === $bootstrapActorId, 'explicit provisioning must be idempotent');
dzn_pia_assert($actorId > 0 && (int) get_option(PostIntroSystemActor::USER_OPTION) === $actorId, 'explicit administrator provisioning must bind the actor option');
$actorUser = get_user_by('id', $actorId);
dzn_pia_assert($actorUser instanceof WP_User, 'provisioned actor must exist');
dzn_pia_assert(get_user_meta($actorId, PostIntroSystemActor::USER_MARKER, true) === '1', 'provisioned actor must carry the plugin marker');
dzn_pia_assert(array_values(array_unique(array_map('strval', (array) $actorUser->roles))) === array(PostIntroSystemActor::ROLE), 'the actor must hold exactly one dedicated role');
$actorRole = get_role(PostIntroSystemActor::ROLE);
$actorRoleCaps = array_keys(array_filter((array) $actorRole->capabilities));
sort($actorRoleCaps);
$expectedActorCaps = PostIntroSystemActor::CAPABILITIES;
sort($expectedActorCaps);
dzn_pia_assert($actorRoleCaps === $expectedActorCaps, 'the actor role must hold exactly the allowlisted capability set');
$ranAs = 0;
PostIntroSystemActor::run(static function (int $id) use (&$ranAs): void { $ranAs = $id; });
dzn_pia_assert($ranAs === $actorId, 'the validated actor must run under its own principal');

// An extra role is a widened identity and must be rejected, not silently repaired at runtime.
$actorUser->add_role('administrator');
$expectActorFailure('post_intro_automation_actor_role_invalid', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($actor, 'wp_cli');

// A direct user grant is rejected even when it is not part of the dedicated role.
$actorUser = get_user_by('id', $actorId);
$actorUser->add_cap('manage_options');
$expectActorFailure('post_intro_automation_actor_direct_grant', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($actor, 'wp_cli');

// An unexpected capability on the dedicated role is rejected.
$actorRole = get_role(PostIntroSystemActor::ROLE);
$actorRole->add_cap('edit_posts');
$expectActorFailure('post_intro_automation_role_capability_invalid', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($actor, 'wp_cli');

// A missing required permission is rejected rather than being added at runtime.
$actorRole = get_role(PostIntroSystemActor::ROLE);
$actorRole->remove_cap('dzn_manage_canonical_lessons');
$expectActorFailure('post_intro_automation_capability_missing', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($actor, 'wp_cli');

// An unmarked account can never be adopted as the automation identity.
update_user_meta($actorId, PostIntroSystemActor::USER_MARKER, '0');
$expectActorFailure('post_intro_automation_actor_unverified', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($actor, 'wp_cli');

// Missing configuration must fail closed without provisioning silently during runtime.
$roleBefore = get_role(PostIntroSystemActor::ROLE)->capabilities;
$usersBefore = count(get_users(array('fields' => 'ID')));
update_option(PostIntroSystemActor::USER_OPTION, 0, false);
$expectActorFailure('post_intro_automation_unconfigured', static fn() => PostIntroSystemActor::run(static fn() => true));
dzn_pia_assert((int) get_option(PostIntroSystemActor::USER_OPTION) === 0, 'runtime must not rewrite the actor option');
dzn_pia_assert(get_role(PostIntroSystemActor::ROLE)->capabilities === $roleBefore, 'runtime must not repair role capabilities');
dzn_pia_assert(count(get_users(array('fields' => 'ID'))) === $usersBefore, 'runtime must not create an actor user');
update_option(PostIntroSystemActor::USER_OPTION, $actorId, false);
PostIntroSystemActor::run(static fn() => true);
$standardCourse = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}courses WHERE instrument_id=%d AND course_type='standard' AND status='active' AND archived_at IS NULL ORDER BY id LIMIT 1", $instrumentId));
if ($standardCourse < 1) {
    $standardCourse = (new CatalogueService())->course(array(
        'instrument_id' => $instrumentId,
        'name_fa' => 'دورهٔ استاندارد آزمون',
        'name_en' => 'Synthetic Standard ' . $suffix,
        'course_type' => 'standard',
        'status' => 'active',
        'default_duration_minutes' => 60,
        'default_buffer_minutes' => 15,
    ));
}
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $standardCourse, 'status' => 'active', 'reason_code' => 'synthetic_post_intro'));
$teacherTimezone = (string) $wpdb->get_var($wpdb->prepare("SELECT timezone FROM {$p}teachers WHERE id=%d", $teacherId));
if (!in_array($teacherTimezone, timezone_identifiers_list(), true)) $teacherTimezone = 'UTC';
try { (new TeacherAvailabilityService())->setProfile(array('teacher_id' => $teacherId, 'timezone' => $teacherTimezone, 'status' => 'active', 'reason_code' => 'synthetic_post_intro')); } catch (Throwable $ignored) {}
$wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_availability_rules WHERE teacher_id=%d", $teacherId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_availability_exceptions WHERE teacher_id=%d", $teacherId));
$availability = new TeacherAvailabilityService();
foreach (array(1, 2, 3, 4, 5, 6, 7) as $weekday) {
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => $teacherTimezone, 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_post_intro'));
}
$catalogue = new CommercialCatalogueService();
$productId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_products WHERE course_id=%d AND status='active' AND archived_at IS NULL ORDER BY id LIMIT 1", $standardCourse));
if ($productId < 1) {
    $product = $catalogue->createProduct(array('course_id' => $standardCourse, 'name_fa' => 'محصول آزمون', 'name_en' => 'Synthetic product', 'status' => 'active', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'post-intro-product-' . $suffix, 'evidence_at' => $now), dzn_pia_key('product'));
    $productId = (int) $product['product_id'];
}
if (!$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}commercial_prices WHERE product_id=%d AND region_code='AU' AND active_slot=1 AND status='active' AND archived_at IS NULL LIMIT 1", $productId))) {
    $catalogue->setPrice(array('product_id' => $productId, 'region_code' => 'AU', 'amount_minor' => 12000, 'evidence_channel' => 'staff_record', 'evidence_reference' => 'post-intro-price-' . $suffix, 'evidence_at' => $now), dzn_pia_key('price'));
}

/**
 * Create one synthetic confirmed introductory booking for an already-created fixture Student.
 *
 * @return array{request:int,student:int,principal:int,confirmation:string,delivery:int}
 */
$bookingSequence = 0;
$booking = function (string $label, int $hoursAgo, string $wallTime) use ($wpdb, $p, $instrumentId, $introCourse, $teacherId, $actor, $suffix, $now, &$bookingSequence): array {
    wp_set_current_user($actor);
    $bookingSequence++;
    $futureDate = gmdate('Y-m-d', strtotime('+' . (14 + $bookingSequence) . ' days'));
    $submission = (new BookingRequestSubmissionService())->submitPublic(array(
        'requested_instrument_id' => $instrumentId,
        'selected_intro_course_id' => $introCourse,
        'full_name' => 'Synthetic Post Intro ' . $label,
        'email' => 'post-intro-' . $label . '-' . $suffix . '@phase-post-intro.invalid',
        'mobile' => '+61400000000',
        'country' => 'AU',
        'city' => 'Brisbane',
        'timezone' => 'UTC',
        'communication_language' => 'en',
        'whatsapp_same_as_mobile' => true,
        'whatsapp_number' => '',
        'privacy_notice_accepted' => true,
        'privacy_notice_version' => '2026-09-05',
        'adult_self_attested' => true,
        // Intake delivery happens before this fixture rewrites the confirmed time below. Keep
        // that temporary request outside the continuation's protected recurring slot (04:xx),
        // otherwise a later scenario is correctly declined as unavailable before its own
        // historical confirmation can be installed.
        'requested_times' => array(array('local_date' => $futureDate, 'local_start_time' => '12:17', 'timezone' => 'UTC')),
    ), dzn_pia_key('request-' . $label));
    $requestId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", (string) $submission['request_reference']));
    dzn_pia_assert($requestId > 0, 'booking request persistence');
    $eventId = (new StudentIdentityResolutionService())->createAndResolve($requestId, array('display_name' => 'Synthetic Post Intro ' . $label), 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
    $studentId = (int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}booking_requests WHERE id=%d", $requestId));
    dzn_pia_assert($studentId > 0, 'identity resolution must bind the Student');
    $userId = wp_insert_user(array(
        'user_login' => 'post-intro-' . $label . '-' . $suffix,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'post-intro-' . $label . '-' . $suffix . '@principal.invalid',
        'role' => 'dzn_student',
    ));
    if (is_wp_error($userId)) throw new RuntimeException('Synthetic Student principal creation failed');
    $authority = new StudentAcceptanceAuthorityService();
    $authority->classify($studentId, 'adult', 'self_attestation', 'authenticated_platform', $now, $actor);
    $authority->establishPrincipal($studentId, (int) $userId, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);

    $delivery = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d LIMIT 1", $requestId));
    dzn_pia_assert($delivery !== null, 'intake delivery required');
    $deliveryId = (int) $delivery->id;
    $requestedTimeId = (int) $delivery->requested_time_id;
    $start = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC -' . $hoursAgo . ' hours'));
    $start = substr($start, 0, 11) . $wallTime;
    // The outcome service correctly refuses a class until it has ended. A start earlier in the
    // same hour can still have a future end (for example, a 04:05–04:35 class at 04:23), so the
    // fixture must prove the full confirmed interval is historical, not only its start.
    $end = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC +30 minutes'));
    if ($end > $now) {
        $start = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC -1 day'));
        $end = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC +30 minutes'));
    }
    $occupied = gmdate('Y-m-d H:i:s', strtotime($end . ' UTC +15 minutes'));
    $localDate = substr($start, 0, 10);
    dzn_pia_assert($wpdb->update($p . 'booking_request_requested_times', array('local_date' => $localDate, 'local_start_time' => $wallTime, 'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied), array('id' => $requestedTimeId)) === 1, 'requested time import');
    dzn_pia_assert($wpdb->update($p . 'booking_requests', array('student_id' => $studentId, 'lifecycle_status' => 'closed', 'resolution_state' => 'teacher_confirmed', 'updated_at' => $now, 'updated_by' => $actor), array('id' => $requestId)) === 1, 'booking request confirmation state');
    dzn_pia_assert($wpdb->update($p . 'booking_request_contact_snapshots', array('adult_self_attested' => 1), array('booking_request_id' => $requestId, 'snapshot_sequence' => 1)) !== false, 'adult attestation persistence');
    dzn_pia_assert($wpdb->update($p . 'booking_request_teacher_deliveries', array('state' => 'confirmed', 'updated_at' => $now, 'responded_at' => $now), array('id' => $deliveryId)) !== false, 'delivery confirmation');
    $confirmationUid = \Delnavazan\Platform\Core\Support\Identifier::uid();
    dzn_pia_assert($wpdb->insert($p . 'booking_request_confirmations', array('uid' => $confirmationUid, 'delivery_id' => $deliveryId, 'booking_request_id' => $requestId, 'requested_time_id' => $requestedTimeId, 'teacher_id' => $teacherId, 'connection_id' => 0, 'state' => 'confirmed', 'version' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'UTC', 'local_wall_date' => $localDate, 'local_wall_time' => $wallTime, 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 0, 'next_attempt_at' => null, 'confirmed_at' => $now, 'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor)) === 1, 'confirmation persistence');
    return array('request' => $requestId, 'student' => $studentId, 'principal' => (int) $userId, 'confirmation' => $confirmationUid, 'delivery' => $deliveryId);
};

$preparations = new PostIntroPreparationService();
$continuations = new PostIntroContinuationService();
$repository = new PostIntroRepository();
$continuePrepared = function (string $uid, int $principal, string $plan = 'full') use ($repository, $continuations): array {
    wp_set_current_user($principal);
    $preparation = $repository->preparationByUid($uid);
    if (!$preparation) throw new RuntimeException('preparation required');
    $quote = (new \Delnavazan\Platform\Core\Application\PostIntroPlanQuoteService())->forPreparation($preparation, (int) $preparation->student_id);
    return $continuations->continue($uid, true, $plan, (string) $quote['quote_token'], $plan === 'two_instalments');
};

// A. Delivered intro -> preparation, student payment action, verified settlement, activation.
$mainWall = sprintf('04:%02d:00', 5 + (hexdec(substr($suffix, 0, 2)) % 50));
$main = $booking('main', 2, $mainWall);
wp_set_current_user($teacherUser);
$outcome = $preparations->recordTeacherOutcome($main['confirmation'], $teacherId, 'delivered', 'Synthetic delivered intro');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT recorded_by FROM {$p}booking_request_intro_outcomes WHERE booking_request_id=%d", (int) $main['request'])) === $teacherUser, 'teacher outcome must record the authenticated WordPress actor');
$prep = $repository->preparationByBooking($main['request']);
dzn_pia_assert($prep !== null, 'preparation row');
dzn_pia_assert((string) $prep->state === 'prepared', 'delivered intro must prepare the continuation, got ' . (string) ($prep->state ?? 'missing'));
$prepUid = (string) $prep->uid;
dzn_pia_assert((int) $prep->intro_lesson_id > 0, 'intro lesson provenance');
dzn_pia_assert((string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}lessons WHERE id=%d", (int) $prep->intro_lesson_id)) === 'completed', 'intro lesson must be genuinely completed');
$bookedIntroCourse = (int) $wpdb->get_var($wpdb->prepare("SELECT selected_intro_course_id FROM {$p}booking_requests WHERE id=%d", (int) $main['request']));
$introLessonCourse = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}lessons WHERE id=%d", (int) $prep->intro_lesson_id));
dzn_pia_assert($bookedIntroCourse > 0 && $introLessonCourse === $bookedIntroCourse, 'the introductory Lesson must keep the booked introductory Course');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id)) === 1, 'one first-regular-slot authority');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}proposal_versions WHERE proposal_option_id=%d", (int) $prep->proposal_option_id)) === 1, 'one initial proposal version');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='post_intro_invitation'", (int) $main['delivery'])) === 1, 'durable continuation invitation');

$card = (new \Delnavazan\Platform\Portals\PostIntroStudentReadService())->forStudent((int) $main['student']);
dzn_pia_assert(count($card) === 1 && $card[0]['state'] === 'ready' && $card[0]['actionable'] === true, 'Student Portal must expose one ready payment action');

// Cross-student authorization is rejected before any commercial mutation (cross_student).
$otherStudentId = (int) $fixture['sources'][1]['student_id'];
$otherUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1", $otherStudentId));
dzn_pia_assert($otherUser > 0, 'second Student principal required');
wp_set_current_user($otherUser);
$crossCaught = null;
try { $continuations->continue($prepUid, true); } catch (Throwable $e) { $crossCaught = $e; }
dzn_pia_assert($crossCaught !== null && $crossCaught->getMessage() === 'Unauthorized', 'cross-student continuation must be rejected');

wp_set_current_user((int) $main['principal']);
$action = $continuePrepared($prepUid, (int) $main['principal']);
dzn_pia_assert(in_array($action['state'], array('checkout_pending', 'payment_received'), true), 'Student action must reach the canonical offer');
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((int) $prep->offer_id > 0 && (int) $prep->continuation_case_id > 0 && (int) $prep->pattern_id > 0, 'Student action must create the offer, continuation case and pattern');
// A termination between the continuation command and the preparation checkpoint must recover
// the durable reservation on the next Student action instead of failing forever.
$wpdb->update($p . 'post_intro_preparations', array('reservation_id' => null), array('id' => (int) $prep->id));
$resumedAction = $continuePrepared($prepUid, (int) $main['principal']);
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((int) $prep->reservation_id > 0, 'a replayed continuation must recover its durable reservation');
dzn_pia_assert(in_array($resumedAction['state'], array('checkout_pending', 'payment_received'), true), 'a replayed Student action must remain actionable');
$caseCourse = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}canonical_continuation_cases WHERE id=%d", (int) $prep->continuation_case_id));
$offerCourse = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}commercial_offers WHERE id=%d", (int) $prep->offer_id));
dzn_pia_assert($caseCourse === $standardCourse && $offerCourse === $standardCourse, 'continuation case and offer must carry the ongoing standard Course');
$obligations = $repository->obligationsForOffer((int) $prep->offer_id);
dzn_pia_assert(count($obligations) === 1, 'a full plan must have one obligation');
$obligation = $obligations[0];
$offer = $repository->offer((int) $prep->offer_id);
dzn_pia_assert($offer !== null && (string) $offer->state === 'issued', 'offer must be issued before settlement');

// The Student Portal must remain usable while the Enrolment has no Term/Teacher Assignment yet.
// This is the production failure shape: an accepted arrangement converted to an Enrolment before payment.
$pendingPortal = (new PortalAuthenticatedReadService())->student();
dzn_pia_assert(count((array) ($pendingPortal['enrolments'] ?? array())) === 1, 'Student Portal must expose the paid-pending Enrolment without a Term');
$pendingEnrolment = $pendingPortal['enrolments'][0];
dzn_pia_assert(($pendingEnrolment['term_reference'] ?? '') === '' && ($pendingEnrolment['assigned_teacher_display_reference'] ?? '') === '', 'the pending Enrolment must not invent a Term or Teacher Assignment');
dzn_pia_assert(($pendingEnrolment['fulfilment_state'] ?? '') === 'awaiting_payment', 'the pending Enrolment must not claim a verified payment');
dzn_pia_assert(($pendingEnrolment['provisioning_message_code'] ?? '') === 'enrolment_awaiting_payment_confirmation', 'the pending Portal message must be the truthful awaiting-payment state');

// A mismatched payment must fail closed and must not enqueue activation.
wp_set_current_user($actor);
$payments = new CommercialPaymentService();
$paymentAt = gmdate('Y-m-d H:i:s');
$rejected = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-reject-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => '1', 'currency' => 'AUD', 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $paymentAt, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-reject-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('rejected'));
dzn_pia_assert(($rejected['processing_state'] ?? '') === 'rejected', 'amount mismatch must be rejected');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d", (int) $prep->offer_id)) === 0, 'a mismatched payment must not create a purchase');

// Verified settlement enqueues exactly one durable job.
$settled = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-success-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => (string) $obligation->amount_minor, 'currency' => (string) $obligation->currency, 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $paymentAt, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-success-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('success'));
dzn_pia_assert(($settled['processing_state'] ?? '') === 'accepted', 'verified settlement must be accepted');
$purchaseId = (int) $wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM {$p}commercial_entitlements WHERE purchase_id IN (SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d) LIMIT 1", (int) $prep->offer_id));
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && (string) $job->state === 'queued', 'settlement must enqueue exactly one activation job');
// Simulate a genuinely delayed worker so stable evidence is observable rather than
// coincidentally equal to the current second.
$activationCheckpoint = $paymentAt;
sleep(2);
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((string) $prep->state === 'payment_received', 'verified payment must move the preparation to payment_received');

// Duplicate webhook: the unique purchase job and settlement replay must not create a second path.
$replay = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-success-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => (string) $obligation->amount_minor, 'currency' => (string) $obligation->currency, 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $paymentAt, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-success-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('success'));
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}post_intro_activation_jobs WHERE purchase_id=%d", $purchaseId)) === 1, 'duplicate webhook must not duplicate the activation job');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d", (int) $prep->offer_id)) === 1, 'duplicate webhook must not duplicate settlement');

// The narrow recovery path must inspect a verified-but-unprovisioned Enrolment and then provision it
// through the existing activation worker. Establish a real authority-owned Calendar connection with
// a local sealed credential; only the final HTTP request is intercepted below, so retry state remains
// the production pending-map -> dispatch -> acknowledgement sequence.
$calendarAdapters = new ContractProviderAdapters();
$calendarAuthority = new ProviderIntegrationService(null, null, $calendarAdapters, $calendarAdapters, $calendarAdapters);
$calendarAuthorization = $calendarAuthority->beginAuthorization(array(
    'provider_code' => 'google_calendar',
    'teacher_id' => $teacherId,
    'client_reference' => 'post-intro-runtime',
    'redirect_uri' => 'https://example.invalid/post-intro-calendar-retry',
    'scope_snapshot' => ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE,
) + dzn_pia_evidence('post-intro-calendar-consent-' . $suffix), dzn_pia_key('calendar-consent'));
$calendarConnection = $calendarAuthority->completeAuthorization(array(
    'state' => (string) $calendarAuthorization['state'],
    'code_verifier' => (string) $calendarAuthorization['code_verifier'],
    'code' => 'post-intro-calendar-code-' . $suffix,
    'redirect_uri' => 'https://example.invalid/post-intro-calendar-retry',
), dzn_pia_key('calendar-complete'));
$calendarConnectionId = (int) $calendarConnection['connection_id'];
$calendarCredential = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}integration_credentials WHERE connection_id=%d AND state='active' ORDER BY id DESC LIMIT 1", $calendarConnectionId));
dzn_pia_assert($calendarCredential !== null, 'Calendar retry fixture requires an active credential');
$calendarSealed = (new IntegrationSecretService())->seal($calendarConnectionId, 'google_calendar', wp_json_encode(array('access_token' => 'local-calendar-retry-token')));
dzn_pia_assert($wpdb->update($p . 'integration_credentials', $calendarSealed, array('id' => (int) $calendarCredential->id, 'state' => 'active')) === 1, 'Calendar retry fixture must replace only its local sealed credential');
$calendarTransportCalls = 0;
$calendarAcknowledgedEventIds = array();
$calendarHttp = static function ($preempt, array $args, string $url) use (&$calendarTransportCalls, &$calendarAcknowledgedEventIds) {
    if (!str_starts_with($url, 'https://www.googleapis.com/calendar/v3/calendars/primary/events')) return $preempt;
    $calendarTransportCalls++;
    if ($calendarTransportCalls === 1) return new WP_Error('dzn_calendar_retry_failure', 'Synthetic Calendar transport failure');
    $request = json_decode((string) ($args['body'] ?? ''), true);
    $eventId = is_array($request) ? trim((string) ($request['id'] ?? '')) : '';
    if ($eventId === '') throw new RuntimeException('Calendar retry transport requires the deterministic event identifier');
    $calendarAcknowledgedEventIds[] = $eventId;
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array('id' => $eventId, 'hangoutLink' => 'https://meet.google.com/abc-defg-hij')),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $calendarHttp, 10, 3);
$recovery = new PostIntroRecoveryService();
$paidInspection = $recovery->inspect((int) $prep->enrolment_id);
dzn_pia_assert(!empty($paidInspection['payment_verified']) && ($paidInspection['fulfilment_state'] ?? '') === 'payment_received', 'verified settlement must be visible as payment_received');
dzn_pia_assert((int) $paidInspection['term_id'] === 0 && (int) $paidInspection['assignment_id'] === 0, 'dry-run must not invent a Term or Assignment');
dzn_pia_assert(in_array('canonical_term', (array) ($paidInspection['planned'] ?? array()), true) && in_array('canonical_lessons:12', (array) ($paidInspection['planned'] ?? array()), true), 'dry-run must plan the Term and twelve Lessons');
$recovered = $recovery->recover((int) $prep->enrolment_id);
dzn_pia_assert(in_array(($recovered['state'] ?? ''), array('completed', 'already_completed'), true), 'canonical provisioning must remain complete after a provider-only Calendar failure while the activation job stays failed');
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((string) $prep->state === 'activated', 'core activation must complete before provider projection');
$enrolment = $repository->enrolment((int) $prep->enrolment_id);
dzn_pia_assert($enrolment && (string) $enrolment->lifecycle_state === 'current', 'Enrolment must be current');
dzn_pia_assert((int) $enrolment->course_id === $standardCourse, 'the active Enrolment must use the ongoing standard Course');
$termId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d LIMIT 1", $purchaseId));
$term = $repository->term($termId);
dzn_pia_assert($term && (string) $term->lifecycle_state === 'current', 'Term must be current');
$assignment = $repository->assignmentForEnrolment((int) $enrolment->id);
dzn_pia_assert($assignment && (string) $assignment->state === 'assigned', 'Teacher Assignment must be assigned');
$lessonCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $termId));
dzn_pia_assert($lessonCount === 12, 'the purchased entitlement must create twelve future Lessons, got ' . $lessonCount);
$scheduledCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND v.superseded_at IS NULL", $termId));
dzn_pia_assert($scheduledCount === 12, 'every created Lesson must have a committed schedule');

// Student and Teacher portals read the same canonical Lesson schedule after provisioning.
wp_set_current_user((int) $main['principal']);
$activePortal = (new PortalAuthenticatedReadService())->student();
$activeEnrolment = $activePortal['enrolments'][0] ?? array();
dzn_pia_assert(($activeEnrolment['fulfilment_state'] ?? '') === 'completed', 'a provider-only Calendar failure must not erase completed canonical fulfilment from the Student Portal');
dzn_pia_assert(($activeEnrolment['term_reference'] ?? '') !== '' && ($activeEnrolment['assigned_teacher_display_reference'] ?? '') !== '', 'the provisioned Enrolment must expose its Term and Teacher Assignment');
$studentCourseName = trim((string) ($activeEnrolment['course_name'] ?? ''));
dzn_pia_assert($studentCourseName !== '' && $studentCourseName !== (string) ($activeEnrolment['course_reference'] ?? ''), 'the Student Enrolment must expose a human Course name, not only its reference');
$futureStudentLessons = array_values(array_filter((array) ($activePortal['lessons'] ?? array()), static fn(array $row): bool => strtotime((string) ($row['starts_at_utc'] ?? '') . ' UTC') >= time()));
dzn_pia_assert(count($futureStudentLessons) === 12, 'the Student Portal must show twelve future canonical Lessons');
dzn_pia_assert(trim((string) ($futureStudentLessons[0]['course_name'] ?? '')) === $studentCourseName, 'the Student Lesson must carry the same human Course name');
$studentModel = dzn_theme_platform_student_model('home');
dzn_pia_assert(trim((string) ($studentModel['upcoming_lesson']['course'] ?? '')) === $studentCourseName, 'the Student Portal must display the Course name rather than the raw reference');
dzn_pia_assert(empty($studentModel['provisioning']), 'a completed canonical Enrolment must not show a provisioning error notice');
$studentLessonUids = array_map(static fn(array $row): string => (string) ($row['lesson_uid'] ?? ''), $futureStudentLessons);
sort($studentLessonUids);
wp_set_current_user($teacherUser);
$teacherPortal = (new PortalAuthenticatedReadService())->teacher();
$futureTeacherLessons = array_values(array_filter((array) ($teacherPortal['lessons'] ?? array()), static fn(array $row): bool => strtotime((string) ($row['starts_at_utc'] ?? '') . ' UTC') >= time()));
dzn_pia_assert(count($futureTeacherLessons) === 12, 'the Teacher Portal must show the same twelve future canonical Lessons');
$teacherLessonUids = array_map(static fn(array $row): string => (string) ($row['lesson_uid'] ?? ''), $futureTeacherLessons);
sort($teacherLessonUids);
dzn_pia_assert($studentLessonUids === $teacherLessonUids, 'Student and Teacher portals must read the same canonical Lesson schedule records');

// The production teacher portal is server-rendered through the theme view model. Exercise that
// exact presentation boundary with the funded Term and twelve canonical Lessons.
wp_set_current_user($teacherUser);
$truthCounts = static function () use ($wpdb, $p, $prep, $enrolment, $termId, $purchaseId): array {
    return array(
        'purchases' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE id=%d", $purchaseId)),
        'settlements' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d", (int) $prep->offer_id)),
        'terms' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $enrolment->id)),
        'assignments' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $enrolment->id)),
        'lessons' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $termId)),
        'schedules' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND v.superseded_at IS NULL", $termId)),
    );
};
$truthBefore = $truthCounts();
$teacherModel = dzn_theme_platform_teacher_model('home');
dzn_pia_assert(($teacherModel['available'] ?? false) === true, 'the teacher theme view model must be available');
dzn_pia_assert(dzn_theme_teacher_portal_validate_model($teacherModel, 'home'), 'the teacher theme view model must satisfy the presentation contract');
$themeClasses = array_values((array) ($teacherModel['classes'] ?? array()));
dzn_pia_assert(count($themeClasses) === 12, 'the teacher theme view model must render twelve future Lessons, got ' . count($themeClasses));
$themeLessonUids = array();
foreach ($themeClasses as $themeClass) {
    dzn_pia_assert(dzn_theme_teacher_portal_class_valid($themeClass), 'every canonical teacher class must satisfy the display contract');
    dzn_pia_assert(trim((string) ($themeClass['ref'] ?? '')) !== '', 'a canonical class must never expose an empty display reference');
    dzn_pia_assert(trim((string) ($themeClass['lesson_uid'] ?? '')) !== '', 'a canonical class must retain its canonical Lesson UID');
    dzn_pia_assert(trim((string) ($themeClass['course'] ?? '')) === $studentCourseName, 'the teacher class must display the human Course name');
    dzn_pia_assert(($themeClass['timezone'] ?? '') === ($teacherModel['teacher_timezone'] ?? ''), 'the class timezone must be the teacher timezone');
    dzn_pia_assert(($themeClass['time'] ?? '') === dzn_theme_platform_zoned_time((string) ($themeClass['starts_at_utc'] ?? ''), 'H:i', (string) ($teacherModel['teacher_timezone'] ?? '')), 'the class time must match the canonical UTC start in the teacher timezone');
    $themeLessonUids[] = (string) $themeClass['lesson_uid'];
}
sort($themeLessonUids);
dzn_pia_assert($themeLessonUids === $studentLessonUids, 'the teacher theme view model and Student Portal must expose the same canonical Lesson UIDs');
dzn_pia_assert(dzn_theme_platform_zoned_time('2026-11-01 07:15:00', 'H:i', 'Asia/Tehran') === '10:45', 'the presentation boundary must preserve the authoritative Asia/Tehran conversion');
$teacherViewModel = dzn_theme_teacher_portal_view_model('home');
dzn_pia_assert(($teacherViewModel['available'] ?? false) === true && count((array) ($teacherViewModel['classes'] ?? array())) === 12, 'the teacher portal view model must render twelve healthy classes');
$optionalNoteClass = $themeClasses[0];
$optionalNoteClass['previous_private_note'] = '';
dzn_pia_assert(dzn_theme_teacher_portal_class_valid($optionalNoteClass), 'a legitimately absent private note must not invalidate a class');
$resilientModel = $teacherModel;
$resilientModel['classes'][0]['ref'] = '';
dzn_pia_assert(dzn_theme_teacher_portal_validate_model($resilientModel, 'home'), 'one malformed optional class must not disable the whole teacher home');
$truthAfter = $truthCounts();
dzn_pia_assert($truthBefore === $truthAfter, 'rendering the teacher view model must not mutate purchase, settlement, Term, Assignment, Lesson or schedule records');

$otherTeacherUser = 0;
foreach ((array) ($fixture['teacherUsers'] ?? array()) as $candidateTeacherUser) {
    if ((int) $candidateTeacherUser > 0 && (int) $candidateTeacherUser !== $teacherUser) { $otherTeacherUser = (int) $candidateTeacherUser; break; }
}
dzn_pia_assert($otherTeacherUser > 0, 'a second teacher principal is required for the ownership regression');
$canonicalLessonId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard' ORDER BY id LIMIT 1", $termId));
dzn_pia_assert($canonicalLessonId > 0, 'the ownership regression requires a canonical Lesson');
wp_set_current_user($otherTeacherUser);
$crossTeacherDenied = false;
try {
    $otherTeacherPrincipal = (new \Delnavazan\Platform\Portals\PortalPrincipalResolver())->resolve('teacher');
    \Delnavazan\Platform\Portals\PortalAccessPolicy::assertObject('teacher_portal', 'lesson', $canonicalLessonId, $otherTeacherPrincipal);
} catch (\Throwable $error) {
    $crossTeacherDenied = in_array($error->getMessage(), array('portal_object_not_owned', 'portal_object_not_portal_visible', 'portal_principal_unresolved', 'teacher_onboarding_required'), true);
}
dzn_pia_assert($crossTeacherDenied, 'another teacher must not read Teacher 7-like canonical Lessons');
wp_set_current_user($actor);

$evidenceOccurredAt = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT e.occurred_at FROM {$p}canonical_lesson_lifecycle_events e INNER JOIN {$p}lessons l ON l.id=e.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND e.to_state='authorised' ORDER BY e.id LIMIT 1",
    $termId
));
dzn_pia_assert($evidenceOccurredAt === $activationCheckpoint, 'activation must reuse the durable settlement checkpoint as evidence time');
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && (string) $job->state === 'retryable', 'a transient Calendar transport failure must be automatically retryable');
dzn_pia_assert((string) $job->last_error_code === 'google_calendar_transport_failed', 'the retryable failure must preserve the Calendar transport code, got ' . (string) $job->last_error_code);
dzn_pia_assert($job->next_attempt_at !== null && strtotime((string) $job->next_attempt_at . ' UTC') > time(), 'a transient Calendar transport failure must schedule a bounded future attempt');
dzn_pia_assert(strtotime((string) $job->next_attempt_at . ' UTC') <= time() + 1800, 'the Calendar retry backoff must stay bounded');
dzn_pia_assert($calendarTransportCalls === 1, 'the first activation must issue exactly one Calendar transport attempt before failure');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='pending'")) === 1, 'a failed provider write must leave exactly one pending Calendar mapping');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='verified'")) === 0, 'provider projection must not be marked verified without an acknowledgement');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_projection_secrets WHERE mapping_kind='calendar_event' AND state='active'")) === 0, 'a pending translation must not persist a provider reference');
$pendingCalendarDigest = (string) $wpdb->get_var($wpdb->prepare("SELECT event_digest FROM {$p}provider_calendar_event_mappings WHERE projection_state='pending' LIMIT 1"));
dzn_pia_assert((bool) preg_match('/^[a-f0-9]{64}$/D', $pendingCalendarDigest), 'a pending mapping may retain only its translation digest');
$calendarTruthBeforeRetry = $truthCounts();

// A terminal provider-projection failure must not regress Student-visible canonical fulfilment.
$jobStateBeforeFailureTest = (string) $job->state;
$jobErrorBeforeFailureTest = $job->last_error_code === null ? null : (string) $job->last_error_code;
dzn_pia_assert($wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'failed', 'next_attempt_at' => null, 'last_error_code' => 'provider_credential_refresh_required', 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $job->id)) !== false, 'provider failure fixture update');
$failedProjection = (new PostIntroFulfilmentReadService())->forEnrolment((int) $enrolment->id);
dzn_pia_assert(($failedProjection['fulfilment_state'] ?? '') === 'completed', 'a terminal provider projection failure must not erase completed canonical fulfilment');
dzn_pia_assert(($failedProjection['message_code'] ?? null) === null, 'a completed canonical Enrolment must not emit a provisioning error after provider failure');
dzn_pia_assert(in_array('activation_job_failed', (array) ($failedProjection['blockers'] ?? array()), true), 'the provider failure must remain visible as an operator blocker');
wp_set_current_user((int) $main['principal']);
$failedPortal = (new PortalAuthenticatedReadService())->student();
$failedEnrolment = $failedPortal['enrolments'][0] ?? array();
dzn_pia_assert(($failedEnrolment['fulfilment_state'] ?? '') === 'completed' && ($failedEnrolment['provisioning_message_code'] ?? null) === null, 'the Student Portal must keep completed fulfilment after a provider projection failure');
$failedThemeModel = dzn_theme_platform_student_model('home');
dzn_pia_assert(empty($failedThemeModel['provisioning']), 'a provider projection failure must not show the Student a schedule-review error');
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => $jobStateBeforeFailureTest, 'last_error_code' => $jobErrorBeforeFailureTest, 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $job->id));
wp_set_current_user($actor);

// The authorised operator retry route replays the durable pending mapping, then the real transport
// acknowledgement promotes it; no test row is inserted or promoted directly.
$attemptsBefore = (int) $job->attempt_count;
dzn_pia_assert($repository->requeueJob((int) $job->id, gmdate('Y-m-d H:i:s')), 'the failed Calendar transport job must be safely requeueable');
PostIntroActivationWorker::run();
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && (string) $job->state === 'completed', 'a provider acknowledgement must complete the requeued activation job');
dzn_pia_assert($calendarTransportCalls === 13, 'one failed request followed by twelve acknowledged Calendar writes is required');
dzn_pia_assert(count($calendarAcknowledgedEventIds) === 12 && count(array_unique($calendarAcknowledgedEventIds)) === 12, 'retries must use one deterministic Calendar event identifier per Lesson');
dzn_pia_assert($calendarTruthBeforeRetry === $truthCounts(), 'Calendar retries must not mutate Term, Assignment, Lesson or schedule truth');
wp_set_current_user((int) $main['principal']);
$acknowledgedPortal = (new PortalAuthenticatedReadService())->student();
dzn_pia_assert((($acknowledgedPortal['enrolments'][0] ?? array())['fulfilment_state'] ?? '') === 'completed', 'the Student Portal must keep completed canonical fulfilment after Calendar acknowledgement');
wp_set_current_user($actor);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='verified'")) === 12, 'all twelve Calendar projections must be verified after acknowledgement');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='pending'")) === 0, 'no Calendar mapping may remain pending after acknowledged retry');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_projection_secrets WHERE mapping_kind='calendar_event' AND state='active'")) === 12, 'provider references must be persisted only after acknowledgement');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='verified' AND event_digest NOT REGEXP '^[a-f0-9]{64}$'")) === 0, 'verified mappings must retain provider identities only as digests');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d", $termId)) === $lessonCount, 'retry must not duplicate Lessons');
$retriedEvidenceAt = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT e.occurred_at FROM {$p}canonical_lesson_lifecycle_events e INNER JOIN {$p}lessons l ON l.id=e.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND e.to_state='authorised' ORDER BY e.id LIMIT 1",
    $termId
));
dzn_pia_assert($retriedEvidenceAt === $activationCheckpoint, 'an expected retry must reconstruct the identical canonical evidence time');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'retry must not duplicate Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d", $purchaseId)) === 1, 'retry must not duplicate Term funding');
$firstCalendarMapping = $wpdb->get_row($wpdb->prepare("SELECT m.id FROM {$p}provider_calendar_event_mappings m INNER JOIN {$p}lessons l ON l.id=m.lesson_id WHERE l.term_id=%d ORDER BY m.id ASC LIMIT 1", $termId));
dzn_pia_assert($firstCalendarMapping !== null, 'the acknowledged callback replay requires the first Calendar mapping');
$callbackReplay = $calendarAuthority->acknowledgeCalendarProjection((int) $firstCalendarMapping->id, array(
    'provider_object_reference' => $calendarAcknowledgedEventIds[0],
    'join_uri_reference' => 'https://meet.google.com/abc-defg-hij',
) + dzn_pia_evidence('post-intro-calendar-callback-replay-' . $suffix), 'post-intro-calendar-dispatch-' . $prepUid . '-1');
dzn_pia_assert(!empty($callbackReplay['idempotent']), 'the repeated acknowledged callback must converge on its existing Calendar projection');
$calendarCountsBeforeReplay = array('mappings' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings")), 'secrets' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_projection_secrets WHERE mapping_kind='calendar_event'")));
$completedReplay = PostIntroActivationWorker::runJob((int) $job->id);
dzn_pia_assert(($completedReplay['state'] ?? '') === 'completed' && $calendarTransportCalls === 13, 'a completed job replay must not create another provider event');
dzn_pia_assert($calendarCountsBeforeReplay === array('mappings' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings")), 'secrets' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_projection_secrets WHERE mapping_kind='calendar_event'"))), 'repeated callbacks and completed retries must not duplicate Calendar references');
dzn_pia_assert($calendarTruthBeforeRetry === $truthCounts(), 'repeated callbacks and completed retries must not duplicate canonical records');
remove_filter('pre_http_request', $calendarHttp, 10);

// Repeated recovery is a no-op once canonical provisioning is complete.
wp_set_current_user($actor);
$repeatRecovery = (new PostIntroRecoveryService())->recover((int) $enrolment->id);
dzn_pia_assert(($repeatRecovery['state'] ?? '') === 'already_completed', 'repeated recovery must be idempotent');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d", $termId)) === $lessonCount, 'repeated recovery must not duplicate Lessons');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'repeated recovery must not duplicate the Term');

// A partial activation (for example a worker failure after five Lessons) must recover the missing
// Lesson/schedule commands without duplicating the five already committed records. Restore the
// pre-existing fixture shape of an unavailable provider before deleting derived records; this keeps
// the partial-canonical recovery independent of the Calendar acknowledgement proof above.
$wpdb->update($p . 'integration_connections', array('connection_state' => 'disconnected', 'active_slot' => null), array('id' => $calendarConnectionId, 'connection_state' => 'connected'));
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'retryable', 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'last_error_code' => 'provider_connection_unusable', 'last_error_detail' => 'provider_connection_unusable'), array('id' => (int) $job->id, 'state' => 'completed'));
$standardLessonIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard' ORDER BY id", $termId)));
dzn_pia_assert(count($standardLessonIds) === 12, 'partial recovery fixture requires twelve Lessons');
$removeLessonIds = array_slice($standardLessonIds, 5);
$removeIn = implode(',', array_map('intval', $removeLessonIds));
if ($removeIn !== '') {
    dzn_pia_assert($wpdb->query($wpdb->prepare("UPDATE {$p}commercial_capacity_claim_intervals SET state='protected',satisfied_lesson_id=NULL,satisfied_schedule_version_id=NULL,satisfied_at=NULL,updated_at=%s,updated_by=%d WHERE satisfied_lesson_id IN ({$removeIn})", $now, $actor)) !== false, 'partial recovery fixture capacity reset failed');
    $calendarMappingIds = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$p}provider_calendar_event_mappings WHERE lesson_id IN ({$removeIn})"));
    if ($calendarMappingIds) $wpdb->query("DELETE FROM {$p}provider_projection_secrets WHERE mapping_kind='calendar_event' AND mapping_id IN (" . implode(',', $calendarMappingIds) . ')');
    $meetingMappingIds = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$p}provider_meeting_mappings WHERE lesson_id IN ({$removeIn})"));
    if ($meetingMappingIds) $wpdb->query("DELETE FROM {$p}provider_projection_secrets WHERE mapping_kind='meeting_conference' AND mapping_id IN (" . implode(',', $meetingMappingIds) . ')');
    foreach (array(
        "DELETE FROM {$p}provider_integration_commands WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}provider_meeting_mappings WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}provider_calendar_event_mappings WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}canonical_lesson_schedule_events WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}canonical_lesson_schedule_commands WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}canonical_lesson_commands WHERE result_lesson_id IN ({$removeIn})",
        "DELETE FROM {$p}lessons WHERE id IN ({$removeIn})",
    ) as $deleteSql) {
        dzn_pia_assert($wpdb->query($deleteSql) !== false, 'partial recovery fixture deletion failed: ' . $wpdb->last_error);
    }
}
$partialInspection = $recovery->inspect((int) $enrolment->id);
dzn_pia_assert(($partialInspection['fulfilment_state'] ?? '') === 'partial', 'a Term with partial Lessons must be reported as partial');
dzn_pia_assert((int) $partialInspection['lesson_count'] === 5 && (int) $partialInspection['expected_lesson_count'] === 12, 'partial inspection must report five of twelve Lessons');
$partialRecovery = $recovery->recover((int) $enrolment->id);
dzn_pia_assert(($partialRecovery['state'] ?? '') === 'completed', 'partial recovery must complete the missing Lessons');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $termId)) === 12, 'partial recovery must restore exactly twelve Lessons');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT id) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $termId)) === 12, 'partial recovery must not duplicate Lesson identities');
$recoveredScheduleCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND v.superseded_at IS NULL", $termId));
$recoveredScheduledLessonCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT v.lesson_id) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND v.superseded_at IS NULL", $termId));
dzn_pia_assert($recoveredScheduleCount === 12 && $recoveredScheduledLessonCount === 12, 'partial recovery must restore one active schedule for each of the twelve canonical Lessons without duplicates');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'partial recovery must not duplicate the Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'partial recovery must not duplicate the Term');
// Once the canonical Lesson exists the operator projection must hand off, not duplicate the booking.
wp_set_current_user($actor);
foreach ((new \Delnavazan\Platform\Core\Application\ConfirmedIntroBookingReadService())->upcoming() as $operatorRow) {
    dzn_pia_assert((string) $operatorRow->confirmation_uid !== (string) $main['confirmation'], 'a canonical Lesson must replace its confirmed-intro operator row');
}
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE booking_request_id=%d", (int) $main['request'])) === 1, 'the canonical intro Lesson must be the single schedule record');


// B. Availability is a real precondition: a delivered intro with no published slot is an admin exception.
$noAvailWall = sprintf('07:%02d:00', 5 + (hexdec(substr($suffix, 4, 2)) % 50));
$noAvailBooking = $booking('no-availability', 2, $noAvailWall);
// Admin Classes/Academy must see a future confirmed intro before any Lesson exists. Move this
// synthetic confirmation forward for the read-only assertion, then restore its delivery facts.
$adminOriginal = $wpdb->get_row($wpdb->prepare("SELECT starts_at_utc,ends_at_utc,local_wall_date,local_wall_time,provider_event_digest FROM {$p}booking_request_confirmations WHERE uid=%s", $noAvailBooking['confirmation']));
$adminFutureStart = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC +24 hours'));
$adminFutureEnd = gmdate('Y-m-d H:i:s', strtotime($adminFutureStart . ' UTC +30 minutes'));
dzn_pia_assert($adminOriginal !== null && $wpdb->update($p . 'booking_request_confirmations', array(
    'starts_at_utc' => $adminFutureStart,
    'ends_at_utc' => $adminFutureEnd,
    'local_wall_date' => substr($adminFutureStart, 0, 10),
    'local_wall_time' => substr($adminFutureStart, 11, 8),
    'provider_event_digest' => hash('sha256', 'operator-visible-' . $noAvailBooking['confirmation']),
), array('uid' => $noAvailBooking['confirmation'])) !== false, 'future admin-visible confirmation fixture');
wp_set_current_user($actor);
$adminIntros = (new \Delnavazan\Platform\Core\Application\ConfirmedIntroBookingReadService())->upcoming();
$adminIntro = null;
foreach ($adminIntros as $candidate) if ((string) $candidate->confirmation_uid === (string) $noAvailBooking['confirmation']) { $adminIntro = $candidate; break; }
dzn_pia_assert($adminIntro !== null, 'confirmed introductory booking must be visible to the operator schedule');
dzn_pia_assert((string) $adminIntro->record_model === 'confirmed_intro_booking_v1' && (string) $adminIntro->class_status === 'Confirmed intro', 'operator intro row must be explicit and not a fabricated Lesson');
dzn_pia_assert((int) $adminIntro->booking_request_id === (int) $noAvailBooking['request'], 'operator intro row must link to its booking request');
dzn_pia_assert((string) $adminIntro->schedule_timezone === 'UTC', 'operator intro row must preserve the confirmation schedule timezone');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE booking_request_id=%d", (int) $noAvailBooking['request'])) === 0, 'admin visibility must not fabricate an introductory Lesson');
$wpdb->update($p . 'booking_request_confirmations', array(
    'starts_at_utc' => $adminOriginal->starts_at_utc,
    'ends_at_utc' => $adminOriginal->ends_at_utc,
    'local_wall_date' => $adminOriginal->local_wall_date,
    'local_wall_time' => $adminOriginal->local_wall_time,
    'provider_event_digest' => $adminOriginal->provider_event_digest,
), array('uid' => $noAvailBooking['confirmation']));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_availability_rules WHERE teacher_id=%d", $teacherId));
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($noAvailBooking['confirmation'], $teacherId, 'delivered', 'Synthetic no availability');
$noAvailPrep = $repository->preparationByBooking($noAvailBooking['request']);
dzn_pia_assert($noAvailPrep !== null && (string) $noAvailPrep->state === 'exception', 'missing availability must become an explicit exception');
dzn_pia_assert((string) $noAvailPrep->exception_code === 'teacher_unavailable', 'missing availability must be teacher_unavailable, got ' . (string) $noAvailPrep->exception_code);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='post_intro_invitation'", (int) $noAvailBooking['delivery'])) === 0, 'missing availability must not invite payment');
wp_set_current_user($teacherUser);
$matchCaught = null;
try {
    $match = (new \Delnavazan\Platform\Core\Application\CanonicalContinuationService())->markMatchNeedsAdmin((int) $noAvailPrep->intro_lesson_id, array('evidence_channel' => 'authenticated_platform', 'evidence_reference' => 'post-intro-match:' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_pia_key('match'));
} catch (Throwable $e) { $matchCaught = $e; }
dzn_pia_assert($matchCaught === null && isset($match['case_id']) && (int) $match['case_id'] > 0, 'Teacher match-exception must open a continuation case on the no-case path: ' . ($matchCaught?->getMessage() ?? 'no case'));
wp_set_current_user($actor);
foreach (array(1, 2, 3, 4, 5, 6, 7) as $weekday) {
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => $teacherTimezone, 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_post_intro'));
}

// C. Historical outcomes are outside the automation window.
$cutoffBooking = $booking('cutoff', 2, sprintf('08:%02d:00', 5 + (hexdec(substr($suffix, 6, 2)) % 50)));
$wpdb->update($p . 'booking_request_confirmations', array('starts_at_utc' => '2026-10-20 03:00:00', 'ends_at_utc' => '2026-10-20 03:30:00', 'local_wall_date' => '2026-10-20', 'local_wall_time' => '03:00:00'), array('uid' => $cutoffBooking['confirmation']));
wp_set_current_user($teacherUser);
$cutoffCaught = null;
try { $preparations->recordTeacherOutcome($cutoffBooking['confirmation'], $teacherId, 'delivered', 'Synthetic historical'); } catch (Throwable $e) { $cutoffCaught = $e; }
dzn_pia_assert($cutoffCaught !== null && $cutoffCaught->getMessage() === 'intro_outcome_outside_window', 'historical intro must be outside the automation window');
dzn_pia_assert($repository->preparationByBooking($cutoffBooking['request']) === null, 'historical intro must not create a preparation');

// D. Privacy erasure clears the not-null attestation without failing.
wp_set_current_user($actor);
$eraseSubmission = (new BookingRequestSubmissionService())->submitPublic(array(
    'requested_instrument_id' => $instrumentId,
    'selected_intro_course_id' => $introCourse,
    'full_name' => 'Synthetic Erasure',
    'email' => 'erase-' . $suffix . '@phase-post-intro.invalid',
    'mobile' => '+61400000000',
    'country' => 'AU',
    'city' => 'Brisbane',
    'timezone' => 'UTC',
    'communication_language' => 'en',
    'whatsapp_same_as_mobile' => true,
    'whatsapp_number' => '',
    'privacy_notice_accepted' => true,
    'privacy_notice_version' => '2026-09-05',
    'adult_self_attested' => true,
    'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+40 days')), 'local_start_time' => '04:17', 'timezone' => 'UTC')),
), dzn_pia_key('erase-request'));
$eraseRequestId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", (string) $eraseSubmission['request_reference']));
dzn_pia_assert($eraseRequestId > 0, 'erase request persistence');
(new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestIntakeRepository())->eraseRequestPii($eraseRequestId, $actor, 'synthetic_privacy', hash('sha256', 'privacy-' . $suffix), $now);
$eraseAttestation = (int) $wpdb->get_var($wpdb->prepare("SELECT adult_self_attested FROM {$p}booking_request_contact_snapshots WHERE booking_request_id=%d AND snapshot_sequence=1", $eraseRequestId));
dzn_pia_assert($eraseAttestation === 0, 'privacy erasure must clear the attestation to zero');

// E. Pattern reuse is Teacher-scoped.
$mainPattern = $repository->activePatternFor((int) $main['student'], $standardCourse, $teacherId);
dzn_pia_assert($mainPattern !== null && (int) $mainPattern->id === (int) $prep->pattern_id, 'same-Teacher pattern must be found');
$exactPattern = $repository->patternForSlotAuthority((int) $prep->slot_authority_id);
dzn_pia_assert($exactPattern !== null && (int) $exactPattern->id === (int) $prep->pattern_id, 'pattern lookup must be exact for the slot authority');
$otherTeacher = (int) $fixture['teachers'][1];
$wpdb->update($p . 'commercial_recurring_patterns', array('teacher_id' => $otherTeacher), array('id' => (int) $mainPattern->id));
dzn_pia_assert($repository->activePatternFor((int) $main['student'], $standardCourse, $teacherId) === null, 'a different-Teacher pattern must not be reused');
dzn_pia_assert($repository->activePatternForAnyTeacher((int) $main['student'], $standardCourse) !== null, 'the conflicting pattern must remain visible for the exception');
$wpdb->update($p . 'commercial_recurring_patterns', array('teacher_id' => $teacherId), array('id' => (int) $mainPattern->id));

// F. No-show and cancellation never invite continuation.
foreach (array('student_no_show', 'teacher_cancelled') as $index => $badOutcome) {
    $badBooking = $booking('bad-' . $badOutcome, 3 + $index, '03:2' . $index . ':00');
    wp_set_current_user($teacherUser);
    $result = $preparations->recordTeacherOutcome($badBooking['confirmation'], $teacherId, $badOutcome, 'Synthetic non-delivery');
    $bad = $repository->preparationByBooking($badBooking['request']);
    dzn_pia_assert($bad !== null && (string) $bad->state === 'exception', $badOutcome . ' must become an explicit exception');
    dzn_pia_assert((string) $bad->exception_code === ($badOutcome === 'student_no_show' ? 'intro_student_no_show' : 'intro_cancelled'), $badOutcome . ' exception code');
    dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='post_intro_invitation'", (int) $badBooking['delivery'])) === 0, $badOutcome . ' must not enqueue a continuation invitation');
    dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE booking_request_id=%d", (int) $badBooking['request'])) === 0, $badOutcome . ' must not create an intro Lesson');
}

// C. Expired reservation is terminal and requires admin intervention.
$expiredBooking = $booking('expired', 4, sprintf('06:%02d:00', 5 + (hexdec(substr($suffix, 2, 2)) % 50)));
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($expiredBooking['confirmation'], $teacherId, 'delivered', 'Synthetic expiry');
$expiredPrep = $repository->preparationByBooking($expiredBooking['request']);
wp_set_current_user((int) $expiredBooking['principal']);
$continuePrepared((string) $expiredPrep->uid, (int) $expiredBooking['principal']);
$expiredPrep = $repository->preparationByBooking($expiredBooking['request']);
$wpdb->update($p . 'canonical_continuation_reservations', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => (int) $expiredPrep->reservation_id));
$expiredCaught = null;
try { $continuePrepared((string) $expiredPrep->uid, (int) $expiredBooking['principal']); } catch (Throwable $e) { $expiredCaught = $e; }
dzn_pia_assert($expiredCaught !== null && $expiredCaught->getMessage() === 'post_intro_invitation_expired', 'an expired hold must be rejected');
$expiredPrep = $repository->preparationByBooking($expiredBooking['request']);
dzn_pia_assert((string) $expiredPrep->state === 'expired', 'an expired hold must become an explicit terminal state');

// D. The durable worker lease is single-owner.
$leaseOne = bin2hex(random_bytes(16));
$leaseTwo = bin2hex(random_bytes(16));
$jobId = (int) $job->id;
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'lease_token_digest' => null, 'lease_expires_at' => null), array('id' => $jobId));
dzn_pia_assert($repository->claimJob($jobId, hash('sha256', $leaseOne), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 60)) === true, 'first worker must claim the job');
dzn_pia_assert($repository->claimJob($jobId, hash('sha256', $leaseTwo), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 60)) === false, 'second concurrent worker must not claim the leased job');
$leaseThree = bin2hex(random_bytes(16));
$wpdb->update($p . 'post_intro_activation_jobs', array('lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => $jobId));
dzn_pia_assert($repository->claimJob($jobId, hash('sha256', $leaseThree), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 60)) === true, 'an expired lease must be reclaimable');
$staleRelease = null;
try { $repository->releaseJob($jobId, 'retryable', array('next_attempt_at' => gmdate('Y-m-d H:i:s')), hash('sha256', $leaseOne)); } catch (Throwable $e) { $staleRelease = $e; }
dzn_pia_assert($staleRelease !== null && $staleRelease->getMessage() === 'post_intro_job_lease_lost', 'a stale worker must not overwrite a reclaimed job');
$repository->releaseJob($jobId, 'queued', array('next_attempt_at' => gmdate('Y-m-d H:i:s')), hash('sha256', $leaseThree));

// G. A Teacher retry after a committed outcome with no preparation must resume the workflow.
$replayedPrepId = (int) $prep->id;
$wpdb->delete($p . 'post_intro_preparations', array('id' => $replayedPrepId));
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($main['confirmation'], $teacherId, 'delivered', 'Synthetic resumed outcome');
$recoveredPrep = $repository->preparationByBooking((int) $main['request']);
dzn_pia_assert($recoveredPrep !== null && (int) $recoveredPrep->id !== $replayedPrepId, 'replaying an existing outcome must recreate the missing preparation');
dzn_pia_assert((string) $recoveredPrep->state === 'prepared', 'a resumed preparation must reach the prepared state');

// H. A Term already created by an authorised operator action is identified and funded, not duplicated.
$existingTermBooking = $booking('existing-term', 2, '09:37:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($existingTermBooking['confirmation'], $teacherId, 'delivered', 'Synthetic existing-term delivery');
$existingTermPrep = $repository->preparationByBooking((int) $existingTermBooking['request']);
dzn_pia_assert($existingTermPrep !== null, 'existing-term preparation');
wp_set_current_user((int) $existingTermBooking['principal']);
$continuePrepared((string) $existingTermPrep->uid, (int) $existingTermBooking['principal']);
$existingTermPrep = $repository->preparationByBooking((int) $existingTermBooking['request']);
$existingEnrolmentId = (int) $existingTermPrep->enrolment_id;
dzn_pia_assert($existingEnrolmentId > 0, 'existing-term enrolment');
wp_set_current_user($actor);
$operatorTerm = (new CanonicalTermAuthorityService())->create(
    $existingEnrolmentId,
    null,
    null,
    array('evidence_channel' => 'authenticated_platform', 'evidence_reference' => 'operator-existing-term:' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s')),
    'operator-existing-term-' . $suffix
);
$operatorTermId = (int) $operatorTerm['term_id'];
$existingOffer = $repository->offer((int) $existingTermPrep->offer_id);
$existingObligations = $repository->obligationsForOffer((int) $existingOffer->id);
$existingObligation = $existingObligations[0];
$existingPaymentAt = gmdate('Y-m-d H:i:s');
$existingPayment = $payments->ingest(array(
    'provider_key' => 'synthetic_provider',
    'provider_reference' => 'existing-term-success-' . $suffix,
    'evidence_kind' => 'success',
    'amount_minor' => (string) $existingObligation->amount_minor,
    'currency' => (string) $existingObligation->currency,
    'obligation_reference' => (string) $existingOffer->uid . ':1',
    'provider_occurred_at' => $existingPaymentAt,
    'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'existing-term-success-evidence-' . $suffix,
    'evidence_at' => $now,
), dzn_pia_key('existing-term-success'));
dzn_pia_assert(($existingPayment['processing_state'] ?? '') === 'accepted', 'existing-term settlement must be accepted');
$existingInspection = $recovery->inspect($existingEnrolmentId);
dzn_pia_assert((int) $existingInspection['term_id'] === $operatorTermId, 'inspection must identify the existing Term');
dzn_pia_assert(!in_array('canonical_term', (array) ($existingInspection['planned'] ?? array()), true), 'existing-Term recovery must not plan a second Term');
dzn_pia_assert(in_array('teacher_assignment', (array) ($existingInspection['planned'] ?? array()), true) && in_array('canonical_lessons:12', (array) ($existingInspection['planned'] ?? array()), true), 'existing-Term recovery must plan the Assignment and remaining Lessons');
$existingRecovery = $recovery->recover($existingEnrolmentId);
dzn_pia_assert(($existingRecovery['state'] ?? '') === 'completed', 'existing-Term recovery must complete provisioning');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", $existingEnrolmentId)) === 1, 'existing-Term recovery must not duplicate the Term');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}terms WHERE enrolment_id=%d", $existingEnrolmentId)) === $operatorTermId, 'existing-Term recovery must reuse the operator-created Term');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", $existingEnrolmentId)) === 1, 'existing-Term recovery must create one Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $operatorTermId)) === 12, 'existing-Term recovery must create twelve Lessons');

// I. Reviewed repair of an unmatched provider evidence row (the production double-hashed legacy shape).
$repairBooking = $booking('unmatched-repair', 2, '12:47:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($repairBooking['confirmation'], $teacherId, 'delivered', 'Synthetic unmatched repair delivery');
$repairPrep = $repository->preparationByBooking((int) $repairBooking['request']);
wp_set_current_user((int) $repairBooking['principal']);
$continuePrepared((string) $repairPrep->uid, (int) $repairBooking['principal']);
$repairPrep = $repository->preparationByBooking((int) $repairBooking['request']);
$repairOffer = $repository->offer((int) $repairPrep->offer_id);
$repairObligations = $repository->obligationsForOffer((int) $repairOffer->id);
$repairObligation = $repairObligations[0];
$rawObligationReference = (string) $repairOffer->uid . ':1';
$legacyObligationReference = CommercialSupport::obligationReference((string) $repairOffer->uid, 1);
$repairAccountReference = 'acct_repair_' . $suffix;
$repairOccurredAt = gmdate('Y-m-d H:i:s');
$repairProviderReference = 'evt_repair_' . $suffix;
wp_set_current_user($actor);
$unmatchedRepair = $payments->ingest(array(
    'provider_key' => 'stripe',
    'provider_reference' => $repairProviderReference,
    'evidence_kind' => 'success',
    'amount_minor' => (string) $repairObligation->amount_minor,
    'currency' => (string) $repairObligation->currency,
    'obligation_reference' => $legacyObligationReference,
    'provider_occurred_at' => $repairOccurredAt,
    'provider_account_reference' => $repairAccountReference,
    'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'repair-unmatched-evidence-' . $suffix,
    'evidence_at' => $repairOccurredAt,
), dzn_pia_key('repair-unmatched'));
dzn_pia_assert(($unmatchedRepair['processing_state'] ?? '') === 'unmatched', 'legacy double-hashed metadata must be preserved as unmatched');
$unmatchedEvidenceId = (int) $unmatchedRepair['evidence_id'];
$repairKey = dzn_pia_key('repair-unmatched-settle');
$repairResult = $payments->repairUnmatchedEvidence(
    $repairProviderReference,
    $rawObligationReference,
    $repairAccountReference,
    $repairOccurredAt,
    (int) $repairObligation->amount_minor,
    (string) $repairObligation->currency,
    $repairKey
);
dzn_pia_assert(($repairResult['processing_state'] ?? '') === 'accepted' && !empty($repairResult['repaired']), 'reviewed repair must attribute and settle the exact unmatched evidence');
dzn_pia_assert((int) $repairResult['purchase_id'] > 0, 'reviewed repair must create the purchase');
$repairEvidence = $wpdb->get_row($wpdb->prepare("SELECT processing_state,reason_code,offer_id,obligation_id,purchase_id FROM {$p}commercial_payment_evidence WHERE id=%d", $unmatchedEvidenceId));
dzn_pia_assert($repairEvidence && (string) $repairEvidence->processing_state === 'accepted' && $repairEvidence->reason_code === null && (int) $repairEvidence->offer_id === (int) $repairOffer->id && (int) $repairEvidence->obligation_id === (int) $repairObligation->id && (int) $repairEvidence->purchase_id === (int) $repairResult['purchase_id'], 'repair must correct attribution and preserve provider facts');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", (int) $repairObligation->id)) === 1, 'repair must create exactly one settlement');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE evidence_id=%d AND state='resolved'", $unmatchedEvidenceId)) === 1, 'repair must resolve the unmatched-evidence exception');
$repairPurchasesBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d", (int) $repairOffer->id));
$payments->repairUnmatchedEvidence(
    $repairProviderReference,
    $rawObligationReference,
    $repairAccountReference,
    $repairOccurredAt,
    (int) $repairObligation->amount_minor,
    (string) $repairObligation->currency,
    $repairKey
);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d", (int) $repairOffer->id)) === $repairPurchasesBefore, 'repeated repair must not duplicate the purchase');

// Align an accepted evidence ingestion instant that predates its settlement (the production repair
// shape), then prove the normal activation worker can consume the repaired commitment.
$settlementSettledAt = (string) $wpdb->get_var($wpdb->prepare("SELECT settled_at FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", (int) $repairObligation->id));
dzn_pia_assert($settlementSettledAt !== '', 'repair settlement timestamp');
$wpdb->update($p . 'commercial_payment_evidence', array('ingested_at' => gmdate('Y-m-d H:i:s', time() - 120)), array('id' => $unmatchedEvidenceId));
$payments->alignAcceptedEvidenceIngestionTime($unmatchedEvidenceId, dzn_pia_key('align-repair-evidence'));
$alignedEvidence = $wpdb->get_row($wpdb->prepare("SELECT ingested_at,processing_state FROM {$p}commercial_payment_evidence WHERE id=%d", $unmatchedEvidenceId));
dzn_pia_assert($alignedEvidence && (string) $alignedEvidence->processing_state === 'accepted' && (string) $alignedEvidence->ingested_at === $settlementSettledAt, 'timeline repair must align only the accepted evidence ingestion instant');
$repairRecovery = $recovery->recover((int) $repairPrep->enrolment_id);
dzn_pia_assert(($repairRecovery['state'] ?? '') === 'completed', 'repaired evidence must support normal post-intro activation');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $repairPrep->enrolment_id)) === 1, 'repaired evidence activation must create one Term');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE enrolment_id=%d AND lesson_type='standard'", (int) $repairPrep->enrolment_id)) === 12, 'repaired evidence activation must create twelve Lessons');

/* -------------------------------------------------------------------------------------------------
 * J. Student-selected two-instalment plan, prerequisite gate, later-instalment convergence,
 *    immutable plan after issuance, and out-of-sequence reconciliation.
 * ------------------------------------------------------------------------------------------------- */
$twoBooking = $booking('two-instalment', 2, '14:17:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($twoBooking['confirmation'], $teacherId, 'delivered', 'Synthetic two-instalment delivery');
$twoPrep = $repository->preparationByBooking($twoBooking['request']);
dzn_pia_assert($twoPrep !== null && (string) $twoPrep->state === 'prepared', 'two-instalment preparation');
wp_set_current_user((int) $twoBooking['principal']);
$twoQuote = (new PostIntroPlanQuoteService())->forPreparation($twoPrep, (int) $twoPrep->student_id);
dzn_pia_assert(isset($twoQuote['options'][1]['plan_kind']) && $twoQuote['options'][1]['plan_kind'] === 'two_instalments', 'two-instalment quote option');
$noOptIn = null;
try { $continuations->continue((string) $twoPrep->uid, true, 'two_instalments', (string) $twoQuote['quote_token'], false); } catch (Throwable $error) { $noOptIn = $error; }
dzn_pia_assert($noOptIn !== null && $noOptIn->getMessage() === 'plan_unavailable', 'two instalments must require the explicit opt-in');
$twoAction = $continuations->continue((string) $twoPrep->uid, true, 'two_instalments', (string) $twoQuote['quote_token'], true);
dzn_pia_assert(in_array($twoAction['state'], array('checkout_pending', 'payment_received'), true), 'two-instalment Student action must issue its offer');
$twoPrep = $repository->preparationByBooking($twoBooking['request']);
$twoOffer = $repository->offer((int) $twoPrep->offer_id);
dzn_pia_assert($twoOffer !== null && (string) $twoOffer->plan_kind === 'two_instalments', 'the issued offer must be the selected two-instalment plan');
$twoObligations = $repository->obligationsForOffer((int) $twoOffer->id);
dzn_pia_assert(count($twoObligations) === 2, 'a two-instalment offer must have two obligations');
dzn_pia_assert((int) $twoObligations[0]->sessions_from === 1 && (int) $twoObligations[0]->sessions_to === 6 && (int) $twoObligations[1]->sessions_from === 7 && (int) $twoObligations[1]->sessions_to === 12, 'two-instalment Lesson ranges');
$choice = $repository->activePlanChoice((int) $twoPrep->id);
dzn_pia_assert($choice !== null && (string) $choice->plan_kind === 'two_instalments' && (int) $choice->offer_id === (int) $twoOffer->id, 'the active plan choice must link the issued offer');
$duplicateChoice = null;
$priorErrorSuppression = $wpdb->suppress_errors(true);
try {
    $repository->insertPlanChoice(array('uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(), 'preparation_id' => (int) $twoPrep->id, 'active_slot' => 1, 'student_id' => (int) $twoPrep->student_id, 'plan_kind' => 'full', 'quote_digest' => str_repeat('a', 64), 'quoted_amount_due_minor' => 1, 'currency' => 'AUD', 'instalment_due_at' => null, 'policy_version' => null, 'offer_id' => null, 'chosen_at' => gmdate('Y-m-d H:i:s'), 'chosen_by' => (int) $twoBooking['principal'], 'created_at' => gmdate('Y-m-d H:i:s')));
} catch (Throwable $error) { $duplicateChoice = $error; } finally { $wpdb->suppress_errors($priorErrorSuppression); }
dzn_pia_assert($duplicateChoice !== null && (string) $repository->activePlanChoice((int) $twoPrep->id)->plan_kind === 'two_instalments', 'the unique active-slot guard must reject a competing plan choice');

// Normal Student Checkout for tranche 2 is refused until tranche 1 settles.
$fakeCheckout = new class implements CheckoutSessionPort {
    public function key(): string { return 'stripe'; }
    public function create(\Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest $request): array { throw new RuntimeException('create must not be called'); }
    public function retrieve(string $providerReference): array { throw new RuntimeException('retrieve must not be called'); }
    public function completionEvent(string $providerReference, int $sessionCreatedAt): array { throw new RuntimeException('completionEvent must not be called'); }
};
$prerequisiteRefusal = null;
try {
    (new StudentCheckoutInitiationService(null, null, null, $fakeCheckout))->initiate(array('obligation_uid' => (string) $twoObligations[1]->uid));
} catch (Throwable $error) {
    $prerequisiteRefusal = $error;
}
dzn_pia_assert($prerequisiteRefusal !== null && $prerequisiteRefusal->getMessage() === 'checkout_prerequisite_pending', 'normal Checkout for instalment 2 must be refused before instalment 1 settles');

// A different plan after issuance is a conflict, never a second offer.
$planConflict = null;
try {
    $continuations->continue((string) $twoPrep->uid, true, 'full', (string) $twoQuote['quote_token'], false);
} catch (Throwable $error) {
    $planConflict = $error;
}
dzn_pia_assert($planConflict !== null && $planConflict->getMessage() === 'plan_choice_conflict', 'a different plan after issuance must be refused');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d", (int) $twoPrep->continuation_case_id)) === 1, 'the case must own exactly one offer');

// Before tranche 1, the read projection blocks tranche 2 and never offers a button.
wp_set_current_user((int) $twoBooking['principal']);
$beforeFirst = (new StudentCommercialCheckoutReadService())->read();
$beforeFirstRows = array_values(array_filter($beforeFirst['obligations'], static fn(array $row): bool => (string) ($row['offer_uid'] ?? '') === (string) $twoOffer->uid));
dzn_pia_assert(count($beforeFirstRows) === 2 && $beforeFirstRows[1]['payment_state'] === 'prerequisite_pending' && $beforeFirstRows[1]['action'] === null, 'the read projection must block tranche 2 before tranche 1 settles');

// Tranche 1: verified settlement, six effective Lessons, six further sessions outstanding.
wp_set_current_user($actor);
$twoPaymentAt = gmdate('Y-m-d H:i:s');
$twoFirst = $payments->ingest(array(
    'provider_key' => 'synthetic_provider', 'provider_reference' => 'two-first-' . $suffix,
    'evidence_kind' => 'success', 'amount_minor' => (string) $twoObligations[0]->amount_minor,
    'currency' => (string) $twoObligations[0]->currency, 'obligation_reference' => (string) $twoOffer->uid . ':1',
    'provider_occurred_at' => $twoPaymentAt, 'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'two-first-evidence-' . $suffix, 'evidence_at' => $twoPaymentAt,
), dzn_pia_key('two-first'));
dzn_pia_assert(($twoFirst['processing_state'] ?? '') === 'accepted' && (int) ($twoFirst['effective_sessions'] ?? 0) === 6, 'tranche 1 must fund exactly six sessions');
$twoPurchaseId = (int) ($twoFirst['purchase_id'] ?? 0);
$twoJob = $repository->activeJobForPurchase($twoPurchaseId);
dzn_pia_assert($twoJob !== null && (int) $twoJob->funding_target === 6, 'tranche 1 must queue a six-session activation target');
$twoRunOne = PostIntroActivationWorker::runJob((int) $twoJob->id);
$twoTermId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d LIMIT 1", $twoPurchaseId));
dzn_pia_assert($twoTermId > 0, 'tranche 1 activation must bind one Term');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $twoTermId)) === 6, 'tranche 1 must materialise only Lessons 1-6');
$twoHalfFunded = (new PostIntroFulfilmentReadService())->forEnrolment((int) $twoPrep->enrolment_id);
dzn_pia_assert(($twoHalfFunded['fulfilment_state'] ?? '') === 'awaiting_next_instalment', 'a half-funded two-instalment Term must be an expected intermediate state');
dzn_pia_assert((int) $twoHalfFunded['lesson_count'] === 6 && (int) $twoHalfFunded['effective_lesson_count'] === 6 && (int) $twoHalfFunded['expected_lesson_count'] === 12, 'the half-funded projection must expose the funded and committed Lesson counts');
dzn_pia_assert(!in_array('lessons_missing', (array) $twoHalfFunded['blockers'], true), 'a half-funded two-instalment Term must not report missing Lessons');
wp_set_current_user($actor);
dzn_pia_assert((new PostIntroRecoveryService())->recover((int) $twoPrep->enrolment_id)['state'] === 'awaiting_next_instalment', 'admin recovery must recognise the expected half-funded state instead of failing');

// After tranche 1, the second instalment becomes normally collectable in the read projection.
wp_set_current_user((int) $twoBooking['principal']);
$afterFirst = (new StudentCommercialCheckoutReadService())->read();
$afterFirstRows = array_values(array_filter($afterFirst['obligations'], static fn(array $row): bool => (string) ($row['offer_uid'] ?? '') === (string) $twoOffer->uid));
dzn_pia_assert(count($afterFirstRows) === 2 && $afterFirstRows[1]['payment_state'] === 'required' && $afterFirstRows[1]['prerequisite_satisfied'] === true, 'tranche 2 must become payable only after tranche 1 settles');
// The original checkout hold is not the life of the plan: an accepted commitment must keep
// tranche 2 payable weeks later, up to its own recorded due date.
$wpdb->update($p . 'commercial_offers', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('id' => (int) $twoOffer->id));
$afterHold = (new StudentCommercialCheckoutReadService())->read();
$afterHoldRows = array_values(array_filter($afterHold['obligations'], static fn(array $row): bool => (string) ($row['offer_uid'] ?? '') === (string) $twoOffer->uid));
dzn_pia_assert(count($afterHoldRows) === 2 && $afterHoldRows[1]['payment_state'] === 'required' && $afterHoldRows[1]['action'] === 'pay', 'an accepted commitment must keep tranche 2 payable after the original checkout hold');

// Simulate the exact race: a worker holds the earlier target while tranche 2 settles, then the
// worker completes the earlier target. The later target must survive and re-arm the job.
$raceLease = bin2hex(random_bytes(16));
$wpdb->update($p . 'post_intro_activation_jobs', array(
    'state' => 'running', 'funding_target' => 6, 'funding_materialised' => 6,
    'lease_token_digest' => hash('sha256', $raceLease),
    'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
), array('id' => (int) $twoJob->id));
wp_set_current_user($actor);
$twoSecond = $payments->ingest(array(
    'provider_key' => 'synthetic_provider', 'provider_reference' => 'two-second-' . $suffix,
    'evidence_kind' => 'success', 'amount_minor' => (string) $twoObligations[1]->amount_minor,
    'currency' => (string) $twoObligations[1]->currency, 'obligation_reference' => (string) $twoOffer->uid . ':2',
    'provider_occurred_at' => gmdate('Y-m-d H:i:s'), 'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'two-second-evidence-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_pia_key('two-second'));
dzn_pia_assert(($twoSecond['processing_state'] ?? '') === 'accepted' && (int) ($twoSecond['effective_sessions'] ?? 0) === 12, 'tranche 2 must raise effective sessions to twelve');
$raceJob = $repository->activeJobForPurchase($twoPurchaseId);
dzn_pia_assert($raceJob !== null && (string) $raceJob->state === 'running' && (int) $raceJob->funding_target === 12, 'a live worker must retain its lease while the target rises to twelve');
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'completed', 'funding_materialised' => 6, 'lease_token_digest' => null, 'lease_expires_at' => null, 'completed_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $raceJob->id));
PostIntroSettlementHook::handle($twoPurchaseId);
$raceJob = $repository->activeJobForPurchase($twoPurchaseId);
dzn_pia_assert($raceJob !== null && (string) $raceJob->state === 'queued' && (int) $raceJob->funding_target === 12, 'a completed earlier target must be re-armed by the later settlement');
$twoRunTwo = PostIntroActivationWorker::runJob((int) $raceJob->id);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $twoTermId)) === 12, 'tranche 2 must incrementally materialise Lessons 7-12');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND v.superseded_at IS NULL", $twoTermId)) === 12, 'tranche 2 must schedule exactly twelve Lessons');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $twoPrep->enrolment_id)) === 1, 'incremental activation must not duplicate the Term');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $twoPrep->enrolment_id)) === 1, 'incremental activation must not duplicate the Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard' AND canonical_sequence<=6", $twoTermId)) === 6, 'tranche 2 must replay, not duplicate, Lessons 1-6');
$twoComplete = (new PostIntroFulfilmentReadService())->forEnrolment((int) $twoPrep->enrolment_id);
dzn_pia_assert(($twoComplete['fulfilment_state'] ?? '') === 'completed' && (int) $twoComplete['lesson_count'] === 12, 'tranche 2 must converge the fulfilment projection to twelve Lessons');

wp_set_current_user((int) $twoBooking['principal']);
$twoRead = (new StudentCommercialCheckoutReadService())->read();
$twoRows = array_values(array_filter($twoRead['obligations'], static fn(array $row): bool => (string) ($row['offer_uid'] ?? '') === (string) $twoOffer->uid));
dzn_pia_assert(count($twoRows) === 2 && $twoRows[0]['term_paid'] === true && (int) $twoRows[0]['paid_instalments'] === 2 && (int) $twoRows[0]['term_sequence'] === 1, 'the Student account must read the term as paid after both instalments');

// Duplicate settlement replay must not add Lessons, settlements or a second job.
$twoSettlementsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d", (int) $twoOffer->id));
wp_set_current_user($actor);
$payments->ingest(array(
    'provider_key' => 'synthetic_provider', 'provider_reference' => 'two-second-' . $suffix,
    'evidence_kind' => 'success', 'amount_minor' => (string) $twoObligations[1]->amount_minor,
    'currency' => (string) $twoObligations[1]->currency, 'obligation_reference' => (string) $twoOffer->uid . ':2',
    'provider_occurred_at' => gmdate('Y-m-d H:i:s'), 'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'two-second-evidence-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_pia_key('two-second-replay'));
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d", (int) $twoOffer->id)) === $twoSettlementsBefore, 'duplicate tranche 2 evidence must not duplicate settlement');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $twoTermId)) === 12, 'duplicate tranche 2 evidence must not duplicate Lessons');

// K. Out-of-sequence reconciliation: money is recorded, academic funding stays blocked until
//    its prerequisite settles, then the full twelve Lessons converge in order.
$oooBooking = $booking('out-of-sequence', 2, '16:23:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($oooBooking['confirmation'], $teacherId, 'delivered', 'Synthetic out-of-sequence delivery');
$oooPrep = $repository->preparationByBooking($oooBooking['request']);
wp_set_current_user((int) $oooBooking['principal']);
$oooQuote = (new PostIntroPlanQuoteService())->forPreparation($oooPrep, (int) $oooPrep->student_id);
$continuations->continue((string) $oooPrep->uid, true, 'two_instalments', (string) $oooQuote['quote_token'], true);
$oooPrep = $repository->preparationByBooking($oooBooking['request']);
$oooOffer = $repository->offer((int) $oooPrep->offer_id);
$oooObligations = $repository->obligationsForOffer((int) $oooOffer->id);
wp_set_current_user($actor);
$oooSecond = $payments->ingest(array(
    'provider_key' => 'synthetic_provider', 'provider_reference' => 'ooo-second-' . $suffix,
    'evidence_kind' => 'success', 'amount_minor' => (string) $oooObligations[1]->amount_minor,
    'currency' => (string) $oooObligations[1]->currency, 'obligation_reference' => (string) $oooOffer->uid . ':2',
    'provider_occurred_at' => gmdate('Y-m-d H:i:s'), 'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'ooo-second-evidence-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_pia_key('ooo-second'));
dzn_pia_assert(($oooSecond['processing_state'] ?? '') === 'accepted' && (int) ($oooSecond['effective_sessions'] ?? -1) === 0, 'an out-of-sequence second payment must record but fund zero sessions');
$oooPurchaseId = (int) $oooSecond['purchase_id'];
$oooJob = $repository->activeJobForPurchase($oooPurchaseId);
dzn_pia_assert($oooJob === null, 'an out-of-sequence second payment must not queue a materialisation job before its prerequisite');
wp_set_current_user((int) $oooBooking['principal']);
$oooRead = (new StudentCommercialCheckoutReadService())->read();
$oooRows = array_values(array_filter($oooRead['obligations'], static fn(array $row): bool => (string) ($row['offer_uid'] ?? '') === (string) $oooOffer->uid));
dzn_pia_assert(count($oooRows) === 2 && $oooRows[1]['payment_state'] === 'paid_prerequisite_pending' && $oooRows[0]['term_paid'] === false, 'an out-of-sequence payment must be visible but not claim a paid term');
wp_set_current_user($actor);
$oooFirst = $payments->ingest(array(
    'provider_key' => 'synthetic_provider', 'provider_reference' => 'ooo-first-' . $suffix,
    'evidence_kind' => 'success', 'amount_minor' => (string) $oooObligations[0]->amount_minor,
    'currency' => (string) $oooObligations[0]->currency, 'obligation_reference' => (string) $oooOffer->uid . ':1',
    'provider_occurred_at' => gmdate('Y-m-d H:i:s'), 'evidence_channel' => 'provider_evidence',
    'evidence_reference' => 'ooo-first-evidence-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_pia_key('ooo-first'));
dzn_pia_assert(($oooFirst['processing_state'] ?? '') === 'accepted' && (int) ($oooFirst['effective_sessions'] ?? 0) === 12, 'settling the prerequisite must make all twelve sessions effective');
$oooJob = $repository->activeJobForPurchase($oooPurchaseId);
dzn_pia_assert($oooJob !== null && (int) $oooJob->funding_target === 12, 'the prerequisite settlement must queue the full twelve-session target');
PostIntroActivationWorker::runJob((int) $oooJob->id);
$oooTermId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d LIMIT 1", $oooPurchaseId));
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $oooTermId)) === 12, 'out-of-sequence reconciliation must converge to twelve ordered Lessons');

/* -------------------------------------------------------------------------------------------------
 * L. B2b: the second-instalment deadline is opt-in configuration, exactly 168 hours before Lesson 7.
 * ------------------------------------------------------------------------------------------------- */
dzn_pia_assert($twoObligations[1]->due_at === null && $oooObligations[1]->due_at === null, 'an unset deadline policy must leave second instalments without an invented due date');

$deadlineUnsetBooking = $booking('deadline-unset', 2, '10:57:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($deadlineUnsetBooking['confirmation'], $teacherId, 'delivered', 'Synthetic unset-deadline delivery');
$deadlineUnsetPrep = $repository->preparationByBooking($deadlineUnsetBooking['request']);
wp_set_current_user((int) $deadlineUnsetBooking['principal']);
$deadlineUnsetQuote = (new PostIntroPlanQuoteService())->forPreparation($deadlineUnsetPrep, (int) $deadlineUnsetPrep->student_id);
$continuations->continue((string) $deadlineUnsetPrep->uid, true, 'two_instalments', (string) $deadlineUnsetQuote['quote_token'], true);
$deadlineUnsetPrep = $repository->preparationByBooking($deadlineUnsetBooking['request']);
$deadlineUnsetOffer = $repository->offer((int) $deadlineUnsetPrep->offer_id);
$deadlineUnsetObligations = $repository->obligationsForOffer((int) $deadlineUnsetOffer->id);
dzn_pia_assert(count($deadlineUnsetObligations) === 2 && $deadlineUnsetObligations[1]->due_at === null, 'the default unset policy must not invent a second-instalment deadline');
$deadlineUnsetChoice = $repository->activePlanChoice((int) $deadlineUnsetPrep->id);
dzn_pia_assert($deadlineUnsetChoice !== null && $deadlineUnsetChoice->instalment_due_at === null && $deadlineUnsetChoice->policy_version === null, 'an unset policy must record no due instant or policy version');

wp_set_current_user($actor);
$policyService = new CommercialPolicyService();

// An explicit non-approved value fails closed rather than inventing a different deadline. The
// same preparation is retried after the approved value is recorded.
$policyService->set(InstalmentDeadlinePolicy::POLICY_KEY, array(
    'policy_value' => '2', 'value_type' => 'weeks', 'reason_code' => 'owner_invalid_probe',
    'evidence_channel' => 'staff_record', 'evidence_reference' => 'runtime-b2b-invalid-' . $suffix,
    'evidence_at' => gmdate('Y-m-d H:i:s'),
), 'runtime-b2b-invalid-' . $suffix);
$deadlineBooking = $booking('deadline-configured', 2, '17:43:00');
wp_set_current_user($teacherUser);
$preparations->recordTeacherOutcome($deadlineBooking['confirmation'], $teacherId, 'delivered', 'Synthetic configured-deadline delivery');
$deadlinePrep = $repository->preparationByBooking($deadlineBooking['request']);
wp_set_current_user((int) $deadlineBooking['principal']);
$deadlineQuote = (new PostIntroPlanQuoteService())->forPreparation($deadlinePrep, (int) $deadlinePrep->student_id);
$invalidDeadlineError = null;
try {
    $continuations->continue((string) $deadlinePrep->uid, true, 'two_instalments', (string) $deadlineQuote['quote_token'], true);
} catch (Throwable $error) {
    $invalidDeadlineError = $error;
}
dzn_pia_assert($invalidDeadlineError !== null && $invalidDeadlineError->getMessage() === 'instalment_due_date_policy_invalid', 'a non-approved deadline value must fail closed');
$deadlinePrep = $repository->preparationByBooking($deadlineBooking['request']);
dzn_pia_assert((int) $deadlinePrep->offer_id === 0, 'a refused deadline policy must not issue an offer');

wp_set_current_user($actor);
$policySet = $policyService->set(InstalmentDeadlinePolicy::POLICY_KEY, array(
    'policy_value' => '1', 'value_type' => 'weeks', 'reason_code' => 'owner_approved_b2b',
    'evidence_channel' => 'staff_record', 'evidence_reference' => 'runtime-b2b-policy-' . $suffix,
    'evidence_at' => gmdate('Y-m-d H:i:s'),
), 'runtime-b2b-policy-' . $suffix);
dzn_pia_assert((int) ($policySet['policy_version'] ?? 0) >= 1, 'an administrator must be able to record the approved deadline policy');
wp_set_current_user((int) $deadlineBooking['principal']);
$continuations->continue((string) $deadlinePrep->uid, true, 'two_instalments', (string) $deadlineQuote['quote_token'], true);
$deadlinePrep = $repository->preparationByBooking($deadlineBooking['request']);
$deadlineOffer = $repository->offer((int) $deadlinePrep->offer_id);
$deadlineObligations = $repository->obligationsForOffer((int) $deadlineOffer->id);
dzn_pia_assert(count($deadlineObligations) === 2 && $deadlineObligations[1]->due_at !== null, 'the configured policy must record a second-instalment due date');
$deadlinePattern = $repository->pattern((int) $deadlinePrep->pattern_id);
$deadlineIntervals = (new CommercialPatternService())->intervalsFor($deadlinePattern, InstalmentDeadlinePolicy::LESSON_SEQUENCE);
$expectedDueAt = gmdate('Y-m-d H:i:s', strtotime((string) $deadlineIntervals[InstalmentDeadlinePolicy::LESSON_SEQUENCE - 1]['starts_at_utc'] . ' UTC') - InstalmentDeadlinePolicy::LEAD_SECONDS);
dzn_pia_assert((string) $deadlineObligations[1]->due_at === $expectedDueAt, 'the due instant must be exactly 168 hours before the canonical Lesson 7 start');
$deadlineChoice = $repository->activePlanChoice((int) $deadlinePrep->id);
dzn_pia_assert($deadlineChoice !== null && (string) $deadlineChoice->instalment_due_at === $expectedDueAt, 'the Student choice must record the derived due instant');
dzn_pia_assert((int) $deadlineChoice->policy_version === (int) $policySet['policy_version'], 'the Student choice must record the exact policy version');
$deadlineOfferPolicies = (new CommercialAuthorityRepository())->offerPolicies((int) $deadlineOffer->id);
$deadlineSnapshot = array_values(array_filter($deadlineOfferPolicies, static fn(object $row): bool => (string) $row->policy_key === InstalmentDeadlinePolicy::POLICY_KEY));
dzn_pia_assert(count($deadlineSnapshot) === 1 && (int) $deadlineSnapshot[0]->policy_version === (int) $policySet['policy_version'], 'the offer must snapshot the exact deadline policy version');

// A future-dated policy version must not hide the currently effective approved version.
wp_set_current_user($actor);
$futurePolicy = $policyService->set(InstalmentDeadlinePolicy::POLICY_KEY, array(
    'policy_value' => '2', 'value_type' => 'weeks',
    'effective_from' => gmdate('Y-m-d H:i:s', time() + 3600),
    'reason_code' => 'owner_future_policy', 'evidence_channel' => 'staff_record',
    'evidence_reference' => 'runtime-b2b-future-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), 'runtime-b2b-future-' . $suffix);
$effectiveNow = (new InstalmentDeadlinePolicy())->forPattern($deadlinePattern, gmdate('Y-m-d H:i:s'));
dzn_pia_assert($effectiveNow['configured'] === true && (int) $effectiveNow['policy_version'] === (int) $policySet['policy_version'] && (string) $effectiveNow['due_at'] === $expectedDueAt, 'a future policy version must not hide the currently effective approved deadline');
$futureInvalid = null;
try {
    (new InstalmentDeadlinePolicy())->forPattern($deadlinePattern, gmdate('Y-m-d H:i:s', time() + 7200));
} catch (Throwable $error) {
    $futureInvalid = $error;
}
dzn_pia_assert($futureInvalid !== null && $futureInvalid->getMessage() === 'instalment_due_date_policy_invalid', 'once effective, an unsupported future value must fail closed');

wp_set_current_user($actor);
$policyService->set(InstalmentDeadlinePolicy::POLICY_KEY, array(
    'reason_code' => 'owner_deferred', 'evidence_channel' => 'staff_record',
    'evidence_reference' => 'runtime-b2b-unset-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), 'runtime-b2b-unset-' . $suffix);
dzn_pia_assert($policyService->current(InstalmentDeadlinePolicy::POLICY_KEY)['set'] === false, 'the deadline policy must remain explicitly unset when deferred');
$afterUnset = (new InstalmentDeadlinePolicy())->forPattern($deadlinePattern, gmdate('Y-m-d H:i:s'));
dzn_pia_assert($afterUnset['configured'] === false && $afterUnset['due_at'] === null && $afterUnset['policy_version'] === null, 'an explicit unset must resolve to no deadline');
$unchangedObligations = $repository->obligationsForOffer((int) $deadlineOffer->id);
$unchangedChoice = $repository->activePlanChoice((int) $deadlinePrep->id);
dzn_pia_assert((string) $unchangedObligations[1]->due_at === $expectedDueAt, 'unsetting the policy must not rewrite an existing offer deadline');
dzn_pia_assert((int) $unchangedChoice->policy_version === (int) $policySet['policy_version'], 'unsetting the policy must not rewrite an existing plan-choice version');

echo "Post-intro enrolment automation runtime passed\n";
