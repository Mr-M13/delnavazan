<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
final class FakeTransport implements NotificationTransportPort{public function handoff(array $c):array{return array('acknowledged'=>true,'permanent_failure'=>null);}}
$p=new FakeTransport();$r=new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$p),array('channel'=>'sms','configured'=>true,'port'=>$p),array('channel'=>'email','configured'=>true,'port'=>$p)));$ch=fn($xs)=>array_map(fn($x)=>$x['channel'],$xs);
$digest=str_repeat('a',64);
$fallback=array('whatsapp_terminal_failure_digest'=>$digest,'sms_fallback_authorised'=>true);
if($ch($r->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp'))throw new RuntimeException('WhatsApp must be the only direct mobile route');
if($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true))!==array())throw new RuntimeException('An unavailable WhatsApp must never select SMS');
if($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>false))!==array())throw new RuntimeException('An ineligible mobile recipient must not select SMS');
if($ch($r->route('mobile',$fallback+array('whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('An authorised verified fallback must select SMS');
if($r->route('mobile',array('sms_fallback_authorised'=>true,'whatsapp_eligible'=>false,'sms_eligible'=>true))!==array())throw new RuntimeException('A fallback without a verified failure digest must be refused');
if($r->fallbackAfter('whatsapp','contact_unusable',array('sms_eligible'=>true))!==array())throw new RuntimeException('A permanent failure alone must never duplicate through SMS');
if($ch($r->fallbackAfter('whatsapp','contact_unusable',$fallback+array('sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('An explicitly authorised fallback must return SMS');
if($r->fallbackAfter('whatsapp',null,array('sms_eligible'=>true))!==array())throw new RuntimeException('Transient failure must not duplicate');
if($ch($r->route('email',array('email_eligible'=>true)))!==array('email'))throw new RuntimeException('Email route failed');
echo "Notification transport routing contract passed\n";
