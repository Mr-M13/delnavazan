<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationCopyPort;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;

/**
 * Transport-side composition. Domain handoff stays channel-neutral; contact and copy are resolved only
 * here and are never written back to notification/attempt persistence.
 *
 * The router is WhatsApp-first.  This class deliberately performs no channel fallback of its own:
 * a WhatsApp transient failure is returned to the dispatch authority for retry, and a permanent
 * provider refusal is returned as a terminal closure.  SMS is only reachable through the separate
 * durable fallback notification that the verified terminal-failure authority creates.
 */
final class CompositeNotificationTransport implements NotificationTransportPort {
 public function __construct(
  private NotificationTransportRouter $router,
  private NotificationCopyPort $copy,
  private $contactResolver,
  private $channelPolicy=null
 ){}
 public function handoff(array $command):array{
  if(!is_callable($this->contactResolver))return array('acknowledged'=>false,'permanent_failure'=>'no_route');
  $contact=($this->contactResolver)($command);
  if(!is_array($contact))return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
  $route=(string)($contact['route']??'mobile');
  $bindings=$this->allowed($this->router->route($route,$contact),$command,$route==='mobile'&&!empty($contact['sms_fallback_authorised']));
  if($bindings===array())return array('acknowledged'=>false,'permanent_failure'=>'no_route');
  $binding=$bindings[0];
  $channel=(string)$binding['channel'];
  $rendered=$this->copy->render((int)$command['template_version_id'],$channel,(string)($contact['locale']??''),(array)($command['parameters']??array()));
  if($rendered===null)return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
  // These fields are transport-bound facts. Array union would retain an
  // accidentally supplied command value, allowing an upstream caller to
  // redirect a resolved recipient or replace reviewed copy.
  $transportCommand=array_merge($command,array('channel'=>$channel,'to'=>(string)($contact[$channel]??''),'subject'=>(string)($rendered['subject']??''),'body'=>(string)$rendered['body']));
  $result=$binding['port']->handoff($transportCommand);
  return $result+array('acknowledged'=>(bool)($result['acknowledged']??false),'permanent_failure'=>$result['permanent_failure']??null,'channel'=>$channel);
 }
 private function allowed(array $bindings,array $command,bool $fallback):array{
  if(!is_callable($this->channelPolicy))return $bindings;
  $eventKey=NotificationEventKey::fromTemplateVersion((int)($command['template_version_id']??0));
  if($eventKey===null||$eventKey==='')return array();
  $out=array();
  foreach($bindings as $binding)if(($this->channelPolicy)($eventKey,(string)($binding['channel']??''),$fallback))$out[]=$binding;
  return $out;
 }
}
