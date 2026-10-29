<?php
$root=dirname(__DIR__);
$GLOBALS['wpdb']=(object)array('prefix'=>'wp_');
function is_wp_error($value):bool{return false;}
function wp_remote_retrieve_response_code($value):int{return (int)$value['code'];}
function wp_remote_retrieve_body($value):string{return (string)$value['body'];}
require_once $root.'/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateDiscoveryService.php';
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateDiscoveryService;
class DznDiscoverySettings {public function value(string $provider,string $field):string{return $provider==='sms'&&$field==='api_key'?'test-key':($provider==='whatsapp'&&$field==='waba_id'?'1421107699354690':'');}}
$pages=array(
    1=>array('data'=>array(array('id'=>'uuid-1','template_id'=>'meta-1','name'=>'delnavazan_class_access_fa','language'=>'fa','category'=>'UTILITY','status'=>'APPROVED','components'=>array(array('type'=>'BODY','text'=>'سلام {{1}} {{2}} {{3}} {{4}}')))),'meta'=>array('total_pages'=>2)),
    2=>array('data'=>array(array('id'=>'uuid-2','template_id'=>'meta-2','name'=>'dzn_unmapped_template','language'=>'fa','category'=>'UTILITY','status'=>'PENDING','components'=>array(array('type'=>'BODY','text'=>'x')))),'meta'=>array('total_pages'=>2)),
);
$http=function(string $url,array $args)use($pages){preg_match('/page\[number\]=(\d+)/',$url,$m);$page=(int)($m[1]??1);return array('code'=>200,'body'=>json_encode($pages[$page]??array('data'=>array(),'meta'=>array('total_pages'=>2))));};
$service=new TelnyxTemplateDiscoveryService(new DznDiscoverySettings(),$http);
$result=$service->discover();
if(!$result['ok']||count($result['templates'])!==2)throw new RuntimeException('template discovery pagination failed');
$approved=$result['templates'][0];$unmapped=$result['templates'][1];
if($approved['event_key']!=='lesson.access.student'||!$approved['variable_contract_matches']||$approved['status']!=='APPROVED')throw new RuntimeException('approved template mapping failed');
if($unmapped['event_key']!==null||$unmapped['status']!=='PENDING')throw new RuntimeException('unknown template must stay unmapped');
$missing=new TelnyxTemplateDiscoveryService(new DznDiscoverySettings(),$http);
$reflection=new ReflectionMethod($missing,'waba');
if($reflection->invoke($missing)!=='1421107699354690')throw new RuntimeException('WABA resolution failed');
echo "Telnyx template discovery unit passed\n";
