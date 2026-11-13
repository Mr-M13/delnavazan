<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Independent mobile-channel routing.
 *
 * WhatsApp and SMS are separate event-owned delivery methods. A channel is returned only when its
 * own provider binding and recipient address are usable; neither channel is a fallback for the other.
 */
final class NotificationTransportRouter {
 public function __construct(private array $bindings){}
 public function route(string $route,array $contact):array{
  if($route==='email')return $this->single('email',$contact);
  if($route!=='mobile')throw new \InvalidArgumentException('notification_route_invalid');
  $routes=array();
  foreach(array('whatsapp','sms') as $channel){
   $binding=$this->single($channel,$contact);
   if($binding!==array())$routes=array_merge($routes,$binding);
  }
  return $routes;
 }
 private function single(string $channel,array $contact):array{
  if(empty($contact[$channel.'_eligible']))return array();
  if($channel==='sms'){
   // A configured SMS.ir binding serves Iranian teachers; otherwise Telnyx remains active.
   // Students always use Telnyx. Never infer teacher status from the number alone.
   $kind=(string)($contact['recipient_kind']??'');
   $number=(string)($contact['sms']??'');
   $iranian=preg_match('/^\+989[0-9]{9}$/',$number)===1;
   $preferred=$kind==='teacher'&&$iranian?'sms_ir':'telnyx';
   $binding=$this->binding('sms',$preferred);
   if($binding===null&&$preferred==='sms_ir')$binding=$this->binding('sms','telnyx');
   return $binding&&$binding['configured']?array($binding):array();
  }
  $binding=$this->binding($channel);
  return $binding&&$binding['configured']?array($binding):array();
 }

 private function binding(string $channel,?string $provider=null):?array{
  foreach($this->bindings as $b){
   if(($b['channel']??'')!==$channel||!($b['port']??null) instanceof NotificationTransportPort)continue;
   $bindingProvider=(string)($b['provider']??($channel==='sms'?'telnyx':''));
   if($provider!==null&&$bindingProvider!==$provider)continue;
   if(empty($b['configured']))continue;
   return array('channel'=>$channel,'configured'=>true,'port'=>$b['port'],'provider'=>$bindingProvider);
  }
  return null;
 }
}
