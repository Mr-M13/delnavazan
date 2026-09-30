<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\RecurringOutboxRepository;

/** Shared fail-closed input and authority handling for Phase 2A.2-R2 commands. */
final class RecurringSupport {
    public static function requireCapability(string $capability):void{
        if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');
    }
    public static function actor(string $message='Recurring actor unavailable'):int{
        $id=get_current_user_id();
        if($id<1)throw new \RuntimeException($message);
        return $id;
    }
    public static function now():string{return gmdate('Y-m-d H:i:s');}
    public static function keyString(string $key):string{return RecurringIdempotency::key($key);}

    /**
     * The R1 commercial serialization root, keyed by beneficiary Student.
     *
     * R2 owns no independent serialisation device: every recurring command that can change a
     * cross-Term financial fact takes the R1 `commercial_account_roots` row for the owning Student
     * first, exactly like an R1 commercial command. The fixed lock order of the R1 contract
     * (commercial account root → canonical Enrolment → ascending Teacher → Assignment →
     * per-Teacher scheduling root) is therefore never inverted, and unrelated Students never
     * contend. The caller must already be inside its own transaction.
     */
    public static function lockAccountRoot(int $studentId,int $actor):void{
        $studentId=self::positiveInt($studentId,'Valid beneficiary Student required');
        (new CommercialAuthorityRepository())->lockAccountRoot($studentId,$actor);
    }

