<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-R1 commercial rule (owner decisions R1-D1…R1-D20).
 *
 * R1 owns the commercial authority that decides what a Student owes, what has actually been
 * settled, how much of the 12-session Term commitment is currently fundable, and which future
 * Teacher capacity is protected for a paid commitment. It owns no provider integration, no Term
 * writer, no Lesson writer, no schedule writer, no notification delivery and no cross-Term
 * recurring-enrolment authority.
 */
final class CommercialRule {
    public const RULE_VERSION='commercial_purchase_v1';
    public const DOMAIN='commercial_v1';
    public const EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference','provider_evidence');
    public const AUTHORITY_BASES=array('adult_principal','guardian_representative','staff_attestation');

    /** The Phase-R1 purchase context is the post-introductory continuation case. */
    public const SOURCE_KINDS=array('continuation_case');

    /** V1 plan shapes. A future plan shape extends this list without redesigning the model. */
    public const PLAN_KINDS=array('full','two_instalments');

    /**
     * Structural V1 two-instalment decomposition: two ordered contiguous tranches covering the
     * whole Term commitment. This is a structural invariant, not a configurable policy.
     */
    public const INSTALMENT_TRANCHES=2;
    public const TRANCHE_ONE_FROM=1;
    public const TRANCHE_ONE_TO=6;
    public const TRANCHE_TWO_FROM=7;
    public const TRANCHE_TWO_TO=12;

    public const OFFER_STATES=array('issued','accepted','expired','withdrawn');
    public const PURCHASE_STATES=array('accepted');
    public const RECONCILIATION_STATES=array('none','capacity_pending','capacity_lost');
    public const ENTITLEMENT_STATES=array('issued','term_bound');
    public const ADJUSTMENT_STATES=array('granted','consumed','revoked','expired');
    public const ADJUSTMENT_EVENT_TYPES=array('granted','consumed','revoked','expired');
    public const CLAIM_STATES=array('active','released','expired');
    public const CLAIM_INTERVAL_STATES=array('protected','satisfied','released');
    public const CLAIM_SOURCE_KINDS=array('q_succession','regular_pattern');
    public const CLAIM_RELEASE_REASONS=array('payment_opportunity_lapsed','commercial_resolution','commitment_cancelled');
    public const EVIDENCE_KINDS=array('attempt','success','failure','refund','mandate');
    public const EVIDENCE_STATES=array('accepted','rejected','unmatched');
    public const PATTERN_STATES=array('active','superseded','retired');
    public const PATTERN_SOURCE_KINDS=array('continuation_slot_authority');
    public const EXCEPTION_STATES=array('open','acknowledged','resolved');
    public const EXCEPTION_SEVERITIES=array('info','warning','error','critical');

    /** Controlled commercial exception classes. */
    public const EXCEPTION_REASONS=array(
        'unmatched_payment_evidence','ambiguous_obligation_attribution','amount_mismatch','currency_mismatch',
        'invalid_or_expired_offer','conflicting_payment_evidence','missing_instalment_predecessor',
        'capacity_handoff_failed','duplicate_entitlement_binding','funding_term_mismatch',
        'late_payment_after_offer_window','capacity_conflict_during_transition','refund_evidence_received',
    );

    /** Informational reconciliation signal; never a payment failure. */
    public const RECONCILIATION_SIGNAL_PREREQUISITE='instalment_prerequisite_unsettled';
    public const RECONCILIATION_SIGNAL_CAPACITY='commercial_capacity_handoff_pending';
    public const RECONCILIATION_SIGNALS=array(self::RECONCILIATION_SIGNAL_PREREQUISITE,self::RECONCILIATION_SIGNAL_CAPACITY);
    /** The controlled commercial exception vocabulary: faults plus informational reconciliation signals. */
    public static function exceptionReason(string $reason):bool{
        return in_array($reason,self::EXCEPTION_REASONS,true)||in_array($reason,self::RECONCILIATION_SIGNALS,true);
    }

    /** Bounded V1 promotion shapes. */
    public const PROMOTION_KINDS=array('percentage','fixed');
    public const ADJUSTMENT_KINDS=array('percentage','fixed');

    /** Immutable pricing-snapshot source types. `credit` is reserved for future stored value. */
    public const ADJUSTMENT_SOURCE_TYPES=array('promotion','account_adjustment','credit');
    public const IMPLEMENTED_ADJUSTMENT_SOURCES=array('promotion','account_adjustment');
    public const CALCULATION_VERSION='commercial_pricing_v1';

    /** Controlled region → currency mapping. Amounts are data; the mapping is structural. */
    public const REGIONS=array('AU'=>'AUD','NZ'=>'NZD','US'=>'USD','CA'=>'CAD','EU'=>'EUR','GB'=>'GBP');
    public const CURRENCIES=array('AUD','NZD','USD','CAD','EUR','GBP');

    /**
     * Class-B runtime commercial policies. Structural invariants (the 12-session Term allocation,
     * the 2-session Term change allowance) are deliberately absent: their single canonical source
     * is the Term authority and the Term's recorded values.
     */
    public const POLICY_KEYS=array(
        'INTRO_BOOKING_HORIZON','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME',
        'PAYMENT_RECOVERY_POLICY','INSTALMENT_DUE_DATE_POLICY',
    );
    public const POLICY_VALUE_TYPES=array('weeks','duration','policy_reference');

    /** Funding effectiveness: an obligation is effective only when it and every lower sequence settled. */
    public const SETTLEMENT_FULL_AMOUNT_REQUIRED=true;

    public static function regionCurrency(string $regionCode):?string{
        $region=strtoupper(trim($regionCode));
        return self::REGIONS[$region]??null;
    }
    public static function currency(string $currency):?string{
        $value=strtoupper(trim($currency));
        return in_array($value,self::CURRENCIES,true)?$value:null;
    }
    /** Tranche session range for one obligation sequence of the V1 two-instalment plan. */
    public static function trancheRange(int $sequence):?array{
        return match($sequence){
            1=>array(self::TRANCHE_ONE_FROM,self::TRANCHE_ONE_TO),
            2=>array(self::TRANCHE_TWO_FROM,self::TRANCHE_TWO_TO),
            default=>null,
        };
    }
    public static function obligationSequences(string $planKind):?int{
        return match($planKind){
            'full'=>1,
            'two_instalments'=>2,
            default=>null,
        };
    }
}
