<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
/** Provider binding. The authorised command contains no raw provider-specific state. */
final class WordPressEmailTransport implements NotificationTransportPort {
 public function __construct(private $adapter=null){}
 public function handoff(array $authorisedCommand):array{
  if(!is_callable($this->adapter))return array('acknowledged'=>false,'permanent_failure'=>'no_route');
  $result=($this->adapter)($authorisedCommand);
  $failure=$result['permanent_failure']??null;
  if($failure!==null&&!in_array($failure,array('contact_unusable','send_refused','no_route'),true))throw new \RuntimeException('notification_transport_result_invalid');
  return array('acknowledged'=>(bool)($result['acknowledged']??false),'permanent_failure'=>$failure);
 }
}
