<?php
$root=dirname(__DIR__);
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root.'/src/Integrations/Notifications/TelnyxWhatsAppTestTemplate.php';
use Delnavazan\Platform\Integrations\Notifications\TelnyxWhatsAppTestTemplate;
$rows=array(
    (object)array('template_name'=>'delnavazan_booking_confirmation_fa','language'=>'fa','status'=>'APPROVED','event_key'=>'booking.requested.student'),
    (object)array('template_name'=>'delnavazan_teacher_new_booking_fa','language'=>'fa','status'=>'APPROVED','event_key'=>'booking.new.teacher'),
    (object)array('template_name'=>'delnavazan_payment_due_fa','language'=>'fa','status'=>'PENDING','event_key'=>null),
);
$byEvent=TelnyxWhatsAppTestTemplate::resolve('booking.new.teacher',$rows);
if($byEvent===null||$byEvent['name']!=='delnavazan_teacher_new_booking_fa'||$byEvent['language']!=='fa'||$byEvent['event_key']!=='booking.new.teacher')throw new RuntimeException('event-key test template resolution failed');
$byName=TelnyxWhatsAppTestTemplate::resolve('delnavazan_booking_confirmation_fa',$rows);
if($byName===null||$byName['name']!=='delnavazan_booking_confirmation_fa'||$byName['event_key']!=='booking.requested.student')throw new RuntimeException('provider-name test template resolution failed');
$fallback=TelnyxWhatsAppTestTemplate::resolve('',$rows);
if($fallback===null||$fallback['name']!=='delnavazan_booking_confirmation_fa')throw new RuntimeException('empty setting must select the first approved mapped template');
if(TelnyxWhatsAppTestTemplate::resolve('booking.new.teacher',array($rows[2]))!==null)throw new RuntimeException('pending template must not be used for an explicit test');
$multi=array(
    (object)array('template_name'=>'delnavazan_class_access_fa','language'=>'en','status'=>'PENDING','event_key'=>null),
    (object)array('template_name'=>'delnavazan_class_access_fa','language'=>'fa','status'=>'APPROVED','event_key'=>'lesson.access.student'),
);
$multiByName=TelnyxWhatsAppTestTemplate::resolve('delnavazan_class_access_fa',$multi);
if($multiByName===null||$multiByName['language']!=='fa'||$multiByName['event_key']!=='lesson.access.student')throw new RuntimeException('a pending language variant must not hide an approved variant');
$multiByEvent=TelnyxWhatsAppTestTemplate::resolve('lesson.access.student',$multi);
if($multiByEvent===null||$multiByEvent['language']!=='fa')throw new RuntimeException('event-key resolution must select the approved language variant');
if(TelnyxWhatsAppTestTemplate::resolve('not_a_template',$rows)!==null)throw new RuntimeException('unknown non-empty setting must fail closed');
echo "Telnyx WhatsApp test template unit passed\n";
