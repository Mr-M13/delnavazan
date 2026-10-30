<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;

/**
 * Applies verified S-owned delivery evidence to the notification aggregate.
 *
 * Provider facts remain append-only evidence. This service owns the separate aggregate transition and
 * deliberately does not mutate the transport outbox, which was already completed at hand-off acknowledgement.
 *
 * A delivered fact is absorbing.  A verified terminal WhatsApp failure is the only trigger that asks the
 * SMS fallback authority to create one linked fallback aggregate; unconfirmed, ambiguous or stale facts
 * never do.  A later delivered fact cancels any fallback that has not been dispatched yet.
 */
final class NotificationDeliveryStateService {
    private const CAPABILITY='dzn_operate_notification_dispatch';

    public function __construct(
        private ?NotificationRepository $notifications=null,
        private ?NotificationDeliveryRepository $deliveries=null,
        private ?NotificationSmsFallbackService $fallbacks=null
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

            $delivered=null;
            foreach($this->deliveries->appliedDeliveries($notificationId) as $fact){
                if((string)$fact->delivery_state==='delivered')$delivered=$fact;
            }
            $failure=$this->deliveries->latestAppliedFailure($notificationId);
            $now=NotificationSupport::now();
            if($delivered!==null){
                $cancelled=0;
                if($this->fallbacks!==null&&(int)$this->fallbacks->sourceId($notification)===0)$cancelled=$this->fallbacks->cancelForDeliveredSource($notificationId,$now,$actor);
                if($state==='delivered'){
                    $this->notifications->commit();
                    return array('notification_id'=>$notificationId,'state'=>'delivered','applied'=>false,'outcome'=>'already_applied','fallback_cancelled'=>$cancelled);
                }
                if($state!=='dispatched'){
                    $this->notifications->commit();
                    return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>'not_applicable','fallback_cancelled'=>$cancelled);
                }
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
                $this->notifications->commit();
                return array('notification_id'=>$notificationId,'state'=>'delivered','applied'=>true,'outcome'=>'delivered','fallback_cancelled'=>$cancelled);
            }

            if($state==='dispatched'&&$failure!==null&&$this->fallbacks!==null&&(int)$this->fallbacks->sourceId($notification)===0){
                $fallbacks=$this->fallbacks??new NotificationSmsFallbackService();
                $result=$fallbacks->authoriseFromVerifiedFailure(
                    $notificationId,(int)$failure->attempt_id,(string)$failure->provider_fact_digest,
                    array(
                        'evidence_channel'=>'provider_evidence',
                        'evidence_reference'=>'telnyx-failure:'.(string)$failure->provider_fact_digest,
                        'evidence_at'=>(string)$failure->occurred_at,
                    ),
                    true
                );
                $this->notifications->commit();
                return array(
                    'notification_id'=>$notificationId,'state'=>$state,'applied'=>false,
                    'outcome'=>$result['authorised']?'whatsapp_failure_fallback_authorised':'whatsapp_failure_fallback_blocked',
                    'fallback_notification_id'=>$result['fallback_notification_id'],'fallback_reason'=>$result['reason'],
                );
            }

            $this->notifications->commit();
            return array('notification_id'=>$notificationId,'state'=>$state,'applied'=>false,'outcome'=>$delivered===null?'no_delivered_fact':'not_applicable');
        }catch(\Throwable $e){
            $this->notifications->rollback();
            throw $e;
        }
    }
}
