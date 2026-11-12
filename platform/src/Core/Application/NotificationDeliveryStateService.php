<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;

/**
 * Applies verified S-owned delivery evidence to the notification aggregate.
 *
 * Provider facts remain append-only evidence. This service owns only the notification aggregate
 * transition; each enabled delivery method is independent.
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
        $state='';
        $delivered=null;
        $transitioned=false;
        $this->notifications->begin();
        try{
            $notification=$this->notifications->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            $state=(string)$notification->state;

            foreach($this->deliveries->appliedDeliveries($notificationId) as $fact){
                if((string)$fact->delivery_state==='delivered')$delivered=$fact;
            }
            $now=NotificationSupport::now();
            if($delivered!==null&&$state==='dispatched'){
                $this->notifications->transition($notificationId,'dispatched',array('state'=>'delivered','delivered_at'=>(string)$delivered->occurred_at,'updated_at'=>$now,'updated_by'=>$actor));
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
                $state='delivered';
                $transitioned=true;
            }
            $this->notifications->commit();
        }catch(\Throwable $e){
            $this->notifications->rollback();
            throw $e;
        }

        return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>$delivered===null?'no_delivered_fact':'not_applicable');
    }
}
