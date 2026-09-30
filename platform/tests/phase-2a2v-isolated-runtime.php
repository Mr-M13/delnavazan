<?php
/**
 * Phase 2A.2-V isolated runtime proof.
 *
 * This suite needs no WordPress, no database and no container: it executes the pure Phase-V seams —
 * the controlled rule, the digest boundary, the credential sealing boundary, the Google translation
 * adapter, the deterministic contract adapters and the fail-closed shape validator — with synthetic
 * local values only. It is the evidence that can be run anywhere, including a sandbox with no PHP
 * database driver; the WP-CLI/MariaDB suites exercise the storage and process-level behaviour.
 */
$root=dirname(__DIR__);
if(!function_exists('wp_salt')){function wp_salt(string $scheme='auth'):string{return 'isolated-salt-'.$scheme.'-0123456789abcdef';}}
if(!function_exists('wp_json_encode')){function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}}
if(!function_exists('current_user_can')){function current_user_can(string $capability):bool{return in_array($capability,$GLOBALS['dzn_v_caps']??array(),true);}}
if(!function_exists('get_current_user_id')){function get_current_user_id():int{return (int)($GLOBALS['dzn_v_actor']??1);}}
require $root.'/src/Core/Application/ProviderIntegrationRule.php';
require $root.'/src/Core/Application/ProviderIntegrationIdempotency.php';
require $root.'/src/Core/Application/IntegrationSecretService.php';
require $root.'/src/Core/Application/ProviderIntegrationValidator.php';
require $root.'/src/Core/Application/Port/ProviderOAuthPort.php';
require $root.'/src/Core/Application/Port/ProviderCalendarPort.php';
require $root.'/src/Core/Application/Port/ProviderMeetingPort.php';
require $root.'/src/Core/Application/Port/ProviderEventNormalizer.php';
require $root.'/src/Integrations/GoogleCalendarMeetAdapter.php';
require $root.'/src/Integrations/ContractProviderAdapters.php';

use Delnavazan\Platform\Core\Application\{IntegrationSecretService,ProviderIntegrationIdempotency,ProviderIntegrationRule,ProviderIntegrationValidator};
use Delnavazan\Platform\Integrations\{ContractProviderAdapters,GoogleCalendarMeetAdapter};

$assertions=0;
$assert=static function(bool $ok,string $message) use (&$assertions):void{$assertions++;if(!$ok)throw new RuntimeException($message);};
$refused=static function(callable $call,string $message) use (&$assertions):void{
    $assertions++;
    try{$call();}catch(\Throwable){return;}
    throw new RuntimeException('Expected refusal: '.$message);
};

// 1. Controlled provider vocabulary: only the two admitted codes, and only Meet may carry evidence.
$assert(ProviderIntegrationRule::providerCode('google_calendar')==='google_calendar','the calendar code must be admitted');
$assert(ProviderIntegrationRule::providerCode('GOOGLE_MEET')==='google_meet','the Meet code must be admitted case-insensitively and normalised');
$assert(ProviderIntegrationRule::evidenceProviderCode('google_meet')==='google_meet','Meet must be an evidence provider code');
$refused(fn()=>ProviderIntegrationRule::providerCode('outlook'),'an unlisted provider code');
$refused(fn()=>ProviderIntegrationRule::evidenceProviderCode('google_calendar'),'a projection-only code carrying attendance evidence');
$refused(fn()=>ProviderIntegrationRule::purpose('free_form_purpose'),'an unlisted mapping purpose');

// 2. V-D2 lifecycle transitions and usability.
$assert(ProviderIntegrationRule::canTransition('disconnected','authorizing'),'disconnected may begin authorizing');
$assert(ProviderIntegrationRule::canTransition('connected','revoking'),'connected may be revoked');
$assert(ProviderIntegrationRule::canTransition('revoking','revoke_failed'),'a failed revoke is a recorded retryable outcome');
$assert(ProviderIntegrationRule::canTransition('revoke_failed','revoked'),'a retried revoke may still succeed');
$assert(!ProviderIntegrationRule::canTransition('revoked','authorizing'),'a revoked connection may never be revived');
$assert(!ProviderIntegrationRule::canTransition('revoked','connected'),'a revoked connection may never return to connected');
$assert(ProviderIntegrationRule::usable('connected'),'only a connected state is usable');
foreach(array('authorizing','refresh_failed','revoking','revoke_failed','revoked','disconnected') as $state)
    $assert(!ProviderIntegrationRule::usable($state),'state '.$state.' must never be usable for a provider call');

