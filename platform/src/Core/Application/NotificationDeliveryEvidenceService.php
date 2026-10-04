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

        $providerEventReference=trim($providerEventReference);
        if($providerEventReference==='')throw new \InvalidArgumentException('delivery_event_reference_required');
        if(NotificationSupport::seconds($observedAt)===null)throw new \InvalidArgumentException('delivery_event_stale');
        $eventDigest=hash_hmac('sha256','delivery_event:'.$providerEventReference,NotificationSupport::salt());
        $factDigest=hash_hmac('sha256','delivery_fact:'.$attemptId.':'.$state.':'.($providerMessageReference??'').':'.$observedAt,NotificationSupport::salt());
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
            'delivery_rank'=>$rank,
            'provider_fact_digest'=>$factDigest,
            'provider_event_reference_digest'=>$eventDigest,
            'occurred_at'=>$observedAt,
            'recorded_at'=>NotificationSupport::now(),
            'applied'=>$applied,
        ));
        return array('applied'=>$applied===NotificationRule::DELIVERY_APPLIED,'outcome'=>$outcome,'delivery_id'=>$id);
    }
}
