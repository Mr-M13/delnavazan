<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * One bounded worker pass. Construction is injected so registration cannot accidentally create a live
 * transport before recipient/contact and provider bindings are configured.
 */
final class NotificationDispatchWorker {
 public function __construct(private NotificationDispatchService $dispatch){}
 public function runOnce():array{
  return NotificationSystemActor::run(function(){
   $runId=NotificationSupport::uid();
   $evidence=array('evidence_channel'=>'system','evidence_reference'=>'notification-worker:'.$runId,'evidence_at'=>NotificationSupport::now());
   $recovery=$this->dispatch->recoverExpiredLeases($evidence,'worker:recover:'.$runId);
   $claim=$this->dispatch->claimLease($evidence,'worker:claim:'.$runId);
   if(empty($claim['claimed']))return array('recovery'=>$recovery,'claim'=>$claim);
   $attempt=(int)$claim['attempt_id'];
   $handoff=$this->dispatch->handOff($attempt,$evidence,'worker:handoff:'.$attempt.':'.$runId);
   if(empty($handoff['handed_off']))return array('recovery'=>$recovery,'claim'=>$claim,'handoff'=>$handoff);
   if(!empty($handoff['acknowledged'])){
    $outcome=$this->dispatch->recordOutcome($attempt,$evidence+array('acknowledged'=>true),'worker:outcome:'.$attempt.':'.$stamp);
   }elseif(($handoff['permanent_failure']??null)!==null){
    $outcome=$this->dispatch->recordOutcome($attempt,$evidence+array('acknowledged'=>false,'failure_class'=>'terminal','reason_code'=>(string)$handoff['permanent_failure']),'worker:outcome:'.$attempt.':'.$stamp);
   }else{
    $outcome=$this->dispatch->recordOutcome($attempt,$evidence+array('acknowledged'=>false,'failure_class'=>'retryable','reason_code'=>'retryable'),'worker:outcome:'.$attempt.':'.$stamp);
   }
   return array('recovery'=>$recovery,'claim'=>$claim,'handoff'=>$handoff,'outcome'=>$outcome);
  });
 }
}
