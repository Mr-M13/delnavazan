<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/SmsProviderRoutingPolicy.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
final class FakeTransport implements NotificationTransportPort{public function handoff(array $c):array{return array('acknowledged'=>true,'permanent_failure'=>null);}}
$p=new FakeTransport();$r=new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$p),array('channel'=>'sms','configured'=>true,'port'=>$p),array('channel'=>'email','configured'=>true,'port'=>$p)));$ch=fn($xs)=>array_map(fn($x)=>$x['channel'],$xs);
if($ch($r->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp','sms'))throw new RuntimeException('Enabled mobile methods must route independently');
if($ch($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('SMS must not depend on WhatsApp eligibility');
if($r->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>false))!==array())throw new RuntimeException('An ineligible mobile recipient must not select SMS');
if($ch($r->route('email',array('email_eligible'=>true)))!==array('email'))throw new RuntimeException('Email route failed');
$telnyx=new FakeTransport();$iran=new FakeTransport();
$providers=new NotificationTransportRouter(array(
 array('channel'=>'sms','provider'=>'telnyx','configured'=>true,'port'=>$telnyx),
 array('channel'=>'sms','provider'=>'sms_ir','configured'=>true,'port'=>$iran)
));
$chosen=fn($kind,$number)=>$providers->route('mobile',array('recipient_kind'=>$kind,'sms'=>$number,'sms_eligible'=>true));
if(($chosen('teacher','+989121234567')[0]['provider']??'')!=='sms_ir')throw new RuntimeException('Iranian teacher must use SMS.ir');
if(($chosen('student','+989121234567')[0]['provider']??'')!=='sms_ir')throw new RuntimeException('Iranian destination must use SMS.ir regardless of role');
if(($chosen('teacher','+61400123456')[0]['provider']??'')!=='telnyx')throw new RuntimeException('Overseas teacher must use Telnyx');
$notReady=new NotificationTransportRouter(array(array('channel'=>'sms','provider'=>'telnyx','configured'=>true,'port'=>$telnyx),array('channel'=>'sms','provider'=>'sms_ir','configured'=>false,'port'=>$iran)));
if($notReady->route('mobile',array('recipient_kind'=>'teacher','sms'=>'+989121234567','sms_eligible'=>true))!==array())throw new RuntimeException('Unconfigured SMS.ir must fail closed without provider fallback');
$unavailable=new NotificationTransportRouter(array(array('channel'=>'sms','provider'=>'sms_ir','configured'=>true,'port'=>$iran)));
if(($unavailable->route('mobile',array('recipient_kind'=>'student','sms'=>'+989121234567','sms_eligible'=>true))[0]['provider']??'')!=='sms_ir')throw new RuntimeException('Iranian student must use SMS.ir');
if(\Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy::select('+989121234567',array('iran'=>'telnyx','international'=>'sms_ir'))!=='telnyx')throw new RuntimeException('Admin override must select Telnyx for Iran');
echo "Notification transport routing contract passed\n";