// 3. V-D3 initiation facts: exact redirect, set-exact scopes, UTC-only instants.
$assert(ProviderIntegrationRule::sameRedirectUri('https://academy.example/cb','https://academy.example/cb'),'an identical redirect target must match');
$assert(!ProviderIntegrationRule::sameRedirectUri('https://academy.example/cb','https://academy.example/cb.evil.example'),'a prefixed redirect target must never match');
$assert(!ProviderIntegrationRule::sameRedirectUri('https://academy.example/cb','http://academy.example/cb'),'a non-HTTPS redirect target must never match');
$scopes=ProviderIntegrationRule::scopeSnapshot(array('https://www.googleapis.com/auth/meetings.space.created','https://www.googleapis.com/auth/calendar.events'));
$assert($scopes==='https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.created','the scope snapshot must be ordered and de-duplicated');
$assert(ProviderIntegrationRule::grantedScopesMatch($scopes,$scopes),'an identical granted scope set must match');
$assert(ProviderIntegrationRule::grantedScopesMatch($scopes,$scopes.' https://www.googleapis.com/auth/drive')===false,'an extra granted scope must never match the requested set');
$assert(ProviderIntegrationRule::grantedScopesMatch($scopes,'https://www.googleapis.com/auth/calendar.events')===false,'a missing granted scope must never match the requested set');
$refused(fn()=>ProviderIntegrationRule::scopeSnapshot(''),'an empty scope set');
$assert(ProviderIntegrationRule::utc('2026-09-25 10:00:00'),'a canonical UTC instant must validate');
foreach(array('2026-09-25T10:00:00Z','2026-09-25','','2026-13-45 99:00:00') as $bad)
    $assert(!ProviderIntegrationRule::utc($bad),'a non-canonical instant must be refused: '.$bad);
$assert(ProviderIntegrationRule::digest(str_repeat('a',64)),'a 64-hex digest must validate');
foreach(array(str_repeat('A',64),str_repeat('a',63),'',null) as $bad)
    $assert(!ProviderIntegrationRule::digest($bad),'a non-hex digest must be refused');
$evidence=ProviderIntegrationRule::evidenceFacts(array('evidence_channel'=>'staff_record','evidence_reference'=>'raw-staff-reference','evidence_at'=>'2026-09-25 10:00:00'));
$assert($evidence['evidence_channel']==='staff_record'&&ProviderIntegrationRule::digest($evidence['evidence_reference_digest']),'provenance must be recorded as a controlled channel plus a digest');
$assert(!str_contains((string)wp_json_encode($evidence),'raw-staff-reference'),'a raw human reference must never survive into the recorded facts');
$refused(fn()=>ProviderIntegrationRule::evidenceFacts(array('evidence_channel'=>'guess','evidence_reference'=>'x','evidence_at'=>'2026-09-25 10:00:00')),'a free-form evidence channel');
$refused(fn()=>ProviderIntegrationRule::evidenceFacts(array('evidence_channel'=>'staff_record','evidence_reference'=>'x','evidence_at'=>'soon')),'a non-UTC evidence instant');

// 3b. V-D8 delivery authenticity: only an envelope a named trusted transport already authenticated,
// bound to the exact body it validated, may become provider evidence.
$authenticated=static function(array $facts,string $providerCode='google_meet'):array{
    $body=(string)wp_json_encode($facts);
    return array(
        'provider_code'=>$providerCode,'transport'=>'deployment_gateway','authenticated'=>true,
        'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>$body,'body_digest'=>hash('sha256',$body),
        'proof_reference'=>'proof-0123456789abcdef','facts'=>$facts,
    );
};
$assert(ProviderIntegrationRule::proofTransport('deployment_gateway')==='deployment_gateway','a named trusted transport must be admitted');
$assert(ProviderIntegrationRule::proofTransport('GOOGLE_CHANNEL_JWT')==='google_channel_jwt','a transport identity must be normalised case-insensitively');
$refused(fn()=>ProviderIntegrationRule::proofTransport('channel_token'),'an unlisted transport identity');
$envelope=ProviderIntegrationRule::deliveryEnvelope($authenticated(array('event_key'=>'e-1')));
$assert($envelope['transport']==='deployment_gateway'&&ProviderIntegrationRule::digest($envelope['proof_reference_digest']),'the authenticated envelope must record the transport and digest of its proof');
$assert(!str_contains((string)wp_json_encode($envelope),'proof-0123456789abcdef'),'the raw transport proof must never survive into the envelope');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_meet','transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>'{}','body_digest'=>str_repeat('0',64),'proof_reference'=>'proof-0123456789abcdef')),'a body that does not match its declared digest');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_meet','transport'=>'deployment_gateway','authenticated'=>false,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>'{}','body_digest'=>hash('sha256','{}'),'proof_reference'=>'proof-0123456789abcdef')),'an unauthenticated delivery');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_meet','transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>'','body_digest'=>hash('sha256',''),'proof_reference'=>'proof-0123456789abcdef')),'an empty body');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_meet','transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>'{}','body_digest'=>hash('sha256','{}'),'proof_reference'=>'short')),'an envelope without a transport proof');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_calendar','transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>'{}','body_digest'=>hash('sha256','{}'),'proof_reference'=>'proof-0123456789abcdef')),'a projection-only provider code carrying attendance evidence');
$refused(fn()=>ProviderIntegrationRule::deliveryEnvelope(array('provider_code'=>'google_meet','raw_body'=>'{}','headers'=>array('x-goog-channel-token'=>'t'))),'a raw delivery carrying a channel token');

