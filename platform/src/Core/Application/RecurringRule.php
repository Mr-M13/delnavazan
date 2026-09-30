<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-R2 recurring-enrolment rule (owner decisions recorded in the R2 contract).
 *
 * R2 is an orchestration and cross-Term authority layer above R1. It records recurring
 * enrolment, next-Term renewal cycles, provider-neutral collection intents, recovery/lapse
 * representation, provider-neutral refund/reversal review and continuous cross-Term protection.
 * It never reimplements R1 pricing, acceptance, funding derivation, protected claims or Term
 * binding, performs no live charge, delivers no notification and invents no unresolved policy.
 */
final class RecurringRule {
    public const RULE_VERSION='recurring_enrolment_v1';
    public const DOMAIN='recurring_v1';
    public const EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference','provider_evidence');

    /** Recurring Enrolment aggregate states. */
    public const RECURRING_STATES=array('active','suspended','closed');
    public const COLLECTION_MODES=array('manual','automatic');
    public const RECURRING_EVENT_TYPES=array('established','collection_mode_changed','suspended','resumed','closed');

    /** Renewal Cycle states. */
    public const CYCLE_STATES=array('pending','guarantee_protected','payment_required','collected','term_bound','closed','lapsed','cancelled');
    /** The renewal-cycle states that may still carry new cross-Term activity; `lapsed`, `cancelled`
     * and `closed` are terminal and are never reopened by a later recurring record. */
    public const CYCLE_LIVE_STATES=array('pending','guarantee_protected','payment_required','collected','term_bound');
    public const CYCLE_EVENT_TYPES=array('opened','guarantee_protected','payment_required','collected','term_bound','closed','lapsed','cancelled');

    /** Collection Intent states and kinds. */
    public const COLLECTION_INTENT_STATES=array('pending','submitted','confirmed','failed','recovered','cancelled');
    public const COLLECTION_KINDS=array('manual_payment_required','automatic_charge');
    public const COLLECTION_INTENT_EVENT_TYPES=array('opened','submitted','confirmed','failed','recovered','cancelled');

    /** Recovery Case states. */
    public const RECOVERY_STATES=array('open','recovering','recovered','lapsed');
    public const RECOVERY_EVENT_TYPES=array('opened','attempt_recorded','recovered','lapsed');

    /** Refund/Reversal review states and kinds. */
    public const REFUND_REVIEW_STATES=array('open','review_required','resolved','dismissed');
    public const REFUND_REVIEW_KINDS=array('refund','reversal');
    public const REFUND_REVIEW_EVENT_TYPES=array('opened','review_required','resolved','dismissed');

    /** Continuous cross-Term protection states. */
    public const PROTECTION_STATES=array('active','released','lapsed');
    public const PROTECTION_EVENT_TYPES=array('established','extended','released','lapsed');

    /**
     * The collection intent kind a cycle's *frozen* collection mode authorises.
     *
     * A cycle snapshots the mode of its recurring enrolment and never rewrites it, so the kind of the
     * intent that collects it is derived from that frozen mode rather than accepted from a caller.
     */
    public const MODE_INTENT_KINDS=array('manual'=>'manual_payment_required','automatic'=>'automatic_charge');

    /**
     * The R1 payment-evidence kinds that can authorise a refund/reversal review case.
     *
     * R1 records exactly one attributable non-settlement kind today (`refund`); a *reversal* has no
     * authoritative R1 representation, so R2 refuses to represent one until R1 provides it instead of
     * recording a caller-asserted reversal over ordinary payment evidence.
     */
    public const REVIEW_EVIDENCE_KINDS=array('refund');

    /** The event types that legitimately record an append-only event without changing state. */
    public const SAME_STATE_EVENT_TYPES=array('collection_mode_changed','extended','attempt_recorded');

    /**
     * The event types that record an append-only fact *without* advancing the aggregate version.
     *
     * `extended` is the only such event: it re-affirms a continuous protection and appends history, but
     * it changes neither the protection's state nor its recorded version. Every other event in the phase
     * advances the owning aggregate's version exactly once, which is what lets a read model prove the
     * version against the history instead of trusting a mutable counter.
     */
    public const VERSION_NEUTRAL_EVENT_TYPES=array('extended');

