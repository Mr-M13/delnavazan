<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;

/** Read-only reconciliation projection for attempts reserved at transport but not durably closed. */
final class NotificationHandOffReconciliation {
 public function __construct(private ?NotificationAttemptRepository $attempts=null,private ?NotificationDeliveryRepository $deliveries=null){$this->attempts??=new NotificationAttemptRepository();$this->deliveries??=new NotificationDeliveryRepository();}
 public function unresolved(string $before,int $limit=50):array{
  $rows=array();
  foreach($this->attempts->unresolvedHandOffs($before,$limit) as $attempt){
   $facts=$this->deliveries->deliveriesForAttempt((int)$attempt->id);
   $rows[]=array(
    'attempt_id'=>(int)$attempt->id,
    'notification_id'=>(int)$attempt->notification_id,
    'attempt_sequence'=>(int)$attempt->attempt_sequence,
    'handed_off_at'=>(string)$attempt->updated_at,
    'provider_evidence_present'=>$facts!==array(),
    'reconciliation_state'=>$facts!==array()?'provider_evidence_review':'operator_review',
    // `stuck_lease` remains the closed §14 diagnostic member; reconciliation_state carries the precise meaning.
    'diagnostic'=>'stuck_lease',
   );
  }
  return $rows;
 }
}
