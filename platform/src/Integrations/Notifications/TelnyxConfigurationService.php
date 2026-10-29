<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Explicit Telnyx account/profile configuration checks.
 *
 * Reads the account public signing key and the Messaging Profile webhook state, and can set the
 * profile webhook to the local endpoint. Every method is an explicit operator action: discovery and
 * readiness reads never mutate provider configuration, and no method sends a message.
 */
final class TelnyxConfigurationService {
    public function __construct(private $settings,private $http=null){}
    public function configured():bool{return (new TelnyxConfiguration($this->settings))->apiKey()!=='';}
    /** @return array{ok:bool,public_key:string,error:?string} */
    public function publicKey():array{
        if(!$this->configured())return array('ok'=>false,'public_key'=>'','error'=>'telnyx_api_key_missing');
        $r=$this->request('GET','https://api.telnyx.com/v2/public_key');
        if(!$r['ok'])return array('ok'=>false,'public_key'=>'','error'=>$r['error']);
        $key=(string)($r['body']['data']['public']??'');
        return $key===''?array('ok'=>false,'public_key'=>'','error'=>'telnyx_public_key_missing'):array('ok'=>true,'public_key'=>$key,'error'=>null);
    }
    /** @return array{ok:bool,error:?string,profile:array} */
    public function profile():array{
        $profileId=(string)$this->settings->value('sms','messaging_profile_id');
        if(!$this->configured()||$profileId==='')return array('ok'=>false,'error'=>'telnyx_profile_missing','profile'=>array());
        $r=$this->request('GET','https://api.telnyx.com/v2/messaging_profiles/'.rawurlencode($profileId));
        if(!$r['ok'])return array('ok'=>false,'error'=>$r['error'],'profile'=>array());
        $data=is_array($r['body']['data']??null)?$r['body']['data']:array();
        return array('ok'=>true,'error'=>null,'profile'=>array(
            'id'=>(string)($data['id']??''),'name'=>(string)($data['name']??''),'enabled'=>(bool)($data['enabled']??false),
            'webhook_url'=>(string)($data['webhook_url']??''),'webhook_failover_url'=>(string)($data['webhook_failover_url']??''),
            'webhook_api_version'=>(string)($data['webhook_api_version']??''),
        ));
    }
    /** @return array{ok:bool,error:?string,phones:array<int,array>} */
    public function phoneNumbers():array{
        if(!$this->configured())return array('ok'=>false,'error'=>'telnyx_api_key_missing','phones'=>array());
        $r=$this->request('GET','https://api.telnyx.com/v2/whatsapp/phone_numbers');
        if(!$r['ok'])return array('ok'=>false,'error'=>$r['error'],'phones'=>array());
        $phones=array();
        foreach((array)($r['body']['data']??array()) as $row)if(is_array($row))$phones[]=array(
            'phone_number'=>(string)($row['phone_number']??''),'waba_id'=>(string)($row['waba_id']??''),
            'status'=>(string)($row['status']??''),'enabled'=>(bool)($row['enabled']??false),
            'display_name'=>(string)($row['display_name']??''),'name_status'=>(string)($row['name_status']??''),
        );
        return array('ok'=>true,'error'=>null,'phones'=>$phones);
    }
    /** Point the Messaging Profile at the local signed webhook. Explicit action only. */
    public function setWebhook(string $url):array{
        $profileId=(string)$this->settings->value('sms','messaging_profile_id');
        if(!$this->configured()||$profileId==='')return array('ok'=>false,'error'=>'telnyx_profile_missing');
        if(!str_starts_with($url,'https://'))return array('ok'=>false,'error'=>'webhook_https_required');
        $r=$this->request('PATCH','https://api.telnyx.com/v2/messaging_profiles/'.rawurlencode($profileId),array('webhook_url'=>$url,'webhook_api_version'=>'2'));
        if(!$r['ok'])return array('ok'=>false,'error'=>$r['error']);
        $data=is_array($r['body']['data']??null)?$r['body']['data']:array();
        if((string)($data['webhook_url']??'')!==$url)return array('ok'=>false,'error'=>'webhook_url_not_confirmed');
        return array('ok'=>true,'error'=>null);
    }
    /** @return array{ok:bool,error:?string,body:array} */
    private function request(string $method,string $url,?array $payload=null):array{
        $args=array('timeout'=>20,'redirection'=>0,'method'=>$method,'headers'=>array(
            'Authorization'=>'Bearer '.(string)(new TelnyxConfiguration($this->settings))->apiKey(),
            'Content-Type'=>'application/json','Accept'=>'application/json',
        ));
        if($payload!==null)$args['body']=wp_json_encode($payload);
        $response=is_callable($this->http)?($this->http)($url,$args):wp_remote_request($url,$args);
        if(is_wp_error($response))return array('ok'=>false,'error'=>'telnyx_transport_failed','body'=>array());
        $status=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300)return array('ok'=>false,'error'=>'telnyx_http_'.$status,'body'=>is_array($body)?$body:array());
        return array('ok'=>true,'error'=>null,'body'=>is_array($body)?$body:array());
    }
}
