<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Commercial exception and reconciliation authority.
 *
 * Ambiguous or conflicting commercial evidence is never guessed at and never silently discarded:
 * it is preserved and routed into a controlled, capability-gated review state. A recurrence of the
 * same fault class converges on the one open row and only increments its occurrence count.
 */
final class CommercialExceptionService {
    private const CAPABILITY='dzn_manage_commercial_exceptions';
    public function __construct(private ?CommercialCapacityRepository $repository=null){$this->repository??=new CommercialCapacityRepository();}

    /** Record inside the caller's transaction (the caller owns commit/rollback). */
    public function recordWithinTransaction(array $data):int{
        return $this->insertRow($data);
    }
    /** Record after the caller's transaction has rolled back, so the durable evidence survives. */
    public function recordAfterFailure(array $data):int{
        $this->repository->begin();
        try{$id=$this->insertRow($data);$this->repository->commit();return $id;}
        catch(\Throwable$e){$this->repository->rollback();throw $e;}
    }

    public function acknowledge(int $exceptionId,array $input,string $key):array{return $this->close($exceptionId,'acknowledged',$input,$key);}
    public function resolve(int $exceptionId,array $input,string $key):array{return $this->close($exceptionId,'resolved',$input,$key);}

    public function open():array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        return array_map(array($this,'view'),$this->repository->exceptions('open'));
    }
    public function forStudent(int $studentId):array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        return array_map(array($this,'view'),$this->repository->exceptionsForStudent($studentId));
    }

    private function insertRow(array $data):int{
        $reason=(string)($data['reason_code']??'');
        if(!CommercialRule::exceptionReason($reason))throw new \InvalidArgumentException('Controlled commercial exception reason required');
        $severity=(string)($data['severity']??'error');
        if(!in_array($severity,CommercialRule::EXCEPTION_SEVERITIES,true))throw new \InvalidArgumentException('Controlled commercial exception severity required');
        $summary=trim((string)($data['summary']??''));
        if($summary==='')throw new \InvalidArgumentException('Commercial exception summary required');
        $scope=(string)($data['scope']??'commercial');
        $value=(string)($data['fingerprint_value']??$reason);
        $fingerprint=CommercialIdempotency::fingerprint($reason,$scope,$value);
        $now=CommercialSupport::now();
        $actor=CommercialSupport::actor('Commercial exception actor unavailable');
        $lock=$this->repository->acquireFingerprintLock($fingerprint);
        try{
            $existing=$this->repository->openException($fingerprint,true);
            if($existing){
                $this->repository->touchException((int)$existing->id,(int)$existing->occurrence_count+1,$now);
                return (int)$existing->id;
            }
            return $this->repository->insertException(array(
            'uid'=>Identifier::uid(),'reference_code'=>null,'reason_code'=>$reason,'severity'=>$severity,'state'=>'open',
            'fingerprint'=>$fingerprint,'summary'=>mb_substr($summary,0,255),'safe_detail'=>isset($data['safe_detail'])?mb_substr((string)$data['safe_detail'],0,65535):null,
            'student_id'=>isset($data['student_id'])?(int)$data['student_id']:null,
            'teacher_id'=>isset($data['teacher_id'])?(int)$data['teacher_id']:null,
            'offer_id'=>isset($data['offer_id'])?(int)$data['offer_id']:null,
            'purchase_id'=>isset($data['purchase_id'])?(int)$data['purchase_id']:null,
            'obligation_id'=>isset($data['obligation_id'])?(int)$data['obligation_id']:null,
            'evidence_id'=>isset($data['evidence_id'])?(int)$data['evidence_id']:null,
            'claim_id'=>isset($data['claim_id'])?(int)$data['claim_id']:null,
            'term_id'=>isset($data['term_id'])?(int)$data['term_id']:null,
            'detected_at'=>$now,'last_seen_at'=>$now,'occurrence_count'=>1,
            'resolved_at'=>null,'resolved_by'=>null,'resolution_note'=>null,
            'created_at'=>$now,'created_by'=>$actor,
            ));
        }finally{
            $this->repository->releaseFingerprintLock($lock);
        }
    }
    private function close(int $exceptionId,string $state,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $note=trim((string)($input['resolution_note']??''));
        if($note==='')throw new \InvalidArgumentException('Commercial exception resolution note required');
        $reason=CommercialSupport::reason($input);
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $operation=$state==='acknowledged'?'acknowledge_commercial_exception':'resolve_commercial_exception';
        $payload=CommercialIdempotency::payload(array('operation'=>$operation,'exception_id'=>$exceptionId,'reason_code'=>$reason,'note_digest'=>CommercialIdempotency::evidence($note),'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $exception=$this->repository->exception($exceptionId,true);
            if(!$exception)throw new \InvalidArgumentException('commercial_exception_required');
            $now=CommercialSupport::now();
            $current=(string)$exception->state;
            // An acknowledged exception may still be resolved; a resolved one may not be reopened.
            $permitted=$state==='acknowledged'?array('open'):array('open','acknowledged');
            if(in_array($current,$permitted,true))$this->repository->resolveException($exceptionId,$current,$state,$now,$actor,mb_substr($note,0,65535));
            elseif($current!==$state)throw new \InvalidArgumentException('commercial_exception_already_closed');
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
                'student_id'=>$exception->student_id===null?null:(int)$exception->student_id,
                'teacher_id'=>$exception->teacher_id===null?null:(int)$exception->teacher_id,
                'offer_id'=>$exception->offer_id===null?null:(int)$exception->offer_id,
                'purchase_id'=>$exception->purchase_id===null?null:(int)$exception->purchase_id,
                'obligation_id'=>$exception->obligation_id===null?null:(int)$exception->obligation_id,
                'claim_id'=>$exception->claim_id===null?null:(int)$exception->claim_id,
                'term_id'=>$exception->term_id===null?null:(int)$exception->term_id,
                'result_state'=>$state,'result_id'=>$exceptionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('exception_id'=>$exceptionId,'state'=>$state,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('acknowledge_commercial_exception','resolve_commercial_exception'),true))throw new \RuntimeException('Contaminated commercial exception command');
        $exception=$this->repository->exception((int)$command->result_id);
        if(!$exception)throw new \RuntimeException('Contaminated commercial exception result');
        return array('exception_id'=>(int)$exception->id,'state'=>(string)$exception->state,'created'=>false,'idempotent'=>true);
    }
    private function view(object $exception):array{
        return array(
            'exception_id'=>(int)$exception->id,'reason_code'=>(string)$exception->reason_code,'severity'=>(string)$exception->severity,
            'state'=>(string)$exception->state,'summary'=>(string)$exception->summary,'occurrence_count'=>(int)$exception->occurrence_count,
            'offer_id'=>$exception->offer_id===null?null:(int)$exception->offer_id,
            'purchase_id'=>$exception->purchase_id===null?null:(int)$exception->purchase_id,
            'obligation_id'=>$exception->obligation_id===null?null:(int)$exception->obligation_id,
            'claim_id'=>$exception->claim_id===null?null:(int)$exception->claim_id,
            'term_id'=>$exception->term_id===null?null:(int)$exception->term_id,
            'detected_at'=>(string)$exception->detected_at,
        );
    }
}
