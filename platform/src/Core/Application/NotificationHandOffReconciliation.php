<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;

/**
 * Reconciliation for attempts reserved at transport but not durably closed.
 *
 * Absence of provider evidence is never permission to resend. An applied, verified fact belonging to the
 * exact handed-off attempt proves the provider accepted that hand-off, so reconciliation delegates the
 * closure to NotificationDispatchService::recordOutcome rather than inventing a second lifecycle writer.
 */
final class NotificationHandOffReconciliation {
 public function __construct(
  private ?NotificationAttemptRepository $attempts=null,
  private ?NotificationDeliveryRepository $deliveries=null,
  private ?NotificationDispatchService $dispatch=null
 ){
  $this->attempts??=new NotificationAttemptRepository();
  $this->deliveries??=new NotificationDeliveryRepository();
 }
 public function unresolved(string $before,int $limit=50):array{
  $rows=array();
  foreach($this->attempts->unresolvedHandOffs($before,$limit) as $attempt){
   $facts=$this->deliveries->deliveriesForAttempt((int)$attempt->id);
   $applied=array_values(array_filter($facts,static fn(object $fact):bool=>(int)$fact->applied===NotificationRule::DELIVERY_APPLIED));
   $rows[]=array(
    'attempt_id'=>(int)$attempt->id,
    'notification_id'=>(int)$attempt->notification_id,
    'attempt_sequence'=>(int)$attempt->attempt_sequence,
    'handed_off_at'=>(string)$attempt->updated_at,
    'provider_evidence_present'=>$facts!==array(),
    'verified_applied_evidence_present'=>$applied!==array(),
    'reconciliation_state'=>$applied!==array()?'provider_evidence_reconcilable':($facts!==array()?'provider_evidence_review':'operator_review'),
    // `stuck_lease` remains the closed §14 diagnostic member; reconciliation_state carries the precise meaning.
    'diagnostic'=>'stuck_lease',
   );
  }
  return $rows;
 }

 /** Close only an exact ambiguous hand-off proved by an applied, verified provider fact. */
 public function reconcile(int $attemptId):array{
  $attempt=$this->attempts->find($attemptId);
  if(!$attempt||$attempt->finished_at!==null||(string)$attempt->state!=='handed_off')return array('attempt_id'=>$attemptId,'reconciled'=>false,'outcome'=>'not_applicable');
  $fact=null;
  foreach($this->deliveries->deliveriesForAttempt($attemptId) as $candidate){
   if((int)$candidate->applied===NotificationRule::DELIVERY_APPLIED){$fact=$candidate;break;}
  }
  if($fact===null)return array('attempt_id'=>$attemptId,'reconciled'=>false,'outcome'=>'operator_review');
  if($this->dispatch===null)throw new \RuntimeException('notification_dispatch_service_required');

  $result=$this->dispatch->recordOutcome(
   $attemptId,
   array(
    'acknowledged'=>true,
    'evidence_channel'=>'provider_evidence',
    'evidence_reference'=>(string)$fact->provider_fact_digest,
    'evidence_at'=>(string)$fact->occurred_at,
   ),
   'handoff-reconciliation:'.$attemptId.':'.(string)$fact->provider_fact_digest,
   'reconcile_handoff'
  );
  return array('attempt_id'=>$attemptId,'notification_id'=>(int)$attempt->notification_id,'reconciled'=>true,'outcome'=>'acknowledged','state'=>(string)$result['state']);
 }
}