    /**
     * The locked legal-transition table of every R2 aggregate: one `from|to` entry per legal step, with
     * a null `from_state` spelled as the empty string. A read model proves a stored aggregate against
     * this table (plus contiguity, the final event and the recorded version), so a row rewritten without
     * its history — or a history rewritten without its row — is never returned as authority.
     */
    public const AGGREGATE_TRANSITIONS=array(
        'recurring_enrolment'=>array(
            '|active','active|suspended','suspended|active','active|closed','suspended|closed',
            'active|active','suspended|suspended',
        ),
        'renewal_cycle'=>array(
            '|pending','pending|guarantee_protected','pending|payment_required','guarantee_protected|payment_required',
            'payment_required|collected','collected|term_bound','term_bound|closed',
            'pending|lapsed','guarantee_protected|lapsed','payment_required|lapsed','collected|lapsed',
            'pending|cancelled','guarantee_protected|cancelled','payment_required|cancelled','collected|cancelled',
        ),
        'collection_intent'=>array(
            '|pending','pending|submitted','submitted|confirmed','submitted|failed',
            'failed|recovered','pending|cancelled','failed|cancelled',
        ),
        'recovery_case'=>array(
            '|open','open|recovering','recovering|recovering','open|recovered','recovering|recovered',
            'open|lapsed','recovering|lapsed',
        ),
        'refund_review'=>array(
            '|open','open|review_required','review_required|resolved','open|dismissed','review_required|dismissed',
        ),
        'recurring_protection'=>array('|active','active|active','active|released','active|lapsed'),
    );

    /**
     * The event type that may record each legal transition of each R2 aggregate.
     *
     * An append-only event audits *which fact happened*, not only that some state changed, so a read
     * model proves the event type against the transition it claims as well as the transition against
     * the locked table. A valid vocabulary member attached to a transition it never recorded — a
     * `resumed` event that does not resume, a `guarantee_protected` event that protects nothing — is a
     * rewrite of the audited history rather than an alternative spelling of it, and is refused.
     *
     * `recovering|recovering` records a repeated recovery attempt: §5.4 keeps attempts as append-only
     * events precisely because there is no mutable attempt counter, so a second attempt on a case that
     * is already recovering is a legal same-state append and stays readable.
     */
    public const AGGREGATE_EVENT_TRANSITIONS=array(
        'recurring_enrolment'=>array(
            'established'=>array('|active'),
            'collection_mode_changed'=>array('active|active','suspended|suspended'),
            'suspended'=>array('active|suspended'),
            'resumed'=>array('suspended|active'),
            'closed'=>array('active|closed','suspended|closed'),
        ),
        'renewal_cycle'=>array(
            'opened'=>array('|pending'),
            'guarantee_protected'=>array('pending|guarantee_protected'),
            'payment_required'=>array('pending|payment_required','guarantee_protected|payment_required'),
            'collected'=>array('payment_required|collected'),
            'term_bound'=>array('collected|term_bound'),
            'closed'=>array('term_bound|closed'),
            'lapsed'=>array('pending|lapsed','guarantee_protected|lapsed','payment_required|lapsed','collected|lapsed'),
            'cancelled'=>array('pending|cancelled','guarantee_protected|cancelled','payment_required|cancelled','collected|cancelled'),
        ),
        'collection_intent'=>array(
            'opened'=>array('|pending'),
            'submitted'=>array('pending|submitted'),
            'confirmed'=>array('submitted|confirmed'),
            'failed'=>array('submitted|failed'),
            'recovered'=>array('failed|recovered'),
            'cancelled'=>array('pending|cancelled','failed|cancelled'),
        ),
        'recovery_case'=>array(
            'opened'=>array('|open'),
            'attempt_recorded'=>array('open|recovering','recovering|recovering'),
            'recovered'=>array('open|recovered','recovering|recovered'),
            'lapsed'=>array('open|lapsed','recovering|lapsed'),
        ),
        'refund_review'=>array(
            'opened'=>array('|open'),
            'review_required'=>array('open|review_required'),
            'resolved'=>array('review_required|resolved'),
            'dismissed'=>array('open|dismissed','review_required|dismissed'),
        ),
        'recurring_protection'=>array(
            'established'=>array('|active'),
            'extended'=>array('active|active'),
            'released'=>array('active|released'),
            'lapsed'=>array('active|lapsed'),
        ),
    );