// 4. V-D6 projection facts are copied from the exact canonical schedule version, never derived.
$version=(object)array('id'=>91,'version_number'=>2,'starts_at_utc'=>'2026-09-25 08:00:00','ends_at_utc'=>'2026-09-25 09:00:00','schedule_timezone'=>'Australia/Brisbane','local_wall_date'=>'2026-09-25','local_wall_time'=>'18:00:00');
$facts=ProviderIntegrationRule::scheduleProjectionFacts($version);
$assert($facts['starts_at_utc']==='2026-09-25 08:00:00'&&$facts['ends_at_utc']==='2026-09-25 09:00:00','the projection must copy the exact canonical interval');
$assert($facts['schedule_timezone']==='Australia/Brisbane'&&$facts['local_wall_time']==='18:00:00','the projection must copy the exact wall-clock provenance');
$assert($facts['schedule_version_id']===91&&$facts['version_number']===2,'the projection must name the exact canonical version');
foreach(array('','not-a-time') as $bad)foreach(array('starts_at_utc','ends_at_utc') as $field){
    $broken=clone $version;$broken->{$field}=$bad;
    $refused(fn()=>ProviderIntegrationRule::scheduleProjectionFacts($broken),'an unusable canonical interval');
}
$broken=clone $version;$broken->ends_at_utc=$broken->starts_at_utc;
$refused(fn()=>ProviderIntegrationRule::scheduleProjectionFacts($broken),'a zero-length canonical interval');
$broken=clone $version;$broken->schedule_timezone='';
$refused(fn()=>ProviderIntegrationRule::scheduleProjectionFacts($broken),'a canonical version without wall-clock provenance');

// 5. V-D10/V-D11 digest boundary: deterministic, domain-separated, reference-sensitive.
$key=ProviderIntegrationIdempotency::key('dzn-v-key-1');
$assert($key===ProviderIntegrationIdempotency::key('dzn-v-key-1'),'the command key digest must be deterministic');
$assert($key!==ProviderIntegrationIdempotency::key('dzn-v-key-2'),'a different command key must digest differently');
$assert(ProviderIntegrationRule::digest($key),'the command key digest must be a 64-hex value');
$payloadA=ProviderIntegrationIdempotency::payload(array('operation'=>'project_calendar_event','lesson_id'=>7,'schedule_version_id'=>91));
$payloadB=ProviderIntegrationIdempotency::payload(array('schedule_version_id'=>91,'lesson_id'=>7,'operation'=>'project_calendar_event'));
$assert($payloadA===$payloadB,'the payload digest must be order-insensitive over the same facts');
$assert($payloadA!==ProviderIntegrationIdempotency::payload(array('operation'=>'project_calendar_event','lesson_id'=>7,'schedule_version_id'=>92)),'a changed fact must change the payload digest');
$digests=array(
    ProviderIntegrationIdempotency::key('same'),
    ProviderIntegrationIdempotency::subject('same','calendar_event'),
    ProviderIntegrationIdempotency::subject('same','meeting_conference'),
    ProviderIntegrationIdempotency::evidence('same'),
    ProviderIntegrationIdempotency::providerEventKey('same'),
    ProviderIntegrationIdempotency::providerPayload('same'),
    ProviderIntegrationIdempotency::proof('same'),
);
$assert(count(array_unique($digests))===count($digests),'every digest domain must be separated from every other domain');
$tampered=ProviderIntegrationIdempotency::payload(array('provider_code'=>'google_meet','provider_account_digest'=>ProviderIntegrationIdempotency::evidence('acct'),'join_at_utc'=>'2026-09-25 08:00:00'));
$assert($tampered!==ProviderIntegrationIdempotency::payload(array('provider_code'=>'google_meet','provider_account_digest'=>ProviderIntegrationIdempotency::evidence('acct'),'join_at_utc'=>'2026-09-25 08:05:00')),'a shifted interval must never converge');