    /**
     * Resolve the owning beneficiary Student for a recurring aggregate. Every resolver fails closed:
     * an aggregate whose ownership cannot be established is never mutated.
     */
    public static function aggregateStudent(string $aggregate,int $id):int{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $id=self::positiveInt($id,'Valid recurring aggregate required');
        switch($aggregate){
            case 'recurring_enrolment':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}recurring_enrolments WHERE id=%d",$id));
                break;
            case 'renewal_cycle':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT r.student_id FROM {$p}renewal_cycles c JOIN {$p}recurring_enrolments r ON r.id=c.recurring_enrolment_id WHERE c.id=%d",$id));
                break;
            case 'collection_intent':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT r.student_id FROM {$p}collection_intents i JOIN {$p}renewal_cycles c ON c.id=i.renewal_cycle_id JOIN {$p}recurring_enrolments r ON r.id=c.recurring_enrolment_id WHERE i.id=%d",$id));
                break;
            case 'recovery_case':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT r.student_id FROM {$p}recovery_cases rc JOIN {$p}recurring_enrolments r ON r.id=rc.recurring_enrolment_id WHERE rc.id=%d",$id));
                break;
            case 'refund_review':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT purchase.beneficiary_student_id FROM {$p}refund_review_cases rr JOIN {$p}commercial_purchases purchase ON purchase.id=rr.purchase_id WHERE rr.id=%d",$id));
                break;
            case 'recurring_protection':
                $student=(int)$wpdb->get_var($wpdb->prepare("SELECT r.student_id FROM {$p}recurring_protections pc JOIN {$p}renewal_cycles c ON c.id=pc.renewal_cycle_id JOIN {$p}recurring_enrolments r ON r.id=c.recurring_enrolment_id WHERE pc.id=%d",$id));
                break;
            default:
                throw new \InvalidArgumentException('Controlled recurring aggregate required');
        }
        if($student<1)throw new \InvalidArgumentException('recurring_aggregate_ownership_conflict');
        return $student;
    }

    /**
     * Serialise a recurring mutation on its owning Student before any recurring row is read or
     * written. Returns the owning Student so the caller can reuse it without a second lookup.
     */
    public static function guardAggregate(string $aggregate,int $id,int $actor):int{
        $student=self::aggregateStudent($aggregate,$id);
        self::lockAccountRoot($student,$actor);
        return $student;
    }

    /**
     * Serialise a command that creates a new aggregate from an existing R1 purchase: the owning
     * Student is resolved from the purchase so a refund/reversal review can never race the buyer's
     * commercial state.
     */
    public static function guardPurchase(int $purchaseId,int $actor):int{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $purchaseId=self::positiveInt($purchaseId,'Valid R1 purchase required');
        $student=(int)$wpdb->get_var($wpdb->prepare("SELECT beneficiary_student_id FROM {$p}commercial_purchases WHERE id=%d",$purchaseId));
        if($student<1)throw new \InvalidArgumentException('commercial_purchase_required');
        self::lockAccountRoot($student,$actor);
        return $student;
    }

    /**
     * Record a channel-neutral notification intent through the existing `platform_outbox` seam.
     *
     * R2 finalises the intent names consumed by Phase S; it never renders a template and never
     * delivers. The outbox row is written inside the caller's transaction so the fact and its intent
     * commit or roll back together.
     */
    public static function publishIntent(string $aggregate,int $aggregateId,string $intent,int $actor):void{
        if(!RecurringRule::notificationIntent($intent))throw new \InvalidArgumentException('Controlled notification intent required');
        (new RecurringOutboxRepository())->publish($aggregate,self::positiveInt($aggregateId,'Valid recurring aggregate required'),$intent,$actor);
    }

    /**
     * §5.3: a collection intent — and the cycle it collects — may only be confirmed by *accepted* R1
     * payment evidence for the exact obligation.
     *
     * The reason is returned rather than thrown so both consumers report the same controlled
     * vocabulary: an obligation with no settlement at all is `obligation_not_settled`, while a
     * settlement recorded against evidence R1 never accepted (`rejected`/`unmatched`) is
     * `accepted_payment_evidence_required`. R2 never invents a settlement of its own; it only refuses
     * to treat non-accepted evidence as a collection.
     */
    public static function settlementReason(int $obligationId):?string{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $obligationId=self::positiveInt($obligationId,'Valid R1 obligation required');
        $settlement=$wpdb->get_row($wpdb->prepare("SELECT settlement.evidence_id AS evidence_id,evidence.processing_state AS processing_state FROM {$p}commercial_obligation_settlements settlement LEFT JOIN {$p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id WHERE settlement.obligation_id=%d",$obligationId));
        if(!$settlement)return 'obligation_not_settled';
        if((string)($settlement->processing_state??'')!=='accepted')return 'accepted_payment_evidence_required';
        return null;
    }

    /** Deterministic test seam: mirrors the R1 phase hook convention. */
    public static function hook(string $moment,...$arguments):void{
        if(function_exists('do_action'))do_action($moment,...$arguments);
    }

    public static function evidence(array $input,string $referenceKey='evidence_reference'):array{
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,RecurringRule::EVIDENCE_CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($input['evidence_at']??'');
        if(!CommercialValidator::utc($at)||$at>self::now())throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        $reference=(string)($input[$referenceKey]??'');
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return array('channel'=>$channel,'at'=>$at,'digest'=>RecurringIdempotency::evidence($reference));
    }
    public static function reason(array $input,string $field='reason_code'):string{
        $reason=(string)($input[$field]??'');
        if(preg_match('/^[a-z0-9_]{1,64}$/D',$reason)!==1)throw new \InvalidArgumentException('Controlled reason code required');
        return $reason;
    }
    public static function positiveInt(mixed $value,string $message):int{
        if(is_int($value))$int=$value;
        elseif(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)$int=(int)trim($value);
        else throw new \InvalidArgumentException($message);
        if($int<1)throw new \InvalidArgumentException($message);
        return $int;
    }
    public static function utc(mixed $value,string $message):string{
        $value=is_string($value)?trim($value):'';
        if(!CommercialValidator::utc($value))throw new \InvalidArgumentException($message);
        return $value;
    }
    public static function collectionMode(array $input):string{
        $mode=(string)($input['collection_mode']??'');
        if(RecurringRule::collectionMode($mode)===null)throw new \InvalidArgumentException('Controlled collection mode required');
        return $mode;
    }
    public static function amount(mixed $value,string $message):int{
        if(is_int($value))return CommercialMoney::amount($value);
        if(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)return CommercialMoney::amount((int)trim($value));
        throw new \InvalidArgumentException($message);
    }
    public static function currency(string $currency):string{
        $value=CommercialRule::currency($currency);
        if($value===null)throw new \InvalidArgumentException('Supported currency required');
        return $value;
    }

    /** Fail-closed aggregate read: a malformed stored aggregate is never presented as authority. */
    public static function integrity(bool $valid,string $reason):void{
        if(!$valid)throw new \RuntimeException($reason);
    }
    public static function utcOrNull(mixed $value):bool{
        return $value===null||(is_string($value)&&CommercialValidator::utc($value));
    }
    public static function controlledReasonOrNull(mixed $value):bool{
        return $value===null||(is_string($value)&&preg_match('/^[a-z0-9_]{1,64}$/D',$value)===1);
    }
    public static function supportedCurrency(string $currency):bool{
        return CommercialRule::currency($currency)!==null;
    }
}
