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
 private function single(string $channel,array $contact):array{$b=$this->binding($channel);return $b&&$b['configured']&&!empty($contact[$channel.'_eligible'])?array($b):array();}
 private function binding(string $channel):?array{foreach($this->bindings as$b)if(($b['channel']??'')===$channel&&($b['port']??null) instanceof NotificationTransportPort)return array('channel'=>$channel,'configured'=>(bool)($b['configured']??false),'port'=>$b['port']);return null;}
}
