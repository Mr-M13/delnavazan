<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\{PostIntroFeedbackRepository,PostIntroRepository};

/**
 * Last-day post-intro follow-up enqueue.
 *
 * This worker never sends a message. It reuses the existing durable
 * `booking_request_student_notifications` queue so the established leased dispatcher owns email
 * retry, hand-off status and portal inbox recording. It only creates one logical survey invitation
 * for an eligible preparation inside the final-day window.
 *
 * Grace: a worker that runs late still may enqueue for up to seven days after the frozen hold
 * expiry. After that the invitation is never sent, so a survey cannot arrive indefinitely later.
 */
final class PostIntroSurveyWorker {
    public const HOOK = 'dzn_post_intro_survey_dispatch';
    public const EVENT_KEY = 'post_intro.survey.student';
    public const NOTIFICATION_KIND = 'post_intro_survey';
    private const BATCH = 50;
    private const GRACE_SECONDS = 7 * 86400;

    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'ensureScheduled'));
    }

    public static function ensureScheduled(): void {
        if (!self::schedulingEnabled()) return;
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 300, 'daily', self::HOOK);
    }

    /** @return array{examined:int,enqueued:int,skipped:int,errors:int} */
    public static function run(): array {
        global $wpdb;
        $result = array('examined' => 0, 'enqueued' => 0, 'skipped' => 0, 'errors' => 0);
        if (!is_object($wpdb)) return $result;
        $repository = new PostIntroRepository();
        $rows = self::candidates($wpdb, $wpdb->prefix . 'dzn_');
        $now = time();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        foreach ($rows as $row) {
            $result['examined']++;
            try {
                $expiresAt = CanonicalContinuationRule::expiresAt((string) $row->intro_end_utc, (string) $row->slot_start_utc);
                $expires = strtotime($expiresAt . ' UTC');
                if ($expires === false || $expires > $now + 86400 || $expires < $now - self::GRACE_SECONDS) {
                    $result['skipped']++;
                    continue;
                }
                $repository->insertNotificationIfMissing((int) $row->delivery_id, (int) $row->confirmation_id, self::NOTIFICATION_KIND, $stamp);
                $result['enqueued']++;
            } catch (\Throwable $error) {
                $result['errors']++;
                do_action('dzn_post_intro_survey_enqueue_failed', (int) ($row->id ?? 0), get_class($error), (string) $error->getMessage());
            }
        }
        return $result;
    }

    /**
     * Re-check eligibility at dispatch time.
     *
     * A queued invitation is suppressed when the Student paid, enrolled, made any continuation
     * decision, or left the bounded final-day/grace window. This makes a pending welcome message
     * unable to arrive after the business fact it was asking about has already resolved.
     */
    public static function eligibleForDispatch(object $row): bool {
        $preparationId = (int) ($row->preparation_id ?? 0);
        $introLessonId = (int) ($row->intro_lesson_id ?? 0);
        if ($preparationId < 1 || $introLessonId < 1) return false;
        $repository = new PostIntroRepository();
        $preparation = $repository->preparation($preparationId);
        if (!$preparation || !in_array((string) $preparation->state, array('prepared', 'checkout_pending', 'expired'), true)) return false;
        if ($repository->continuationCaseForIntro($introLessonId) !== null) return false;
        if ((new PostIntroFeedbackRepository())->byPreparation($preparationId) !== null) return false;
        $offerId = (int) ($preparation->offer_id ?? 0);
        if ($offerId > 0) {
            $funding = (new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->obligationStatus($offerId);
            $obligations = (array) ($funding['obligations'] ?? array());
            if ($obligations && (int) ($funding['settled'] ?? 0) === count($obligations)) return false;
        }
        $enrolmentId = (int) ($preparation->enrolment_id ?? 0);
        if ($enrolmentId > 0) {
            try {
                $fulfilment = (new \Delnavazan\Platform\Core\Application\PostIntroFulfilmentReadService())->forEnrolment($enrolmentId);
            } catch (\Throwable) {
                return false;
            }
            $fulfilmentState = (string) ($fulfilment['fulfilment_state'] ?? '');
            if (!empty($fulfilment['payment_verified']) || in_array($fulfilmentState, array('completed', 'payment_received', 'provisioning', 'partial', 'awaiting_next_instalment'), true)) return false;
        }
        $confirmation = $repository->confirmationByBooking((int) $preparation->booking_request_id);
        $slot = $repository->slotAuthorityForIntro($introLessonId);
        if (!$confirmation || !$slot) return false;
        try {
            $expiresAt = CanonicalContinuationRule::expiresAt((string) $confirmation->ends_at_utc, (string) $slot->starts_at_utc);
        } catch (\Throwable) {
            return false;
        }
        $expires = strtotime($expiresAt . ' UTC');
        $now = time();
        return $expires !== false && $expires <= $now + 86400 && $expires >= $now - self::GRACE_SECONDS;
    }

    /** @return list<object> */
    private static function candidates(object $wpdb, string $prefix): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT preparation.id,preparation.uid,preparation.state,
                    confirmation.id confirmation_id,confirmation.delivery_id,
                    confirmation.ends_at_utc intro_end_utc,slot.starts_at_utc slot_start_utc
             FROM {$prefix}post_intro_preparations preparation
             INNER JOIN {$prefix}students student ON student.id=preparation.student_id
             INNER JOIN {$prefix}booking_request_confirmations confirmation ON confirmation.booking_request_id=preparation.booking_request_id
             INNER JOIN {$prefix}canonical_continuation_slot_authorities slot ON slot.id=preparation.slot_authority_id
             WHERE preparation.state IN ('prepared','checkout_pending','expired')
               AND student.status='active' AND student.archived_at IS NULL
               AND confirmation.ends_at_utc<=%s
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}canonical_continuation_cases continuation
                    WHERE continuation.intro_lesson_id=preparation.intro_lesson_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}booking_request_student_notifications notification
                    WHERE notification.delivery_id=confirmation.delivery_id
                      AND notification.notification_kind=%s
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}post_intro_feedback feedback
                    WHERE feedback.preparation_id=preparation.id
               )
             ORDER BY preparation.id DESC
             LIMIT %d",
            gmdate('Y-m-d H:i:s', time() - 3600),
            self::NOTIFICATION_KIND,
            self::BATCH
        ));
        return is_array($rows) ? $rows : array();
    }

    private static function schedulingEnabled(): bool {
        if (!function_exists('wp_get_environment_type') || !function_exists('apply_filters')) return false;
        $default = in_array(wp_get_environment_type(), array('staging', 'production'), true);
        return (bool) apply_filters('dzn_post_intro_survey_scheduling_enabled', $default);
    }
}
