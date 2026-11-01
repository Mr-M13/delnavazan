<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationCopyPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
require_once dirname(__DIR__).'/src/Integrations/Notifications/CompositeNotificationTransport.php';
use Delnavazan\Platform\Core\Application\NotificationTransportPort;use Delnavazan\Platform\Core\Application\NotificationCopyPort;use Delnavazan\Platform\Core\Application\NotificationTransportRouter;use Delnavazan\Platform\Integrations\Notifications\CompositeNotificationTransport;
final class P implements NotificationTransportPort{public array $seen=array();public function __construct(private array $r){}public function handoff(array $c):array{$this->seen[]=$c;return $this->r;}}
final class C implements NotificationCopyPort{public function render(int $v,string $ch,string $l,array $p):?array{return array('subject'=>'s','body'=>($ch==='sms'?'sms ':'wa ').($p['name']??''));}}
$wa=new P(array('acknowledged'=>false,'permanent_failure'=>'contact_unusable','provider_attempted'=>true));
$sms=new P(array('acknowledged'=>true,'permanent_failure'=>null));
$bindings=array(array('channel'=>'whatsapp','configured'=>true,'port'=>$wa),array('channel'=>'sms','configured'=>true,'port'=>$sms));
$t=new CompositeNotificationTransport(new NotificationTransportRouter($bindings),new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>true,'sms_eligible'=>true,'whatsapp'=>'+1','sms'=>'+1'));
$r=$t->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا'),'notification_key_digest'=>'x','attempt_sequence'=>1,'audience'=>'student','variable_codes'=>array('name')));
if(!empty($r['acknowledged'])||$r['permanent_failure']!=='contact_unusable'||count($wa->seen)!==1||count($sms->seen)!==0)throw new RuntimeException('A WhatsApp terminal refusal must not fall back to SMS inside transport');
$digest=str_repeat('b',64);
$t2=new CompositeNotificationTransport(new NotificationTransportRouter($bindings),new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>false,'sms_eligible'=>true,'whatsapp'=>'+1','sms'=>'+1','sms_fallback_authorised'=>true,'whatsapp_terminal_failure_digest'=>$digest));
$r2=$t2->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا'),'notification_key_digest'=>'x','attempt_sequence'=>2,'audience'=>'student','variable_codes'=>array('name')));
if(empty($r2['acknowledged'])||count($wa->seen)!==1||count($sms->seen)!==1)throw new RuntimeException('An authorised fallback notification must use SMS exactly once');
if(($sms->seen[0]['body']??'')!=='sms ندا')throw new RuntimeException('Copy must render the authorised SMS channel');
$retry=new P(array('acknowledged'=>false,'permanent_failure'=>null,'ambiguous'=>true));$sms2=new P(array('acknowledged'=>true,'permanent_failure'=>null));
$t3=new CompositeNotificationTransport(new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$retry),array('channel'=>'sms','configured'=>true,'port'=>$sms2))),new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>true,'sms_eligible'=>true,'whatsapp'=>'+1','sms'=>'+1'));
$r3=$t3->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا')));
if(!array_key_exists('permanent_failure',$r3)||$r3['permanent_failure']!==null||count($sms2->seen)!==0)throw new RuntimeException('Transient failure must not duplicate through SMS');
$waSafe=new P(array('acknowledged'=>true,'permanent_failure'=>null));
$safe=new CompositeNotificationTransport(new NotificationTransportRouter(array(array('channel'=>'whatsapp','configured'=>true,'port'=>$waSafe))),new C(),fn($c)=>array('route'=>'mobile','locale'=>'fa-IR','whatsapp_eligible'=>true,'whatsapp'=>'+61400123456'));
$safe->handoff(array('template_version_id'=>7,'parameters'=>array('name'=>'ندا'),'channel'=>'sms','to'=>'+19999999999','subject'=>'unsafe subject','body'=>'unsafe body'));
if(($waSafe->seen[0]['channel']??'')!=='whatsapp'||($waSafe->seen[0]['to']??'')!=='+61400123456'||($waSafe->seen[0]['subject']??'')!=='s'||($waSafe->seen[0]['body']??'')!=='wa ندا')throw new RuntimeException('Resolved channel, recipient, and rendered copy must override untrusted handoff fields');
echo "Composite notification transport contract passed\n";