// 6. V-D4 credential sealing: unique nonce, versioned key/cipher, bound to one connection, fail closed.
$secrets=new IntegrationSecretService();
$sealed=$secrets->seal(41,'google_calendar','refresh-material-under-test');
$assert($sealed['key_version']===IntegrationSecretService::KEY_VERSION&&$sealed['cipher_version']!=='','a sealed credential must record its key and cipher version');
$assert(base64_decode($sealed['nonce'],true)!==false&&base64_decode($sealed['ciphertext'],true)!==false,'a sealed credential must be base64 ciphertext plus nonce');
$assert(!str_contains((string)wp_json_encode($sealed),'refresh-material-under-test'),'sealing must never leave the material readable');
$assert($secrets->open(41,'google_calendar',$sealed)==='refresh-material-under-test','a sealed credential must open for its own connection');
$resealed=$secrets->seal(41,'google_calendar','refresh-material-under-test');
$assert($resealed['nonce']!==$sealed['nonce'],'every seal must use a unique nonce');
$rotated=$secrets->rotate(41,'google_calendar',$sealed);
$assert($rotated['nonce']!==$sealed['nonce']&&$secrets->open(41,'google_calendar',$rotated)==='refresh-material-under-test','rotation must re-seal under a fresh nonce and still open');
$refused(fn()=>$secrets->open(42,'google_calendar',$sealed),'a credential row moved to another connection');
$refused(fn()=>$secrets->open(41,'google_meet',$sealed),'a credential row moved to another provider code');
$corrupt=$sealed;$corrupt['ciphertext']=base64_encode(substr(base64_decode($sealed['ciphertext'],true),0,-4).'evil');
$refused(fn()=>$secrets->open(41,'google_calendar',$corrupt),'a tampered ciphertext');
$corrupt=$sealed;$corrupt['nonce']=base64_encode(str_repeat("\x01",3));
$refused(fn()=>$secrets->open(41,'google_calendar',$corrupt),'a malformed nonce');
$corrupt=$sealed;$corrupt['key_version']='dzn_unknown_v9';
$refused(fn()=>$secrets->open(41,'google_calendar',$corrupt),'an unknown key version');
$corrupt=$sealed;$corrupt['cipher_version']='rot13_v0';
$refused(fn()=>$secrets->open(41,'google_calendar',$corrupt),'an unknown cipher version');
$redacted=IntegrationSecretService::redacted($sealed);
$assert($redacted['sealed']===true&&!str_contains((string)wp_json_encode($redacted),'refresh-material'),'a redacted credential report must never carry material');

// 7. Google translation seam: pure, exact, PKCE-bound, translation-only, and never an I/O client.
$adapter=new GoogleCalendarMeetAdapter();
$uri=$adapter->authorizationUri(array('client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events','state'=>'state-value','code_challenge'=>'challenge-value'));
foreach(array('client_id=client-1','redirect_uri=https%3A%2F%2Facademy.example%2Fcb','code_challenge=challenge-value','code_challenge_method=S256','state=state-value') as $needle)
    $assert(str_contains($uri,$needle),'the authorization request must carry '.$needle);
