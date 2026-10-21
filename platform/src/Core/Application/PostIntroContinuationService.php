<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Checkout\StudentCheckoutInitiationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Student-owned "complete payment and confirm enrolment" action.
 *
 * The Student principal is only used for the authorities that actually belong to the Student
 * (the continuation decision and hosted Checkout). Canonical acceptance, conversion and
 * commercial issuance run under the dedicated automation principal, while the final acceptance
 * still names the real Student WordPress principal as the accepting person.
 */
final class PostIntroContinuationService {
    public function __construct(private ?PostIntroRepository $repository = null) {
        $this->repository = $repository ?? new PostIntroRepository();
    }

    /** @return array{state:string,redirect_url:?string,message:string} */
    public function continue(string $preparationUid, bool $adultAttested): array {
        $repository = $this->repository;
        $preparation = $repository->preparationByUid($preparationUid);
        if (!$preparation) throw new \InvalidArgumentException('post_intro_invitation_not_found');
        if ($preparation->exception_code === 'continuation_reservation_expired' || $preparation->state === 'expired') {
            throw new \InvalidArgumentException('post_intro_invitation_expired');
        }

        $principal = (new PortalPrincipalResolver())->resolve('student');
        $studentId = (int) $principal['id'];
        if ((int) $preparation->student_id !== $studentId) throw new \RuntimeException('Unauthorized');
        $studentUserId = (int) get_current_user_id();
        if ($studentUserId < 1) throw new \RuntimeException('Unauthorized');

        $anyPattern = $repository->activePatternForAnyTeacher($studentId, (int) $preparation->standard_course_id);
        if ($anyPattern && (int) $anyPattern->teacher_id !== (int) $preparation->teacher_id) {
            throw new \RuntimeException('commercial_pattern_teacher_conflict');
        }

        if ($preparation->state === 'activated') return array('state' => 'activated', 'redirect_url' => null, 'message' => 'already_activated');
        if ($preparation->state === 'payment_received') return array('state' => 'payment_received', 'redirect_url' => null, 'message' => 'payment_received');
        if (!in_array((string) $preparation->state, array('prepared', 'checkout_pending', 'exception'), true)) {
            throw new \InvalidArgumentException('post_intro_invitation_not_ready');
        }

        $confirmation = $repository->confirmationByBooking((int) $preparation->booking_request_id);
        if (!$confirmation) throw new \InvalidArgumentException('post_intro_invitation_not_found');

        try {
            $this->assertAdultCapacity($preparation, $confirmation, $studentId, $adultAttested);

            $proposalUids = $repository->proposalUids((int) $preparation->proposal_version_id);
            if (!$proposalUids) throw new \RuntimeException('post_intro_proposal_missing');
            $subjectRef = 'booking_request:' . (int) $preparation->booking_request_id;

            if (!$preparation->provisional_event_id) {
                $event = PostIntroSystemActor::run(fn() => (new ProposalAcceptanceService())->record(
                    (string) $proposalUids->family_uid,
                    (string) $proposalUids->option_uid,
                    (int) $proposalUids->version_number,
                    $subjectRef,
                    'authenticated_platform',
                    gmdate('Y-m-d H:i:s'),
                    'post-intro-provisional-' . (string) $preparation->uid
                ));
                $preparation = $this->save((int) $preparation->id, array('provisional_event_id' => (int) $event['event_id']));
            }

            if (!$preparation->arrangement_id) {
                $existing = $repository->arrangementForFamily((int) $preparation->proposal_family_id);
                if ($existing) {
                    $preparation = $this->save((int) $preparation->id, array('arrangement_id' => (int) $existing->id));
                } else {
                    $provisional = $repository->provisionalEvent((int) $preparation->provisional_event_id);
                    if (!$provisional) throw new \RuntimeException('post_intro_provisional_acceptance_missing');
                    $accepted = PostIntroSystemActor::run(fn() => (new FinalAcceptanceService())->accept(
                        (int) $preparation->booking_request_id,
                        (int) $preparation->coordination_case_id,
                        (string) $proposalUids->family_uid,
                        (string) $proposalUids->option_uid,
                        (int) $proposalUids->version_number,
                        (string) $provisional->uid,
                        $studentId,
                        $studentUserId,
                        'affirmed',
                        'authenticated_platform',
                        gmdate('Y-m-d H:i:s'),
                        'post-intro-final-' . (string) $preparation->uid
                    ));
                    $preparation = $this->save((int) $preparation->id, array('arrangement_id' => (int) $accepted['arrangement_id']));
                }
            }

            if (!$preparation->enrolment_id) {
                $existing = $repository->enrolmentForArrangement((int) $preparation->arrangement_id);
                if ($existing) {
                    $preparation = $this->save((int) $preparation->id, array('enrolment_id' => (int) $existing->id));
                } else {
                    $converted = PostIntroSystemActor::run(fn() => (new EnrolmentConversionService())->convert(
                        (int) $preparation->arrangement_id,
                        'post-intro-conversion-' . (string) $preparation->uid
                    ));
                    $preparation = $this->save((int) $preparation->id, array('enrolment_id' => (int) $converted['enrolment_id']));
                }
            }

            if (!$preparation->continuation_case_id) {
                $existing = $repository->continuationCaseForIntro((int) $preparation->intro_lesson_id);
                if ($existing) {
                    $preparation = $this->save((int) $preparation->id, array('continuation_case_id' => (int) $existing->id));
                } else {
                    $decision = (new CanonicalContinuationService())->continueWithTeacher(
                        (int) $preparation->intro_lesson_id,
                        array(
                            'ongoing_course_id' => (int) $preparation->standard_course_id,
                            'evidence_channel' => 'authenticated_platform',
                            'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                            'evidence_at' => gmdate('Y-m-d H:i:s'),
                        ),
                        'post-intro-continuation-' . (string) $preparation->uid
                    );
                    $reservation = $decision['reservation'] ?? null;
                    if (!is_array($reservation) || empty($reservation['reservation_id'])) throw new \RuntimeException('continuation_reservation_required');
                    $preparation = $this->save((int) $preparation->id, array(
                        'continuation_case_id' => (int) $decision['case_id'],
                        'reservation_id' => (int) $reservation['reservation_id'],
                    ));
                }
            }

            $reservation = $preparation->reservation_id ? $repository->reservationForCase((int) $preparation->continuation_case_id) : null;
            if (!$reservation) throw new \RuntimeException('continuation_reservation_required');
            if (!CanonicalContinuationRule::capacityEffective((string) $reservation->state, (string) $reservation->expires_at, gmdate('Y-m-d H:i:s'))) {
                $this->expire($preparation);
                throw new \InvalidArgumentException('post_intro_invitation_expired');
            }

            if (!$preparation->pattern_id) {
                $existing = $repository->activePatternFor($studentId, (int) $preparation->standard_course_id, (int) $preparation->teacher_id);
                if (!$existing) {
                    $any = $repository->activePatternForAnyTeacher($studentId, (int) $preparation->standard_course_id);
                    if ($any && (int) $any->teacher_id !== (int) $preparation->teacher_id) throw new \RuntimeException('commercial_pattern_teacher_conflict');
                }
                if ($existing) {
                    $preparation = $this->save((int) $preparation->id, array('pattern_id' => (int) $existing->id));
                } else {
                    $pattern = PostIntroSystemActor::run(fn() => (new CommercialPatternService())->establish(array(
                        'slot_authority_id' => (int) $preparation->slot_authority_id,
                        'course_id' => (int) $preparation->standard_course_id,
                        'evidence_channel' => 'authenticated_platform',
                        'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                        'evidence_at' => gmdate('Y-m-d H:i:s'),
                    ), 'post-intro-pattern-' . (string) $preparation->uid));
                    $preparation = $this->save((int) $preparation->id, array('pattern_id' => (int) $pattern['pattern_id']));
                }
            }

            if (!$preparation->offer_id) {
                $existing = $repository->offerByContinuationCase((int) $preparation->continuation_case_id);
                if ($existing) {
                    $preparation = $this->save((int) $preparation->id, array('offer_id' => (int) $existing->id, 'offer_uid' => (string) $existing->uid));
                } else {
                    $offer = PostIntroSystemActor::run(fn() => (new CommercialOfferService())->issue(array(
                        'continuation_case_id' => (int) $preparation->continuation_case_id,
                        'product_id' => (int) $preparation->product_id,
                        'region_code' => (string) $preparation->region_code,
                        'plan_kind' => 'full',
                        'evidence_channel' => 'authenticated_platform',
                        'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                        'evidence_at' => gmdate('Y-m-d H:i:s'),
                    ), 'post-intro-offer-' . (string) $preparation->uid));
                    $preparation = $this->save((int) $preparation->id, array(
                        'offer_id' => (int) $offer['offer_id'],
                        'offer_uid' => (string) $offer['offer_uid'],
                        'state' => 'checkout_pending',
                    ));
                }
            }

            if (!in_array((string) $preparation->state, array('checkout_pending', 'payment_received', 'activated'), true)) {
                $preparation = $this->save((int) $preparation->id, array('state' => 'checkout_pending'));
            }

            $obligations = $repository->obligationsForOffer((int) $preparation->offer_id);
            $first = null;
            foreach ($obligations as $obligation) {
                if ((int) $obligation->obligation_sequence === 1) { $first = $obligation; break; }
            }
            $first ??= $obligations[0] ?? null;
            if (!$first || trim((string) $first->uid) === '') throw new \RuntimeException('commercial_obligation_missing');

            try {
                $checkout = (new StudentCheckoutInitiationService())->initiate(array('obligation_uid' => (string) $first->uid));
            } catch (\Throwable $checkoutError) {
                if (trim($checkoutError->getMessage()) === 'checkout_unavailable') {
                    return array('state' => 'checkout_pending', 'redirect_url' => null, 'message' => 'checkout_temporarily_unavailable');
                }
                throw $checkoutError;
            }
            if (!empty($checkout['redirect_url'])) {
                return array('state' => 'checkout_pending', 'redirect_url' => (string) $checkout['redirect_url'], 'message' => 'redirect');
            }
            return array('state' => 'checkout_pending', 'redirect_url' => null, 'message' => 'checkout_pending');
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            if ($message === 'adult_attestation_required' || $message === 'guardian_required') throw $error;
            if ($message === 'post_intro_invitation_expired') {
                $this->expire($preparation);
                throw $error;
            }
            $this->repository->updatePreparation((int) $preparation->id, array(
                'state' => 'exception',
                'exception_code' => preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'post_intro_continuation_failed',
                'exception_detail' => mb_substr($error->getMessage(), 0, 500),
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            throw $error;
        }
    }

    private function assertAdultCapacity(object $preparation, object $confirmation, int $studentId, bool $adultAttested): void {
        $capacity = $this->repository->studentCapacity($studentId);
        $classification = $capacity ? (string) $capacity->classification : 'unknown';
        if ($classification === 'minor') throw new \InvalidArgumentException('guardian_required');
        if ($classification === 'adult') return;
        $attested = $adultAttested || (int) ($confirmation->adult_self_attested ?? 0) === 1;
        if (!$attested) throw new \InvalidArgumentException('adult_attestation_required');
        PostIntroSystemActor::run(fn(int $actor) => (new StudentAcceptanceAuthorityService())->classify(
            $studentId,
            'adult',
            'self_attestation',
            'authenticated_platform',
            gmdate('Y-m-d H:i:s'),
            $actor
        ));
        $this->repository->snapshotAdultAttestation((int) $preparation->booking_request_id, true);
    }

    private function expire(object $preparation): void {
        $this->repository->updatePreparation((int) $preparation->id, array(
            'state' => 'expired',
            'exception_code' => 'continuation_reservation_expired',
            'exception_detail' => 'The first regular slot hold expired before payment was completed.',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    private function save(int $id, array $changes): object {
        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->repository->updatePreparation($id, $changes);
        $row = $this->repository->preparation($id);
        if (!$row) throw new \RuntimeException('post_intro_preparation_missing');
        return $row;
    }
}
