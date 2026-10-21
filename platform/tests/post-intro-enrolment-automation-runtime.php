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

use Delnavazan\Platform\Core\Application\{BookingRequestSubmissionService,CatalogueService,CommercialCatalogueService,CommercialPaymentService,PostIntroContinuationService,PostIntroPreparationService,StudentAcceptanceAuthorityService,StudentIdentityResolutionService,TeachingEligibilityService,TeacherAvailabilityService};
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
$booking = function (string $label, int $daysAgo, string $wallTime) use ($wpdb, $p, $instrumentId, $introCourse, $teacherId, $actor, $suffix, $now): array {
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
        'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+14 days')), 'local_start_time' => '04:17', 'timezone' => 'UTC')),
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

    $requestedTimeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_requested_times WHERE booking_request_id=%d AND sequence_number=1", $requestId));
    $start = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC -' . $daysAgo . ' days'));
    $start = substr($start, 0, 11) . $wallTime;
    $end = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC +30 minutes'));
    $occupied = gmdate('Y-m-d H:i:s', strtotime($end . ' UTC +15 minutes'));
    $localDate = substr($start, 0, 10);
    dzn_pia_assert($wpdb->update($p . 'booking_request_requested_times', array('local_date' => $localDate, 'local_start_time' => $wallTime, 'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied), array('id' => $requestedTimeId)) === 1, 'requested time import');
    dzn_pia_assert($wpdb->update($p . 'booking_requests', array('student_id' => $studentId, 'lifecycle_status' => 'closed', 'resolution_state' => 'teacher_confirmed', 'updated_at' => $now, 'updated_by' => $actor), array('id' => $requestId)) === 1, 'booking request confirmation state');
    dzn_pia_assert($wpdb->update($p . 'booking_request_contact_snapshots', array('adult_self_attested' => 1), array('booking_request_id' => $requestId, 'snapshot_sequence' => 1)) !== false, 'adult attestation persistence');
    $deliveryUid = \Delnavazan\Platform\Core\Support\Identifier::uid();
    dzn_pia_assert($wpdb->insert($p . 'booking_request_teacher_deliveries', array('uid' => $deliveryUid, 'booking_request_id' => $requestId, 'requested_time_id' => $requestedTimeId, 'teacher_id' => $teacherId, 'state' => 'confirmed', 'match_strength' => 'strong', 'created_at' => $now, 'updated_at' => $now, 'responded_at' => $now)) === 1, 'delivery persistence');
    $deliveryId = (int) $wpdb->insert_id;
    $confirmationUid = \Delnavazan\Platform\Core\Support\Identifier::uid();
    dzn_pia_assert($wpdb->insert($p . 'booking_request_confirmations', array('uid' => $confirmationUid, 'delivery_id' => $deliveryId, 'booking_request_id' => $requestId, 'requested_time_id' => $requestedTimeId, 'teacher_id' => $teacherId, 'connection_id' => 0, 'state' => 'confirmed', 'version' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'UTC', 'local_wall_date' => $localDate, 'local_wall_time' => $wallTime, 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 0, 'next_attempt_at' => null, 'confirmed_at' => $now, 'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor)) === 1, 'confirmation persistence');
    return array('request' => $requestId, 'student' => $studentId, 'principal' => (int) $userId, 'confirmation' => $confirmationUid, 'delivery' => $deliveryId);
};

$preparations = new PostIntroPreparationService();
$continuations = new PostIntroContinuationService();
$repository = new PostIntroRepository();

// A. Delivered intro -> preparation, student payment action, verified settlement, activation.
$mainWall = sprintf('04:%02d:00', 5 + (hexdec(substr($suffix, 0, 2)) % 50));
$main = $booking('main', 3, $mainWall);
wp_set_current_user($teacherUser);
$outcome = $preparations->recordTeacherOutcome($main['confirmation'], $teacherId, 'delivered', 'Synthetic delivered intro');
$prep = $repository->preparationByBooking($main['request']);
dzn_pia_assert($prep !== null, 'preparation row');
dzn_pia_assert((string) $prep->state === 'prepared', 'delivered intro must prepare the continuation, got ' . (string) ($prep->state ?? 'missing'));
$prepUid = (string) $prep->uid;
dzn_pia_assert((int) $prep->intro_lesson_id > 0, 'intro lesson provenance');
dzn_pia_assert((string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}lessons WHERE id=%d", (int) $prep->intro_lesson_id)) === 'completed', 'intro lesson must be genuinely completed');
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
$obligations = $repository->obligationsForOffer((int) $prep->offer_id);
dzn_pia_assert(count($obligations) === 1, 'a full plan must have one obligation');
$obligation = $obligations[0];
$offer = $repository->offer((int) $prep->offer_id);
dzn_pia_assert($offer !== null && (string) $offer->state === 'issued', 'offer must be issued before settlement');

// A mismatched payment must fail closed and must not enqueue activation.
wp_set_current_user($actor);
$payments = new CommercialPaymentService();
$rejected = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-reject-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => '1', 'currency' => 'AUD', 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $now, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-reject-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('rejected'));
dzn_pia_assert(($rejected['processing_state'] ?? '') === 'rejected', 'amount mismatch must be rejected');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d", (int) $prep->offer_id)) === 0, 'a mismatched payment must not create a purchase');

// Verified settlement enqueues exactly one durable job.
$settled = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-success-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => (string) $obligation->amount_minor, 'currency' => (string) $obligation->currency, 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $now, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-success-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('success'));
dzn_pia_assert(($settled['processing_state'] ?? '') === 'accepted', 'verified settlement must be accepted');
$purchaseId = (int) $wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM {$p}commercial_entitlements WHERE purchase_id IN (SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d) LIMIT 1", (int) $prep->offer_id));
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && (string) $job->state === 'queued', 'settlement must enqueue exactly one activation job');
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((string) $prep->state === 'payment_received', 'verified payment must move the preparation to payment_received');

// Duplicate webhook: the unique purchase job and settlement replay must not create a second path.
$replay = $payments->ingest(array('provider_key' => 'synthetic_provider', 'provider_reference' => 'post-intro-success-' . $suffix, 'evidence_kind' => 'success', 'amount_minor' => (string) $obligation->amount_minor, 'currency' => (string) $obligation->currency, 'obligation_reference' => (string) $offer->uid . ':1', 'provider_occurred_at' => $now, 'evidence_channel' => 'provider_evidence', 'evidence_reference' => 'post-intro-success-evidence-' . $suffix, 'evidence_at' => $now), dzn_pia_key('success'));
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}post_intro_activation_jobs WHERE purchase_id=%d", $purchaseId)) === 1, 'duplicate webhook must not duplicate the activation job');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d", (int) $prep->offer_id)) === 1, 'duplicate webhook must not duplicate settlement');

