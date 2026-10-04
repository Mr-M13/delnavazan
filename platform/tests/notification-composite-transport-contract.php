<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationCopyPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
require_once dirname(__DIR__).'/src/Integrations/Notifications/CompositeNotificationTransport.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;use Delnavazan\Platform\Core\Application\NotificationCopyPort;use Delnavazan\Platform\Core\Application\NotificationTransportRouter;use Delnavazan\Platform\Integrations\Notifications\CompositeNotificationTransport;
final class P implements NotificationTransportPort{public array $seen=array();public function __construct(private array $r){}public function handoff(array $c):array{$this->seen[]=$c;return $this->r;}}
final class C implements NotificationCopyPort{public function render(int $v,string $ch,string $l,array $p):?array{return array('subject'=>'s','body'=>'hello '.($p['name']??''));}}
$wa=new P(array('acknowledged'=>false,'permanent_failure'=>'contact_unusable'));$sms=new P(array('acknowledged'=>true,'permanent_failure'=>null));
$router=new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$wa),array('channel'=>'sms','configured'=>true,'port'=>$sms)));
$t=new CompositeNotificationTransport($router,new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>true,'sms_eligible'=>true,'whatsapp'=>'+1','sms'=>'+1'));
$r=$t->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا'),'notification_key_digest'=>'x','attempt_sequence'=>1,'audience'=>'student','variable_codes'=>array('name')));
if(empty($r['acknowledged'])||count($wa->seen)!==1||count($sms->seen)!==1)throw new RuntimeException('Permanent WhatsApp failure must fall back once to SMS');
if(($sms->seen[0]['body']??'')!=='hello ندا')throw new RuntimeException('Copy must render at transport boundary');
$retry=new P(array('acknowledged'=>false,'permanent_failure'=>null));$sms2=new P(array('acknowledged'=>true,'permanent_failure'=>null));
$t2=new CompositeNotificationTransport(new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$retry),array('channel'=>'sms','configured'=>true,'port'=>$sms2))),new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>true,'sms_eligible'=>true,'whatsapp'=>'+1','sms'=>'+1'));
$r2=$t2->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا')));
if(!array_key_exists('permanent_failure',$r2)||$r2['permanent_failure']!==null||count($sms2->seen)!==0)throw new RuntimeException('Transient failure must not duplicate through SMS');
echo "Composite notification transport contract passed\n";