<?php
require_once dirname(__DIR__).'/src/Integrations/Notifications/TelnyxWebhookEvent.php';
use Delnavazan\Platform\Integrations\Notifications\TelnyxWebhookEvent;
function dzn_telnyx_event(array $data):string{return json_encode(array('data'=>$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
$base=array('id'=>'evt_1','occurred_at'=>'2026-10-30T10:00:00+00:00');
$final=$base+array('event_type'=>'message.finalized','payload'=>array('id'=>'msg_1','direction'=>'outbound','type'=>'SMS','to'=>array(array('status'=>'delivered'))));
$e=TelnyxWebhookEvent::normalise(dzn_telnyx_event($final));
if($e['delivery_state']!=='delivered'||$e['channel']!=='sms'||$e['occurred_at']!=='2026-10-30 10:00:00')throw new RuntimeException('SMS delivered normalisation failed');
$failed=$base+array('event_type'=>'message.finalized','payload'=>array('id'=>'msg_2','type'=>'SMS','to'=>array(array('status'=>'delivery_failed'))));
if(TelnyxWebhookEvent::normalise(dzn_telnyx_event($failed))['delivery_state']!=='failed')throw new RuntimeException('SMS failed normalisation failed');
$sent=$base+array('event_type'=>'message.sent','payload'=>array('id'=>'msg_3','type'=>'SMS','to'=>array(array('status'=>'queued'))));
if(TelnyxWebhookEvent::normalise(dzn_telnyx_event($sent))['delivery_state']!=='sent')throw new RuntimeException('SMS sent normalisation failed');
$unconfirmed=$base+array('event_type'=>'message.finalized','payload'=>array('id'=>'msg_4','type'=>'SMS','to'=>array(array('status'=>'delivery_unconfirmed'))));
if(TelnyxWebhookEvent::normalise(dzn_telnyx_event($unconfirmed))['delivery_state']!=='undelivered')throw new RuntimeException('SMS unconfirmed normalisation failed');
$inbound=$base+array('event_type'=>'message.received','payload'=>array('direction'=>'inbound','type'=>'SMS','from'=>array('phone_number'=>'+15550001111'),'text'=>'  STOP. '));
$e=TelnyxWebhookEvent::normalise(dzn_telnyx_event($inbound));
if($e['channel']!=='sms'||!$e['stop']||$e['recipient_phone']!=='+15550001111'||$e['delivery_state']!==null)throw new RuntimeException('SMS inbound STOP normalisation failed');
$wa=$base+array('event_type'=>'message.received','payload'=>array('direction'=>'inbound','type'=>'WHATSAPP','body'=>array('id'=>'wamid.1','from'=>'+989121234567','text'=>'لغو')));
$e=TelnyxWebhookEvent::normalise(dzn_telnyx_event($wa));
if($e['channel']!=='whatsapp'||!$e['stop']||$e['recipient_phone']!=='+989121234567')throw new RuntimeException('WhatsApp inbound STOP normalisation failed');
foreach(array('STOPALL','STOP ALL','UNSUBSCRIBE','CANCEL','END','QUIT','OPT OUT','توقف','انصراف') as $word)if(!TelnyxWebhookEvent::isStop($word))throw new RuntimeException('STOP word refused: '.$word);
if(TelnyxWebhookEvent::isStop('Please stop by later'))throw new RuntimeException('ordinary text must not suppress');
$invalid=false;try{TelnyxWebhookEvent::normalise('{broken');}catch(InvalidArgumentException $e){$invalid=$e->getMessage()==='webhook_payload_invalid';}
if(!$invalid)throw new RuntimeException('malformed JSON must be refused');
$unknown=false;try{TelnyxWebhookEvent::normalise(dzn_telnyx_event(array('id'=>'x','event_type'=>'message.read','occurred_at'=>'2026-10-30T10:00:00Z','payload'=>array())));}catch(InvalidArgumentException $e){$unknown=$e->getMessage()==='webhook_event_unsupported';}
if(!$unknown)throw new RuntimeException('unknown event must be refused');
echo "Telnyx webhook event unit passed\n";
