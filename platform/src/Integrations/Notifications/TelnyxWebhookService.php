<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\NotificationDeliveryEvidenceService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStateService;
use Delnavazan\Platform\Core\Application\NotificationSuppressionService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationSystemActor;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;

/**
 * Idempotent Telnyx webhook intake.
 *
 * Trust is established by the Ed25519 signature before any JSON is parsed. The provider event id is
 * reduced to a keyed digest and recorded once; a replayed delivery is acknowledged without repeating
 * the side effect. Delivery facts use the existing monotonic evidence service, so out-of-order events
 * are retained but never regress the notification. Inbound STOP creates a purpose-wide suppression.
 */
final class TelnyxWebhookService {
    public function __construct(
        private ?TelnyxWebhookSignature $signature=null,
        private ?NotificationProviderRepository $provider=null,
        private ?NotificationDeliveryEvidenceService $evidence=null,
        private ?NotificationSuppressionService $suppressions=null,
        private ?StudentNotificationContactLookup $contacts=null,
        private ?NotificationDeliveryStateService $states=null
    ){
        $this->provider??=new NotificationProviderRepository();
        $this->evidence??=new NotificationDeliveryEvidenceService();
        $this->suppressions??=new NotificationSuppressionService();
        $this->contacts??=new StudentNotificationContactLookup();
        $this->states??=new NotificationDeliveryStateService();
    }

    public static function fromSettings($settings):self{
        return new self(TelnyxWebhookSignature::fromSettings((new TelnyxConfiguration($settings))->webhookPublicKey()));
    }

    /**
     * @param array<string,string> $headers lower-case request headers
     * @return array{http:int,outcome:string,replayed:bool}
     */
    public function receive(string $rawBody,array $headers,int $now):array{
        if($this->signature===null)return array('http'=>503,'outcome'=>'webhook_not_configured','replayed'=>false);
        $verification=$this->signature->verify($rawBody,$headers,$now);
        if(!($verification['valid']??false))return array('http'=>$this->verificationStatus((string)$verification['code']),'outcome'=>(string)$verification['code'],'replayed'=>false);
        try{$event=TelnyxWebhookEvent::normalise($rawBody);}
        catch(\InvalidArgumentException $error){return array('http'=>400,'outcome'=>$error->getMessage(),'replayed'=>false);}
        $eventDigest=$this->digest('event:'.$event['event_id']);
        $existing=$this->provider->event($eventDigest);
        $replayed=$existing!==null;
        if($existing){
            $this->provider->incrementReplay((int)$existing->id,NotificationSupport::now());
            if($this->terminal((string)$existing->outcome_code))return array('http'=>200,'outcome'=>(string)$existing->outcome_code,'replayed'=>true);
            $eventId=(int)$existing->id;
        }else{
            try{
                $eventId=$this->provider->insertEvent(array(
                    'uid'=>NotificationSupport::uid(),'provider'=>'telnyx','event_reference_digest'=>$eventDigest,
                    'event_type'=>$event['event_type'],'message_reference_digest'=>$event['message_reference']!==''?$this->digest('message:'.$event['message_reference']):null,
                    'notification_id'=>null,'attempt_id'=>null,'delivery_state'=>$event['delivery_state'],
                    'outcome_code'=>'received','occurred_at'=>$event['occurred_at'],'received_at'=>NotificationSupport::now(),
                    'processed_at'=>null,'replay_count'=>0,
                ));
            }catch(\Throwable $error){
                $winner=$this->provider->event($eventDigest);
                if(!$winner)throw $error;
                $eventId=(int)$winner->id;$replayed=true;
                $this->provider->incrementReplay($eventId,NotificationSupport::now());
            }
        }
        try{$result=$this->process($event,$eventDigest);}
        catch(\Throwable $error){
            $this->provider->updateEvent($eventId,array('outcome_code'=>'processing_failed','processed_at'=>NotificationSupport::now()));
            throw $error;
        }
        $this->provider->updateEvent($eventId,array(
            'notification_id'=>$result['notification_id'],'attempt_id'=>$result['attempt_id'],
            'delivery_state'=>$result['delivery_state'],'outcome_code'=>$result['outcome'],'processed_at'=>NotificationSupport::now(),
        ));
        $http=$result['outcome']==='unmapped_message'?503:200;
        return array('http'=>$http,'outcome'=>$result['outcome'],'replayed'=>$replayed);
    }

