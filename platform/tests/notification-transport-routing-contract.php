<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
final class FakeTransport implements NotificationTransportPort {public function __construct(private string $c,private bool $ok=true){}public function channel():string{return $this->c;}public function configured():bool{return $this->ok;}public function send(array $m):array{return array('status'=>'accepted');}}
$wa=new FakeTransport('whatsapp');$sms=new FakeTransport('sms');$email=new FakeTransport('email');$r=new NotificationTransportRouter(array($wa,$sms,$email));
$channels=fn($xs)=>array_map(fn($x)=>$x->channel(),$xs);
if($channels($r->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp'))throw new RuntimeException('WhatsApp must be preferred');
if($channels($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('SMS must cover unavailable WhatsApp');
if($channels($r->fallbackAfter('whatsapp','unreachable',array('sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('Unreachable WhatsApp must allow SMS fallback');
if($r->fallbackAfter('whatsapp','retryable',array('sms_eligible'=>true))!==array())throw new RuntimeException('Transient WhatsApp failure must not duplicate via SMS');
if($channels($r->route('email',array('email_eligible'=>true)))!==array('email'))throw new RuntimeException('Email route must remain explicit');
echo "Notification transport routing contract passed\n";