$refused(fn()=>$adapter->authorizationUri(array('client_reference'=>'client-1','redirect_uri'=>'http://academy.example/cb','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events','state'=>'s','code_challenge'=>'c')),'an insecure redirect target');
$refused(fn()=>$adapter->authorizationUri(array('client_reference'=>'','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events','state'=>'s','code_challenge'=>'c')),'a request without a client reference');
$projection=$adapter->project(array('provider_code'=>'google_calendar','operation'=>'project','lesson_id'=>7,'schedule_version_id'=>91,'starts_at_utc'=>'2026-09-25 08:00:00','ends_at_utc'=>'2026-09-25 09:00:00','schedule_timezone'=>'Australia/Brisbane','local_wall_date'=>'2026-09-25','local_wall_time'=>'18:00:00','projection_reference'=>'ref-1','now_utc'=>'2026-09-25 07:00:00'));
$assert(($projection['provider_request']['body']['start']['dateTime']??'')==='2026-09-25T08:00:00Z','the provider write must carry the exact canonical start instant');
$assert(($projection['provider_request']['body']['end']['dateTime']??'')==='2026-09-25T09:00:00Z','the provider write must carry the exact canonical end instant');
$assert(($projection['provider_request']['body']['privateExtendedProperties']['dzn_schedule_version_id']??'')==='91','the provider write must name the exact canonical schedule version');
$assert(ProviderIntegrationRule::digest($projection['provider_facts_digest']),'the provider command facts must be digested, not stored raw');
$assert(($projection['acknowledged']??true)===false&&!isset($projection['provider_object_reference']),'a translation-only seam must never claim an acknowledged provider reference');
$refused(fn()=>$adapter->project(array('provider_code'=>'google_calendar','operation'=>'project','lesson_id'=>7,'schedule_version_id'=>91,'starts_at_utc'=>'','ends_at_utc'=>'')),'a projection without a canonical interval');
$assert($adapter->verify(array('provider_code'=>'google_meet','raw_body'=>'{}','headers'=>array()))===false,'a raw provider delivery must be refused');
$assert($adapter->verify(array('provider_code'=>'google_meet','raw_body'=>'{}','headers'=>array('x-goog-channel-token'=>'t')))===false,'a channel token is routing metadata and must never be accepted as proof');
$assert($adapter->verify(array('provider_code'=>'google_meet','raw_body'=>'{}'))===false,'a bare body must be refused');
$authenticatedDelivery=$authenticated(array('event_key'=>'e-1','participant_reference'=>'acct-1','participant_role'=>'teacher','observed_at'=>'2026-09-25 09:00:00','join_at_utc'=>'2026-09-25 08:00:00','leave_at_utc'=>'2026-09-25 08:40:00'));
$assert($adapter->verify($authenticatedDelivery),'an authenticated transport envelope must pass the seam');
$rebound=$authenticatedDelivery;$rebound['body_digest']=hash('sha256','{}');
$assert($adapter->verify($rebound)===false,'an envelope that does not bind the exact body must be refused');
$unproven=$authenticatedDelivery;$unproven['proof_reference']='short';
$assert($adapter->verify($unproven)===false,'an envelope without a transport proof must be refused');
$normalised=$adapter->normalise($authenticatedDelivery);
$assert($normalised['provider_code']==='google_meet'&&$normalised['participant_role']==='teacher','a normalised provider event must be provider-neutral');
$assert($normalised['provider_account_key']==='acct-1'&&$normalised['join_at_utc']==='2026-09-25 08:00:00','the normaliser must return the raw account reference for Phase-P digesting and the exact instants');
$assert($normalised['lesson_id']===null,'a provider event that names no occurrence must not inherit one');
$refused(fn()=>$adapter->normalise($authenticated(array('event_key'=>'e-1','participant_reference'=>'acct-1','participant_role'=>'teacher','observed_at'=>'later'))),'a provider event without a usable UTC instant');
$refused(fn()=>$adapter->normalise($authenticated(array('event_key'=>'e-1','participant_reference'=>'acct-1','participant_role'=>'teacher','observed_at'=>'2026-09-25 09:00:00','join_at_utc'=>'whenever'))),'a provider event with a non-UTC join instant');

