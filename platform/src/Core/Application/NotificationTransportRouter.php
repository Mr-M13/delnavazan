<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * WhatsApp-first mobile routing.
 *
 * Automated SMS is never a capability fallback.  A mobile notification is offered WhatsApp only,
 * even when WhatsApp is unconfigured, the recipient has no WhatsApp address or SMS is fully
 * configured.  SMS becomes routable only when the caller carries the durable, verified terminal
 * WhatsApp failure authorisation written by NotificationSmsFallbackService.
 */
final class NotificationTransportRouter {
 public function __construct(private array $bindings){}
 public function route(string $route,array $contact):array{
  if($route==='email')return $this->single('email',$contact);
  if($route!=='mobile')throw new \InvalidArgumentException('notification_route_invalid');
  if($this->authorisedSmsFallback($contact))return $this->single('sms',$contact);
  return $this->single('whatsapp',$contact);
 }
 /**
  * Legacy seam retained for compatibility.  It refuses unless the contact proves both an explicit
  * SMS-fallback authorisation and the verified terminal-failure digest it belongs to.
  */
 public function fallbackAfter(string $channel,?string $permanentFailure,array $contact):array{
  if($channel!=='whatsapp'||!$this->authorisedSmsFallback($contact))return array();
  return $this->single('sms',$contact);
 }
 private function authorisedSmsFallback(array $contact):bool{
  return !empty($contact['sms_fallback_authorised'])
   &&is_string($contact['whatsapp_terminal_failure_digest']??null)
   &&preg_match('/^[a-f0-9]{64}$/',(string)$contact['whatsapp_terminal_failure_digest'])===1;
 }
 private function single(string $channel,array $contact):array{$b=$this->binding($channel);return $b&&$b['configured']&&!empty($contact[$channel.'_eligible'])?array($b):array();}
 private function binding(string $channel):?array{foreach($this->bindings as$b)if(($b['channel']??'')===$channel&&($b['port']??null) instanceof NotificationTransportPort)return array('channel'=>$channel,'configured'=>(bool)($b['configured']??false),'port'=>$b['port']);return null;}
}
