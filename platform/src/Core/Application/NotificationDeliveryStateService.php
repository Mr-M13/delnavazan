<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;

/**
 * Applies verified S-owned delivery evidence to the notification aggregate.
 *
 * Provider facts remain append-only evidence. This service owns the separate aggregate transition and
 * deliberately does not mutate the transport outbox, which was already completed at hand-off acknowledgement.
 */
final class NotificationDeliveryStateService {
    private const CAPABILITY='dzn_operate_notification_dispatch';

    public function __construct(
        private ?NotificationRepository $notifications=null,
        private ?NotificationDeliveryRepository $deliveries=null
    ){
        $this->notifications??=new NotificationRepository();
        $this->deliveries??=new NotificationDeliveryRepository();
    }

    public function apply(int $notificationId):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $this->notifications->begin();
        try{
            $notification=$this->notifications->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            $state=(string)$notification->state;
            if(in_array($state,array('delivered','closed'),true)){
                $this->notifications->commit();
                return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>'already_applied');
            }
            if($state!=='dispatched'){
                $this->notifications->commit();
                return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>'not_applicable');
            }

            $delivered=null;
            foreach($this->deliveries->appliedDeliveries($notificationId) as $fact){
                if((string)$fact->delivery_state==='delivered')$delivered=$fact;
            }
            if($delivered===null){
                $this->notifications->commit();
                return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>'no_delivered_fact');
            }

            $now=NotificationSupport::now();
            $this->notifications->transition($notificationId,'dispatched',array('state'=>'delivered','updated_at'=>$now,'updated_by'=>$actor));
            $this->notifications->insertEvent(array(
                'uid'=>NotificationSupport::uid(),
                'notification_id'=>$notificationId,
                'event_sequence'=>$this->notifications->nextSequence($notificationId),
                'event_type'=>'delivered',
                'from_state'=>'dispatched',
                'to_state'=>'delivered',
                'reason_code'=>'delivered',
                'evidence_channel'=>'provider_evidence',
                'evidence_reference_digest'=>(string)$delivered->provider_fact_digest,
                'evidence_at'=>(string)$delivered->occurred_at,
                'occurred_at'=>(string)$delivered->occurred_at,
                'recorded_at'=>$now,
                'recorded_by'=>$actor,
                'created_at'=>$now,
                'created_by'=>$actor,
            ));
            $this->notifications->commit();
            return array('notification_id'=>$notificationId,'state'=>'delivered','applied'=>true,'outcome'=>'delivered');
        }catch(\Throwable $e){
            $this->notifications->rollback();
            throw $e;
        }
    }
}