// Activation worker: core canonical truth completes even when Google projection is unavailable.
PostIntroActivationWorker::run();
$prep = $repository->preparationByUid($prepUid);
dzn_pia_assert((string) $prep->state === 'activated', 'core activation must complete before provider projection');
$enrolment = $repository->enrolment((int) $prep->enrolment_id);
dzn_pia_assert($enrolment && (string) $enrolment->lifecycle_state === 'current', 'Enrolment must be current');
$termId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d LIMIT 1", $purchaseId));
$term = $repository->term($termId);
dzn_pia_assert($term && (string) $term->lifecycle_state === 'current', 'Term must be current');
$assignment = $repository->assignmentForEnrolment((int) $enrolment->id);
dzn_pia_assert($assignment && (string) $assignment->state === 'assigned', 'Teacher Assignment must be assigned');
$lessonCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard'", $termId));
dzn_pia_assert($lessonCount === 12, 'the purchased entitlement must create twelve future Lessons, got ' . $lessonCount);
$scheduledCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.term_id=%d AND v.superseded_at IS NULL", $termId));
dzn_pia_assert($scheduledCount === 12, 'every created Lesson must have a committed schedule');
$job = $repository->activeJobForPurchase($purchaseId);
dzn_pia_assert($job !== null && in_array((string) $job->state, array('retryable', 'failed'), true), 'a missing Google connection must leave a retryable/failed job, not a fabricated projection');
dzn_pia_assert(str_starts_with((string) $job->last_error_code, 'provider_'), 'the retryable failure must be a provider failure, got ' . (string) $job->last_error_code);
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE projection_state='verified'")) === 0, 'provider projection must not be marked verified without a provider result');

// Concurrent/retry convergence: re-running the same job must not duplicate canonical truth.
$attemptsBefore = (int) $job->attempt_count;
$wpdb->update($p . 'post_intro_activation_jobs', array('state' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'lease_token_digest' => null, 'lease_expires_at' => null), array('id' => (int) $job->id));
PostIntroActivationWorker::run();
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d", $termId)) === $lessonCount, 'retry must not duplicate Lessons');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE enrolment_id=%d", (int) $enrolment->id)) === 1, 'retry must not duplicate Teacher Assignment');
dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE purchase_id=%d", $purchaseId)) === 1, 'retry must not duplicate Term funding');

// B. No-show and cancellation never invite continuation.
foreach (array('student_no_show', 'teacher_cancelled') as $index => $badOutcome) {
    $badBooking = $booking('bad-' . $badOutcome, 4 + $index, '03:2' . $index . ':00');
    wp_set_current_user($teacherUser);
    $result = $preparations->recordTeacherOutcome($badBooking['confirmation'], $teacherId, $badOutcome, 'Synthetic non-delivery');
    $bad = $repository->preparationByBooking($badBooking['request']);
    dzn_pia_assert($bad !== null && (string) $bad->state === 'exception', $badOutcome . ' must become an explicit exception');
    dzn_pia_assert((string) $bad->exception_code === ($badOutcome === 'student_no_show' ? 'intro_student_no_show' : 'intro_cancelled'), $badOutcome . ' exception code');
    dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='post_intro_invitation'", (int) $badBooking['delivery'])) === 0, $badOutcome . ' must not enqueue a continuation invitation');
    dzn_pia_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE booking_request_id=%d", (int) $badBooking['request'])) === 0, $badOutcome . ' must not create an intro Lesson');
}

// C. Expired reservation is terminal and requires admin intervention.
$expiredBooking = $booking('expired', 5, sprintf('06:%02d:00', 5 + (hexdec(substr($suffix, 2, 2)) % 50)));
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

echo "Post-intro enrolment automation runtime passed\n";
