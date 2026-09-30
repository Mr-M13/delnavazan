<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\RenewalCycleRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Phase 2A.2-R2 Renewal Cycle authority: next-Term boundary derivation and the bounded
 * pending → guarantee → payment → collected → term-bound → closed (or lapsed/cancelled) lifecycle.
 *
 * Next-Term creation and entitlement binding are delegated to R1/Phase-L; this service never writes
 * a Term, funding plan or protected capacity of its own.
 */
final class RenewalCycleService {
    private const CAPABILITY='dzn_manage_renewal_cycles';
    public function __construct(private ?RenewalCycleRepository $repository=null){$this->repository??=new RenewalCycleRepository();}

    public function openCycle(int $recurringEnrolmentId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $sourceTermId=RecurringSupport::positiveInt($input['source_term_id']??null,'Valid source Term required');
        $facts=$this->boundaryAndPrice($recurringEnrolmentId,$sourceTermId);
        $digest=RecurringSupport::keyString($key);
        $payload='';
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_enrolment',$recurringEnrolmentId,$actor);
            // §5.1/§5.2: a cycle snapshots the *recorded* collection mode of its recurring enrolment,
            // read from the locked row. The mode is an audited attribute of the enrolment and the cycle
            // must never be opened in a mode the enrolment does not carry, so the derivation is the only
            // source of truth and a caller-supplied mode may only restate it.
            $recurring=$this->repository->enrolment($recurringEnrolmentId,true);
            if(!$recurring)throw new \InvalidArgumentException('recurring_enrolment_required');
            $mode=$this->resolveCycleMode($recurring,$input);
            $chargeAt=self::automaticChargeAt($mode,(string)$facts['boundary_derived_at']);
            $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'open_cycle','recurring_enrolment_id'=>$recurringEnrolmentId,'source_term_id'=>$sourceTermId,'collection_mode'=>$mode,'boundary_derived_at'=>$facts['boundary_derived_at'],'evidence_reference_digest'=>$evidence['digest']));
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            // Only an *operational* recurring enrolment opens a new cycle; an unchanged command still
            // converges on its recorded result above.
            if((string)$recurring->state!=='active')throw new \InvalidArgumentException('recurring_enrolment_not_operational');
            $sequence=$this->nextCycleSequence($recurringEnrolmentId);
            $now=RecurringSupport::now();
            $id=$this->repository->insertCycle(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$recurringEnrolmentId,'sequence'=>$sequence,
                'source_term_id'=>$sourceTermId,'next_term_id'=>null,'collection_mode'=>$mode,
                'currency'=>$facts['currency'],'amount_minor'=>$facts['amount_minor'],
                'boundary_derived_at'=>$facts['boundary_derived_at'],
                // §6.2.4(a): the announced instant is written once, in the transaction that commits the
                // cycle's own `opened` fact, so it is a durable subject fact rather than a later
                // recomputation. `automatic_charge_at` is never rewritten after this insert.
                'automatic_charge_at'=>$chargeAt,'guarantee_deadline_at'=>$facts['guarantee_deadline_at'],
                'state'=>'pending','renewal_cycle_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$id,'event_sequence'=>1,'event_type'=>'opened',
                'from_state'=>null,'to_state'=>'pending','reason_code'=>'opened','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'open_cycle',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'renewal_cycle_id'=>$id,
                'result_state'=>'pending','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            // An automatic-renewal notice is an advance notice: with the charge lead time unset there
            // is no advance instant to announce, so the intent stays unrecorded (safe default). This is
            // the *only* publication site of `AUTOMATIC_RENEWAL_UPCOMING` (§6.2.4(b)(3)/(b)(6)): the
            // durable intent name identifies this one bound `opened` fact, never a later transition.
            if($chargeAt!==null)RecurringSupport::publishIntent('renewal_cycle',$id,'AUTOMATIC_RENEWAL_UPCOMING',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_cycle_event_insert','open_cycle',$id);
            $this->repository->commit();
            return array('renewal_cycle_id'=>$id,'sequence'=>$sequence,'state'=>'pending','collection_mode'=>$mode,'charge_at'=>$chargeAt,'boundary_derived_at'=>$facts['boundary_derived_at'],'guarantee_deadline_at'=>$facts['guarantee_deadline_at'],'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /**
     * The cycle's collection mode is the locked recurring enrolment's recorded mode.
     *
     * `manual` and `automatic` name the recorded provider-neutral collection states only: no default
     * opt-in/out is invented here, and a caller that supplies the mode must supply the recorded one.
     * A conflicting input fails closed instead of silently opening a cycle the enrolment did not record.
     */
    private function resolveCycleMode(object $recurring,array $input):string{
        $mode=RecurringRule::collectionMode((string)$recurring->collection_mode);
        if($mode===null)throw new \InvalidArgumentException('recurring_enrolment_collection_mode_required');
        $supplied=trim((string)($input['collection_mode']??''));
        if($supplied!==''&&$supplied!==$mode)throw new \InvalidArgumentException('recurring_collection_mode_conflict');
        return $mode;
    }

    public function activateManualGuarantee(int $cycleId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'activate_manual_guarantee','renewal_cycle_id'=>$cycleId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $cycle=$this->repository->find($cycleId,true);
            if(!$cycle||(string)$cycle->state!=='pending')throw new \InvalidArgumentException('invalid_renewal_cycle_state');
            // The manual same-slot guarantee window is a locked owner decision resolved in the
            // pattern timezone, recorded when the cycle boundary was derived.
            $deadline=$cycle->guarantee_deadline_at!==null
                ?(string)$cycle->guarantee_deadline_at
                :$this->guaranteeFallback((int)$cycle->recurring_enrolment_id,(string)$cycle->boundary_derived_at);
            $now=RecurringSupport::now();
            $this->repository->updateCycle($cycleId,(int)$cycle->renewal_cycle_version,array('state'=>'guarantee_protected','guarantee_deadline_at'=>$deadline),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'event_sequence'=>$this->repository->nextSequence($cycleId),'event_type'=>'guarantee_protected',
                'from_state'=>'pending','to_state'=>'guarantee_protected','reason_code'=>'guarantee_protected','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'activate_manual_guarantee',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'renewal_cycle_id'=>$cycleId,
                'result_state'=>'guarantee_protected','result_id'=>$cycleId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::publishIntent('renewal_cycle',$cycleId,'GUARANTEE_DEADLINE_APPROACHING',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_cycle_event_insert','activate_manual_guarantee',$cycleId);
            $this->repository->commit();
            return array('renewal_cycle_id'=>$cycleId,'state'=>'guarantee_protected','guarantee_deadline_at'=>$deadline,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    public function requirePayment(int $cycleId,array $input,string $key):array{
        return $this->cycleTransition($cycleId,array('pending','guarantee_protected'),'payment_required','require_payment',$input,$key);
    }
    public function confirmCollection(int $cycleId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'confirm_collection','renewal_cycle_id'=>$cycleId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $cycle=$this->repository->find($cycleId,true);
            if(!$cycle||(string)$cycle->state!=='payment_required')throw new \InvalidArgumentException('invalid_renewal_cycle_state');
            $this->assertCycleObligationSettled($cycleId);
            $now=RecurringSupport::now();
            $this->repository->updateCycle($cycleId,(int)$cycle->renewal_cycle_version,array('state'=>'collected'),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'event_sequence'=>$this->repository->nextSequence($cycleId),'event_type'=>'collected',
                'from_state'=>'payment_required','to_state'=>'collected','reason_code'=>'collected','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'confirm_collection',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'renewal_cycle_id'=>$cycleId,
                'result_state'=>'collected','result_id'=>$cycleId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_cycle_event_insert','confirm_collection',$cycleId);
            $this->repository->commit();
            return array('renewal_cycle_id'=>$cycleId,'state'=>'collected','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    public function bindNextTerm(int $cycleId,int $entitlementId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'bind_next_term','renewal_cycle_id'=>$cycleId,'entitlement_id'=>$entitlementId,'evidence_reference_digest'=>$evidence['digest']));
        // 1. Authorise the recorded aggregate position before any delegation.
        $cycle=$this->repository->find($cycleId);
        if(!$cycle||(string)$cycle->state!=='collected')throw new \InvalidArgumentException('invalid_renewal_cycle_state');
        // The entitlement being bound must be a commitment of this cycle's own beneficiary Student,
        // Course and frozen currency: R2 records the next Term of this recurring enrolment, so it may
        // never adopt another Student's, another Course's or another currency's purchase chain.
        $this->assertEntitlementOwnership($cycleId,$entitlementId);
        // Phase L is the sole Term authority and it refuses to create a successor Term unless the
        // caller proves the aggregate position it replaces: the latest canonical Term of the
        // Enrolment and its terminal state. R2 forwards that authorised fact and never invents it, so
        // a renewal whose position cannot be proved fails closed before anything is delegated.
        $position=$this->successorPosition($cycleId,$input);
        // 2. The R1 entitlement binding owns its own transaction and creates the next Term, the
        //    funding plan and the entitlement/claim binding as one R1 unit. R2 never nests a
        //    transaction inside it, and never creates a Term, funding plan or claim itself.
        $bound=(new CommercialTermFundingService())->bindEntitlementToTerm($entitlementId,array('evidence_channel'=>$evidence['channel'],'evidence_reference'=>(string)($input['evidence_reference']??''),'evidence_at'=>$evidence['at'],'expected_latest_term_id'=>$position['term_id'],'expected_latest_state'=>$position['state']),$key);
        $termId=(int)$bound['term_id'];
        // 3. Record the durable R1 outcome in R2's own serialised transaction, after verifying it.
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $cycle=$this->repository->find($cycleId,true);
            if(!$cycle||(string)$cycle->state!=='collected')throw new \InvalidArgumentException('invalid_renewal_cycle_state');
            $this->assertEntitlementBoundToTerm($cycleId,$entitlementId,$termId);
            $now=RecurringSupport::now();
            $this->repository->updateCycle($cycleId,(int)$cycle->renewal_cycle_version,array('state'=>'term_bound','next_term_id'=>$termId),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'event_sequence'=>$this->repository->nextSequence($cycleId),'event_type'=>'term_bound',
                'from_state'=>'collected','to_state'=>'term_bound','reason_code'=>'term_bound','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'bind_next_term',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'renewal_cycle_id'=>$cycleId,
                'result_state'=>'term_bound','result_id'=>$termId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_cycle_event_insert','bind_next_term',$cycleId);
            $this->repository->commit();
            return array('renewal_cycle_id'=>$cycleId,'state'=>'term_bound','next_term_id'=>$termId,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    public function lapse(int $cycleId,array $input,string $key):array{return $this->cycleTransition($cycleId,array('pending','guarantee_protected','payment_required','collected'),'lapsed','lapse',$input,$key,true);}
    public function cancel(int $cycleId,array $input,string $key):array{return $this->cycleTransition($cycleId,array('pending','guarantee_protected','payment_required','collected'),'cancelled','cancel',$input,$key,true);}
    public function close(int $cycleId,array $input,string $key):array{return $this->cycleTransition($cycleId,array('term_bound'),'closed','close',$input,$key);}

    private function cycleTransition(int $cycleId,array $from,string $to,string $operation,array $input,string $key,bool $releaseProtectionFirst=false):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>$operation,'renewal_cycle_id'=>$cycleId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $cycle=$this->repository->find($cycleId,true);
            if(!$cycle||!in_array((string)$cycle->state,$from,true))throw new \InvalidArgumentException('invalid_renewal_cycle_state');
            // §5.6: continuous protection is released under the same per-Teacher scheduling root and is
            // never a silent side effect of an R2 state change. A cycle may only become terminal once
            // every active protection it owns has been explicitly released, so a lapsed or cancelled
            // cycle can never leave a live protected-capacity claim behind.
            if($releaseProtectionFirst&&$this->activeProtectionId($cycleId)>0)throw new \InvalidArgumentException('recurring_protection_release_required');
            $now=RecurringSupport::now();
            $this->repository->updateCycle($cycleId,(int)$cycle->renewal_cycle_version,array('state'=>$to),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'event_sequence'=>$this->repository->nextSequence($cycleId),'event_type'=>$to,
                'from_state'=>(string)$cycle->state,'to_state'=>$to,'reason_code'=>$operation,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'renewal_cycle_id'=>$cycleId,
                'result_state'=>$to,'result_id'=>$cycleId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $intent=$this->intentForTransition($operation,(string)$cycle->collection_mode);
            if($intent!==null)RecurringSupport::publishIntent('renewal_cycle',$cycleId,$intent,$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_cycle_event_insert',$operation,$cycleId);
            $this->repository->commit();
            return array('renewal_cycle_id'=>$cycleId,'state'=>$to,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated renewal cycle command');
        $cycle=$this->repository->find((int)$command->result_id);
        if(!$cycle||(string)$cycle->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated renewal cycle result');
        return array('renewal_cycle_id'=>(int)$cycle->id,'state'=>(string)$cycle->state,'next_term_id'=>$cycle->next_term_id===null?null:(int)$cycle->next_term_id,'created'=>false,'idempotent'=>true);
    }

    private function nextCycleSequence(int $recurringId):int{global $wpdb;$p=$wpdb->prefix.'dzn_';return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence),0)+1 FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",$recurringId));}

    private function intentForTransition(string $operation,string $mode):?string{
        // §6.2.4(b)(6): `AUTOMATIC_RENEWAL_UPCOMING` is bound to the cycle-open `opened` fact alone, and
        // the seam carries aggregate type, aggregate id and intent name only — never the originating
        // event. Publishing the same intent name from `require_payment` in `automatic` mode would
        // therefore add a second candidate bound fact the durable evidence cannot tell apart, so an
        // automatic `require_payment` transition publishes no advance notice at all. The manual
        // publication is unchanged: that intent is bound to its own `payment_required` event.
        if($operation==='require_payment')return $mode==='manual'?'MANUAL_RENEWAL_PAYMENT_REQUIRED':null;
        if($operation==='lapse')return 'TERM_LAPSED';
        return null;
    }

    /**
     * Boundary, whole-Term price snapshot and guarantee/charge instants derived from authoritative
     * R1/Phase-N facts only.
     *
     * The next-Term boundary is the first R1 Regular pattern occurrence strictly after the current
     * Term's last applicable scheduled interval: progression-derived from the recorded Term and the
     * frozen Phase-Q authorised regular slot stepped by whole weeks in the pattern timezone. It is
     * never `intro + 7`, never wall-clock guessing and never inferred from provider data; when either
     * fact is unavailable the command fails closed instead of inventing a boundary.
     */
    private function boundaryAndPrice(int $recurringId,int $sourceTermId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $recurring=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recurring_enrolments WHERE id=%d",$recurringId));
        if(!$recurring)throw new \InvalidArgumentException('recurring_enrolment_required');
        $this->assertSourceTermAuthority((int)$recurring->enrolment_id,$sourceTermId);
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_term_funding_plans WHERE enrolment_id=%d ORDER BY id DESC LIMIT 1",(int)$recurring->enrolment_id));
        if(!$plan)throw new \InvalidArgumentException('funding_plan_required');
        $offer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offers WHERE id=%d",(int)$plan->offer_id));
        if(!$offer)throw new \InvalidArgumentException('commercial_offer_required');
        if((int)$offer->beneficiary_student_id!==(int)$recurring->student_id)throw new \InvalidArgumentException('commercial_offer_required');
        $lessonIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}lessons WHERE term_id=%d",$sourceTermId));
        $lastInterval=null;
        if($lessonIds){
            $in=implode(',',array_map('intval',$lessonIds));
            $lastInterval=$wpdb->get_var("SELECT MAX(occupied_ends_at_utc) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id IN ({$in})");
        }
        if($lastInterval===null)throw new \InvalidArgumentException('boundary_facts_required');
        $pattern=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_recurring_patterns WHERE student_id=%d AND course_id=%d ORDER BY (state='active') DESC,id DESC LIMIT 1",(int)$recurring->student_id,(int)$recurring->course_id));
        if(!$pattern)throw new \InvalidArgumentException('boundary_facts_required');
        $boundary=$this->nextPatternOccurrence($pattern,(string)$lastInterval);
        if($boundary===null)throw new \InvalidArgumentException('boundary_facts_required');
        $deadline=$this->guaranteeDeadlineForPattern($pattern,(string)$boundary['local_wall_date']);
        return array(
            'boundary_derived_at'=>(string)$boundary['starts_at_utc'],
            'guarantee_deadline_at'=>$deadline,
            'currency'=>(string)$offer->currency,'amount_minor'=>(int)$offer->amount_due_minor,
        );
    }

    /**
     * The first pattern occurrence strictly after the current Term's last occupied interval.
     * Occurrences are stepped by whole weeks from the frozen anchor and independently resolved in the
     * pattern timezone through the canonical Phase-Q wall-clock rule, so a DST transition shifts the
     * UTC instant without changing the agreed local class time.
     */
    private function nextPatternOccurrence(object $pattern,string $afterUtc):?array{
        // The canonical Regular pattern authority owns weekly occurrence resolution, so the committed
        // window is consulted first; only a Term longer than that window is stepped analytically, and
        // every step still goes through the same Phase-Q wall-clock rule.
        foreach((new CommercialPatternService())->intervalsFor($pattern,52) as $occurrence)if((string)$occurrence['starts_at_utc']>$afterUtc)return $occurrence;
        $anchor=$pattern->anchor_local_wall_date;
        $anchorStart=(string)$pattern->anchor_starts_at_utc;
        $index=max(52,(int)floor((strtotime($afterUtc.' UTC')-strtotime($anchorStart.' UTC'))/(7*86400))-1);
        for($step=$index;$step<=$index+8;$step++){
            $localDate=$step===0?(string)$anchor:gmdate('Y-m-d',strtotime((string)$anchor.' UTC +'.($step*7).' days'));
            $resolved=CanonicalContinuationRule::resolveWallClock((string)$pattern->schedule_timezone,$localDate,(string)$pattern->local_wall_time,(int)$pattern->duration_minutes,(int)$pattern->buffer_minutes);
            if((string)$resolved['starts_at_utc']>$afterUtc)return $resolved;
        }
        return null;
    }

    /**
     * Fallback guarantee window for a cycle whose deadline was not recorded at boundary derivation.
     * The window is resolved in the owning pattern's timezone; without a pattern the UTC-equivalent
     * whole-week offset is used, because the locked constant is a fixed number of weeks either way.
     */
    private function guaranteeFallback(int $recurringEnrolmentId,string $boundary):string{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $pattern=$wpdb->get_row($wpdb->prepare("SELECT pattern.* FROM {$p}commercial_recurring_patterns pattern JOIN {$p}recurring_enrolments recurring ON recurring.student_id=pattern.student_id AND recurring.course_id=pattern.course_id WHERE recurring.id=%d ORDER BY (pattern.state='active') DESC,pattern.id DESC LIMIT 1",$recurringEnrolmentId));
        if(!$pattern)return gmdate('Y-m-d H:i:s',strtotime($boundary.' UTC')+RecurringRule::MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS*7*86400);
        $localDate=gmdate('Y-m-d',strtotime($boundary.' UTC'));
        return $this->guaranteeDeadlineForPattern($pattern,$localDate);
    }
    private function guaranteeDeadlineForPattern(object $pattern,string $boundaryLocalDate,?string $boundaryLocalTime=null):string{
        $weeks=RecurringRule::MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS;
        $localDate=gmdate('Y-m-d',strtotime($boundaryLocalDate.' UTC +'.($weeks*7).' days'));
        $localTime=$boundaryLocalTime??(string)$pattern->local_wall_time;
        $resolved=CanonicalContinuationRule::resolveWallClock((string)$pattern->schedule_timezone,$localDate,$localTime,(int)$pattern->duration_minutes,(int)$pattern->buffer_minutes);
        return (string)$resolved['starts_at_utc'];
    }

    /**
     * `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` is an unresolved product decision: while it is unset no
     * advance charge instant exists and the automatic-charge date stays NULL.
     *
     * This is the *write-time* resolution seam for an automatic charge instant, used exactly once: by
     * `openCycle()`, which persists the resolved value as the cycle's durable `automatic_charge_at`
     * (§6.2.4(a)). The collection side never calls it again — `CollectionIntentService::open()` reads
     * the persisted column — so an announced instant and a charged instant can no longer diverge after
     * a policy version. No caller can assert a charge time of its own while the lead-time policy is
     * unset (or record one that disagrees with the analysed boundary).
     */
    public static function automaticChargeAt(string $mode,string $boundary):?string{
        if($mode!=='automatic')return null;
        $policy=(new CommercialPolicyService())->current('AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME');
        if(!$policy['set'])return null;
        $lead=0;
        if((string)$policy['value_type']==='weeks')$lead=((int)$policy['value'])*7*86400;
        elseif((string)$policy['value_type']==='duration')$lead=(int)$policy['value'];
        else return null;
        if($lead<1)return null;
        $charge=strtotime($boundary.' UTC')-$lead;
        return $charge>=0?gmdate('Y-m-d H:i:s',$charge):null;
    }
    private function assertCycleObligationSettled(int $cycleId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        // The cycle is collected by its authoritative first obligation: the earliest non-cancelled
        // collection intent for exactly this cycle. A later obligation — tranche 2 may settle early —
        // can never collect the cycle on its own, and a cancelled intent can never stand in for one.
        $intent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}collection_intents WHERE renewal_cycle_id=%d AND state<>'cancelled' ORDER BY id ASC LIMIT 1",$cycleId));
        if(!$intent)throw new \InvalidArgumentException('collection_intent_required');
        $settlement=RecurringSupport::settlementReason((int)$intent->obligation_id);
        if($settlement!==null)throw new \InvalidArgumentException($settlement);
    }

    /**
     * The aggregate position the next-Term binding replaces, proved from stored facts.
     *
     * Phase L authorises a successor Term only against an explicit expectation — the latest
     * canonical Term of the Enrolment plus its terminal state (`closed` or `cancelled`) — so R2
     * requires the caller to supply that position and then proves it against the cycle's own
     * Enrolment. R2 records what it verified; it never guesses a position, never closes or cancels a
     * Term itself and never derives the expectation from wall-clock time.
     */
    private function successorPosition(int $cycleId,array $input):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $termId=RecurringSupport::positiveInt($input['expected_latest_term_id']??null,'renewal_aggregate_position_required');
        $state=(string)($input['expected_latest_state']??'');
        if(!in_array($state,array('closed','cancelled'),true))throw new \InvalidArgumentException('renewal_aggregate_position_required');
        $latest=$wpdb->get_row($wpdb->prepare("SELECT term.id AS id,term.lifecycle_state AS lifecycle_state FROM {$p}terms term JOIN {$p}renewal_cycles cycle ON cycle.id=%d JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE term.enrolment_id=recurring.enrolment_id AND term.record_model='canonical_enrolment_term_v1' AND term.archived_at IS NULL ORDER BY term.sequence_number DESC,term.id DESC LIMIT 1",$cycleId));
        if(!$latest||(int)$latest->id!==$termId||(string)$latest->lifecycle_state!==$state)throw new \InvalidArgumentException('renewal_aggregate_position_mismatch');
        return array('term_id'=>$termId,'state'=>$state);
    }

    /**
     * The entitlement that will produce the next Term must belong to this cycle's own commitment:
     * the same beneficiary Student, the same Course and the cycle's frozen currency. R2 adopts the
     * R1 purchase chain — it never re-prices or re-accepts it — but it must prove ownership before it
     * delegates, so a foreign commitment can never be recorded as this cycle's next Term.
     */
    private function assertEntitlementOwnership(int $cycleId,int $entitlementId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT c.currency AS currency,r.student_id AS student_id,r.course_id AS course_id FROM {$p}renewal_cycles c JOIN {$p}recurring_enrolments r ON r.id=c.recurring_enrolment_id WHERE c.id=%d",$cycleId));
        if(!$cycle)throw new \InvalidArgumentException('renewal_cycle_required');
        $entitlement=$wpdb->get_row($wpdb->prepare("SELECT entitlement.beneficiary_student_id AS student_id,offer.course_id AS course_id,offer.currency AS currency FROM {$p}commercial_entitlements entitlement JOIN {$p}commercial_purchases purchase ON purchase.id=entitlement.purchase_id JOIN {$p}commercial_offers offer ON offer.id=purchase.offer_id WHERE entitlement.id=%d",$entitlementId));
        if(!$entitlement
            ||(int)$entitlement->student_id!==(int)$cycle->student_id
            ||(int)$entitlement->course_id!==(int)$cycle->course_id
            ||(string)$entitlement->currency!==(string)$cycle->currency)throw new \InvalidArgumentException('renewal_entitlement_ownership_conflict');
    }

    /**
     * The boundary is derived from the recurring enrolment's own canonical Term history only.
     *
     * A foreign Enrolment's Term, a legacy Term, an archived Term or a cancelled Term may never seed a
     * next-Term boundary: R2 fails closed instead of deriving a boundary from facts the recurring
     * enrolment does not own. The Term's applicable schedule versions alone supply the last occupied
     * interval of the current Term.
     */
    private function assertSourceTermAuthority(int $enrolmentId,int $sourceTermId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $term=$wpdb->get_row($wpdb->prepare("SELECT record_model,lifecycle_state,enrolment_id,archived_at FROM {$p}terms WHERE id=%d",$sourceTermId));
        if(!$term
            ||(string)($term->record_model??'')!=='canonical_enrolment_term_v1'
            ||(int)$term->enrolment_id!==$enrolmentId
            ||$term->archived_at!==null
            ||!in_array((string)$term->lifecycle_state,array('authorised','current','closed'),true))throw new \InvalidArgumentException('canonical_source_term_required');
    }
    /** The renewal cycle's own active continuous protection, if any. */
    private function activeProtectionId(int $cycleId):int{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}recurring_protections WHERE renewal_cycle_id=%d AND state='active'",$cycleId));
    }
    /**
     * The delegated R1 outcome must be durable, exactly what R2 is about to record, and still inside
     * this cycle's own commitment: the funding plan that authorises the created Term must belong to a
     * canonical Enrolment of the cycle's Student and Course, so `next_term_id` can never name a Term
     * raised for somebody else's enrolment.
     */
    private function assertEntitlementBoundToTerm(int $cycleId,int $entitlementId,int $termId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT entitlement.state AS state,plan.term_id AS term_id,enrolment.student_id AS student_id,enrolment.course_id AS course_id,recurring.student_id AS cycle_student_id,recurring.course_id AS cycle_course_id FROM {$p}commercial_entitlements entitlement LEFT JOIN {$p}commercial_term_funding_plans plan ON plan.entitlement_id=entitlement.id LEFT JOIN {$p}enrolments enrolment ON enrolment.id=plan.enrolment_id JOIN {$p}renewal_cycles cycle ON cycle.id=%d JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE entitlement.id=%d",$cycleId,$entitlementId));
        if(!$row
            ||(string)$row->state!=='term_bound'
            ||(int)$row->term_id!==$termId
            ||$termId<1
            ||(int)$row->student_id!==(int)$row->cycle_student_id
            ||(int)$row->course_id!==(int)$row->cycle_course_id)
            throw new \RuntimeException('renewal_term_binding_conflict');
    }
}
