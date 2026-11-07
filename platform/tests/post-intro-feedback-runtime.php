<?php
/**
 * Disposable MariaDB/WordPress runtime proof for the post-intro feedback survey.
 *
 * Run with DZN_POST_INTRO_FEEDBACK_RUNTIME_TEST=authority through WP-CLI. It uses the synthetic
 * preparation left by the post-intro automation runtime; the database is disposable.
 */
if (getenv('DZN_POST_INTRO_FEEDBACK_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Post-intro feedback runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Application\PostIntroFeedbackRule;
use Delnavazan\Platform\Core\Application\PostIntroFeedbackService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroFeedbackRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\BookingStudentNotificationWorker;
use Delnavazan\Platform\Integrations\PostIntroSurveyWorker;
use Delnavazan\Platform\Portals\PostIntroStudentReadService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException('post-intro feedback runtime: ' . $message); };
$prep = $wpdb->get_row("SELECT * FROM {$p}post_intro_preparations WHERE state='prepared' AND slot_authority_id IS NOT NULL ORDER BY id DESC LIMIT 1");
$assert($prep !== null, 'a prepared survey candidate is required');
$prepId = (int) $prep->id;
$uid = (string) $prep->uid;
$studentId = (int) $prep->student_id;
$confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $prep->booking_request_id));
$assert($confirmation !== null, 'the survey candidate has a confirmation');
$deliveryId = (int) $confirmation->delivery_id;
$case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d LIMIT 1", (int) $prep->intro_lesson_id));

$resetCase = static function () use ($wpdb, $p, $prep): void {
    $caseIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id));
    foreach ((array) $caseIds as $caseId) {
        $caseId = (int) $caseId;
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d", $caseId));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d", $caseId));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d", $caseId));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_cases WHERE id=%d", $caseId));
    }
    // The command ledger is immutable in production; this disposable test removes its own
    // deterministic keys so a repeated run cannot replay a case this test just deleted.
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_commands WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id));
};
$resetFeedback = static function () use ($wpdb, $p, $prepId): void {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}post_intro_feedback WHERE preparation_id=%d", $prepId));
};
$resetNotifications = static function () use ($wpdb, $p, $prepId, $prep): void {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}portal_notifications WHERE event_key=%s AND source_kind=%s AND source_id=%d", PostIntroSurveyWorker::EVENT_KEY, 'post_intro_survey', $prepId));
    $confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $prep->booking_request_id));
    if ($confirmation) $wpdb->query($wpdb->prepare("DELETE FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind=%s", (int) $confirmation->delivery_id, PostIntroSurveyWorker::NOTIFICATION_KIND));
};
$setWindow = static function (int $introDaysAgo, int $slotHoursAhead) use ($wpdb, $p, $prep): array {
    $introEnd = gmdate('Y-m-d H:i:s', time() - ($introDaysAgo * 86400));
    $introStart = gmdate('Y-m-d H:i:s', strtotime($introEnd . ' UTC') - 1800);
    $slotStartTs = time() + ($slotHoursAhead * 3600);
    $slotStart = gmdate('Y-m-d H:i:s', $slotStartTs);
    $localDate = gmdate('Y-m-d', $slotStartTs);
    $localTime = gmdate('H:i:s', $slotStartTs);
    // Keep the canonical wall clock and the persisted UTC interval in exact agreement so the
    // continuation validator accepts a decision recorded after this test window.
    $resolved = CanonicalContinuationRule::resolveWallClock('UTC', $localDate, $localTime, 30, 0);
    $wpdb->update($p . 'booking_request_confirmations', array('starts_at_utc' => $introStart, 'ends_at_utc' => $introEnd), array('booking_request_id' => (int) $prep->booking_request_id));
    $wpdb->update($p . 'canonical_continuation_slot_authorities', array(
        'schedule_timezone' => 'UTC',
        'local_wall_date' => $localDate,
        'local_wall_time' => $localTime,
        'duration_minutes' => 30,
        'buffer_minutes' => 0,
        'starts_at_utc' => $resolved['starts_at_utc'],
        'ends_at_utc' => $resolved['ends_at_utc'],
        'occupied_ends_at_utc' => $resolved['occupied_ends_at_utc'],
    ), array('id' => (int) $prep->slot_authority_id));
    return array('intro_end' => $introEnd, 'slot_start' => $resolved['starts_at_utc']);
};
$studentUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 ORDER BY id DESC LIMIT 1", $studentId));
$assert($studentUser > 0, 'the synthetic Student principal is linked');
$decisionErrors = array();
add_action('dzn_post_intro_feedback_decision_failed', static function ($feedbackId, $message) use (&$decisionErrors): void {
    $decisionErrors[] = (string) $message;
}, 10, 2);
$wpdb->update($p . 'post_intro_preparations', array('state' => 'prepared'), array('id' => $prepId));
$resetCase();
$resetFeedback();
$resetNotifications();
wp_set_current_user($studentUser);

