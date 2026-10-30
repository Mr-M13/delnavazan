<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry;

/**
 * Telnyx WhatsApp API adapter for approved business-initiated templates.
 *
 * Local notification parameters are mapped to the approved provider template by event key. The
 * adapter never invents a parameter: a missing local variable is a permanent refusal, which lets the
 * existing router fall back to SMS only when the channel policy permits it.
 */
final class TelnyxWhatsAppAdapter {
    public function __construct(
        private $settings,
        private $http=null,
        private ?NotificationProviderRepository $provider=null,
        private ?NotificationRepository $notifications=null,
        private ?NotificationAttemptRepository $attempts=null,
        private ?NotificationTemplateRepository $templates=null
    ){
        $this->provider??=new NotificationProviderRepository();
        $this->notifications??=new NotificationRepository();
        $this->attempts??=new NotificationAttemptRepository();
        $this->templates??=new NotificationTemplateRepository();
    }

    /**
     * Explicit operator test. It uses an approved template and synthetic parameters, and never creates
     * a notification/attempt correlation row. The caller must supply an authorised destination.
     */
    public function sendTest(string $to,string $name,string $language,array $parameters):array{
        $to=PhoneNumberNormalizer::toE164($to);
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$to))return array('state'=>'failed','code'=>'destination_invalid');
        $sender=(string)$this->settings->value('whatsapp','sender_id');
        $order=TelnyxTemplateRegistry::variableOrderForName($name);
        if($sender===''||$name===''||$order===array())return array('state'=>'failed','code'=>'telnyx_test_template_not_configured');
        foreach($order as $code)if(!array_key_exists($code,$parameters))return array('state'=>'failed','code'=>'test_template_variable_missing');
        $profile=(string)$this->settings->value('whatsapp','messaging_profile_id');if($profile==='')$profile=(string)$this->settings->value('sms','messaging_profile_id');
        $payload=self::templatePayload($sender,$to,$name,$language,$order,$parameters,$profile);
        $response=$this->post('https://api.telnyx.com/v2/messages/whatsapp',array(
            'timeout'=>20,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.(string)$this->settings->value('sms','api_key'),'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($payload),
        ));
        if(is_wp_error($response))return array('state'=>'failed','code'=>'telnyx_transport_failed','template'=>$name,'language'=>$language);
        $status=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status>=200&&$status<300){
            $reference=is_array($body)?(string)($body['data']['id']??''):'';
            if($reference!=='')$this->rememberTest($reference,$name,$language);
            return array('state'=>'success','code'=>'telnyx_accepted','http'=>$status,'template'=>$name,'language'=>$language,'reference_present'=>$reference!=='');
        }
        return array_merge(array('state'=>'failed','code'=>'telnyx_http_'.$status,'http'=>$status,'template'=>$name,'language'=>$language),self::providerError(is_array($body)?$body:null));
    }

    /** Reduce a Telnyx error body to safe code/title/detail without recipient data. */
    public static function providerError(?array $body):array{
        $error=is_array($body)&&is_array($body['errors']??null)?($body['errors'][0]??null):null;
        if(!is_array($error))return array('provider_code'=>null,'provider_title'=>null,'provider_detail'=>null);
        return array(
            'provider_code'=>isset($error['code'])?(string)$error['code']:null,
            'provider_title'=>self::safeProviderText((string)($error['title']??''),80),
            'provider_detail'=>self::safeProviderText((string)($error['detail']??''),240),
        );
    }
    private static function safeProviderText(string $text,int $limit):?string{
        $text=trim(strip_tags($text));
        if($text==='')return null;
        $text=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[email]',$text)??$text;
        $text=preg_replace('/\+?[0-9][0-9\s().-]{6,}[0-9]/','[number]',$text)??$text;
        $text=preg_replace('/\b[0-9]{5,}\b/','[number]',$text)??$text;
        return mb_substr($text,0,$limit);
    }

    public function send(array $command):array{
        $to=PhoneNumberNormalizer::toE164((string)($command['to']??''));
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$to))return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
        $sender=(string)$this->settings->value('whatsapp','sender_id');
        if($sender==='')return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        $notification=$this->notification($command);
        $attempt=$this->attempt($command,$notification);
        if($notification&&$attempt&&$this->provider->messageForAttempt((int)$notification->id,(int)$attempt->id)){
            return array('acknowledged'=>true,'permanent_failure'=>null,'idempotent'=>true);
        }
        $mapped=$this->mapping($command);
        if($mapped===null)return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'template_not_mapped');
        foreach($mapped['variable_order'] as $code)if(!array_key_exists($code,(array)($command['parameters']??array())))return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'variable_contract_mismatch');
        $profile=(string)$this->settings->value('whatsapp','messaging_profile_id');if($profile==='')$profile=(string)$this->settings->value('sms','messaging_profile_id');
        $payload=self::templatePayload($sender,$to,$mapped['name'],$mapped['language'],$mapped['variable_order'],(array)($command['parameters']??array()),$profile);
        $response=$this->post('https://api.telnyx.com/v2/messages/whatsapp',array(
            'timeout'=>20,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.(string)$this->settings->value('sms','api_key'),'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($payload),
        ));
        if(is_wp_error($response))return array('acknowledged'=>false,'permanent_failure'=>null,'failure'=>'transport','ambiguous'=>true);
        $status=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300){
            $permanent=self::permanentFailure($status);
            $error=self::providerError(is_array($body)?$body:null);
            $result=array('acknowledged'=>false,'permanent_failure'=>$permanent,'http'=>$status,'provider_attempted'=>true,'ambiguous'=>$permanent===null);
            // Only a recipient/message-specific rejection can authorise SMS. Authentication, routing,
            // configuration, payload/template and provider-capacity failures close or retry WhatsApp
            // without fanning out an SMS to every recipient.
            if($permanent!==null&&self::recipientRejection($status,$error)){
                $at=NotificationSupport::now();
                $result['provider_evidence_digest']=hash_hmac('sha256','telnyx:whatsapp:rejected:'.(string)($command['notification_key_digest']??'').':'.(int)($command['attempt_sequence']??0).':'.$status.':'.$at,NotificationSupport::salt());
                $result['provider_evidence_at']=$at;
                $result['provider_outcome']='rejected';
            }
            return $result;
        }
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        $reference=is_array($body)?(string)($body['data']['id']??''):'';
        if($reference!==''&&$notification&&$attempt)$this->remember($notification,$attempt,$reference,$mapped['name'],$mapped['language']);
        return array('acknowledged'=>true,'permanent_failure'=>null,'provider_attempted'=>true,'provider_reference_present'=>$reference!=='');
    }

    public static function templatePayload(string $from,string $to,string $name,string $language,array $order,array $parameters,string $profile=''):array{
        $params=array();
        foreach($order as $code)$params[]=array('type'=>'text','text'=>(string)$parameters[$code]);
        $payload=array(
            'from'=>$from,'to'=>$to,
            'whatsapp_message'=>array(
                'type'=>'template',
                'template'=>array(
                    'name'=>$name,
                    'language'=>array('policy'=>'deterministic','code'=>$language),
                    'components'=>array(array('type'=>'body','parameters'=>$params)),
                ),
            ),
        );
        if($profile!=='')$payload['messaging_profile_id']=$profile;
        return $payload;
    }
    public static function permanentFailure(int $status):?string{
        if(in_array($status,array(408,425,429),true)||$status>=500)return null;
        return 'send_refused';
    }
    /**
     * A provider rejection may authorise a fallback only when it names the recipient/message, not when it
     * describes the platform's credentials, endpoint, account, template or provider capacity.  This keeps
     * a rotated key or a broken template from fanning one failure out to SMS for every recipient.
     */
    public static function recipientRejection(int $status,array $error):bool{
        if(in_array($status,array(401,403,404,405,409,413,429),true))return false;
        $code=(string)($error['provider_code']??'');
        if(in_array($code,array('40310','40311','40312'),true))return true;
        $text=strtolower(trim((string)($error['provider_title']??'').' '.(string)($error['provider_detail']??'')));
        if($text==='')return false;
        foreach(array('invalid to','invalid recipient','invalid number','invalid destination','unreachable','not a valid whatsapp','not on whatsapp') as $needle){
            if(str_contains($text,$needle))return true;
        }
        return false;
    }

    /** @return array{name:string,language:string,variable_order:string[]}|null */
    private function mapping(array $command):?array{
        $templateVersionId=(int)($command['template_version_id']??0);
        $version=$templateVersionId>0?$this->templates->version($templateVersionId):null;
        if(!$version)return null;
        $template=$this->templates->template((int)$version->template_id);
        if(!$template)return null;
        $parts=explode('.',(string)$template->template_key);
        if(count($parts)<3)return null;
        $locale=array_pop($parts);$channel=array_pop($parts);$eventKey=implode('.',$parts);
        if($channel!=='whatsapp')return null;
        $providerLanguage=str_starts_with(strtolower($locale),'fa')?TelnyxTemplateRegistry::LANGUAGE:$locale;
        $approved=$this->provider->templateForEvent('telnyx',$eventKey,$providerLanguage);
        if($approved){
            $order=array_values(array_filter(array_map('trim',explode(',',(string)$approved->variable_order))));
            if($order===array())$order=TelnyxTemplateRegistry::variableOrderForName((string)$approved->template_name);
            return array('name'=>(string)$approved->template_name,'language'=>(string)$approved->language,'variable_order'=>$order);
        }
        $manual=(new NotificationTemplateLibrary())->providerMapping((string)$template->template_key);
        $name=(string)($manual['name']??'');
        if($name==='')return null;
        $language=(string)($manual['language']??TelnyxTemplateRegistry::LANGUAGE);
        $known=$this->provider->templateByName('telnyx',$name,$language);
        if($known&&strtoupper((string)$known->status)!=='APPROVED')return null;
        $order=$known?array_values(array_filter(array_map('trim',explode(',',(string)$known->variable_order)))):TelnyxTemplateRegistry::variableOrderForName($name);
        if($order===array())$order=TelnyxTemplateRegistry::variableOrderForName($name);
        return $order===array()?null:array('name'=>$name,'language'=>$language,'variable_order'=>$order);
    }
    private function notification(array $command):?object{
        $key=(string)($command['notification_key_digest']??'');
        return $key===''?null:$this->notifications->byKeyDigest($key);
    }
    private function attempt(array $command,?object $notification):?object{
        if(!$notification)return null;
        return $this->attempts->bySequence((int)$notification->id,(int)($command['attempt_sequence']??0));
    }
    /** Explicit tests have no notification/attempt; zero ids mark a tracked test delivery. */
    private function rememberTest(string $reference,string $name,string $language):void{
        $digest=hash_hmac('sha256','telnyx:message:'.$reference,NotificationSupport::salt());$now=NotificationSupport::now();
        try{$this->provider->insertMessage(array(
            'uid'=>NotificationSupport::uid(),'provider'=>'telnyx','channel'=>'whatsapp','message_reference_digest'=>$digest,
            'notification_id'=>0,'attempt_id'=>0,'provider_template_name'=>$name,'provider_template_language'=>$language,
            'state'=>'sent','created_at'=>$now,'updated_at'=>$now,
        ));}catch(\Throwable $error){if(!$this->provider->messageForReference($digest))throw $error;}
    }
    private function remember(object $notification,object $attempt,string $reference,string $name,string $language):void{
        $digest=hash_hmac('sha256','telnyx:message:'.$reference,NotificationSupport::salt());
        $now=NotificationSupport::now();
        try{
            $this->provider->insertMessage(array(
                'uid'=>NotificationSupport::uid(),'provider'=>'telnyx','channel'=>'whatsapp',
                'message_reference_digest'=>$digest,'notification_id'=>(int)$notification->id,'attempt_id'=>(int)$attempt->id,
                'provider_template_name'=>$name,'provider_template_language'=>$language,'state'=>'sent',
                'created_at'=>$now,'updated_at'=>$now,
            ));
        }catch(\Throwable $error){
            if($this->provider->messageForReference($digest))return;
            throw $error;
        }
    }
    private function post(string $url,array $args){
        return is_callable($this->http)?($this->http)($url,$args):wp_remote_post($url,$args);
    }
}
