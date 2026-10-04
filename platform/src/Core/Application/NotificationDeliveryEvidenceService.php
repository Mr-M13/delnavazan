<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;

/**
 * S-owned intake for already verified, normalised delivery facts.
 *
 * Provider adapters verify signatures and derive keyed digests before crossing this boundary.
 * Raw provider identifiers and payloads are never accepted or persisted here.
 */
final class NotificationDeliveryEvidenceService {
    public function __construct(
        private ?NotificationDeliveryRepository $deliveries=null,
        private ?NotificationAttemptRepository $attempts=null
    ){
        $this->deliveries??=new NotificationDeliveryRepository();
        $this->attempts??=new NotificationAttemptRepository();
    }

    /**
     * @param array $normalisedFacts notification_id, attempt_id, delivery_state,
     * provider_fact_digest, provider_event_reference_digest, occurred_at.
     */
    public function submit(array $normalisedFacts):array{
        $notificationId=(int)($normalisedFacts['notification_id']??0);
        $attemptId=(int)($normalisedFacts['attempt_id']??0);
        $state=trim((string)($normalisedFacts['delivery_state']??''));
        $factDigest=strtolower(trim((string)($normalisedFacts['provider_fact_digest']??'')));
        $eventDigest=strtolower(trim((string)($normalisedFacts['provider_event_reference_digest']??'')));
        $occurredAt=trim((string)($normalisedFacts['occurred_at']??''));

        $rank=NotificationRule::deliveryRank($state);
        if($rank===null||NotificationSupport::seconds($occurredAt)===null)throw new \InvalidArgumentException('delivery_event_stale');
        if($notificationId<1||$attemptId<1||!preg_match('/^[a-f0-9]{64}$/',$factDigest)||!preg_match('/^[a-f0-9]{64}$/',$eventDigest))throw new \InvalidArgumentException('delivery_event_stale');

        $attempt=$this->attempts->find($attemptId);
        if(!$attempt||!in_array((string)$attempt->state,array('handed_off','acknowledged'),true)||(int)$attempt->notification_id!==$notificationId)throw new \RuntimeException('attempt_lifecycle_invalid');

        $existing=$this->deliveries->byProviderReference($eventDigest);
        if($existing)return array('applied'=>(int)$existing->applied===NotificationRule::DELIVERY_APPLIED,'outcome'=>'replay','delivery_id'=>(int)$existing->id);

        $currentRank=$this->deliveries->appliedRank($notificationId);
        $applied=NotificationRule::DELIVERY_APPLIED;$outcome='applied';
        if($rank<$currentRank){$applied=NotificationRule::DELIVERY_NOT_APPLIED;$outcome='delivery_regression_attempt';}
        elseif($rank===$currentRank&&$currentRank>0){$applied=NotificationRule::DELIVERY_NOT_APPLIED;$outcome='delivery_event_stale';}

        $id=$this->deliveries->insertDelivery(array(
            'uid'=>NotificationSupport::uid(),
            'notification_id'=>$notificationId,
            'attempt_id'=>$attemptId,
            'delivery_sequence'=>$this->deliveries->nextSequence($notificationId),
            'delivery_state'=>$state,
            'delivery_rank'=>$rank,
            'provider_fact_digest'=>$factDigest,
            'provider_event_reference_digest'=>$eventDigest,
            'occurred_at'=>$occurredAt,
            'recorded_at'=>NotificationSupport::now(),
            'applied'=>$applied,
        ));
        return array('applied'=>$applied===NotificationRule::DELIVERY_APPLIED,'outcome'=>$outcome,'delivery_id'=>$id);
    }
}
