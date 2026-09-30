<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Fail-closed integrity gate for Phase 2A.2-R1 commercial aggregates.
 *
 * Every consumer (capacity arbitration, funding derivation, Term binding, read seams) hydrates and
 * validates the aggregate through this class before using it, so a corrupted offer, obligation,
 * settlement or capacity claim blocks the consuming authority instead of silently approving a false
 * commercial truth. Nothing here mutates state.
 */
final class CommercialValidator {
    public static function utc(?string $value):bool{
        return is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)===1&&strtotime($value.' UTC')!==false;
    }
    public static function digest(?string $value):bool{
        return is_string($value)&&preg_match('/^[0-9a-f]{64}$/D',$value)===1;
    }
    private static function key(string $value):bool{
        return preg_match('/^[a-z0-9_]+$/D',$value)===1;
    }

    /**
     * A commercial offer is a coherent immutable pricing snapshot only when its plan decomposition
     * exactly explains the amount due: contiguous ordered obligations covering the whole Term
     * commitment, tranche amounts summing to the amount due, and a discount total that accounts for
     * every recorded adjustment.
     */
    public static function offerValid(object $offer,array $obligations,array $adjustments=array()):bool{
        if((string)$offer->state!=='issued'&&!in_array((string)$offer->state,CommercialRule::OFFER_STATES,true))return false;
        if(!self::key((string)$offer->plan_kind)||(int)$offer->committed_sessions<1)return false;
        $currency=CommercialRule::currency((string)$offer->currency);
        if($currency===null||$currency!==(string)$offer->currency)return false;
        $base=(int)$offer->base_amount_minor;$discount=(int)$offer->discount_total_minor;$due=(int)$offer->amount_due_minor;
        if($base<1||$discount<0||$discount>$base||$due!==$base-$discount)return false;
        $recorded=(int)($offer->promotion_amount_minor??0)+(int)($offer->account_adjustment_amount_minor??0);
        if($recorded!==$discount)return false;
        if((int)($offer->promotion_amount_minor??0)>0&&$offer->promotion_id===null)return false;
        if($offer->promotion_id===null&&(int)($offer->promotion_amount_minor??0)!==0)return false;
        if((int)($offer->account_adjustment_amount_minor??0)>0&&$offer->account_adjustment_id===null)return false;
        if($offer->account_adjustment_id===null&&(int)($offer->account_adjustment_amount_minor??0)!==0)return false;
        if(!self::utc((string)$offer->issued_at)||!self::digest((string)$offer->evidence_reference_digest))return false;
        if((int)$offer->offer_version<1)return false;
        foreach($adjustments as $adjustment){
            $order=(int)$adjustment->application_order;
            if($order<1||(string)$adjustment->currency!==$currency)return false;
            if(!in_array((string)$adjustment->source_type,CommercialRule::ADJUSTMENT_SOURCE_TYPES,true))return false;
            if((int)$adjustment->applied_amount_minor<0||!self::digest((string)$adjustment->snapshot_digest))return false;
            if(!self::utc((string)$adjustment->created_at))return false;
        }
        return self::obligationsValid($obligations,(int)$offer->committed_sessions,(string)$offer->plan_kind,$currency,$due);
    }

    /** Ordered, contiguous, whole-commitment plan decomposition whose tranches sum to the amount due. */
    public static function obligationsValid(array $obligations,int $committedSessions,string $planKind,string $currency,int $amountDue):bool{
        $expected=CommercialRule::obligationSequences($planKind);
        if($expected===null||count($obligations)!==$expected)return false;
        $covered=0;$sum=0;$nextSequence=1;$nextSession=1;
        foreach($obligations as $obligation){
            $sequence=(int)$obligation->obligation_sequence;
            if($sequence!==$nextSequence)return false;
            $nextSequence++;
            $from=(int)$obligation->sessions_from;$to=(int)$obligation->sessions_to;$sessions=(int)$obligation->sessions_covered;
            if($from!==$nextSession||$to<$from||$sessions!==$to-$from+1)return false;
            if($to>$committedSessions)return false;
            if((string)$obligation->currency!==$currency)return false;
            if((int)$obligation->amount_minor<0)return false;
            if(!self::digest((string)$obligation->obligation_reference_digest))return false;
            $nextSession=$to+1;
            $covered+=$sessions;
            $sum+=(int)$obligation->amount_minor;
        }
        if($covered!==$committedSessions)return false;
        return $sum===$amountDue;
    }

    /** Provider-neutral evidence: a digest-only reference, an explicit exact amount/currency pair. */
    public static function evidenceValid(object $evidence):bool{
        if(trim((string)$evidence->provider_key)==='')return false;
        if(!self::digest((string)$evidence->evidence_reference_digest))return false;
        if(!in_array((string)$evidence->evidence_kind,CommercialRule::EVIDENCE_KINDS,true))return false;
        if(!in_array((string)$evidence->processing_state,CommercialRule::EVIDENCE_STATES,true))return false;
        $amount=$evidence->amount_minor;$currency=$evidence->currency;
        if(($amount===null)!==($currency===null))return false;
        if($amount!==null&&(int)$amount<1)return false;
        if($currency!==null&&CommercialRule::currency((string)$currency)!==(string)$currency)return false;
        if($evidence->obligation_reference_digest!==null&&!self::digest((string)$evidence->obligation_reference_digest))return false;
        if(!self::digest((string)($evidence->evidence_fact_digest??'')))return false;
        return self::utc((string)$evidence->ingested_at);
    }

    /**
     * Exact protected-interval ↔ canonical-occupancy identity.
     *
     * A commercial protected interval authorises a Phase-N schedule only when the authoritative
     * occupancy is the same interval: same Teacher, same UTC bounds (including the buffered
     * occupied end), same schedule timezone and same local wall clock. Matching only Term plus
     * canonical session sequence is deliberately insufficient.
     */
    public static function intervalMatchesSchedule(object $interval,array $schedule):bool{
        foreach(array('teacher_id','starts_at_utc','ends_at_utc','occupied_ends_at_utc','schedule_timezone','local_wall_date','local_wall_time') as $field){
            if(!isset($schedule[$field]))return false;
            if((string)$interval->{$field}!==(string)$schedule[$field])return false;
        }
        return true;
    }
    /** Course identity must be continuous across every authority that represents it. */
    public static function courseConsistent(array $courses):bool{
        $expected=null;
        foreach($courses as $courseId){
            if($courseId===null)continue;
            $courseId=(int)$courseId;
            if($courseId<1)return false;
            if($expected===null){$expected=$courseId;continue;}
            if($courseId!==$expected)return false;
        }
        return $expected!==null;
    }
    /** Whether recorded evidence already carries the incoming (nullable) attribution. */
    public static function evidenceAttributionMatches(object $evidence,?object $offer,?object $obligation):bool{
        $recordedOffer=$evidence->offer_id===null?null:(int)$evidence->offer_id;
        $recordedObligation=$evidence->obligation_id===null?null:(int)$evidence->obligation_id;
        $incomingOffer=$offer===null?null:(int)$offer->id;
        $incomingObligation=$obligation===null?null:(int)$obligation->id;
        return $recordedOffer===$incomingOffer&&$recordedObligation===$incomingObligation;
    }

    /**
     * Canonical identity of one immutable offer-adjustment snapshot row.
     *
     * The write boundary (offer issuance) and every later verification boundary must derive the
     * digest through this single implementation: the snapshot digest proves the snapshot itself, and
     * re-deriving it is what detects a rewritten or fabricated pricing snapshot.
     */
    public static function adjustmentSnapshotDigest(string $sourceType,int $sourceId,string $kind,?int $percentageBp,?int $fixedAmountMinor,int $appliedAmountMinor,string $currency):string{
        return CommercialIdempotency::payload(array(
            'source_type'=>$sourceType,'source_id'=>$sourceId,'kind'=>$kind,
            'percentage_bp'=>$percentageBp,'amount_minor'=>$fixedAmountMinor,
            'applied_amount_minor'=>$appliedAmountMinor,'currency'=>$currency,
        ));
    }

    /**
     * The immutable pricing pipeline order: promotion first, then the account adjustment.
     *
     * Returns the snapshot row for one source type only when the stored snapshot set is itself
     * coherent — contiguous orders starting at one, no duplicate order, and the requested source in
     * its canonical position. A null result is corruption, never "no snapshot".
     */
    public static function adjustmentSnapshotFor(object $offer,array $snapshots,string $sourceType):?object{
        $expectedOrder=match($sourceType){
            'promotion'=>1,
            'account_adjustment'=>$offer->promotion_id!==null?2:1,
            default=>null,
        };
        if($expectedOrder===null)return null;
        $orders=array();$found=null;
        foreach($snapshots as $snapshot){
            $order=(int)$snapshot->application_order;
            if($order<1||isset($orders[$order]))return null;
            $orders[$order]=true;
            if((string)$snapshot->source_type!==$sourceType)continue;
            if($found!==null)return null;
            $found=$snapshot;
        }
        ksort($orders);
        if(array_keys($orders)!==range(1,count($orders)))return null;
        if($found===null||(int)$found->application_order!==$expectedOrder)return null;
        if($expectedOrder>1&&!isset($orders[$expectedOrder-1]))return null;
        return $found;
    }

    /**
     * Exact correspondence between a locked promotional source and its immutable snapshot.
     *
     * The discount arithmetic of a promotion is owned by the promotion authority (eligibility,
     * Term scope and product scope all participate), so this proves the identity, economics, order,
     * currency and snapshot digest, and the applied amount is revalidated by that authority.
     */
    public static function promotionSnapshotMatches(object $source,object $snapshot,string $offerCurrency,int $offerPromotionAmountMinor):bool{
        if((string)$snapshot->source_type!=='promotion')return false;
        return self::adjustmentSourceIdentityMatches(
            (int)$source->id,(string)$source->kind,
            $source->percentage_bp===null?null:(int)$source->percentage_bp,
            $source->fixed_amount_minor===null?null:(int)$source->fixed_amount_minor,
            $source->currency===null?null:(string)$source->currency,
            $snapshot,'promotion',$offerCurrency,$offerPromotionAmountMinor
        );
    }

    /**
     * Exact correspondence between a locked account-adjustment source and its immutable snapshot.
     *
     * The adjustment is recomputed against the correct running purchase amount — the amount AFTER
     * any earlier immutable pipeline stage, so a snapshot whose application order follows a promotion
     * is recomputed against the post-promotion amount rather than the gross Term price — and the
     * canonical snapshot digest is re-derived. Every monetary comparison is integer minor units.
     */
    public static function accountAdjustmentSnapshotMatches(object $source,object $snapshot,int $runningAmountMinor,int $expectedOrder,string $offerCurrency,int $offerAdjustmentAmountMinor):bool{
        if((string)$snapshot->source_type!=='account_adjustment')return false;
        if((int)$snapshot->application_order!==$expectedOrder)return false;
        if(!self::adjustmentSourceIdentityMatches(
            (int)$source->id,(string)$source->kind,
            $source->percentage_bp===null?null:(int)$source->percentage_bp,
            $source->amount_minor===null?null:(int)$source->amount_minor,
            $source->currency===null?null:(string)$source->currency,
            $snapshot,'account_adjustment',$offerCurrency,$offerAdjustmentAmountMinor
        ))return false;
        $applied=self::recomputedAdjustmentAmount($source,$runningAmountMinor);
        if($applied===null)return false;
        return $applied===(int)$snapshot->applied_amount_minor&&$applied===$offerAdjustmentAmountMinor;
    }

    /**
     * The exact discount one account-adjustment source produces against one running amount.
     *
     * A percentage adjustment is recomputed against the running amount it actually applied to and a
     * fixed adjustment is bounded by that same amount, so a snapshot can never be justified by an
     * arithmetic path the issuance boundary would not have produced.
     */
    public static function recomputedAdjustmentAmount(object $source,int $runningAmountMinor):?int{
        $kind=(string)($source->kind??'');
        $currency=$source->currency;
        if(!in_array($kind,CommercialRule::ADJUSTMENT_KINDS,true))return null;
        if($kind==='percentage'){
            if($source->percentage_bp===null||$source->amount_minor!==null)return null;
            $percentage=(int)$source->percentage_bp;
            if($percentage<1||$percentage>CommercialMoney::MAX_BASIS_POINTS)return null;
            return CommercialMoney::discount($runningAmountMinor,CommercialMoney::percentage($runningAmountMinor,$percentage));
        }
        if($source->amount_minor===null||$source->percentage_bp!==null)return null;
        $fixed=(int)$source->amount_minor;
        if($fixed<1)return null;
        if($currency!==null&&CommercialRule::currency((string)$currency)!==(string)$currency)return null;
        return CommercialMoney::discount($runningAmountMinor,$fixed);
    }

    /**
     * Identity, economics, currency and snapshot-digest correspondence for one snapshotted source.
     *
     * The caller supplies the authoritative economics of the locked source, because the two source
     * authorities store their fixed amount under their own column (`fixed_amount_minor` for a
     * promotion, `amount_minor` for an account adjustment). Everything else is proved here.
     */
    private static function adjustmentSourceIdentityMatches(int $sourceId,string $sourceKind,?int $percentage,?int $fixed,?string $sourceCurrency,object $snapshot,string $sourceType,string $offerCurrency,int $offerAmountMinor):bool{
        if((int)$snapshot->source_id!==$sourceId)return false;
        if((string)$snapshot->kind!==$sourceKind)return false;
        if(!in_array((string)$snapshot->kind,CommercialRule::ADJUSTMENT_KINDS,true))return false;
        if($sourceKind==='percentage'){
            if($percentage===null||$percentage<1||$percentage>CommercialMoney::MAX_BASIS_POINTS)return false;
            if($fixed!==null)return false;
        }else{
            if($fixed===null||$fixed<1||$percentage!==null)return false;
            if($sourceCurrency===null||CommercialRule::currency($sourceCurrency)!==$sourceCurrency)return false;
        }
        if($snapshot->percentage_bp===null?$percentage!==null:(int)$snapshot->percentage_bp!==$percentage)return false;
        if($snapshot->amount_minor===null?$fixed!==null:(int)$snapshot->amount_minor!==$fixed)return false;
        if((string)$snapshot->currency!==$offerCurrency)return false;
        if($sourceCurrency!==null&&$sourceCurrency!==$offerCurrency)return false;
        if((int)$snapshot->applied_amount_minor!==$offerAmountMinor)return false;
        return hash_equals((string)$snapshot->snapshot_digest,self::adjustmentSnapshotDigest($sourceType,$sourceId,$sourceKind,$percentage,$fixed,$offerAmountMinor,$offerCurrency));
    }

    /** A settlement must be the exact obligation amount in the obligation currency. */
    public static function settlementValid(object $settlement,object $obligation):bool{
        if((int)$settlement->obligation_id!==(int)$obligation->id)return false;
        if((int)$settlement->amount_minor!==(int)$obligation->amount_minor)return false;
        if((string)$settlement->currency!==(string)$obligation->currency)return false;
        return self::utc((string)$settlement->settled_at);
    }

    /** Purchase and its bounded entitlement: one entitlement, one Term binding at most. */
    public static function entitlementValid(object $entitlement):bool{
        if(!in_array((string)$entitlement->state,CommercialRule::ENTITLEMENT_STATES,true))return false;
        if((int)$entitlement->session_count<1)return false;
        if((string)$entitlement->state==='term_bound'&&((int)($entitlement->term_id??0)<1||!self::utc((string)($entitlement->bound_at??null))))return false;
        if((string)$entitlement->state==='issued'&&$entitlement->term_id!==null)return false;
        return (int)$entitlement->entitlement_version>=1;
    }

    /**
     * A protected capacity claim is coherent only when its interval set is contiguous, ordered,
     * arithmetically valid and consistent with its own interval states.
     */
    public static function claimValid(object $claim,array $intervals):bool{
        if(!in_array((string)$claim->state,CommercialRule::CLAIM_STATES,true))return false;
        if(!in_array((string)$claim->source_kind,CommercialRule::CLAIM_SOURCE_KINDS,true))return false;
        $committed=(int)$claim->committed_sessions;
        if($committed<1||(int)$claim->interval_count!==count($intervals)||count($intervals)<1)return false;
        if((string)$claim->state==='released'&&($claim->released_at===null||trim((string)$claim->release_reason_code)===''))return false;
        if((int)$claim->claim_version<1||!self::utc((string)$claim->established_at))return false;
        if(!self::digest((string)$claim->evidence_reference_digest))return false;
        $nextSequence=1;
        foreach($intervals as $interval){
            if((int)$interval->interval_sequence!==$nextSequence)return false;
            $nextSequence++;
            if((int)$interval->teacher_id!==(int)$claim->teacher_id)return false;
            $expected=(int)$interval->expected_session;
            if($expected<1||$expected>$committed)return false;
            $starts=(string)$interval->starts_at_utc;$ends=(string)$interval->ends_at_utc;$occupied=(string)$interval->occupied_ends_at_utc;
            if(!self::utc($starts)||!self::utc($ends)||!self::utc($occupied))return false;
            if(!($starts<$ends&&$ends<=$occupied))return false;
            if((int)$interval->duration_minutes<5||(int)$interval->buffer_minutes<0)return false;
            if(!self::utc((string)$interval->local_wall_date.' 00:00:00'))return false;
            $state=(string)$interval->state;
            if(!in_array($state,CommercialRule::CLAIM_INTERVAL_STATES,true))return false;
            if($state==='satisfied'&&((int)($interval->satisfied_lesson_id??0)<1||(int)($interval->satisfied_schedule_version_id??0)<1||!self::utc((string)($interval->satisfied_at??null))))return false;
            if($state==='protected'&&($interval->satisfied_lesson_id!==null||$interval->satisfied_at!==null))return false;
            if($state==='released'&&!self::utc((string)($interval->released_at??null)))return false;
            if((int)$interval->interval_version<1)return false;
        }
        // An active claim protects at least one interval and never more than the whole commitment:
        // a Regular commitment claims every expected interval, a Flexible commitment claims only the
        // single interval that was explicitly authorised.
        if((string)$claim->state==='active'&&(int)$claim->interval_count>$committed)return false;
        // A non-active claim may never still hold protected capacity: that pair is corruption.
        if((string)$claim->state!=='active')foreach($intervals as $interval)if((string)$interval->state==='protected')return false;
        return true;
    }

    /** Runtime policies are class-B only; a structural invariant may never be stored here. */
    public static function policyValid(object $policy):bool{
        if(!in_array((string)$policy->policy_key,CommercialRule::POLICY_KEYS,true))return false;
        if((int)$policy->policy_version<1)return false;
        if((string)$policy->status!=='active')return false;
        if($policy->value_type!==null&&!in_array((string)$policy->value_type,CommercialRule::POLICY_VALUE_TYPES,true))return false;
        if($policy->policy_value!==null&&trim((string)$policy->policy_value)==='')return false;
        if(!self::utc((string)$policy->recorded_at)||(int)$policy->recorded_by<1)return false;
        return self::digest((string)$policy->evidence_reference_digest);
    }
    /** Money must be an exact non-negative integer minor-unit string; this guards against float drift. */
    public static function minorUnits(mixed $value):?int{
        if(is_int($value))return $value>=0?$value:null;
        if(is_string($value)&&preg_match('/^\d{1,18}$/D',$value)===1)return (int)$value;
        return null;
    }
}
