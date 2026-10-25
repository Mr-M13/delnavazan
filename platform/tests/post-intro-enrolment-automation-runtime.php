<?php
/**
 * Disposable production-path post-introductory enrolment automation proof.
 *
 * Run inside the offline WordPress runtime after the Phase-J fixture exists:
 *   DZN_POST_INTRO_RUNTIME_TEST=authority wp eval-file platform/tests/post-intro-enrolment-automation-runtime.php
 *
 * Synthetic local data only. It never contacts Stripe or Google: provider projection is expected
 * to fail closed as a retryable provider_* job, and no Meet URL is ever fabricated.
 */
if (getenv('DZN_POST_INTRO_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Post-intro runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{BookingRequestSubmissionService,CatalogueService,CommercialCatalogueService,CommercialPaymentService,PostIntroContinuationService,PostIntroPreparationService,PostIntroSystemActor,StudentAcceptanceAuthorityService,StudentIdentityResolutionService,TeachingEligibilityService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\PostIntroActivationWorker;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$actor = 1;
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || empty($fixture['sources'][0]) || empty($fixture['teacherUsers'][0])) throw new RuntimeException('Phase-J fixture required');
// This is a disposable, standalone runtime. Reset only derived automation/commercial/schedule
// storage so repeated runs are deterministic; the Phase-J accepted Enrolments themselves remain.
foreach (array(
    'post_intro_activation_jobs','post_intro_preparations','booking_request_intro_outcomes',
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
try { (new TeacherAvailabilityService())->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_post_intro')); } catch (Throwable $ignored) {}
$wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_availability_rules WHERE teacher_id=%d", $teacherId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_availability_exceptions WHERE teacher_id=%d", $teacherId));
$availability = new TeacherAvailabilityService();
foreach (array(1, 2, 3, 4, 5, 6, 7) as $weekday) {
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_post_intro'));
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
$action = $continuations->continue($prepUid, true);
dzn_pia_assert(in_array($action['state'], array('checkout_pending', 'payment_received'), true), 'Student action must reach the canonical offer');
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((int) $prep->offer_id > 0 && (int) $prep->continuation_case_id > 0 && (int) $prep->pattern_id > 0, 'Student action must create the offer, continuation case and pattern');
// A termination between the continuation command and the preparation checkpoint must recover
// the durable reservation on the next Student action instead of failing forever.
$wpdb->update($p . 'post_intro_preparations', array('reservation_id' => null), array('id' => (int) $prep->id));
$resumedAction = $continuations->continue($prepUid, true);
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

// Activation worker: core canonical truth completes even when Google projection is unavailable.
PostIntroActivationWorker::run();
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
$evidenceOccurredAt = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT e.occurred_at FROM {$p}canonical_lesson_lifecycle_events e INNER JOIN {$p}lessons l ON l.id=e.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND e.to_state='authorised' ORDER BY e.id LIMIT 1",
    $termId
));
dzn_pia_assert($evidenceOccurredAt === $activationCheckpoint, 'activation must reuse the durable settlement checkpoint as evidence time');
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && in_array((string) $job->state, array('retryable', 'failed'), true), 'a missing Google connection must leave a retryable/failed job, not a fabricated projection');
dzn_pia_assert(str_starts_with((string) $job->last_error_code, 'provider_'), 'the retryable failure must be a provider failure, got ' . (string) $job->last_error_code);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='verified'")) === 0, 'provider projection must not be marked verified without a provider result');

// Concurrent/retry convergence: re-running the same job must not duplicate canonical truth.
$attemptsBefore = (int) $job->attempt_count;
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'lease_token_digest' => null, 'lease_expires_at' => null), array('id' => (int) $job->id));
PostIntroActivationWorker::run();
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d", $termId)) === $lessonCount, 'retry must not duplicate Lessons');
$retriedEvidenceAt = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT e.occurred_at FROM {$p}canonical_lesson_lifecycle_events e INNER JOIN {$p}lessons l ON l.id=e.lesson_id WHERE l.term_id=%d AND l.lesson_type='standard' AND e.to_state='authorised' ORDER BY e.id LIMIT 1",
    $termId
));
dzn_pia_assert($retriedEvidenceAt === $activationCheckpoint, 'an expected retry must reconstruct the identical canonical evidence time');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'retry must not duplicate Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d", $purchaseId)) === 1, 'retry must not duplicate Term funding');
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
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_post_intro'));
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
$continuations->continue((string) $expiredPrep->uid, true);
$expiredPrep = $repository->preparationByBooking($expiredBooking['request']);
$wpdb->update($p . 'canonical_continuation_reservations', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => (int) $expiredPrep->reservation_id));
$expiredCaught = null;
try { $continuations->continue((string) $expiredPrep->uid, true); } catch (Throwable $e) { $expiredCaught = $e; }
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

echo "Post-intro enrolment automation runtime passed\n";
