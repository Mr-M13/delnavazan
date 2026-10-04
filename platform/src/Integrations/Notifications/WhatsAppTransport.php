<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
final class WhatsAppTransport implements NotificationTransportPort {public function __construct(private $adapter=null){}public function handoff(array $c):array{if(!is_callable($this->adapter))return array('acknowledged'=>false,'permanent_failure'=>'no_route');$r=($this->adapter)($c);$f=$r['permanent_failure']??null;if($f!==null&&!in_array($f,array('contact_unusable','send_refused','no_route'),true))throw new \RuntimeException('notification_transport_result_invalid');return array('acknowledged'=>(bool)($r['acknowledged']??false),'permanent_failure'=>$f);}}