// 8. Deterministic contract adapters: in-memory only, call-recording, credential-redacting.
$oauth=new ContractProviderAdapters(array('material-1'=>'subject-1'));
$exchanged=$oauth->exchange(array('client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','code'=>'code-1','code_verifier'=>'verifier-1','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events'));
$assert(ProviderIntegrationRule::digest(ProviderIntegrationIdempotency::subject($exchanged['provider_subject_reference'],'connection_identity')),'an exchanged subject reference must be digestible into a mapping value');
$assert($oauth->count('oauth')===1,'the contract adapter must record exactly the calls it received');
$assert(!str_contains((string)wp_json_encode($oauth->calls()),'material-1'),'the contract adapter must never record credential material');
$inspection=$oauth->inspect(array('credential_material'=>'material-1','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events'));
$assert($inspection['usable']===true&&$inspection['provider_subject_reference']==='subject-1','the contract adapter must validate only the credentials it was given');
$assert($oauth->inspect(array('credential_material'=>'material-unknown'))['usable']===false,'an unknown credential must never inspect as usable');
$deterministicA=$oauth->project(array('provider_code'=>'google_meet','operation'=>'project','lesson_id'=>7,'schedule_version_id'=>91,'now_utc'=>'2026-09-25 07:00:00'));
$deterministicB=$oauth->project(array('provider_code'=>'google_meet','operation'=>'project','lesson_id'=>7,'schedule_version_id'=>91,'now_utc'=>'2026-09-25 07:00:00'));
$assert($deterministicA['provider_object_reference']===$deterministicB['provider_object_reference'],'the contract adapter must be deterministic for identical canonical facts');
$assert($oauth->count('google_meet')===2,'the projection calls must be recorded per provider code');
$assert($oauth->verify(array('provider_code'=>'google_meet','raw_body'=>'{}'))===false,'the deterministic transport must refuse a raw delivery');
$assert($oauth->verify($authenticated(array('provider_event_key'=>'e-1')))===true,'the deterministic transport must accept an authenticated envelope');
// The deterministic transport normalises the body its named transport authenticated — bound to the
// envelope by digest — and never a caller-supplied outer fact array, which the seam does not even hand
// over. An outer field may therefore never state the facts a delivery is ingested under.
$contractDelivery=$authenticated(array('provider_event_key'=>'e-1','provider_account_key'=>'acct-1','participant_role'=>'teacher','observed_at'=>'2026-09-25 09:00:00','join_at_utc'=>'2026-09-25 08:00:00','leave_at_utc'=>'2026-09-25 08:40:00'));
$contractFacts=$oauth->normalise($contractDelivery);
$assert($contractFacts['provider_event_key']==='e-1'&&$contractFacts['provider_account_key']==='acct-1'&&$contractFacts['participant_role']==='teacher','the deterministic transport must normalise the authenticated body');
$assert($contractFacts['join_at_utc']==='2026-09-25 08:00:00'&&$contractFacts['leave_at_utc']==='2026-09-25 08:40:00'&&$contractFacts['lesson_id']===null,'the deterministic transport must return the exact instants and inherit no occurrence');
$assert($oauth->normalise(ProviderIntegrationRule::deliveryEnvelope($contractDelivery))['provider_event_key']==='e-1','the sanitised envelope the ingest seam hands over must normalise from its digest-bound body');
$lying=$contractDelivery;$lying['facts']=array('provider_event_key'=>'forged','participant_role'=>'student','provider_account_key'=>'forged-account');
$assert($oauth->normalise($lying)['provider_event_key']==='e-1'&&$oauth->normalise($lying)['participant_role']==='teacher','a caller-supplied outer fact set must never override the authenticated body');
$refused(fn()=>$oauth->normalise(array('provider_code'=>'google_meet','raw_body'=>'{}','body_digest'=>str_repeat('0',64))),'a deterministic normalisation whose body does not match its declared digest');
$refused(fn()=>$oauth->normalise(array('provider_code'=>'google_meet','raw_body'=>'{}','body_digest'=>hash('sha256','{}'))),'a deterministic normalisation of a body with no provider event');
$refused(fn()=>$oauth->normalise(array('provider_code'=>'google_meet','raw_body'=>'not-json','body_digest'=>hash('sha256','not-json'))),'a deterministic normalisation of a non-object body');
$translationOnly=new ContractProviderAdapters(array(),ContractProviderAdapters::PROJECTION_PENDING);
$translation=$translationOnly->project(array('provider_code'=>'google_calendar','operation'=>'project','lesson_id'=>7,'schedule_version_id'=>91,'now_utc'=>'2026-09-25 07:00:00'));
$assert(isset($translation['provider_request'])&&!isset($translation['provider_object_reference'])&&ProviderIntegrationRule::digest($translation['provider_facts_digest']),'a translation-only transport must return a request and no provider reference');