/* 1. Before the final day the worker and portal both stay ineligible. */
$setWindow(2, 72);
$before = PostIntroSurveyWorker::run();
$countBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE notification_kind=%s AND delivery_id=%d", PostIntroSurveyWorker::NOTIFICATION_KIND, $deliveryId));
$assert($countBefore === 0, 'no survey may be enqueued before the final day');
$readBefore = (new PostIntroStudentReadService())->forStudent($studentId);
$assert(!empty($readBefore) && $readBefore[0]['survey_available'] === false, 'the portal must not offer the survey before the final day');

/* 2. The final 24 hours make one invitation eligible; repeats are idempotent. */
$setWindow(5, 12);
$first = PostIntroSurveyWorker::run();
$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}booking_request_student_notifications WHERE notification_kind=%s AND delivery_id=%d", PostIntroSurveyWorker::NOTIFICATION_KIND, $deliveryId));
$assert(count($rows) === 1, 'exactly one survey notification is enqueued');
$notificationId = (int) $rows[0]->id;
$assert($first['enqueued'] === 1, 'the first worker run reports one enqueue');
$second = PostIntroSurveyWorker::run();
$countAfterRepeat = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE notification_kind=%s AND delivery_id=%d", PostIntroSurveyWorker::NOTIFICATION_KIND, $deliveryId));
$assert($countAfterRepeat === 1 && $second['enqueued'] === 0, 'a concurrent/repeated worker run cannot create a duplicate');
$readFinal = (new PostIntroStudentReadService())->forStudent($studentId);
$assert(!empty($readFinal) && $readFinal[0]['survey_available'] === true, 'the portal offers the survey in the final day');

/* 3. Leased dispatch records the portal inbox and hands the email off. */
$sent = array();
$success = static function ($return, $atts) use (&$sent) { $sent[] = (string) ($atts['message'] ?? ''); return true; };
add_filter('pre_wp_mail', $success, 10, 2);
$dispatched = BookingStudentNotificationWorker::dispatch($notificationId);
$assert(($dispatched['state'] ?? '') === 'handed_off', 'a successful hand-off is recorded');
$inbox = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_notifications WHERE event_key=%s AND source_kind=%s AND source_id=%d", PostIntroSurveyWorker::EVENT_KEY, 'post_intro_survey', $prepId));
$assert($inbox === 1, 'one portal inbox row is recorded');
$assert(count($sent) === 1 && str_contains($sent[0], 'در حال پایان است'), 'the final-day email states the hold is ending, not expired');
$assert(str_contains($sent[0], 'survey_url') === false, 'the email must contain the rendered survey URL, not a placeholder');
$repeatRefused = false;
try { BookingStudentNotificationWorker::dispatch($notificationId); } catch (InvalidArgumentException $error) { $repeatRefused = $error->getMessage() === 'Booking notification is not dispatchable'; }
$assert($repeatRefused && count($sent) === 1, 'a handed-off notification is not sent twice');

