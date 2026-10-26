<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Checkout\StudentCheckoutInitiationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Core\Support\Identifier;
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
    public function continue(string $preparationUid, bool $adultAttested, ?string $planKind = null, ?string $quoteToken = null, bool $instalmentsOptIn = false): array {
        $repository = $this->repository;
        global $wpdb;
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

        if ($preparation->state === 'activated') return array('state' => 'activated', 'redirect_url' => null, 'message' => 'already_activated');
        if ($preparation->state === 'payment_received') return array('state' => 'payment_received', 'redirect_url' => null, 'message' => 'payment_received');
        $confirmation = $repository->confirmationByBooking((int) $preparation->booking_request_id);
        if (!$confirmation) throw new \InvalidArgumentException('post_intro_invitation_not_found');
        if ($confirmation->privacy_erased_at !== null) throw new \InvalidArgumentException('post_intro_invitation_not_found');

        $anyPattern = $repository->activePatternForAnyTeacher($studentId, (int) $preparation->standard_course_id);
        if ($anyPattern && (int) $anyPattern->teacher_id !== (int) $preparation->teacher_id) {
            throw new \RuntimeException('commercial_pattern_teacher_conflict');
        }

        if (!in_array((string) $preparation->state, array('prepared', 'checkout_pending'), true)) {
            throw new \InvalidArgumentException('post_intro_invitation_not_ready');
        }

        // The Student chooses before any offer exists. An existing live offer is immutable and
        // always wins: a later request may only repeat its plan, never silently replace it.
        $existingOffer = null;
        if ((int) ($preparation->offer_id ?? 0) > 0) {
            $existingOffer = $repository->offer((int) $preparation->offer_id);
        }
        if (!$existingOffer && (int) ($preparation->continuation_case_id ?? 0) > 0) {
            $existingOffer = $repository->offerByContinuationCase((int) $preparation->continuation_case_id);
        }
        if ($existingOffer) {
            $existingPlan = (string) $existingOffer->plan_kind;
            $requestedPlan = trim((string) $planKind);
            if ($requestedPlan !== '' && $requestedPlan !== $existingPlan) {
                throw new \InvalidArgumentException('plan_choice_conflict');
            }
            $planKind = $existingPlan;
            $quoteToken = '';
        } else {
            $planKind = trim((string) $planKind);
            if ($planKind === '') $planKind = 'full';
            if (!in_array($planKind, CommercialRule::PLAN_KINDS, true)) {
                throw new \InvalidArgumentException('plan_unavailable');
            }
            if ($planKind === 'two_instalments' && !$instalmentsOptIn) {
                throw new \InvalidArgumentException('plan_unavailable');
            }
            $quoteToken = trim((string) $quoteToken);
            if (!CommercialOfferQuoteDigest::valid($quoteToken)) {
                throw new \InvalidArgumentException('plan_quote_required');
            }
            $quote = (new PostIntroPlanQuoteService())->forPreparation($preparation, $studentId);
            if (!hash_equals((string) $quote['quote_token'], $quoteToken)) {
                throw new \InvalidArgumentException('plan_quote_stale');
            }
            $selectedOption = null;
            foreach ((array) ($quote['options'] ?? array()) as $option) {
                if ((string) ($option['plan_kind'] ?? '') === $planKind) { $selectedOption = $option; break; }
            }
            if ($selectedOption === null) throw new \InvalidArgumentException('plan_unavailable');

            $now = gmdate('Y-m-d H:i:s');
            $choice = $repository->activePlanChoice((int) $preparation->id);
            if ($choice && (string) $choice->plan_kind !== $planKind) {
                $repository->supersedeActivePlanChoice((int) $preparation->id);
                $choice = null;
            }
            if ($choice) {
                // A pre-offer change of mind is allowed; the offer remains the canonical fact.
                $repository->updatePlanChoice((int) $choice->id, array(
                    'quote_digest' => $quoteToken,
                    'quoted_amount_due_minor' => (int) $selectedOption['amount_minor'],
                    'currency' => (string) $quote['currency'],
                    'chosen_at' => $now,
                    'chosen_by' => $studentUserId,
                ));
            } else {
                try {
                    $repository->insertPlanChoice(array(
                        'uid' => Identifier::uid(),
                        'preparation_id' => (int) $preparation->id,
                        'active_slot' => 1,
                        'student_id' => $studentId,
                        'plan_kind' => $planKind,
                        'quote_digest' => $quoteToken,
                        'quoted_amount_due_minor' => (int) $selectedOption['amount_minor'],
                        'currency' => (string) $quote['currency'],
                        'instalment_due_at' => null,
                        'policy_version' => null,
                        'offer_id' => null,
                        'chosen_at' => $now,
                        'chosen_by' => $studentUserId,
                        'created_at' => $now,
                    ));
                } catch (\Throwable $choiceError) {
                    // Two concurrent submissions may race on the one active slot. The winner is
                    // authoritative; a different requested plan is a conflict, not a second offer.
                    $winner = $repository->activePlanChoice((int) $preparation->id);
                    if (!$winner) throw $choiceError;
                    if ((string) $winner->plan_kind !== $planKind) throw new \InvalidArgumentException('plan_choice_conflict');
                }
            }
        }

        $evidenceAt = $repository->claimStudentActionAt((int) $preparation->id, gmdate('Y-m-d H:i:s'));
        $preparation = $repository->preparation((int) $preparation->id) ?? $preparation;
        // A concurrent submission may have issued the offer between the pre-offer choice check and
        // this refreshed read. The persisted offer is canonical: never redirect a conflicting
        // request into another plan's Checkout.
        if ((int) ($preparation->offer_id ?? 0) > 0) {
            $persistedOffer = $repository->offer((int) $preparation->offer_id);
            if (!$persistedOffer || (string) $persistedOffer->plan_kind !== $planKind) {
                throw new \InvalidArgumentException('plan_choice_conflict');
            }
        }

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
                    $evidenceAt,
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
                        $evidenceAt,
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
                    if ((int) $existing->course_id !== (int) $preparation->standard_course_id) throw new \RuntimeException('continuation_course_conflict');
                    $existingReservation = $repository->reservationForCase((int) $existing->id);
                    if (!$existingReservation) throw new \RuntimeException('continuation_reservation_required');
                    $preparation = $this->save((int) $preparation->id, array(
                        'continuation_case_id' => (int) $existing->id,
                        'reservation_id' => (int) $existingReservation->id,
                    ));
                } else {
                    $decision = (new CanonicalContinuationService())->continueWithTeacher(
                        (int) $preparation->intro_lesson_id,
                        array(
                            'ongoing_course_id' => (int) $preparation->standard_course_id,
                            'evidence_channel' => 'authenticated_platform',
                            'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                            'evidence_at' => $evidenceAt,
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

            // A previous invocation may have committed the continuation case but terminated before
            // saving its reservation. Recover the durable reservation instead of failing forever.
            if ($preparation->continuation_case_id && !(int) $preparation->reservation_id) {
                $existingReservation = $repository->reservationForCase((int) $preparation->continuation_case_id);
                if (!$existingReservation) throw new \RuntimeException('continuation_reservation_required');
                $preparation = $this->save((int) $preparation->id, array('reservation_id' => (int) $existingReservation->id));
            }

            $reservation = $preparation->reservation_id ? $repository->reservationForCase((int) $preparation->continuation_case_id) : null;
            if (!$reservation) throw new \RuntimeException('continuation_reservation_required');
            if (!CanonicalContinuationRule::capacityEffective((string) $reservation->state, (string) $reservation->expires_at, gmdate('Y-m-d H:i:s'))) {
                $this->expire($preparation);
                throw new \InvalidArgumentException('post_intro_invitation_expired');
            }

            if (!$preparation->pattern_id) {
                // Reuse only the exact pattern materialised from this continuation's slot
                // authority. A broad Student/Course/Teacher lookup can silently apply an old
                // pattern's recurrence to the newly held slot.
                $existing = $repository->patternForSlotAuthority((int) $preparation->slot_authority_id);
                if ($existing) {
                    $this->assertPatternIdentity($preparation, $existing);
                    $preparation = $this->save((int) $preparation->id, array('pattern_id' => (int) $existing->id));
                } else {
                    $any = $repository->activePatternForAnyTeacher($studentId, (int) $preparation->standard_course_id);
                    if ($any) {
                        if ((int) $any->teacher_id !== (int) $preparation->teacher_id) throw new \RuntimeException('commercial_pattern_teacher_conflict');
                        throw new \RuntimeException('commercial_pattern_exists');
                    }
                    $pattern = PostIntroSystemActor::run(fn() => (new CommercialPatternService())->establish(array(
                        'slot_authority_id' => (int) $preparation->slot_authority_id,
                        'course_id' => (int) $preparation->standard_course_id,
                        'evidence_channel' => 'authenticated_platform',
                        'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                        'evidence_at' => $evidenceAt,
                    ), 'post-intro-pattern-' . (string) $preparation->uid));
                    $preparation = $this->save((int) $preparation->id, array('pattern_id' => (int) $pattern['pattern_id']));
                }
            } else {
                $stored = $repository->pattern((int) $preparation->pattern_id);
                if (!$stored) throw new \RuntimeException('commercial_pattern_conflict');
                $this->assertPatternIdentity($preparation, $stored);
            }

            if (!$preparation->offer_id) {
                $existing = $repository->offerByContinuationCase((int) $preparation->continuation_case_id);
                if ($existing) {
                    if ((string) $existing->plan_kind !== $planKind) throw new \InvalidArgumentException('plan_choice_conflict');
                    $preparation = $this->save((int) $preparation->id, array('offer_id' => (int) $existing->id, 'offer_uid' => (string) $existing->uid));
                } else {
                    // B2b: the second-instalment deadline is derived only when an administrator
                    // has explicitly configured the approved policy. Unset remains no deadline.
                    $instalmentDueAt = null;
                    $instalmentPolicyVersion = null;
                    if ($planKind === 'two_instalments') {
                        $pattern = $repository->pattern((int) $preparation->pattern_id);
                        if (!$pattern) throw new \RuntimeException('commercial_pattern_conflict');
                        $deadline = (new InstalmentDeadlinePolicy())->forPattern($pattern, $evidenceAt);
                        $instalmentDueAt = $deadline['due_at'];
                        $instalmentPolicyVersion = $deadline['policy_version'];
                    }
                    try {
                        $offer = PostIntroSystemActor::run(fn() => (new CommercialOfferService())->issue(array(
                            'continuation_case_id' => (int) $preparation->continuation_case_id,
                            'product_id' => (int) $preparation->product_id,
                            'region_code' => (string) $preparation->region_code,
                            'plan_kind' => $planKind,
                            'instalment_due_at' => $instalmentDueAt,
                            'instalment_due_date_policy_version' => $instalmentPolicyVersion,
                            'quote_subject' => (string) $preparation->uid,
                            'expected_quote_digest' => $quoteToken,
                            'evidence_channel' => 'authenticated_platform',
                            'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                            'evidence_at' => $evidenceAt,
                        ), 'post-intro-offer-' . (string) $preparation->uid . '-' . $planKind));
                    } catch (\Throwable $offerError) {
                        if (trim($offerError->getMessage()) !== 'commercial_offer_exists') throw $offerError;
                        $existing = $repository->offerByContinuationCase((int) $preparation->continuation_case_id);
                        if (!$existing || (string) $existing->plan_kind !== $planKind) throw new \InvalidArgumentException('plan_choice_conflict');
                        $offer = array('offer_id' => (int) $existing->id, 'offer_uid' => (string) $existing->uid);
                    }
                    $preparation = $this->save((int) $preparation->id, array(
                        'offer_id' => (int) $offer['offer_id'],
                        'offer_uid' => (string) $offer['offer_uid'],
                        'state' => 'checkout_pending',
                    ));
                }
            }

            $choice = $repository->activePlanChoice((int) $preparation->id);
            if ($choice && !(int) $choice->offer_id) {
                $changes = array('offer_id' => (int) $preparation->offer_id);
                if ($planKind === 'two_instalments') {
                    $deadline = $repository->instalmentDeadlineForOffer((int) $preparation->offer_id);
                    $changes['instalment_due_at'] = $deadline['due_at'];
                    $changes['policy_version'] = $deadline['policy_version'];
                }
                $repository->updatePlanChoice((int) $choice->id, $changes);
            }

            if (!in_array((string) $preparation->state, array('checkout_pending', 'payment_received', 'activated'), true)) {
                $preparation = $this->save((int) $preparation->id, array(
                    'state' => 'checkout_pending',
                    'exception_code' => null,
                    'exception_detail' => null,
                ));
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
            if ((string) ($checkout['checkout_state'] ?? '') === 'unavailable') {
                // The provider explicitly has no usable session. Do not tell the Student that an
                // ambiguous payment is pending; surface the existing temporary-unavailability copy.
                return array('state' => 'checkout_pending', 'redirect_url' => null, 'message' => 'checkout_temporarily_unavailable');
            }
            return array('state' => 'checkout_pending', 'redirect_url' => null, 'message' => 'checkout_pending');
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            if ($message === 'adult_attestation_required' || $message === 'guardian_required') throw $error;
            if ($message === 'post_intro_invitation_expired') {
                $this->expire($preparation);
                throw $error;
            }
            if (in_array($message, array('plan_choice_conflict', 'plan_quote_stale', 'plan_quote_required', 'plan_unavailable', 'plan_selection_in_progress', 'instalment_due_date_policy_invalid', 'instalment_due_date_schedule_unavailable'), true)) {
                throw $error;
            }
            // A committed idempotent command or a concurrent winner is not a review exception.
            // Leave the preparation actionable so the next Student action converges on the
            // durable result instead of poisoning the invitation until an admin intervenes.
            if ($this->concurrentReplay($error)) {
                $fresh = $this->repository->preparation((int) $preparation->id);
                if ($fresh && (string) $fresh->state === 'payment_received') return array('state' => 'payment_received', 'redirect_url' => null, 'message' => 'payment_received');
                if ($fresh && (string) $fresh->state === 'activated') return array('state' => 'activated', 'redirect_url' => null, 'message' => 'already_activated');
                if ($fresh && (int) $fresh->offer_id > 0) return array('state' => 'checkout_pending', 'redirect_url' => null, 'message' => 'checkout_pending');
                throw $error;
            }
            $code = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'post_intro_continuation_failed';
            $this->repository->updatePreparation((int) $preparation->id, array(
                'state' => 'exception',
                'exception_code' => $code,
                'exception_detail' => $code,
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            throw $error;
        }
    }

    private function concurrentReplay(\Throwable $error): bool {
        if ($error instanceof IdempotencyConflictException) return false;
        $message = strtolower(trim($error->getMessage()));
        foreach (array('already accepted', 'duplicate', 'deadlock', 'lock wait timeout') as $needle) {
            if (str_contains($message, $needle)) return true;
        }
        return false;
    }

    private function assertPatternIdentity(object $preparation, object $pattern): void {
        if ((string) $pattern->state !== 'active'
            || (int) $pattern->student_id !== (int) $preparation->student_id
            || (int) $pattern->course_id !== (int) $preparation->standard_course_id
            || (int) $pattern->source_slot_authority_id !== (int) $preparation->slot_authority_id) {
            throw new \RuntimeException('commercial_pattern_conflict');
        }
        if ((int) $pattern->teacher_id !== (int) $preparation->teacher_id) throw new \RuntimeException('commercial_pattern_teacher_conflict');
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