// 9. Fail-closed shape validation over stored rows.
$connection=(object)array('id'=>1,'provider_code'=>'google_calendar','teacher_id'=>5,'connection_state'=>'connected','connection_version'=>2,'lifecycle_sequence'=>1,'identity_state'=>'verified','active_slot'=>1,'identity_digest'=>str_repeat('b',64),'scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events');
$assert(ProviderIntegrationValidator::connectionShape($connection),'a fully connected, mapped connection must validate');
foreach(array('active_slot','identity_digest','scope_snapshot','connection_version','identity_state','connection_state') as $field){
    $broken=clone $connection;$broken->{$field}=$field==='active_slot'?null:($field==='connection_version'?0:null);
    $assert(!ProviderIntegrationValidator::connectionShape($broken),'a connection with a missing '.$field.' must fail closed');
}
$idle=clone $connection;$idle->connection_state='disconnected';$idle->active_slot=null;
$assert(ProviderIntegrationValidator::connectionShape($idle),'a disconnected connection with no active slot must validate');
$assert(!ProviderIntegrationValidator::credentialShape((object)array('id'=>1,'connection_id'=>1,'state'=>'active','nonce'=>$sealed['nonce'],'ciphertext'=>$sealed['ciphertext'])),'a credential missing its version identity must fail closed');
$assert(!ProviderIntegrationValidator::credentialShape((object)array('id'=>1,'connection_id'=>1,'state'=>'active','key_version'=>'k','cipher_version'=>'c','nonce'=>'','ciphertext'=>$sealed['ciphertext'])),'a credential with an empty sealed column must fail closed');
$assert(ProviderIntegrationValidator::credentialShape((object)array('id'=>1,'connection_id'=>1,'state'=>'active','key_version'=>'k','cipher_version'=>'c','nonce'=>$sealed['nonce'],'ciphertext'=>$sealed['ciphertext'])),'a sealed credential row must validate');
$assert(!ProviderIntegrationValidator::sealedShape('not-base64!!','also-not-base64!!'),'a non-base64 credential column must fail closed');
$assert(ProviderIntegrationValidator::mappingShape((object)array('id'=>1,'provider_code'=>'google_calendar','teacher_id'=>5,'subject_digest'=>str_repeat('c',64),'mapping_state'=>'verified','mapping_version'=>1,'active_slot'=>1),'connection_identity'),'a verified connection-identity mapping must validate');
$assert(!ProviderIntegrationValidator::mappingShape((object)array('id'=>1,'provider_code'=>'google_calendar','teacher_id'=>5,'subject_digest'=>str_repeat('c',64),'mapping_state'=>'verified','mapping_version'=>1,'active_slot'=>null),'connection_identity'),'a verified mapping without an active slot must fail closed');
$assert(ProviderIntegrationValidator::mappingShape((object)array('id'=>2,'provider_code'=>'google_calendar','lesson_id'=>7,'schedule_version_id'=>91,'event_digest'=>str_repeat('d',64),'projection_state'=>'verified','mapping_version'=>1,'active_slot'=>1),'calendar_event'),'a verified calendar projection must validate');
$assert(ProviderIntegrationValidator::mappingShape((object)array('id'=>3,'provider_code'=>'google_meet','lesson_id'=>7,'schedule_version_id'=>91,'conference_digest'=>str_repeat('e',64),'join_uri_digest'=>str_repeat('f',64),'projection_state'=>'verified','mapping_version'=>1,'active_slot'=>1),'meeting_conference'),'a verified conference projection must validate');
$event=(object)array('id'=>1,'provider_code'=>'google_meet','provider_event_key_digest'=>str_repeat('1',64),'event_fact_digest'=>str_repeat('2',64),'provider_account_digest'=>str_repeat('3',64),'event_sequence'=>1,'processing_state'=>'received','occurred_at'=>'2026-09-25 08:00:00','received_at'=>'2026-09-25 08:00:05','transport'=>'deployment_gateway','proof_reference_digest'=>str_repeat('7',64));
$assert(ProviderIntegrationValidator::ingestEventShape($event),'a well-formed provider event receipt must validate');
$future=clone $event;$future->occurred_at='2026-09-25 09:00:00';
$assert(!ProviderIntegrationValidator::ingestEventShape($future),'a provider instant after the local receipt must fail closed');
$unproven=clone $event;$unproven->proof_reference_digest=null;
$assert(!ProviderIntegrationValidator::ingestEventShape($unproven),'a receipt without a transport proof must fail closed');
$unproven=clone $event;$unproven->transport='';
$assert(!ProviderIntegrationValidator::ingestEventShape($unproven),'a receipt without an authenticating transport must fail closed');
$unproven=clone $event;$unproven->processing_state='settled';
$assert(!ProviderIntegrationValidator::ingestEventShape($unproven),'an uncontrolled receipt state must fail closed');
$pendingProjection=(object)array('id'=>4,'provider_code'=>'google_calendar','lesson_id'=>7,'schedule_version_id'=>91,'event_digest'=>str_repeat('a',64),'projection_state'=>'pending','mapping_version'=>1,'active_slot'=>null);
$assert(ProviderIntegrationValidator::mappingShape($pendingProjection,'calendar_event'),'a pending translation must validate as a projection');
$pendingProjection->active_slot=1;
$assert(!ProviderIntegrationValidator::mappingShape($pendingProjection,'calendar_event'),'a pending translation must never hold an active slot');
$outcome=(object)array('id'=>1,'provider_ingest_event_id'=>1,'provider_code'=>'google_meet','provider_event_key_digest'=>str_repeat('1',64),'handoff_attempt'=>1,'outcome'=>'admitted','reason_code'=>null,'intake_result_digest'=>str_repeat('9',64),'recorded_at'=>'2026-09-25 08:00:05');
$assert(ProviderIntegrationValidator::ingestOutcomeShape($outcome),'an admitted handoff outcome must validate');
$refusal=clone $outcome;$refusal->outcome='refused';$refusal->reason_code='evidence_refused';$refusal->intake_result_digest=null;
$assert(ProviderIntegrationValidator::ingestOutcomeShape($refusal),'a refused handoff outcome must carry its reason');
$refusal=clone $outcome;$refusal->outcome='refused';$refusal->reason_code='';$refusal->intake_result_digest=null;
$assert(!ProviderIntegrationValidator::ingestOutcomeShape($refusal),'a refusal without a reason must fail closed');
$refusal=clone $outcome;$refusal->outcome='maybe';
$assert(!ProviderIntegrationValidator::ingestOutcomeShape($refusal),'an uncontrolled handoff outcome must fail closed');
$refusal=clone $outcome;$refusal->handoff_attempt=0;
$assert(!ProviderIntegrationValidator::ingestOutcomeShape($refusal),'a handoff outcome without an attempt number must fail closed');
$assert(ProviderIntegrationValidator::conflictShape((object)array('id'=>1,'provider_code'=>'google_meet','provider_event_key_digest'=>str_repeat('1',64),'conflicting_fact_digest'=>str_repeat('2',64),'conflict_kind'=>'cross_interval')),'a recorded conflict receipt must validate');
$assert(!ProviderIntegrationValidator::conflictShape((object)array('id'=>1,'provider_code'=>'google_meet','provider_event_key_digest'=>str_repeat('1',64),'conflicting_fact_digest'=>str_repeat('2',64),'conflict_kind'=>'whatever')),'an uncontrolled conflict kind must fail closed');
$command=(object)array('command_domain'=>'provider_integration_v1','operation'=>'project_meeting_conference','command_key_digest'=>str_repeat('4',64),'command_payload_digest'=>str_repeat('5',64));
$assert(ProviderIntegrationValidator::commandShape($command,'project_meeting_conference',str_repeat('5',64)),'a matching command replay must validate');
$assert(!ProviderIntegrationValidator::commandShape($command,'project_meeting_conference',str_repeat('6',64)),'a divergent command payload digest must fail closed');
$assert(!ProviderIntegrationValidator::commandShape($command,'project_calendar_event',str_repeat('5',64)),'a replay under a different operation must fail closed');

