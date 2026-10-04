<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
final class FakeTransport implements NotificationTransportPort {public function handoff(array $c):array{return array('acknowledged'=>true,'permanent_failure'=>null);}}
$port=new FakeTransport();$r=new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$port),array('channel'=>'sms','configured'=>true,'port'=>$port),array('channel'=>'email','configured'=>true,'port'=>$port)));
$channels=fn($xs)=>array_map(fn($x)=>$x['channel'],$xs);
if($channels($r->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp'))throw new RuntimeException('WhatsApp must be preferred');
if($channels($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('SMS must cover unavailable WhatsApp');
if($channels($r->fallbackAfter('whatsapp','contact_unusable',array('sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('Unusable WhatsApp contact must allow SMS fallback');
if($r->fallbackAfter('whatsapp',null,array('sms_eligible'=>true))!==array())throw new RuntimeException('Non-permanent WhatsApp failure must not duplicate via SMS');
if($channels($r->route('email',array('email_eligible'=>true)))!==array('email'))throw new RuntimeException('Email route must remain explicit');
echo "Notification transport routing contract passed\n";
