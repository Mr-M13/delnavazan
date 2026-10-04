<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;

/**
 * Provider-neutral ingestion of append-only delivery evidence.
 * Raw provider references are never persisted; only keyed digests cross into S-owned storage.
 */
final class NotificationDeliveryEvidenceService {
    public function __construct(
        private ?NotificationDeliveryRepository $deliveries=null,
        private ?NotificationAttemptRepository $attempts=null
    ){
        $this->deliveries??=new NotificationDeliveryRepository();
        $this->attempts??=new NotificationAttemptRepository();
    }

    public function record(int $attemptId,string $state,string $providerEventReference,?string $providerMessageReference,string $observedAt):array{
        $rank=NotificationRule::deliveryRank($state);
        if($rank===null)throw new \InvalidArgumentException('delivery_event_stale');
        $attempt=$this->attempts->find($attemptId);
        if(!$attempt||!in_array((string)$attempt->state,array('handed_off','acknowledged'),true))throw new \RuntimeException('attempt_lifecycle_invalid');

        $eventDigest=NotificationSupport::digest('delivery_event:'.$providerEventReference);
        $messageDigest=$providerMessageReference!==null&&$providerMessageReference!==''?NotificationSupport::digest('delivery_message:'.$providerMessageReference):null;
        $existing=$this->deliveries->byProviderReference($eventDigest);
        if($existing)return array('applied'=>(int)$existing->applied===NotificationRule::DELIVERY_APPLIED,'outcome'=>'replay','delivery_id'=>(int)$existing->id);

        $currentRank=$this->deliveries->appliedRank((int)$attempt->notification_id);
        $applied=NotificationRule::DELIVERY_APPLIED;$outcome='applied';
        if($rank<$currentRank){$applied=NotificationRule::DELIVERY_NOT_APPLIED;$outcome='delivery_regression_attempt';}
        elseif($rank===$currentRank&&$currentRank>0){$applied=NotificationRule::DELIVERY_NOT_APPLIED;$outcome='delivery_event_stale';}

        $id=$this->deliveries->insertDelivery(array(
            'uid'=>NotificationSupport::uid(),
            'notification_id'=>(int)$attempt->notification_id,
            'attempt_id'=>$attemptId,
            'delivery_sequence'=>$this->deliveries->nextSequence((int)$attempt->notification_id),
            'delivery_state'=>$state,
            'provider_message_reference_digest'=>$messageDigest,
            'provider_event_reference_digest'=>$eventDigest,
            'applied'=>$applied,
            'observed_at'=>$observedAt,
            'created_at'=>NotificationSupport::now(),
        ));
        return array('applied'=>$applied===NotificationRule::DELIVERY_APPLIED,'outcome'=>$outcome,'delivery_id'=>$id);
    }
}