// 10. The whole phase layer is free of any live-traffic or credential-logging primitive.
$phaseFiles=array('Core/Application/ProviderIntegrationRule.php','Core/Application/ProviderIntegrationIdempotency.php','Core/Application/IntegrationSecretService.php','Core/Application/ProviderIntegrationValidator.php','Core/Application/ProviderIntegrationService.php','Core/Application/ProviderEventIngestService.php','Core/Application/ProviderIntegrationReadService.php','Core/Infrastructure/Repository/ProviderIntegrationRepository.php','Integrations/GoogleCalendarMeetAdapter.php','Integrations/ContractProviderAdapters.php','Core/Application/Port/ProviderOAuthPort.php','Core/Application/Port/ProviderCalendarPort.php','Core/Application/Port/ProviderMeetingPort.php','Core/Application/Port/ProviderEventNormalizer.php');
foreach($phaseFiles as $relative){
    $source=(string)file_get_contents($root.'/src/'.$relative);
    foreach(array('wp_remote_','wp_safe_remote_','curl_','fsockopen','stream_socket_client','error_log','print_r(','var_dump(') as $forbidden)
        $assert(!str_contains($source,$forbidden),'the phase layer must not contain '.$forbidden.' in '.$relative);
}

echo 'Phase 2A.2-V isolated runtime passed ('.$assertions." assertions)\n";
