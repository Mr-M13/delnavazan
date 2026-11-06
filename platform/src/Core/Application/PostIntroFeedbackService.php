<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PostIntroFeedbackRepository,PostIntroRepository};
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Student-owned post-intro feedback submission.
 *
 * Feedback is stored in its own durable record. A continuation choice is optional and only the
 * existing canonical continuation authority records a decision: `contact_me` enters the existing
 * contact-request intervention, `not_continuing` records the explicit decline, and `feedback_only`
 * records no decision at all. A feedback-only submission never touches the reservation hold; an
 * explicit contact/decline decision follows the canonical continuation hold rules unchanged.
 */
final class PostIntroFeedbackService {
    public function __construct(
        private ?PostIntroRepository $postIntro = null,
        private ?PostIntroFeedbackRepository $feedback = null
    ) {
        $this->postIntro ??= new PostIntroRepository();
        $this->feedback ??= new PostIntroFeedbackRepository();
    }

    /**
     * @return array{state:string,feedback_id:int,created:bool,decision:?string,decision_recorded:bool}
     */
    public function submit(string $preparationUid, string $reason, string $comment, string $choice): array {
        if (!PostIntroFeedbackRule::reason($reason)) throw new \InvalidArgumentException('post_intro_survey_reason_required');
        if (!PostIntroFeedbackRule::choice($choice)) throw new \InvalidArgumentException('post_intro_survey_choice_required');
        $preparation = $this->postIntro->preparationByUid($preparationUid);
        if (!$preparation || !in_array((string) $preparation->state, array('prepared', 'checkout_pending', 'expired'), true)) {
            throw new \InvalidArgumentException('post_intro_survey_unavailable');
        }
        $introLessonId = (int) ($preparation->intro_lesson_id ?? 0);
        if ($introLessonId < 1) throw new \InvalidArgumentException('post_intro_survey_unavailable');
        $principal = (new PortalPrincipalResolver())->resolve('student');
        if ((int) ($principal['id'] ?? 0) !== (int) $preparation->student_id) {
            throw new \RuntimeException('Unauthorized');
        }
        $offerId = (int) ($preparation->offer_id ?? 0);
        if ($offerId > 0) {
            $funding = (new CommercialTermFundingService())->obligationStatus($offerId);
            $obligations = (array) ($funding['obligations'] ?? array());
            if ($obligations && (int) ($funding['settled'] ?? 0) === count($obligations)) {
                throw new \InvalidArgumentException('post_intro_survey_unavailable');
            }
        }
        $enrolmentId = (int) ($preparation->enrolment_id ?? 0);
        if ($enrolmentId > 0) {
            $fulfilment = (new PostIntroFulfilmentReadService())->forEnrolment($enrolmentId);
            $fulfilmentState = (string) ($fulfilment['fulfilment_state'] ?? '');
            if (!empty($fulfilment['payment_verified']) || in_array($fulfilmentState, array('completed', 'payment_received', 'provisioning', 'partial', 'awaiting_next_instalment'), true)) {
                throw new \InvalidArgumentException('post_intro_survey_unavailable');
            }
        }
        $existing = $this->feedback->byPreparation((int) $preparation->id);
        if ($existing) {
            // One logical submission per preparation. A second request is a successful replay and
            // never overwrites a newer decision or a staff member's follow-up state.
            return array(
                'state' => 'recorded',
                'feedback_id' => (int) $existing->id,
                'created' => false,
                'decision' => $existing->continuation_decision === null ? null : (string) $existing->continuation_decision,
                'decision_recorded' => $existing->canonical_decision_id !== null,
            );
        }
        if ($this->postIntro->continuationCaseForIntro($introLessonId) !== null) {
            throw new \InvalidArgumentException('post_intro_survey_unavailable');
        }
        $confirmation = $this->postIntro->confirmationByBooking((int) $preparation->booking_request_id);
        $slot = $this->postIntro->slotAuthorityForIntro($introLessonId);
        if (!$confirmation || !$slot) throw new \InvalidArgumentException('post_intro_survey_unavailable');
        $expiresAt = CanonicalContinuationRule::expiresAt((string) $confirmation->ends_at_utc, (string) $slot->starts_at_utc);
        if (strtotime($expiresAt . ' UTC') > time() + 86400) {
            throw new \InvalidArgumentException('post_intro_survey_unavailable');
        }
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Unauthorized');
        $created = $this->feedback->insert(array(
            'preparation_id' => (int) $preparation->id,
            'intro_lesson_id' => $introLessonId,
            'student_id' => (int) $preparation->student_id,
            'teacher_id' => (int) $preparation->teacher_id,
            'course_id' => (int) $preparation->standard_course_id,
            'feedback_reason' => $reason,
            'feedback_comment' => trim($comment) === '' ? null : trim($comment),
            'contact_requested' => $choice === PostIntroFeedbackRule::CHOICE_CONTACT,
            'continuation_choice' => $choice,
            'created_by' => $actor,
        ));
        $feedback = $created['feedback'];
        if (!$feedback || !$created['created']) {
            return array(
                'state' => 'recorded',
                'feedback_id' => $feedback ? (int) $feedback->id : 0,
                'created' => false,
                'decision' => $feedback && $feedback->continuation_decision !== null ? (string) $feedback->continuation_decision : null,
                'decision_recorded' => $feedback && $feedback->canonical_decision_id !== null,
            );
        }
        if ($choice === PostIntroFeedbackRule::CHOICE_FEEDBACK_ONLY) {
            return array('state' => 'recorded', 'feedback_id' => (int) $feedback->id, 'created' => true, 'decision' => null, 'decision_recorded' => false);
        }
        $input = array(
            'ongoing_course_id' => (int) $preparation->standard_course_id,
            'evidence_channel' => 'authenticated_platform',
            'evidence_reference' => 'post-intro-survey:' . $preparationUid,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        );
        try {
            if ($choice === PostIntroFeedbackRule::CHOICE_CONTACT) {
                $decision = $reason === 'teacher_fit'
                    ? (new CanonicalContinuationService())->requestDifferentTeacher($introLessonId, $input, 'post-intro-survey:' . $preparationUid . ':different-teacher')
                    : (new CanonicalContinuationService())->requestContact($introLessonId, $input, 'post-intro-survey:' . $preparationUid . ':contact');
            } else {
                $decision = (new CanonicalContinuationService())->stopContinuation($introLessonId, $input + array('feedback_reason' => $reason), 'post-intro-survey:' . $preparationUid . ':decline');
            }
            $decisionId = (int) ($decision['decision_id'] ?? 0);
            $decisionName = (string) ($decision['decision'] ?? $choice);
            $this->attachWithRetry((int) $feedback->id, $decisionName, $decisionId, $actor);
            return array('state' => 'recorded', 'feedback_id' => (int) $feedback->id, 'created' => true, 'decision' => $decisionName, 'decision_recorded' => true);
        } catch (\Throwable $error) {
            // The feedback record is durable and must never be rolled back because a newer canonical
            // decision exists. The staff follow-up list still shows the student's concern.
            do_action('dzn_post_intro_feedback_decision_failed', (int) $feedback->id, $error->getMessage());
            return array('state' => 'recorded', 'feedback_id' => (int) $feedback->id, 'created' => true, 'decision' => null, 'decision_recorded' => false);
        }
    }

    private function attachWithRetry(int $feedbackId, string $decision, int $decisionId, int $actor): void {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $row = $this->feedback->byId($feedbackId);
            if (!$row) return;
            try {
                $this->feedback->attachDecision($feedbackId, (int) $row->feedback_version, $decision, $decisionId, $actor);
                return;
            } catch (\RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'concurrently') || $attempt === 1) throw $error;
            }
        }
    }
}