    /** @return array{outcome:string,notification_id:?int,attempt_id:?int,delivery_state:?string} */
    private function process(array $event,string $eventDigest):array{
        if($event['event_type']==='message.received'){
            if(!($event['stop']??false))return $this->result('ignored_inbound');
            $studentId=$event['recipient_phone']!==''?$this->contacts->studentIdForPhone($event['recipient_phone']):null;
            if($studentId===null)return $this->result('suppression_unresolved');
            $this->suppressions->suppressFromVerifiedProvider(array(
                'subject_kind'=>'student','subject_digest'=>$this->contacts->recipientDigest($studentId),
                'purpose'=>'*','reason_code'=>'provider_stop','effective_from'=>NotificationSupport::now(),
                'evidence_channel'=>'provider_evidence','evidence_reference'=>'telnyx:'.$eventDigest,'evidence_at'=>$event['occurred_at'],
            ),'telnyx-stop:'.$eventDigest);
            return $this->result('suppressed');
        }
        if($event['message_reference']==='')return $this->result('message_reference_missing');
        $referenceDigest=$this->digest('message:'.$event['message_reference']);
        $message=$this->provider->messageForReference($referenceDigest);
        if(!$message)return $this->result('unmapped_message',null,null,$event['delivery_state']);
        $state=$event['delivery_state'];
        if($state===null)return $this->result('ignored_status',(int)$message->notification_id,(int)$message->attempt_id);
        $result=$this->evidence->submit(array(
            'notification_id'=>(int)$message->notification_id,'attempt_id'=>(int)$message->attempt_id,
            'delivery_state'=>$state,'provider_fact_digest'=>$this->digest('fact:'.$event['message_reference'].'|'.$state.'|'.$event['occurred_at']),
            'provider_event_reference_digest'=>$eventDigest,'occurred_at'=>$event['occurred_at'],
        ));
        if(!empty($result['applied']))$this->provider->updateMessageState($referenceDigest,$state,NotificationSupport::now());
        $outcome=match($result['outcome']){
            'applied'=>'delivery_applied','replay'=>'delivery_replay',
            'delivery_regression_attempt'=>'delivery_regression','delivery_event_stale'=>'delivery_stale',
            default=>'delivery_'.(string)$result['outcome'],
        };
        $this->applyAggregate((int)$message->notification_id);
        return $this->result($outcome,(int)$message->notification_id,(int)$message->attempt_id,$state);
    }
    /** The aggregate transition remains capability-owned; a missing system actor leaves evidence only. */
    private function applyAggregate(int $notificationId):void{
        try{NotificationSystemActor::run(fn()=>$this->states->apply($notificationId));}
        catch(\Throwable){/* Evidence is durable; the aggregate is reconciled by the configured worker. */}
    }
    private function result(string $outcome,?int $notificationId=null,?int $attemptId=null,?string $deliveryState=null):array{
        return array('outcome'=>$outcome,'notification_id'=>$notificationId,'attempt_id'=>$attemptId,'delivery_state'=>$deliveryState);
    }
    private function digest(string $value):string{return hash_hmac('sha256','telnyx:'.$value,NotificationSupport::salt());}
    private function terminal(string $outcome):bool{
        return !in_array($outcome,array('received','processing_failed','unmapped_message'),true);
    }
    private function verificationStatus(string $code):int{
        return str_starts_with($code,'webhook_public_key_unconfigured')||$code==='webhook_signature_unavailable'?503:403;
    }
}
