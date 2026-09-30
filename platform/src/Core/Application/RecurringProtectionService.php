<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\RecurringProtectionRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Phase 2A.2-R2 continuous cross-Term protection: links a renewal cycle to an R1 protected claim. */
final class RecurringProtectionService {
    private const CAPABILITY='dzn_manage_recurring_protection';
    public function __construct(private ?RecurringProtectionRepository $repository=null){$this->repository??=new RecurringProtectionRepository();}

    public function establishProtection(int $cycleId,int $claimId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        // Provisional guard only: the authoritative claim check runs again inside the serialised
        // transaction below, so a claim released between these two points can never be adopted.
        if(!$this->claimActive($claimId))throw new \InvalidArgumentException('commercial_capacity_claim_not_active');
        if(!$this->cycleLive($cycleId))throw new \InvalidArgumentException('invalid_renewal_cycle_state');
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'establish_protection','renewal_cycle_id'=>$cycleId,'claim_id'=>$claimId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            // §5.6: a protection extends the cycle's own current-Term protected capacity across the
            // next-Term boundary, so the claim it adopts must still be an active claim of the same
            // beneficiary Student and Course and must not already be protection-linked elsewhere.
            $this->assertClaimOwnership($cycleId,$claimId);
            if($this->repository->forCycle($cycleId))throw new \InvalidArgumentException('recurring_protection_already_exists');
            if($this->repository->forClaim($claimId))throw new \InvalidArgumentException('recurring_protection_already_exists');
            $now=RecurringSupport::now();
            $id=$this->repository->insertProtection(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'claim_id'=>$claimId,'state'=>'active',
                'recurring_protection_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_protection_id'=>$id,'event_sequence'=>1,'event_type'=>'established',
                'from_state'=>null,'to_state'=>'active','reason_code'=>'established','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'establish_protection',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_protection_id'=>$id,
                'result_state'=>'active','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_protection_event_insert','establish_protection',$id);
            $this->repository->commit();
            return array('recurring_protection_id'=>$id,'state'=>'active','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    public function extendProtection(int $protectionId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'extend_protection','recurring_protection_id'=>$protectionId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_protection',$protectionId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $protection=$this->repository->find($protectionId,true);
            if(!$protection||(string)$protection->state!=='active')throw new \InvalidArgumentException('invalid_recurring_protection_state');
            $now=RecurringSupport::now();
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_protection_id'=>$protectionId,'event_sequence'=>$this->repository->nextSequence($protectionId),'event_type'=>'extended',
                'from_state'=>'active','to_state'=>'active','reason_code'=>'extended','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'extend_protection',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_protection_id'=>$protectionId,
                'result_state'=>'active','result_id'=>$protectionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_protection_event_insert','extend_protection',$protectionId);
            $this->repository->commit();
            return array('recurring_protection_id'=>$protectionId,'state'=>'active','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    public function releaseProtection(int $protectionId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'release_protection','recurring_protection_id'=>$protectionId,'evidence_reference_digest'=>$evidence['digest']));
        // 1. Authorise the recorded aggregate position before any delegation.
        $hint=$this->repository->find($protectionId);
        if(!$hint||(string)$hint->state!=='active')throw new \InvalidArgumentException('invalid_recurring_protection_state');
        $claimId=(int)$hint->claim_id;
        // 1b. §5.6: never release a predecessor before its successor is durable. The authority for the
        //     release is proved from the cycle's own stored facts *before* any R1 capacity is touched, so
        //     an unauthorised release can never reach the protected-interval release at all.
        if(($refusal=$this->releaseRefusal((int)$hint->renewal_cycle_id,$claimId))!==null)throw new \InvalidArgumentException($refusal);
        // 2. The underlying protected-capacity release is delegated to R1, which owns the transaction
        //    and releases the intervals under the same per-Teacher scheduling root as R1-D10. A
        //    predecessor claim or hold is released there only after its successor is durable.
        (new CommercialCapacityService())->releaseClaim($claimId,array('evidence_channel'=>$evidence['channel'],'evidence_reference'=>(string)($input['evidence_reference']??''),'evidence_at'=>$evidence['at'],'release_reason_code'=>'commercial_resolution'),$key);
        // 3. Record the durable R1 outcome in R2's own serialised transaction, after verifying it.
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('recurring_protection',$protectionId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $protection=$this->repository->find($protectionId,true);
            if(!$protection||(string)$protection->state!=='active')throw new \InvalidArgumentException('invalid_recurring_protection_state');
            // The release authority is re-proved after the aggregate is locked: a cycle that was rewritten
            // into an unauthorised position while the R1 half was running fails this half closed.
            if(($refusal=$this->releaseRefusal((int)$protection->renewal_cycle_id,(int)$protection->claim_id))!==null)throw new \InvalidArgumentException($refusal);
            if(!$this->claimReleased((int)$protection->claim_id))throw new \RuntimeException('recurring_protection_release_conflict');
            $now=RecurringSupport::now();
            $this->repository->updateProtection($protectionId,(int)$protection->recurring_protection_version,array('state'=>'released'),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'recurring_protection_id'=>$protectionId,'event_sequence'=>$this->repository->nextSequence($protectionId),'event_type'=>'released',
                'from_state'=>'active','to_state'=>'released','reason_code'=>'released','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'release_protection',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'recurring_protection_id'=>$protectionId,
                'result_state'=>'released','result_id'=>$protectionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_protection_event_insert','release_protection',$protectionId);
            $this->repository->commit();
            return array('recurring_protection_id'=>$protectionId,'state'=>'released','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated recurring protection command');
        $protection=$this->repository->find((int)$command->result_id);
        if(!$protection||(string)$protection->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated recurring protection result');
        return array('recurring_protection_id'=>(int)$protection->id,'state'=>(string)$protection->state,'created'=>false,'idempotent'=>true);
    }
    private function claimActive(int $claimId):bool{global $wpdb;$p=$wpdb->prefix.'dzn_';$row=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",$claimId));return $row&&(string)$row->state==='active';}
    private function claimReleased(int $claimId):bool{global $wpdb;$p=$wpdb->prefix.'dzn_';$row=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",$claimId));return $row&&(string)$row->state==='released';}
    /**
     * The adopted claim must be the cycle's own *current-Term* claim.
     *
     * §5.6 protects the current Term's capacity across the next-Term boundary, so the claim R2 adopts
     * must be the active claim of the cycle's recorded `source_term_id` for the cycle's own beneficiary
     * Student and Course — not merely any active claim the same Student happens to hold for the same
     * Course, and in particular not the successor-Term claim the renewal itself will create. Both the
     * cycle's live state and the claim's identity are re-proven here, inside the serialised transaction
     * and after the cycle row is locked, rather than trusted from the provisional read: a claim
     * released, replaced or superseded in the interim fails this command closed.
     */
    private function assertClaimOwnership(int $cycleId,int $claimId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $claim=$wpdb->get_row($wpdb->prepare("SELECT claim.state AS state,claim.student_id AS student_id,claim.course_id AS course_id,claim.term_id AS term_id,cycle.state AS cycle_state,cycle.source_term_id AS cycle_term_id,recurring.student_id AS cycle_student_id,recurring.course_id AS cycle_course_id FROM {$p}commercial_capacity_claims claim JOIN {$p}renewal_cycles cycle ON cycle.id=%d JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE claim.id=%d",$cycleId,$claimId));
        if(!$claim||!in_array((string)$claim->cycle_state,RecurringRule::CYCLE_LIVE_STATES,true))throw new \InvalidArgumentException('invalid_renewal_cycle_state');
        if((string)$claim->state!=='active')throw new \InvalidArgumentException('commercial_capacity_claim_not_active');
        if($claim->term_id===null||(int)$claim->term_id!==(int)$claim->cycle_term_id
            ||(int)$claim->student_id!==(int)$claim->cycle_student_id
            ||(int)$claim->course_id!==(int)$claim->cycle_course_id)throw new \InvalidArgumentException('recurring_protection_claim_conflict');
    }
    /**
     * §5.6: a predecessor claim may only be released once its successor is durable, or along an
     * authorised terminal path.
     *
     * The authorised terminal paths are:
     *
     * 1. the underlying claim is *already released in R1* — the capacity has returned to the Teacher
     *    through R1's own authority, so R2 must be able to record that durable fact (and a release whose
     *    R1 half committed before an R2 write-boundary failure must converge on the retry) instead of
     *    leaving a live protection behind a released claim that no later command could clear;
     * 2. the cycle itself already reached a terminal state (`lapsed`/`cancelled`, where the renewal
     *    ended); and
     * 3. a recovery case of this very cycle reached its explicit, evidenced terminal `lapsed` state (the
     *    collection failed terminally, so there is no successor coming).
     *
     * Anything else — including the ordinary "release the guaranteed slot early" shape — fails closed
     * with `renewal_successor_not_durable` instead of releasing protected capacity R2 cannot justify.
     */
    private function releaseRefusal(int $cycleId,int $claimId):?string{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        if($this->claimReleased($claimId))return null;
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId));
        if(!$cycle)return 'renewal_cycle_required';
        if(in_array((string)$cycle->state,array('lapsed','cancelled'),true))return null;
        if($this->successorDurable($cycleId))return null;
        if($this->terminalLapseRecorded($cycleId))return null;
        return 'renewal_successor_not_durable';
    }
    /**
     * The successor is durable when the cycle's own next Term carries an R1 funding plan *and* the
     * custody of that Term's protected capacity: a claim bound to the successor Term inside the cycle's
     * own Enrolment. Only then may the predecessor's claim intervals be released.
     */
    private function successorDurable(int $cycleId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT cycle.state AS state,cycle.next_term_id AS next_term_id,recurring.enrolment_id AS enrolment_id FROM {$p}renewal_cycles cycle JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE cycle.id=%d",$cycleId));
        if(!$row||$row->next_term_id===null||!in_array((string)$row->state,array('term_bound','closed'),true))return false;
        return $wpdb->get_row($wpdb->prepare("SELECT plan.id FROM {$p}commercial_term_funding_plans plan JOIN {$p}commercial_capacity_claims claim ON claim.term_id=plan.term_id WHERE plan.term_id=%d AND plan.enrolment_id=%d AND claim.state IN ('active','released') LIMIT 1",(int)$row->next_term_id,(int)$row->enrolment_id))!==null;
    }
    /** An explicit, evidenced terminal recovery lapse of this cycle is an authorised terminal path. */
    private function terminalLapseRecorded(int $cycleId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recovery_cases WHERE renewal_cycle_id=%d AND state='lapsed'",$cycleId))>0;
    }
    /** Protection extends a live cycle's claim across the next-Term boundary; a terminal cycle has none. */
    private function cycleLive(int $cycleId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId));
        return $row&&in_array((string)$row->state,RecurringRule::CYCLE_LIVE_STATES,true);
    }
}
