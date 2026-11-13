<?php
$options=array();
function get_option(string $key,$default=false){global $options;return $options[$key]??$default;}
function update_option(string $key,$value,bool $autoload=false):bool{global $options;$options[$key]=$value;return true;}
function sanitize_key(string $value):string{return strtolower(preg_replace('/[^a-z0-9_\-]/','',$value));}
require_once dirname(__DIR__).'/src/Core/Application/SmsProviderRoutingPolicy.php';
use Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
foreach(array(
    array('iran'=>'sms_ir','international'=>'telnyx'),
    array('iran'=>'telnyx','international'=>'sms_ir'),
    array('iran'=>'off','international'=>'off'),
) as $choice){
    $saved=SmsProviderRoutingPolicy::save($choice);
    $assert($saved===$choice,'SMS routing save must preserve both selected groups.');
    $assert(SmsProviderRoutingPolicy::settings()===$choice,'SMS routing reload must return the saved policy.');
}
$assert(SmsProviderRoutingPolicy::select('+989121234567',array('iran'=>'sms_ir','international'=>'telnyx'))==='sms_ir','Iranian numbers must use the Iran choice.');
$assert(SmsProviderRoutingPolicy::select('+61412345678',array('iran'=>'sms_ir','international'=>'telnyx'))==='telnyx','Other numbers must use the other-number choice.');
$failed=false;try{SmsProviderRoutingPolicy::save(array('iran'=>'invalid','international'=>'telnyx'));}catch(InvalidArgumentException){$failed=true;}
$assert($failed,'Unknown SMS routing choices must be rejected.');
echo "SMS routing policy unit passed\n";
