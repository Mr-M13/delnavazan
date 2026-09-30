<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;

/**
 * §6.8/§8.1 — the channel-neutral, purpose-scoped suppression register.
 *
 * A suppression is evaluated at enqueue **and** immediately before hand-off, so one that appears while a
 * notification sits `queued` moves it to `suppressed` without a send. Nothing here decides consent: it
 * records an exclusion the identity/consent owner asked for.
 */
final class NotificationSuppressionService {
    private const CAPABILITY='dzn_manage_notification_suppressions';
    public function __construct(private ?NotificationSuppressionRepository $repository=null){$this->repository??=new NotificationSuppressionRepository();}

    public function suppress(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $subjectKind=trim((string)($input['subject_kind']??''));
        if(!in_array($subjectKind,array('student','guardian','academy'),true))throw new \InvalidArgumentException('suppressed');
        $subjectDigest=(string)($input['subject_digest']??'');
        if(!preg_match('/^[0-9a-f]{64}$/',$subjectDigest))throw new \InvalidArgumentException('suppressed');
        $purpose=substr(trim((string)($input['purpose']??'')),0,48);
        if($purpose==='')throw new \InvalidArgumentException('suppressed');
        $reason=NotificationSupport::reason($input,'reason_code');
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'suppress','subject_digest'=>$subjectDigest,'purpose'=>$purpose));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('suppression_id'=>(int)$winner->result_id,'state'=>'active','created'=>false,'idempotent'=>true);}
            $now=NotificationSupport::now();
            $id=$this->repository->insertSuppression(array(
                'uid'=>NotificationSupport::uid(),'reference_code'=>null,'subject_kind'=>$subjectKind,'subject_digest'=>$subjectDigest,
                'purpose'=>$purpose,'reason_code'=>$reason,'state'=>'active','effective_from'=>$input['effective_from']??$now,
                'expires_at'=>$input['expires_at']??null,'released_at'=>null,'released_by'=>null,'suppression_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>NotificationSupport::uid(),'suppression_id'=>$id,'event_sequence'=>1,'event_type'=>'suppressed',
                'from_state'=>null,'to_state'=>'active','reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'suppress',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'suppression_id'=>$id,'result_state'=>'active','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('suppression_id'=>$id,'state'=>'active','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('suppression_id'=>(int)$winner->result_id,'state'=>'active','created'=>false,'idempotent'=>true);
            throw $e;
        }
    }

    public function release(int $suppressionId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $reason=NotificationSupport::reason($input,'reason_code');
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('suppression_id'=>$suppressionId,'state'=>'released','created'=>false,'idempotent'=>true);}
            $suppression=$this->repository->find($suppressionId,true);
            if(!$suppression||(string)$suppression->state!=='active')throw new \InvalidArgumentException('invalid_notification_state');
            $now=NotificationSupport::now();
            $this->repository->updateSuppression($suppressionId,(int)$suppression->suppression_version,array('state'=>'released','released_at'=>$now,'released_by'=>$actor),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>NotificationSupport::uid(),'suppression_id'=>$suppressionId,'event_sequence'=>$this->repository->nextSequence($suppressionId),
                'event_type'=>'released','from_state'=>'active','to_state'=>'released','reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'release',
                'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'release','suppression_id'=>$suppressionId)),
                'suppression_id'=>$suppressionId,'result_state'=>'released','result_id'=>$suppressionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('suppression_id'=>$suppressionId,'state'=>'released','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }
}
