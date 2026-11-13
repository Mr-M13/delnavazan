<?php
namespace Delnavazan\Platform\Core\Application;

/** Administrator-controlled SMS provider preference; never an automatic failure fallback. */
final class SmsProviderRoutingPolicy {
 public const OPTION='dzn_sms_provider_routing';
 public static function defaults():array{return array('iran'=>'sms_ir','international'=>'telnyx');}
 public static function settings():array{
  $saved=function_exists('get_option')?get_option(self::OPTION,array()):array();
  $saved=is_array($saved)?$saved:array();$out=self::defaults();
  foreach($out as $region=>$default)if(in_array($saved[$region]??null,array('sms_ir','telnyx','off'),true))$out[$region]=$saved[$region];
  return $out;
 }
 public static function select(string $number,?array $settings=null):string{
  $settings=$settings??self::settings();
  return preg_match('/^\+989[0-9]{9}$/',$number)===1?($settings['iran']??'sms_ir'):($settings['international']??'telnyx');
 }
}
