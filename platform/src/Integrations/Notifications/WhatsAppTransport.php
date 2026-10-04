<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
final class WhatsAppTransport implements NotificationTransportPort {
 public function __construct(private $adapter=null){} public function channel():string{return 'whatsapp';} public function configured():bool{return is_callable($this->adapter);}
 public function send(array $message):array{return $this->configured()?($this->adapter)($message):array('status'=>'refused','reason'=>'no_route');}
}
