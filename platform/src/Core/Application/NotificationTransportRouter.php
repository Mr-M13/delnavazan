<?php
namespace Delnavazan\Platform\Core\Application;
final class NotificationTransportRouter {
 public function __construct(private array $bindings){}
 public function route(string $route,array $contact):array{if($route==='email')return $this->single('email',$contact);if($route!=='mobile')throw new \InvalidArgumentException('notification_route_invalid');$wa=$this->binding('whatsapp');if($wa&&$wa['configured']&&!empty($contact['whatsapp_eligible']))return array($wa);$sms=$this->binding('sms');if($sms&&$sms['configured']&&!empty($contact['sms_eligible']))return array($sms);return array();}
 public function fallbackAfter(string $channel,?string $permanentFailure,array $contact):array{if($channel!=='whatsapp'||!in_array($permanentFailure,array('contact_unusable','send_refused'),true))return array();$sms=$this->binding('sms');return $sms&&$sms['configured']&&!empty($contact['sms_eligible'])?array($sms):array();}
 private function single(string $channel,array $contact):array{$b=$this->binding($channel);return $b&&$b['configured']&&!empty($contact[$channel.'_eligible'])?array($b):array();}
 private function binding(string $channel):?array{foreach($this->bindings as$b)if(($b['channel']??'')===$channel&&($b['port']??null) instanceof NotificationTransportPort)return array('channel'=>$channel,'configured'=>(bool)($b['configured']??false),'port'=>$b['port']);return null;}
}
