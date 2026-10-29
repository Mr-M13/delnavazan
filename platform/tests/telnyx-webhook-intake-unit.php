<?php
$root=dirname(__DIR__);
$GLOBALS['wpdb']=(object)array('prefix'=>'wp_');
$GLOBALS['dzn_options']=array();
function wp_salt(string $scheme='auth'):string{return 'telnyx-webhook-unit-salt-'.$scheme;}
function wp_json_encode($value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function get_option(string $key,$default=false){return $GLOBALS['dzn_options'][$key]??$default;}
function get_current_user_id():int{return 0;}
function is_user_logged_in():bool{return false;}
function current_user_can(string $capability):bool{return false;}
function get_user_by(string $field,$value){return null;}
function user_can($user,string $capability):bool{return false;}
function wp_set_current_user(int $id):void{}
require_once $root.'/src/Core/Support/Identifier.php';
require_once $root.'/src/Core/Application/NotificationRule.php';
require_once $root.'/src/Core/Application/NotificationSupport.php';
require_once $root.'/src/Core/Application/NotificationDeliveryEvidenceService.php';
require_once $root.'/src/Core/Application/NotificationDeliveryStateService.php';
require_once $root.'/src/Core/Application/NotificationSuppressionService.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationDeliveryRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationSuppressionRepository.php';
require_once $root.'/src/Core/Application/NotificationSystemActor.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
require_once $root.'/src/Integrations/Notifications/StudentNotificationContactLookup.php';
require_once $root.'/src/Integrations/Notifications/TelnyxWebhookSignature.php';
require_once $root.'/src/Integrations/Notifications/TelnyxWebhookEvent.php';
require_once $root.'/src/Integrations/Notifications/TelnyxWebhookService.php';
use Delnavazan\Platform\Core\Application\NotificationDeliveryEvidenceService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStateService;
use Delnavazan\Platform\Core\Application\NotificationSuppressionService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Integrations\Notifications\StudentNotificationContactLookup;
use Delnavazan\Platform\Integrations\Notifications\TelnyxWebhookService;
use Delnavazan\Platform\Integrations\Notifications\TelnyxWebhookSignature;

class DznFakeProvider extends NotificationProviderRepository {
    public array $events=array();public array $messages=array();public int $replays=0;
    public function event(string $digest):?object{return $this->events[$digest]??null;}
    public function insertEvent(array $data):int{$id=count($this->events)+1;$data['id']=$id;$this->events[$data['event_reference_digest']]=(object)$data;return $id;}
    public function updateEvent(int $id,array $fields):void{foreach($this->events as $key=>$row)if((int)$row->id===$id){foreach($fields as $k=>$v)$row->$k=$v;$this->events[$key]=$row;}}
    public function incrementReplay(int $id):void{$this->replays++;foreach($this->events as $key=>$row)if((int)$row->id===$id){$row->replay_count++;$this->events[$key]=$row;}}
    public function messageForReference(string $digest):?object{return $this->messages[$digest]??null;}
    public function updateMessageState(string $digest,string $state,string $now):void{if(isset($this->messages[$digest]))$this->messages[$digest]->state=$state;}
}
class DznFakeEvidence extends NotificationDeliveryEvidenceService {
    public array $calls=array();public string $outcome='applied';
    public function submit(array $facts):array{$this->calls[]=$facts;return array('applied'=>$this->outcome==='applied','outcome'=>$this->outcome,'delivery_id'=>1);}
}
class DznFakeSuppressions extends NotificationSuppressionService {
    public array $calls=array();
    public function suppressFromVerifiedProvider(array $input,string $key):array{$this->calls[]=array($input,$key);return array('suppression_id'=>1,'state'=>'active','created'=>true);}
}
class DznFakeContacts extends StudentNotificationContactLookup {
    public array $studentIds=array();public array $teacherIds=array();
    public function studentIdsForPhone(string $phone):array{return $this->studentIds[$phone]??array();}
    public function teacherIdsForPhone(string $phone):array{return $this->teacherIds[$phone]??array();}
    public function recipientDigest(int $studentId):string{return hash_hmac('sha256','recipient:student:'.$studentId,NotificationSupport::salt());}
    public function teacherRecipientDigest(int $teacherId):string{return hash_hmac('sha256','recipient:teacher:'.$teacherId,NotificationSupport::salt());}
}

function dzn_telnyx_body(array $data):string{return json_encode(array('data'=>$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function dzn_telnyx_headers(string $body,$secret):array{$timestamp=(string)time();return array('telnyx-timestamp'=>$timestamp,'telnyx-signature-ed25519'=>base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body,$secret)));}

$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);$public=base64_encode(sodium_crypto_sign_publickey($keypair));
$signature=TelnyxWebhookSignature::fromSettings($public);
$provider=new DznFakeProvider();$evidence=new DznFakeEvidence();$suppressions=new DznFakeSuppressions();$contacts=new DznFakeContacts();$contacts->studentIds['+15550001111']=array(42);$contacts->teacherIds['+15550002222']=array(73);$contacts->studentIds['+15550003333']=array(5,6);
$service=new TelnyxWebhookService($signature,$provider,$evidence,$suppressions,$contacts);
$reference='msg_1';$digest=hash_hmac('sha256','telnyx:message:'.$reference,NotificationSupport::salt());
$provider->messages[$digest]=(object)array('notification_id'=>7,'attempt_id'=>9,'state'=>'sent');
$data=array('id'=>'evt-1','event_type'=>'message.finalized','occurred_at'=>'2026-10-30T10:00:00Z','payload'=>array('id'=>$reference,'type'=>'SMS','to'=>array(array('status'=>'delivered'))));
$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['http']!==200||$r['outcome']!=='delivery_applied'||count($evidence->calls)!==1)throw new RuntimeException('first delivery receipt was not applied');
$r=$service->receive($body,$headers,time());
if($r['http']!==200||!$r['replayed']||$r['outcome']!=='delivery_applied'||count($evidence->calls)!==1||$provider->replays!==1)throw new RuntimeException('duplicate delivery receipt was not idempotent');
$evidence->outcome='delivery_regression_attempt';
$data['id']='evt-2';$data['payload']['to'][0]['status']='delivery_failed';$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['http']!==200||$r['outcome']!=='delivery_regression')throw new RuntimeException('out-of-order delivery fact was not retained as a regression');
if(($provider->messages[$digest]->state??'')!=='delivered')throw new RuntimeException('out-of-order fact must not regress provider message state');
$data=array('id'=>'evt-3','event_type'=>'message.received','occurred_at'=>'2026-10-30T10:01:00Z','payload'=>array('direction'=>'inbound','type'=>'SMS','from'=>array('phone_number'=>'+15550001111'),'text'=>'STOP'));
$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['http']!==200||$r['outcome']!=='suppressed'||count($suppressions->calls)!==1)throw new RuntimeException('inbound STOP was not suppressed');
if(($suppressions->calls[0][0]['purpose']??'')!=='*'||($suppressions->calls[0][0]['evidence_channel']??'')!=='provider_evidence')throw new RuntimeException('STOP suppression must be purpose-wide provider evidence');
$r=$service->receive($body,$headers,time());
if(!$r['replayed']||count($suppressions->calls)!==1)throw new RuntimeException('replayed STOP must not create a second suppression');
$data['id']='evt-4';$data['payload']['from']['phone_number']='+15559999999';$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['outcome']!=='suppression_unresolved')throw new RuntimeException('unknown STOP sender must be recorded without suppression');
$data['id']='evt-5';$data['payload']['from']['phone_number']='+15550002222';$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['outcome']!=='suppressed'||($suppressions->calls[1][0]['subject_kind']??'')!=='teacher')throw new RuntimeException('teacher STOP was not suppressed');
$data['id']='evt-6';$data['payload']['from']['phone_number']='+15550003333';$body=dzn_telnyx_body($data);$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['outcome']!=='suppressed'||count($suppressions->calls)!==4)throw new RuntimeException('shared-number STOP must suppress every matching student');
$body='not-json';$headers=dzn_telnyx_headers($body,$secret);
$r=$service->receive($body,$headers,time());
if($r['http']!==400||$r['outcome']!=='webhook_payload_invalid')throw new RuntimeException('malformed verified JSON must be refused');
$r=$service->receive($body,array('telnyx-timestamp'=>(string)time(),'telnyx-signature-ed25519'=>'bad'),time());
if($r['http']!==403||$r['outcome']!=='webhook_signature_invalid')throw new RuntimeException('invalid signature must be refused before parsing');
echo "Telnyx webhook intake unit passed\n";
