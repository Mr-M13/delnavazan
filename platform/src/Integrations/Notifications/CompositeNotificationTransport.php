<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationCopyPort;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
use Delnavazan\Platform\Core\Application\NotificationSupport;

/**
 * Transport-side composition. Domain handoff stays channel-neutral; contact and copy are resolved only
 * here and are never written back to notification/attempt persistence.
 */
final class CompositeNotificationTransport implements NotificationTransportPort {
 public function __construct(
  private NotificationTransportRouter $router,
  private NotificationCopyPort $copy,
  private $contactResolver
 ){}
 public function handoff(array $command):array{
  if(isset($command['contact_expires_at'])&&is_string($command['contact_expires_at'])&&$command['contact_expires_at']!==''&&$command['contact_expires_at']<=NotificationSupport::now())return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
  $contact=NotificationSupport::decryptEnvelope($command['contact_envelope']??null,$command['contact_cipher_version']??null);
  if($contact===null&&is_callable($this->contactResolver))$contact=($this->contactResolver)($command);
  if(!is_array($contact))return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
  $route=(string)($contact['route']??'mobile');
  $bindings=$this->router->route($route,$contact);
  if($bindings===array())return array('acknowledged'=>false,'permanent_failure'=>'no_route');
  return $this->tryBindings($bindings,$command,$contact);
 }
 private function tryBindings(array $bindings,array $command,array $contact):array{
  foreach($bindings as $binding){
   $channel=(string)$binding['channel'];
   $rendered=$this->copy->render((int)$command['template_version_id'],$channel,(string)($contact['locale']??''),(array)($command['parameters']??array()));
   if($rendered===null){
    $fallback=$this->router->fallbackAfter($channel,'send_refused',$contact);
    if($fallback!==array())return $this->tryBindings($fallback,$command,$contact);
    return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
   }
   $transportCommand=$command+array('channel'=>$channel,'to'=>(string)($contact[$channel]??''),'subject'=>(string)($rendered['subject']??''),'body'=>(string)$rendered['body']);
   $result=$binding['port']->handoff($transportCommand);
   if(!empty($result['acknowledged']))return array('acknowledged'=>true,'permanent_failure'=>null);
   $failure=$result['permanent_failure']??null;
   $fallback=$this->router->fallbackAfter($channel,$failure,$contact);
   if($fallback!==array())return $this->tryBindings($fallback,$command,$contact);
   return array('acknowledged'=>false,'permanent_failure'=>$failure);
  }
  return array('acknowledged'=>false,'permanent_failure'=>'no_route');
 }
}
