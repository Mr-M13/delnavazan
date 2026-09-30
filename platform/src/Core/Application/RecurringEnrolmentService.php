<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\RecurringEnrolmentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Phase 2A.2-R2 Recurring Enrolment aggregate authority.
 *
 * A recurring enrolment freezes an already-funded canonical Enrolment into a recurring authority:
 * currency, region, mutable audited collection mode and a bounded active/suspended/closed lifecycle.
 * It never re-prices, re-accepts, re-funds or re-binds: every financial fact stays with R1.
 */
final class RecurringEnrolmentService {
    private const CAPABILITY='dzn_manage_recurring_enrolments';
    public function __construct(private ?RecurringEnrolmentRepository $repository=null){$this->repository??=new RecurringEnrolmentRepository();}

    public function establish(array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $enrolmentId=RecurringSupport::positiveInt($input['enrolment_id']??null,'Valid canonical Enrolment required');
        $mode=RecurringSupport::collectionMode($input);
        $ctx=$this->fundingContext($enrolmentId);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'establish','enrolment_id'=>$enrolmentId,'collection_mode'=>$mode,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            // R1 serialization root first: the owning Student's commercial account root is taken
            // before any recurring row is read or written, so the fixed R1 lock order is preserved.
            RecurringSupport::lockAccountRoot((int)$ctx['student_id'],$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replayEstablish($winner,$payload);$this->repository->commit();return $result;}
            if($this->repository->byEnrolment($enrolmentId))throw new \InvalidArgumentException('recurring_enrolment_already_exists');
            $now=RecurringSupport::now();
            $id=$this->repository->insertRecurring(array(
                'uid'=>Identifier::uid(),'enrolment_id'=>$enrolmentId,'student_id'=>$ctx['student_id'],'course_id'=>$ctx['course_id'],
                'currency'=>$ctx['currency'],'region_code'=>$ctx['region_code'],'collection_mode'=>$mode,'state'=>'active',
                'rule_version'=>RecurringRule::RULE_VERSION,'recurring_enrolment_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$id,'event_sequence'=>1,'event_type'=>'established',
                'from_state'=>null,'to_state'=>'active','from_collection_mode'=>null,'to_collection_mode'=>$mode,
                'reason_code'=>'established','evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'establish',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_enrolment_id'=>$id,
                'result_state'=>'active','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_recurring_command_insert','establish',$id);
            $this->repository->commit();
            return array('recurring_enrolment_id'=>$id,'enrolment_id'=>$enrolmentId,'state'=>'active','collection_mode'=>$mode,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayEstablish($winner,$payload);
            throw $e;
        }
    }

    public function setCollectionMode(int $recurringId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $mode=RecurringSupport::collectionMode($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'set_collection_mode','recurring_enrolment_id'=>$recurringId,'collection_mode'=>$mode,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_enrolment',$recurringId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replayTransition($winner,$payload);$this->repository->commit();return $result;}
            $row=$this->repository->find($recurringId,true);
            if(!$row||(string)$row->state==='closed')throw new \InvalidArgumentException('recurring_enrolment_not_operational');
            if((string)$row->collection_mode===$mode)throw new \InvalidArgumentException('collection_mode_unchanged');
            $now=RecurringSupport::now();
            $this->repository->updateRecurring($recurringId,(int)$row->recurring_enrolment_version,array('collection_mode'=>$mode),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$recurringId,'event_sequence'=>$this->repository->nextSequence($recurringId),'event_type'=>'collection_mode_changed',
                'from_state'=>(string)$row->state,'to_state'=>(string)$row->state,'from_collection_mode'=>(string)$row->collection_mode,'to_collection_mode'=>$mode,
                'reason_code'=>'collection_mode_changed','evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'set_collection_mode',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_enrolment_id'=>$recurringId,
                'result_state'=>(string)$row->state,'result_id'=>$recurringId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_recurring_command_insert','set_collection_mode',$recurringId);
            $this->repository->commit();
            return array('recurring_enrolment_id'=>$recurringId,'state'=>(string)$row->state,'collection_mode'=>$mode,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayTransition($winner,$payload);
            throw $e;
        }
    }

    public function suspend(int $recurringId,array $input,string $key):array{return $this->transition($recurringId,'active','suspended','suspend',$input,$key);}
    public function resume(int $recurringId,array $input,string $key):array{return $this->transition($recurringId,'suspended','active','resume',$input,$key);}
    public function close(int $recurringId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'close','recurring_enrolment_id'=>$recurringId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_enrolment',$recurringId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replayTransition($winner,$payload);$this->repository->commit();return $result;}
            $row=$this->repository->find($recurringId,true);
            if(!$row||(string)$row->state==='closed')throw new \InvalidArgumentException('recurring_enrolment_not_operational');
            if($this->hasOpenBlockingCase($recurringId))throw new \InvalidArgumentException('recurring_enrolment_not_closable');
            $now=RecurringSupport::now();
            $this->repository->updateRecurring($recurringId,(int)$row->recurring_enrolment_version,array('state'=>'closed'),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$recurringId,'event_sequence'=>$this->repository->nextSequence($recurringId),'event_type'=>'closed',
                'from_state'=>(string)$row->state,'to_state'=>'closed','from_collection_mode'=>(string)$row->collection_mode,'to_collection_mode'=>(string)$row->collection_mode,
                'reason_code'=>'closed','evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'close',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_enrolment_id'=>$recurringId,
                'result_state'=>'closed','result_id'=>$recurringId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_recurring_command_insert','close',$recurringId);
            $this->repository->commit();
            return array('recurring_enrolment_id'=>$recurringId,'state'=>'closed','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayTransition($winner,$payload);
            throw $e;
        }
    }

    private function transition(int $recurringId,string $from,string $to,string $operation,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>$operation,'recurring_enrolment_id'=>$recurringId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_enrolment',$recurringId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replayTransition($winner,$payload);$this->repository->commit();return $result;}
            $row=$this->repository->find($recurringId,true);
            if(!$row||(string)$row->state!==$from)throw new \InvalidArgumentException('invalid_recurring_enrolment_state');
            $now=RecurringSupport::now();
            $this->repository->updateRecurring($recurringId,(int)$row->recurring_enrolment_version,array('state'=>$to),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_enrolment_id'=>$recurringId,'event_sequence'=>$this->repository->nextSequence($recurringId),'event_type'=>($to==='active'?'resumed':'suspended'),
                'from_state'=>$from,'to_state'=>$to,'from_collection_mode'=>(string)$row->collection_mode,'to_collection_mode'=>(string)$row->collection_mode,
                'reason_code'=>$operation,'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_enrolment_id'=>$recurringId,
                'result_state'=>$to,'result_id'=>$recurringId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_recurring_command_insert',$operation,$recurringId);
            $this->repository->commit();
            return array('recurring_enrolment_id'=>$recurringId,'state'=>$to,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayTransition($winner,$payload);
            throw $e;
        }
    }

    private function replayEstablish(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN||(string)$command->operation!=='establish')throw new \RuntimeException('Contaminated recurring enrolment command');
        $row=$this->repository->find((int)$command->result_id);
        if(!$row||(string)$row->state!=='active')throw new \RuntimeException('Contaminated recurring enrolment result');
        return array('recurring_enrolment_id'=>(int)$row->id,'enrolment_id'=>(int)$row->enrolment_id,'state'=>(string)$row->state,'collection_mode'=>(string)$row->collection_mode,'created'=>false,'idempotent'=>true);
    }
    private function replayTransition(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated recurring enrolment command');
        $row=$this->repository->find((int)$command->result_id);
        if(!$row||(string)$row->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated recurring enrolment result');
        return array('recurring_enrolment_id'=>(int)$row->id,'state'=>(string)$row->state,'collection_mode'=>(string)$row->collection_mode,'created'=>false,'idempotent'=>true);
    }

    /** Read-only funding context: an applicable Enrolment with an authoritative R1 funding plan. */
    private function fundingContext(int $enrolmentId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $enrolment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}enrolments WHERE id=%d",$enrolmentId));
        if(!$enrolment)throw new \InvalidArgumentException('canonical_enrolment_required');
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_term_funding_plans WHERE enrolment_id=%d ORDER BY id DESC LIMIT 1",$enrolmentId));
        if(!$plan)throw new \InvalidArgumentException('funding_plan_required');
        $offer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offers WHERE id=%d",(int)$plan->offer_id));
        if(!$offer||RecurringSupport::currency((string)$offer->currency)===null)throw new \InvalidArgumentException('commercial_offer_required');
        return array('student_id'=>(int)$enrolment->student_id,'course_id'=>(int)$enrolment->course_id,'currency'=>(string)$offer->currency,'region_code'=>(string)$offer->region_code,'offer_id'=>(int)$offer->id);
    }
    /**
     * §5.1: closing a recurring enrolment requires the release of all active protection and no open
     * refund/recovery case. Every blocking case is resolved from stored facts — an unresolved refund
     * review reaches the recurring enrolment through its own canonical Enrolment, so a Student cannot
     * close a recurring enrolment while a refund/reversal review of the funded Term is still open.
     */
    private function hasOpenBlockingCase(int $recurringId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $enrolmentId=(int)$wpdb->get_var($wpdb->prepare("SELECT enrolment_id FROM {$p}recurring_enrolments WHERE id=%d",$recurringId));
        $cycleIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",$recurringId));
        if($cycleIds){
            $in=implode(',',array_map('intval',$cycleIds));
            $protection=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}recurring_protections WHERE renewal_cycle_id IN ({$in}) AND state='active'");
            if($protection>0)return true;
        }
        $recovery=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recovery_cases WHERE recurring_enrolment_id=%d AND state IN ('open','recovering')",$recurringId));
        if($recovery>0)return true;
        if($enrolmentId<1)return false;
        $refund=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}refund_review_cases review JOIN {$p}commercial_entitlements entitlement ON entitlement.purchase_id=review.purchase_id WHERE entitlement.enrolment_id=%d AND review.state IN ('open','review_required')",$enrolmentId));
        return $refund>0;
    }
}
