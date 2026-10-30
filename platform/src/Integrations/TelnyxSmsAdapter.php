<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;

/**
 * Telnyx Messaging API v2 SMS adapter.
 *
 * The explicit admin test and the notification transport use the same payload contract. A provider
 * message id is persisted only as a keyed digest so a verified delivery webhook can be correlated
 * without exposing the raw provider identifier to the notification authority.
 */
final class TelnyxSmsAdapter {
    public function __construct(
        private $settings,
        private $http=null,
        private ?NotificationProviderRepository $provider=null,
        private ?NotificationRepository $notifications=null,
        private ?NotificationAttemptRepository $attempts=null
    ){
        $this->provider??=new NotificationProviderRepository();
        $this->notifications??=new NotificationRepository();
        $this->attempts??=new NotificationAttemptRepository();
    }

    public function sendTest(string $destination):array{
        $destination=PhoneNumberNormalizer::toE164($destination);
        if($destination==='')return array('state'=>'failed','code'=>'destination_invalid');
        $from=(string)$this->settings->value('sms','sender_id');
        $profile=(string)$this->settings->value('sms','messaging_profile_id');
        if($from===''&&$profile==='')return array('state'=>'failed','code'=>'telnyx_sender_not_configured');
        $payload=self::messagePayload($destination,'[Delnavazan TEST] SMS transport check. This is not a notification.',$from,$profile);
        $response=$this->post('https://api.telnyx.com/v2/messages',array(
            'timeout'=>20,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.(string)$this->settings->value('sms','api_key'),'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($payload),
        ));
        if(is_wp_error($response))return array('state'=>'failed','code'=>'telnyx_transport_failed');
        $status=(int)wp_remote_retrieve_response_code($response);
        return array('state'=>$status>=200&&$status<300?'success':'failed','code'=>$status>=200&&$status<300?'telnyx_accepted':'telnyx_http_'.$status);
    }

    /** NotificationTransportPort callable. Returns only the channel-neutral acknowledgement vocabulary. */
    public function send(array $command):array{
        $to=PhoneNumberNormalizer::toE164((string)($command['to']??''));
        $text=(string)($command['body']??'');
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$to))return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
        if($text==='')return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        $from=(string)$this->settings->value('sms','sender_id');
        $profile=(string)$this->settings->value('sms','messaging_profile_id');
        if($from===''&&$profile==='')return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        $notification=$this->notification($command);
        $attempt=$this->attempt($command,$notification);
        if($notification&&$attempt&&$this->provider->messageForAttempt((int)$notification->id,(int)$attempt->id)){
            return array('acknowledged'=>true,'permanent_failure'=>null,'idempotent'=>true);
        }
        $payload=self::messagePayload($to,$text,$from,$profile);
        $response=$this->post('https://api.telnyx.com/v2/messages',array(
            'timeout'=>20,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.(string)$this->settings->value('sms','api_key'),'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($payload),
        ));
        if(is_wp_error($response))return array('acknowledged'=>false,'permanent_failure'=>null,'failure'=>'transport','ambiguous'=>true);
        $status=(int)wp_remote_retrieve_response_code($response);
        if($status<200||$status>=300)return array('acknowledged'=>false,'permanent_failure'=>self::permanentFailure($status),'http'=>$status,'ambiguous'=>in_array($status,array(408,425),true)||$status>=500);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        $reference=is_array($body)?(string)($body['data']['id']??''):'';
        if($reference!==''&&$notification&&$attempt)$this->remember($notification,$attempt,$reference,'sent','sms');
        return array('acknowledged'=>true,'permanent_failure'=>null,'provider_reference_present'=>$reference!=='');
    }

    public static function messagePayload(string $to,string $text,string $from,string $profile):array{
        $payload=array('to'=>$to,'text'=>$text);
        if($from!=='')$payload['from']=$from;else $payload['messaging_profile_id']=$profile;
        return $payload;
    }
    public static function permanentFailure(int $status):?string{
        if(in_array($status,array(408,425,429),true)||$status>=500)return null;
        if($status===400||$status===422)return 'contact_unusable';
        return 'send_refused';
    }

    private function notification(array $command):?object{
        $key=(string)($command['notification_key_digest']??'');
        return $key===''?null:$this->notifications->byKeyDigest($key);
    }
    private function attempt(array $command,?object $notification):?object{
        if(!$notification)return null;
        return $this->attempts->bySequence((int)$notification->id,(int)($command['attempt_sequence']??0));
    }
    private function remember(object $notification,object $attempt,string $reference,string $state,string $channel):void{
        $digest=hash_hmac('sha256','telnyx:message:'.$reference,NotificationSupport::salt());
        $now=NotificationSupport::now();
        try{
            $this->provider->insertMessage(array(
                'uid'=>NotificationSupport::uid(),'provider'=>'telnyx','channel'=>$channel,
                'message_reference_digest'=>$digest,'notification_id'=>(int)$notification->id,'attempt_id'=>(int)$attempt->id,
                'provider_template_name'=>null,'provider_template_language'=>null,'state'=>$state,
                'created_at'=>$now,'updated_at'=>$now,
            ));
        }catch(\Throwable $error){
            // A concurrent retry may win the unique reference after the provider accepted both calls.
            if($this->provider->messageForReference($digest))return;
            throw $error;
        }
    }
    private function post(string $url,array $args){
        return is_callable($this->http)?($this->http)($url,$args):wp_remote_post($url,$args);
    }
}