/* 4. A failed transport produces a retry state; the retry then hands off. */
$wpdb->update($p . 'booking_request_student_notifications', array('state' => 'queued', 'attempt_count' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'lease_token_digest' => null, 'lease_expires_at' => null, 'handed_off_at' => null, 'last_error_code' => null), array('id' => $notificationId));
remove_filter('pre_wp_mail', $success, 10);
$failure = static function ($return) { return false; };
add_filter('pre_wp_mail', $failure, 10, 1);
$failed = BookingStudentNotificationWorker::dispatch($notificationId);
$assert(($failed['state'] ?? '') === 'retryable', 'a refused transport becomes retryable');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM {$p}booking_request_student_notifications WHERE id=%d", $notificationId)) === 1, 'the failed attempt is counted');
remove_filter('pre_wp_mail', $failure, 10);
add_filter('pre_wp_mail', $success, 10, 2);
$wpdb->update($p . 'booking_request_student_notifications', array('next_attempt_at' => gmdate('Y-m-d H:i:s')), array('id' => $notificationId));
$retried = BookingStudentNotificationWorker::dispatch($notificationId);
$assert(($retried['state'] ?? '') === 'handed_off', 'the retry hands off successfully');
$inboxAfterRetry = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_notifications WHERE event_key=%s AND source_id=%d", PostIntroSurveyWorker::EVENT_KEY, $prepId));
$assert($inboxAfterRetry === 1, 'retries never duplicate the portal inbox row');
remove_filter('pre_wp_mail', $success, 10);

/* 5. Late dispatch inside the grace window uses the expired-hold copy. */
$resetNotifications();
$setWindow(8, 12);
$lateRun = PostIntroSurveyWorker::run();
$lateRows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}booking_request_student_notifications WHERE notification_kind=%s AND delivery_id=%d", PostIntroSurveyWorker::NOTIFICATION_KIND, $deliveryId));
$assert(count($lateRows) === 1, 'a late worker run inside the grace window enqueues once');
$lateSent = array();
$lateSuccess = static function ($return, $atts) use (&$lateSent) { $lateSent[] = (string) ($atts['message'] ?? ''); return true; };
add_filter('pre_wp_mail', $lateSuccess, 10, 2);
$lateDispatch = BookingStudentNotificationWorker::dispatch((int) $lateRows[0]->id);
$assert(($lateDispatch['state'] ?? '') === 'handed_off', 'a late notification can still hand off');
$assert(count($lateSent) === 1 && str_contains($lateSent[0], 'به پایان رسیده است'), 'the late email never implies the hold is still active');
remove_filter('pre_wp_mail', $lateSuccess, 10);

/* 6. Payment/decision suppression cancels a pending notification. */
$resetNotifications();
$setWindow(5, 12);
PostIntroSurveyWorker::run();
$pendingId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_student_notifications WHERE notification_kind=%s AND delivery_id=%d ORDER BY id DESC LIMIT 1", PostIntroSurveyWorker::NOTIFICATION_KIND, $deliveryId));
$wpdb->update($p . 'post_intro_preparations', array('state' => 'payment_received'), array('id' => $prepId));
$suppressed = BookingStudentNotificationWorker::dispatch($pendingId);
$assert(($suppressed['state'] ?? '') === 'cancelled', 'a pending survey is cancelled after payment');
$wpdb->update($p . 'post_intro_preparations', array('state' => 'prepared'), array('id' => $prepId));

