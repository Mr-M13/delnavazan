<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\RecoveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Phase 2A.2-R2 Recovery Case authority: representation only, no automatic lapse machinery. */
final class RecoveryService {
    private const CAPABILITY='dzn_manage_recovery';
    public function __construct(private ?RecoveryRepository $repository=null){$this->repository??=new RecoveryRepository();}

    public function openRecovery(int $collectionIntentId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $context=$this->intentContext($collectionIntentId);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'open_recovery','collection_intent_id'=>$collectionIntentId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('collection_intent',$collectionIntentId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            if($this->repository->forIntent($collectionIntentId))throw new \InvalidArgumentException('recovery_case_already_exists');
            // §5.4: a recovery case records a *failed* collection intent of a still-live cycle. The
            // source state is proved from stored facts inside the serialised transaction, so a
            // pending, submitted, confirmed, recovered or cancelled intent — and a cycle that already
            // reached a terminal state — fails this command closed instead of being re-presented as a
            // recoverable collection.
            $this->assertRecoverable($collectionIntentId);
            $now=RecurringSupport::now();
            $id=$this->repository->insertCase(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$context['recurring_enrolment_id'],'renewal_cycle_id'=>$context['renewal_cycle_id'],
                'collection_intent_id'=>$collectionIntentId,'state'=>'open','recovery_case_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recovery_case_id'=>$id,'event_sequence'=>1,'event_type'=>'opened',
                'from_state'=>null,'to_state'=>'open','reason_code'=>'opened','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'open_recovery',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recovery_case_id'=>$id,
                'result_state'=>'open','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_recovery_case_event_insert','open_recovery',$id);
            $this->repository->commit();
            return array('recovery_case_id'=>$id,'state'=>'open','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    public function recordRecoveryAttempt(int $caseId,array $input,string $key):array{return $this->transition($caseId,array('open','recovering'),'recovering','record_recovery_attempt',$input,$key);}
    /**
     * §5.4: `recovered` records the exact R1 evidence that settled the obligation.
     *
     * R2 never settles anything itself, so the recovery is proved against the same accepted-R1-evidence
     * seam the collection commands consume. The proof runs inside the serialised transaction, after the
     * aggregate is locked, so a settlement withdrawn (or evidence R1 never accepted) between the read
     * and the write still fails this command closed with the shared `obligation_not_settled` /
     * `accepted_payment_evidence_required` reason.
     */
    public function markRecovered(int $caseId,array $input,string $key):array{
        return $this->transition($caseId,array('open','recovering'),'recovered','mark_recovered',$input,$key,function(object $case):void{$this->assertRecoverySettled((int)$case->collection_intent_id);});
    }
    public function markLapsed(int $caseId,array $input,string $key):array{
        // No automatic lapse while PAYMENT_RECOVERY_POLICY is unset; an explicit administrator command
        // may lapse only when policy authorises it or a deliberate unset-policy override is supplied.
        $policy=(new CommercialPolicyService())->current('PAYMENT_RECOVERY_POLICY');
        if(!$policy['set']&&empty($input['policy_unset_authorisation']))throw new \InvalidArgumentException('recovery_policy_unset');
        return $this->transition($caseId,array('open','recovering'),'lapsed','mark_lapsed',$input,$key);
    }

    private function transition(int $caseId,array $from,string $to,string $operation,array $input,string $key,?callable $guard=null):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>$operation,'recovery_case_id'=>$caseId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recovery_case',$caseId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $case=$this->repository->find($caseId,true);
            if(!$case||!in_array((string)$case->state,$from,true))throw new \InvalidArgumentException('invalid_recovery_case_state');
            if($guard!==null)$guard($case);
            $now=RecurringSupport::now();
            $this->repository->updateCase($caseId,(int)$case->recovery_case_version,array('state'=>$to),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recovery_case_id'=>$caseId,'event_sequence'=>$this->repository->nextSequence($caseId),'event_type'=>$operation==='record_recovery_attempt'?'attempt_recorded':$to,
                'from_state'=>(string)$case->state,'to_state'=>$to,'reason_code'=>$operation,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recovery_case_id'=>$caseId,
                'result_state'=>$to,'result_id'=>$caseId,'created_at'=>$now,'created_by'=>$actor,
            ));
            if($operation==='mark_recovered')RecurringSupport::publishIntent('recovery_case',$caseId,'PAYMENT_RECOVERED',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_recovery_case_event_insert',$operation,$caseId);
            $this->repository->commit();
            return array('recovery_case_id'=>$caseId,'state'=>$to,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated recovery command');
        $case=$this->repository->find((int)$command->result_id);
        if(!$case||(string)$case->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated recovery result');
        return array('recovery_case_id'=>(int)$case->id,'state'=>(string)$case->state,'created'=>false,'idempotent'=>true);
    }
    private function intentContext(int $intentId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $intent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}collection_intents WHERE id=%d",$intentId));
        if(!$intent)throw new \InvalidArgumentException('collection_intent_required');
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}renewal_cycles WHERE id=%d",(int)$intent->renewal_cycle_id));
        if(!$cycle)throw new \InvalidArgumentException('renewal_cycle_required');
        return array('recurring_enrolment_id'=>(int)$cycle->recurring_enrolment_id,'renewal_cycle_id'=>(int)$cycle->id);
    }

    /**
     * §5.4: `open` records a failed collection intent — nothing else.
     *
     * A pending, submitted, confirmed, recovered or cancelled intent can never seed a recovery case,
     * and a cycle that has already reached a terminal state (`lapsed`, `cancelled`, `closed`) is never
     * reopened by a recovery record. Both facts are re-read after the owning Student's commercial
     * account root is held, so a racing intent or cycle transition fails this command closed with
     * `collection_intent_not_failed` / `invalid_renewal_cycle_state` rather than a stale pre-state read.
     */
    private function assertRecoverable(int $intentId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $intent=$wpdb->get_row($wpdb->prepare("SELECT state,renewal_cycle_id FROM {$p}collection_intents WHERE id=%d",$intentId));
        if(!$intent||(string)$intent->state!=='failed')throw new \InvalidArgumentException('collection_intent_not_failed');
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$intent->renewal_cycle_id));
        if(!$cycle||!in_array((string)$cycle->state,RecurringRule::CYCLE_LIVE_STATES,true))throw new \InvalidArgumentException('invalid_renewal_cycle_state');
    }

    /**
     * §5.4: `recovered` records the exact R1 evidence that settled the obligation.
     *
     * The settlement is re-proved from the case's own collection intent through the shared
     * accepted-R1-evidence seam, so R2 can never record a recovery against an obligation that is not
     * settled (`obligation_not_settled`) or against evidence R1 never accepted
     * (`accepted_payment_evidence_required`). R2 records the provider-neutral outcome and writes no
     * settlement, reversal or clawback of its own.
     */
    private function assertRecoverySettled(int $intentId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $intent=$wpdb->get_row($wpdb->prepare("SELECT obligation_id FROM {$p}collection_intents WHERE id=%d",$intentId));
        if(!$intent)throw new \InvalidArgumentException('collection_intent_required');
        $reason=RecurringSupport::settlementReason((int)$intent->obligation_id);
        if($reason!==null)throw new \InvalidArgumentException($reason);
    }
}
