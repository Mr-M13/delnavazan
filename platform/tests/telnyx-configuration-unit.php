<?php
$root=dirname(__DIR__);
function is_wp_error($value):bool{return false;}
function wp_remote_retrieve_response_code($value):int{return (int)$value['code'];}
function wp_remote_retrieve_body($value):string{return (string)$value['body'];}
function wp_json_encode($value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
require_once $root.'/src/Integrations/Notifications/TelnyxConfiguration.php';
require_once $root.'/src/Integrations/Notifications/TelnyxConfigurationService.php';
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfigurationService;
class DznConfigSettings {public function value(string $provider,string $field):string{return $provider==='sms'&&$field==='api_key'?'key':($provider==='sms'&&$field==='messaging_profile_id'?'profile-1':($provider==='whatsapp'&&$field==='sender_id'?'+15550000001':($provider==='whatsapp'&&$field==='waba_id'?'1421107699354690':'')));}}
$calls=array();
$http=function(string $url,array $args)use(&$calls){
    $calls[]=array($url,$args['method']??'GET',$args);
    if(str_ends_with($url,'/public_key'))return array('code'=>200,'body'=>json_encode(array('data'=>array('public'=>'iGtB96aTJO4SmJPzRPqW20Zc10AWCiN8OQLE5Tg330U='))));
    if(str_contains($url,'/messaging_profiles/profile-1')&&($args['method']??'GET')==='GET')return array('code'=>200,'body'=>json_encode(array('data'=>array('id'=>'profile-1','name'=>'Delnavazan Notifications','enabled'=>true,'webhook_url'=>null,'webhook_failover_url'=>null,'webhook_api_version'=>'2'))));
    if(str_ends_with($url,'/whatsapp/phone_numbers'))return array('code'=>200,'body'=>json_encode(array('data'=>array(array('phone_number'=>'+15550000001','waba_id'=>'1421107699354690','status'=>'CONNECTED','enabled'=>true,'display_name'=>'Delnavazan','name_status'=>'PENDING_REVIEW')))));
    if(str_contains($url,'/messaging_profiles/profile-1')&&($args['method']??'GET')==='PATCH'){ $payload=json_decode((string)($args['body']??'{}'),true); return array('code'=>200,'body'=>json_encode(array('data'=>array('webhook_url'=>$payload['webhook_url']??'','webhook_api_version'=>'2')))); }
    return array('code'=>404,'body'=>'{}');
};
$service=new TelnyxConfigurationService(new DznConfigSettings(),$http);
$key=$service->publicKey();if(!$key['ok']||$key['public_key']==='')throw new RuntimeException('public key fetch failed');
$profile=$service->profile();if(!$profile['ok']||$profile['profile']['webhook_url']!==''||$profile['profile']['webhook_api_version']!=='2')throw new RuntimeException('profile read failed');
$phones=$service->phoneNumbers();if(!$phones['ok']||count($phones['phones'])!==1||$phones['phones'][0]['status']!=='CONNECTED')throw new RuntimeException('phone discovery failed');
$set=$service->setWebhook('https://delnavazan.com/wp-json/delnavazan-platform/v1/provider-events/telnyx');
if(!$set['ok'])throw new RuntimeException('webhook set failed');
$missing=$service->setWebhook('http://insecure.example/webhook');if($missing['ok']||$missing['error']!=='webhook_https_required')throw new RuntimeException('webhook must require https');
$patch=false;foreach($calls as $call)if(($call[1]??'')==='PATCH')$patch=true;if(!$patch)throw new RuntimeException('webhook update must use PATCH');
echo "Telnyx configuration service unit passed\n";