    /** The states, legal transitions and event types of one R2 aggregate. */
    public static function aggregateStates(string $aggregate):array{
        return match($aggregate){
            'recurring_enrolment'=>self::RECURRING_STATES,
            'renewal_cycle'=>self::CYCLE_STATES,
            'collection_intent'=>self::COLLECTION_INTENT_STATES,
            'recovery_case'=>self::RECOVERY_STATES,
            'refund_review'=>self::REFUND_REVIEW_STATES,
            'recurring_protection'=>self::PROTECTION_STATES,
            default=>throw new \InvalidArgumentException('Controlled recurring aggregate required'),
        };
    }
    public static function aggregateEventTypes(string $aggregate):array{
        return match($aggregate){
            'recurring_enrolment'=>self::RECURRING_EVENT_TYPES,
            'renewal_cycle'=>self::CYCLE_EVENT_TYPES,
            'collection_intent'=>self::COLLECTION_INTENT_EVENT_TYPES,
            'recovery_case'=>self::RECOVERY_EVENT_TYPES,
            'refund_review'=>self::REFUND_REVIEW_EVENT_TYPES,
            'recurring_protection'=>self::PROTECTION_EVENT_TYPES,
            default=>throw new \InvalidArgumentException('Controlled recurring aggregate required'),
        };
    }
    public static function legalTransition(string $aggregate,?string $from,string $to):bool{
        $table=self::AGGREGATE_TRANSITIONS[$aggregate]??null;
        if($table===null)throw new \InvalidArgumentException('Controlled recurring aggregate required');
        return in_array(($from??'').'|'.$to,$table,true);
    }
    /** The `from|to` transitions one event type is allowed to record for one aggregate. */
    public static function eventTransitions(string $aggregate,string $eventType):array{
        $table=self::AGGREGATE_EVENT_TRANSITIONS[$aggregate]??null;
        if($table===null)throw new \InvalidArgumentException('Controlled recurring aggregate required');
        return $table[$eventType]??array();
    }
    /** Whether one event type records exactly this transition. */
    public static function recordsTransition(string $aggregate,string $eventType,?string $from,string $to):bool{
        return in_array(($from??'').'|'.$to,self::eventTransitions($aggregate,$eventType),true);
    }
    public static function intentKindForMode(string $mode):?string{return self::MODE_INTENT_KINDS[$mode]??null;}
    public static function reviewEvidenceKind(string $kind):bool{return in_array($kind,self::REVIEW_EVIDENCE_KINDS,true);}
    public static function sameStateEventType(string $eventType):bool{return in_array($eventType,self::SAME_STATE_EVENT_TYPES,true);}
    public static function versionNeutralEventType(string $eventType):bool{return in_array($eventType,self::VERSION_NEUTRAL_EVENT_TYPES,true);}

    /**
     * Channel-neutral notification intents consumed by Phase S. These are intent names only,
     * never templates and never delivery records.
     */
    public const NOTIFICATION_INTENTS=array(
        'AUTOMATIC_RENEWAL_UPCOMING','AUTOMATIC_RENEWAL_CHARGED','AUTOMATIC_RENEWAL_FAILED',
        'MANUAL_RENEWAL_PAYMENT_REQUIRED','GUARANTEE_DEADLINE_APPROACHING','GUARANTEE_EXPIRED',
        'PAYMENT_FAILED','PAYMENT_RECOVERED','TERM_LAPSED','REFUND_REVIEW_REQUIRED','REFUND_RESOLVED',
    );

    /**
     * The manual same-slot guarantee expression is fixed at n weeks; the value is a locked owner
     * decision, not a configurable policy. `MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS` remains a class-B
     * R1 policy only to bound future review; R2 uses the locked default below when it is unset.
     */
    public const MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS=4;

    /** A collection confirmation may only follow accepted R1 payment evidence. */
    public const CONFIRM_REQUIRES_ACCEPTED_EVIDENCE=true;

    public static function validState(string $state,array $allowed):bool{return in_array($state,$allowed,true);}
    public static function collectionMode(string $mode):?string{return in_array($mode,self::COLLECTION_MODES,true)?$mode:null;}
    public static function notificationIntent(string $intent):bool{return in_array($intent,self::NOTIFICATION_INTENTS,true);}
}
