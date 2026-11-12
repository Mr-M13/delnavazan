<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
final class FakeTransport implements NotificationTransportPort{public function handoff(array $c):array{return array('acknowledged'=>true,'permanent_failure'=>null);}}
$p=new FakeTransport();$r=new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$p),array('channel'=>'sms','configured'=>true,'port'=>$p),array('channel'=>'email','configured'=>true,'port'=>$p)));$ch=fn($xs)=>array_map(fn($x)=>$x['channel'],$xs);
if($ch($r->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp','sms'))throw new RuntimeException('Enabled mobile methods must route independently');
if($ch($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('SMS must not depend on WhatsApp eligibility');
if($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>false))!==array())throw new RuntimeException('An ineligible mobile recipient must not select SMS');
if($ch($r->route('email',array('email_eligible'=>true)))!==array('email'))throw new RuntimeException('Email route failed');
echo "Notification transport routing contract passed\n";