/* 7. Feedback-only does not create a continuation decision. */
$resetCase(); $resetFeedback(); $resetNotifications();
$setWindow(5, 12);
$feedback = (new PostIntroFeedbackService())->submit($uid, 'schedule', 'عصرها دشوار است', PostIntroFeedbackRule::CHOICE_FEEDBACK_ONLY);
$assert($feedback['created'] === true && $feedback['decision_recorded'] === false, 'feedback-only records feedback and no decision');
$row = (new PostIntroFeedbackRepository())->byPreparation($prepId);
$assert($row !== null && (string) $row->feedback_reason === 'schedule' && (string) $row->feedback_comment === 'عصرها دشوار است', 'feedback reason and comment persist');
$assert((int) $row->contact_requested === 0 && $row->continuation_decision === null, 'feedback-only does not request contact or record a decision');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id)) === 0, 'feedback-only creates no continuation case');
$replay = (new PostIntroFeedbackService())->submit($uid, 'price', '', PostIntroFeedbackRule::CHOICE_FEEDBACK_ONLY);
$assert($replay['created'] === false && (string) (new PostIntroFeedbackRepository())->byPreparation($prepId)->feedback_reason === 'schedule', 'duplicate submissions are idempotent and do not overwrite feedback');

/* 8. Explicit decline records the canonical decision. */
$resetFeedback(); $resetCase(); $resetNotifications();
$decline = (new PostIntroFeedbackService())->submit($uid, 'teacher_fit', '', PostIntroFeedbackRule::CHOICE_DECLINE);
$declineCase = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d LIMIT 1", (int) $prep->intro_lesson_id));
$assert($decline['decision_recorded'] === true && $declineCase !== null && (string) $declineCase->current_decision === CanonicalContinuationRule::DECISION_NOT_CONTINUING, 'explicit decline records not_continuing (' . implode(' | ', $decisionErrors) . ')');

/* 9. Contact request enters the existing contact/different-Teacher workflow. */
$resetFeedback(); $resetCase(); $resetNotifications();
$contact = (new PostIntroFeedbackService())->submit($uid, 'schedule', '', PostIntroFeedbackRule::CHOICE_CONTACT);
$contactCase = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d LIMIT 1", (int) $prep->intro_lesson_id));
$contactIntervention = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id));
$assert($contact['decision_recorded'] === true && $contactCase !== null && (string) $contactCase->current_decision === CanonicalContinuationRule::DECISION_CONTACT_ME, 'contact request records contact_me');
$assert($contactIntervention === 1, 'contact request enters the existing intervention workflow');

/* 10. A newer canonical decision is never overwritten by a duplicate feedback submission. */
$duplicate = (new PostIntroFeedbackService())->submit($uid, 'price', '', PostIntroFeedbackRule::CHOICE_FEEDBACK_ONLY);
$assert($duplicate['created'] === false && (string) (new PostIntroFeedbackRepository())->byPreparation($prepId)->feedback_reason === 'schedule', 'a later duplicate cannot overwrite the existing feedback row');

/* 11. Follow-up CAS rejects a stale staff edit. */
$follow = (new PostIntroFeedbackRepository())->byPreparation($prepId);
$stale = false;
try {
    (new PostIntroFeedbackRepository())->updateFollowUp((int) $follow->id, (int) $follow->feedback_version - 1, array(
        'follow_up_status' => 'resolved', 'assigned_staff_user_id' => null,
        'last_contact_at' => null, 'follow_up_notes' => null, 'outcome' => null,
    ), $studentUser);
} catch (RuntimeException $error) {
    $stale = str_contains($error->getMessage(), 'concurrently');
}
$assert($stale, 'a stale staff follow-up update is refused');

/* 12. A paid/enrolled preparation cannot accept new feedback. */
$wpdb->update($p . 'post_intro_preparations', array('state' => 'payment_received'), array('id' => $prepId));
$blocked = false;
try {
    (new PostIntroFeedbackService())->submit($uid, 'price', '', PostIntroFeedbackRule::CHOICE_FEEDBACK_ONLY);
} catch (InvalidArgumentException $error) {
    $blocked = $error->getMessage() === 'post_intro_survey_unavailable';
}
$assert($blocked, 'a paid/enrolled preparation is excluded from the survey');
$wpdb->update($p . 'post_intro_preparations', array('state' => 'prepared'), array('id' => $prepId));

echo "Post-intro feedback runtime passed\n";
