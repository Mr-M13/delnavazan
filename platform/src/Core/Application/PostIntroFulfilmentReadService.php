<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialPaymentRepository,PaymentProviderRepository,PostIntroRepository,TeacherAssignmentRepository};

/**
 * Read-only fulfilment projection for one post-intro Enrolment.
 *
 * It deliberately reports the authoritative payment, term, assignment and Lesson facts rather than
 * inferring success from the Stripe redirect or from the existence of an Enrolment. The projection is
 * safe for the Student Portal: it contains no provider reference, digest or payment credential.
 */
final class PostIntroFulfilmentReadService {
    public function __construct(
        private ?PostIntroRepository $postIntro = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?CheckoutSessionRepository $checkouts = null,
        private ?TeacherAssignmentRepository $assignments = null,
        private ?PaymentProviderRepository $provider = null
    ) {
        $this->postIntro ??= new PostIntroRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->checkouts ??= new CheckoutSessionRepository();
        $this->assignments ??= new TeacherAssignmentRepository();
        $this->provider ??= new PaymentProviderRepository();
    }

    /** @return array<string,mixed> */
    public function forEnrolment(int $enrolmentId): array {
        if ($enrolmentId < 1) throw new \InvalidArgumentException('canonical_enrolment_required');
        $enrolment = $this->postIntro->enrolment($enrolmentId);
        if (!$enrolment) throw new \InvalidArgumentException('canonical_enrolment_required');

        $preparation = $this->postIntro->preparationByEnrolment($enrolmentId);
        $offer = null;
        $obligation = null;
        $purchase = null;
        $settlement = null;
        $entitlement = null;
        $job = null;
        $checkout = null;
        if ($preparation && (int) ($preparation->offer_id ?? 0) > 0) {
            $offer = $this->postIntro->offer((int) $preparation->offer_id);
            $obligations = $this->postIntro->obligationsForOffer((int) $preparation->offer_id);
            $obligation = $obligations[0] ?? null;
            $purchase = $this->postIntro->purchaseForOffer((int) $preparation->offer_id);
            if ($obligation) {
                $settlement = $this->payments->settlementForObligation((int) $obligation->id);
                $checkout = $this->checkouts->latestForObligation((int) $obligation->id);
            }
            if ($purchase) {
                $entitlement = $this->postIntro->entitlementForPurchase((int) $purchase->id);
                $job = $this->postIntro->activeJobForPurchase((int) $purchase->id);
            }
        }

        $term = null;
        $termState = 'none';
        try {
            $inspection = (new TermApplicabilityAssessment())->inspectForAuthority($enrolmentId);
            $termState = (string) ($inspection['classification'] ?? 'none');
            foreach ((array) ($inspection['terms'] ?? array()) as $candidate) {
                if ((int) ($candidate->applicable_slot ?? 0) === 1) {
                    $term = $candidate;
                    break;
                }
            }
        } catch (\Throwable) {
            $termState = 'data_integrity_conflict';
        }
        $assignment = $this->assignments->currentForEnrolment($enrolmentId);

        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $lessonCount = $term
            ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='standard' AND archived_at IS NULL", (int) $term->id))
            : 0;
        $expectedLessons = $entitlement
            ? (int) ($entitlement->session_count ?? 0)
            : ($offer ? (int) ($offer->committed_sessions ?? 0) : 0);
        // A two-instalment plan deliberately materialises only the funded tranche first. That is an
        // expected intermediate state, not a partial provisioning failure.
        $effectiveLessons = $offer ? (new CommercialTermFundingService())->effectiveSessions((int) $offer->id) : 0;
        $awaitingNextInstalment = $offer !== null
            && (string) $offer->plan_kind === 'two_instalments'
            && $paymentVerified
            && $effectiveLessons > 0
            && $effectiveLessons < $expectedLessons
            && $lessonCount >= $effectiveLessons;

        $pattern = null;
        if ($preparation && (int) ($preparation->pattern_id ?? 0) > 0) {
            $pattern = $this->postIntro->pattern((int) $preparation->pattern_id);
        }
        if (!$pattern && $preparation && (int) ($preparation->slot_authority_id ?? 0) > 0) {
            $pattern = $this->postIntro->patternForSlotAuthority((int) $preparation->slot_authority_id);
        }
        $scheduleReady = $pattern !== null
            && trim((string) ($pattern->schedule_timezone ?? '')) !== ''
            && trim((string) ($pattern->local_wall_time ?? '')) !== ''
            && (int) ($pattern->weekday ?? 0) >= 1
            && (int) ($pattern->weekday ?? 0) <= 7
            && (int) ($pattern->duration_minutes ?? 0) > 0;

        $paymentVerified = $purchase !== null && $settlement !== null;
        $preparationState = $preparation ? (string) $preparation->state : 'missing';
        $jobState = $job ? (string) $job->state : 'missing';

        if ($preparation === null) {
            $state = 'not_applicable';
        } elseif ($preparationState === 'exception') {
            $state = 'failed';
        } elseif ($preparationState === 'activated' && $term && $assignment && ($expectedLessons < 1 || $lessonCount >= $expectedLessons)) {
            // Canonical provisioning is the Student-visible fulfilment. A later provider
            // projection failure (for example Google credentials) must remain an operator
            // blocker, not erase the funded Term, Assignment and Lessons from the Student Portal.
            $state = 'completed';
        } elseif ($jobState === 'failed') {
            $state = 'failed';
        } elseif ($awaitingNextInstalment) {
            $state = 'awaiting_next_instalment';
        } elseif ($paymentVerified) {
            if (!$term || !$assignment) {
                $state = 'payment_received';
            } elseif ($expectedLessons > 0 && $lessonCount < $expectedLessons) {
                $state = 'partial';
            } else {
                $state = 'provisioning';
            }
        } elseif ($checkout && in_array((string) $checkout->state, array('creating', 'open'), true)) {
            $state = 'awaiting_payment';
        } elseif ($preparationState === 'prepared') {
            $state = 'invited';
        } else {
            $state = 'pending';
        }

        $messageCode = match ($state) {
            'awaiting_payment' => 'enrolment_awaiting_payment_confirmation',
            'payment_received', 'partial', 'provisioning' => 'payment_received_classes_preparing',
            'awaiting_next_instalment' => 'next_instalment_due',
            'failed' => 'schedule_review_required',
            'completed', 'not_applicable' => null,
            default => 'enrolment_processing',
        };

        $blockers = array();
        if (!$paymentVerified) $blockers[] = 'payment_not_verified';
        if (!$paymentVerified && $checkout) $blockers[] = 'checkout_reconciliation_available';
        if (!$scheduleReady) $blockers[] = 'authoritative_schedule_missing';
        if ($paymentVerified && !$term) $blockers[] = 'term_missing';
        if ($paymentVerified && !$assignment) $blockers[] = 'teacher_assignment_missing';
        if ($paymentVerified && $expectedLessons > 0 && $lessonCount < $expectedLessons && $state !== 'awaiting_next_instalment') $blockers[] = 'lessons_missing';
        if (in_array($jobState, array('failed', 'retryable'), true)) $blockers[] = 'activation_job_' . $jobState;
        if ($preparation && trim((string) ($preparation->exception_code ?? '')) !== '') $blockers[] = 'preparation_exception';

        $checkoutOpen = $checkout !== null && in_array((string) $checkout->state, array('creating', 'open'), true);
        $planningBasis = $paymentVerified ? 'verified_payment' : ($checkoutOpen ? 'checkout_reconciliation_required' : 'none');
        $planned = array();
        if (($paymentVerified || $checkoutOpen) && $scheduleReady) {
            if (!$term) $planned[] = 'canonical_term';
            if (!$assignment) $planned[] = 'teacher_assignment';
            if ($state !== 'awaiting_next_instalment' && $expectedLessons > 0 && $lessonCount < $expectedLessons) $planned[] = 'canonical_lessons:' . ($expectedLessons - $lessonCount);
        }

        return array(
            'enrolment_id' => $enrolmentId,
            'student_id' => (int) $enrolment->student_id,
            'teacher_id' => $enrolment->teacher_id === null ? null : (int) $enrolment->teacher_id,
            'course_id' => (int) $enrolment->course_id,
            'enrolment_lifecycle_state' => (string) $enrolment->lifecycle_state,
            'preparation_id' => $preparation ? (int) $preparation->id : 0,
            'preparation_state' => $preparationState,
            'offer_id' => $offer ? (int) $offer->id : 0,
            'obligation_id' => $obligation ? (int) $obligation->id : 0,
            'purchase_id' => $purchase ? (int) $purchase->id : 0,
            'settlement_id' => $settlement ? (int) $settlement->id : 0,
            'settlement_evidence_id' => $settlement ? (int) ($settlement->evidence_id ?? 0) : 0,
            'entitlement_id' => $entitlement ? (int) $entitlement->id : 0,
            'job_id' => $job ? (int) $job->id : 0,
            'job_state' => $jobState,
            'job_last_error_code' => $job && $job->last_error_code !== null ? (string) $job->last_error_code : null,
            'checkout_session_uid' => $checkout ? (string) $checkout->uid : '',
            'checkout_session_state' => $checkout ? (string) $checkout->state : '',
            'checkout_provider_key' => $checkout ? (string) $checkout->provider_key : '',
            'term_id' => $term ? (int) $term->id : 0,
            'term_state' => $termState,
            'assignment_id' => $assignment ? (int) $assignment->id : 0,
            'lesson_count' => $lessonCount,
            'effective_lesson_count' => $effectiveLessons,
            'expected_lesson_count' => $expectedLessons,
            'pattern_id' => $pattern ? (int) $pattern->id : 0,
            'schedule_timezone' => $pattern ? (string) $pattern->schedule_timezone : '',
            'weekday' => $pattern ? (int) $pattern->weekday : 0,
            'local_wall_time' => $pattern ? (string) $pattern->local_wall_time : '',
            'payment_verified' => $paymentVerified,
            'schedule_ready' => $scheduleReady,
            'fulfilment_state' => $state,
            'message_code' => $messageCode,
            'blockers' => $blockers,
            'planning_basis' => $planningBasis,
            'planned' => $planned,
        );
    }

    /** @return list<object> */
    public function webhookRefusals(int $limit = 10): array {
        return $this->provider->recentRefusals($limit);
    }
}
