<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
final class SmsTransport implements NotificationTransportPort {
 public function __construct(private $adapter=null){} public function channel():string{return 'sms';} public function configured():bool{return is_callable($this->adapter);}
 public function send(array $message):array{return $this->configured()?($this->adapter)($message):array('status'=>'refused','reason'=>'no_route');}
}
