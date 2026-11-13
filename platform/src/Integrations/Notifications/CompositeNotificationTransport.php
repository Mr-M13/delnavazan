<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationCopyPort;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;

/**
 * Transport-side composition. Domain handoff stays channel-neutral; contact and copy are resolved only
 * here and are never written back to notification/attempt persistence.
 *
 * Each enabled mobile channel is handed off independently. A WhatsApp outcome never authorises,
 * blocks or replaces SMS; both are ordinary event-owned methods.
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
  $bindings=$this->allowed($this->router->route($route,$contact),$command);
  if($bindings===array())return array('acknowledged'=>false,'permanent_failure'=>'no_route');
  $results=array();$acknowledged=false;$retryable=false;$failure=null;
  foreach($bindings as $binding){
   $channel=(string)$binding['channel'];
   $rendered=$this->copy->render((int)$command['template_version_id'],$channel,(string)($contact['locale']??''),(array)($command['parameters']??array()));
   if($rendered===null){$results[$channel]=array('acknowledged'=>false,'permanent_failure'=>'send_refused');$failure='send_refused';continue;}
   // These fields are transport-bound facts. Array union would retain an accidentally supplied
   // command value, allowing an upstream caller to redirect a resolved recipient or replace copy.
   $transportCommand=array_merge($command,array('channel'=>$channel,'to'=>(string)($contact[$channel]??''),'subject'=>(string)($rendered['subject']??''),'body'=>(string)$rendered['body'],'locale'=>(string)($contact['locale']??'')));
   $result=(array)$binding['port']->handoff($transportCommand);
   $results[$channel]=$result;
   if(!empty($result['acknowledged']))$acknowledged=true;
   elseif(($result['permanent_failure']??null)===null)$retryable=true;
   else $failure=(string)$result['permanent_failure'];
  }
  return array('acknowledged'=>$acknowledged,'permanent_failure'=>$acknowledged||$retryable?null:($failure??'send_refused'),'channels'=>$results);
 }
 private function allowed(array $bindings,array $command):array{
  if(!is_callable($this->channelPolicy))return $bindings;
  $eventKey=NotificationEventKey::fromTemplateVersion((int)($command['template_version_id']??0));
  if($eventKey===null||$eventKey==='')return array();
  $out=array();
  foreach($bindings as $binding)if(($this->channelPolicy)($eventKey,(string)($binding['channel']??'')))$out[]=$binding;
  return $out;
 }
}
