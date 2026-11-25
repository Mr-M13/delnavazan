<?php
namespace Delnavazan\Platform\Admin;

/**
 * Operator sentences for the machine refusal codes the admin commands can actually reach.
 *
 * A service refusal is a machine key. When the admin layer does not know it, the operator reads
 * "The action could not be completed; no change was saved." and cannot tell which control to fix
 * (audit finding OPS-11). This registry is the one place to add the missing sentences; the admin
 * mappers consult it as a fallback before their generic branch.
 *
 * Add a code only with a sentence that names the control and the next step. The refusal-coverage
 * ratchet (`platform/tests/operator-refusal-coverage-contract.php`) fails when a *new* code has no
 * sentence, and its baseline shrinks as entries are added here.
 */
final class OperatorRefusalCopy {
    /** @var array<string,string> code => one sentence naming the control and the next step */
    public const COPY = array(
        'portal_capability_binding_mismatch'          => 'This portal link is bound to a different person or class. Do not share it; issue a new link from Portal capabilities.',
        'commercial_obligation_required'              => 'There is no payable obligation on this offer yet. Create or review the offer before recording money.',
        'commercial_pattern_conflict'                 => 'An active recurring pattern already exists for this Student and Course. Review the existing pattern before creating another.',
        'canonical_attendance_integrity_conflict'     => 'The attendance record fails its integrity check. Review the case in Attendance review; nothing was changed.',
        'commercial_pattern_teacher_conflict'         => 'This Student already has an active pattern with a different Teacher. Resolve the existing pattern first.',
        'commercial_offer_required'                   => 'This action needs a live commercial offer. Issue the offer, then repeat the action.',
        'intro_booking_not_found'                     => 'No introductory booking exists for this request. Confirm the intro first.',
        'schedule_version_conflict'                   => 'The class schedule changed while you were editing. Reload and review the current version.',
        'canonical_attendance_case_required'          => 'This action needs an attendance case. Open the class and record the evidence first.',
        'canonical_lesson_integrity_conflict'         => 'The lesson record fails its integrity check. Review the class history; nothing was changed.',
        'commercial_offer_integrity_conflict'         => 'The commercial offer fails its integrity check. Review the offer before recording or refunding money.',
        'student_identity_unresolved'                 => 'The Student identity is not resolved. Resolve it before this action.',
        'intro_booking_unavailable'                   => 'The introductory booking is no longer available (cancelled, erased or superseded). Start a new request.',
        'continuation_slot_wall_clock_invalid'        => 'The continuation slot is not a valid local time in that timezone. Choose a different time.',
        'teacher_unavailable'                         => 'The Teacher is not available at that time. Choose a covered time, or record an availability override.',
        'teacher_not_available'                       => 'The Teacher is not available at that time. Choose another time.',
        'promotion_not_found'                         => 'That promotion code does not exist. Check the code and retry.',
        'promotion_offer_unavailable'                 => 'The promotion cannot apply to this offer. Review the offer and the promotion rules.',
        'promotion_already_applied'                   => 'This promotion is already applied to the offer. No second application was made.',
        'promotion_offer_superseded'                  => 'The offer this promotion belonged to was superseded. Apply the promotion to the current offer.',
        'manual_refund_evidence_required'             => 'Record the refund reference before saving the refund.',
        'manual_refund_obligation_sequence_invalid'   => 'Choose the obligation the refund belongs to.',
        'post_intro_proposal_missing'                 => 'The continuation proposal is missing. Rebuild the continuation before this action.',
        'post_intro_provisional_acceptance_missing'   => 'The Student has not accepted provisionally yet. Wait for acceptance, or record the agreement.',
        'post_intro_preparation_missing'              => 'The continuation preparation is missing. Record the introductory stage first.',
        'intro_course_unresolved'                     => 'The introductory Course for this instrument is not configured. Fix the Catalogue and retry.',
        'duplicate_cutover_instant'                   => 'That cutover instant is already recorded. Choose a later instant.',
        'expected_case_version_required'              => 'Reload the case and retry; the version is required to record this decision.',
        'stale_case_version'                          => 'The case changed while you were deciding. Reload and review the current version.',
        'delivery_outcome_exists'                     => 'A delivery outcome is already recorded for this class. Use Correct or Dispute instead of recording again.',
        'schedule_required_for_delivery_outcome'      => 'Schedule the class before recording its delivery outcome.',
        'lesson_not_completed_for_reconciliation'     => 'Only a completed class can be reconciled. Record the outcome first.',
        'completion_event_missing'                    => 'The completion event is missing, so this class cannot be reconciled. Review the class history.',
        'academy_obligation_cannot_be_removed'        => 'An academy obligation already exists for this class and cannot be removed here. Review it in Finance.',
        'canonical_delivery_integrity_conflict'       => 'The delivery record fails its integrity check. Review the class history; nothing was changed.',
        'teacher_timezone_invalid'                    => 'The Teacher timezone is not a valid IANA zone. Correct the Teacher profile first.',
        'instalment_due_date_policy_invalid'          => 'The instalment due-date policy value is not supported. Choose a supported policy.',
        'manual_payment_received_before_offer'        => 'That payment date is before the offer was issued. Check the receipt date.',
        'manual_payment_already_settled'              => 'That obligation is already settled. Review the payment instead of recording it again.',
        'manual_payment_amount_required'              => 'The obligation has no payable amount. Review the offer before recording a payment.',
    );

    /** The sentence for a refusal code, or null when this registry does not own it. */
    public static function sentence( string $code ): ?string {
        $code = trim( $code );
        return self::COPY[ $code ] ?? null;
    }
}
