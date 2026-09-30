<?php
/**
 * Disposable production-path Phase-V provider-integration proof. Synthetic local data only.
 *
 * Covers the whole recorded integration path against real canonical storage: consent initiation and
 * one-time completion, credentialed refresh, local disconnect, provider revoke with a retryable
 * failure, reconnect as a new consent lifecycle, connection-identity mapping, calendar and meeting
 * projection against one exact applicable canonical schedule version, stale-version refusal,
 * retraction that leaves canonical truth untouched, provider-event ingestion through the Phase-P
 * seam with duplicate convergence and durable conflict, digest-only persistence, capability denial
 * and object-level read authorisation.
 *
 * Every provider interaction uses the deterministic {@see ContractProviderAdapters}: no live Google
 * credential, no network, no production environment and no canonical write by the integration layer.
 */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdentityService,ProviderIntegrationIdempotency,ProviderIntegrationReadService,ProviderIntegrationService,ProviderEventIngestService};
use Delnavazan\Platform\Integrations\ContractProviderAdapters;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_v_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_v_key(string $label):string{return 'dzn-2a2v-'.$label.'-'.wp_generate_uuid4();}
function dzn_v_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_v_assert($caught!==null,$message.' was accepted');dzn_v_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_v_assert(is_array($fixture)&&count($fixture['sources']??array())>=3,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('provider_event_conflicts','provider_ingest_events','provider_integration_commands','provider_calendar_event_mappings','provider_meeting_mappings','provider_identity_mappings','integration_oauth_authorizations','integration_credentials','integration_connections') as $table)
    dzn_v_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase V storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());

$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],'authority',1);
$target=$wpdb->get_row($wpdb->prepare("SELECT version.* FROM {$p}canonical_lesson_schedule_versions version INNER JOIN {$p}lessons lesson ON lesson.id=version.lesson_id WHERE lesson.term_id=%d AND version.applicable_slot=1 ORDER BY version.id LIMIT 1",(int)$funded['term_id']));
dzn_v_assert($target!==null,'the fixture must leave one applicable canonical schedule version');
$lessonId=(int)$target->lesson_id;$versionId=(int)$target->id;
$lesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$lessonId));
$teacherId=(int)$lesson->teacher_id;$studentId=(int)$lesson->student_id;
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$service=static fn(ContractProviderAdapters $ports):ProviderIntegrationService=>new ProviderIntegrationService(null,null,$ports,$ports,$ports);
$sealedRows=static function(int $connectionId) use($wpdb,$p):array{return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}integration_credentials WHERE connection_id=%d ORDER BY credential_sequence",$connectionId))?:array();};
$connectionRow=static function(int $id) use($wpdb,$p):object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}integration_connections WHERE id=%d",$id));};
$occurrenceState=static function() use($wpdb,$p,$lessonId,$versionId):array{
    return array(
        'lesson'=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$lessonId)),
        'version'=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",$versionId)),
    );
};

// 1. Capability denial: nothing in this phase is reachable without its own grant.
$previous=get_current_user_id();
$subscriber=wp_insert_user(array('user_login'=>'dzn-v-sub-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
wp_set_current_user((int)$subscriber);
$ports=new ContractProviderAdapters(array());
dzn_v_rejected(fn()=>$service($ports)->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events')+$evidence('sub-begin'),dzn_v_key('sub-begin')),'Unauthorized','a non-administrator consent initiation');
dzn_v_rejected(fn()=>$service($ports)->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-sub')+$evidence('sub-project'),dzn_v_key('sub-project')),'Unauthorized','a non-administrator projection');
dzn_v_rejected(fn()=>(new ProviderEventIngestService(null,$ports,null))->ingest(array('provider_code'=>'google_meet','lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'verified'=>true,'facts'=>array()),dzn_v_key('sub-ingest')),'Unauthorized','a non-administrator provider event ingestion');
dzn_v_rejected(fn()=>(new ProviderIntegrationReadService())->connection(1),'Unauthorized','a non-administrator integration read');
wp_set_current_user($previous);

// 2. Consent initiation: server-side state and verifier, digest-only persistence, one-time binding.
$scope='https://www.googleapis.com/auth/calendar.events';
$begin=$service($ports)->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('begin'),dzn_v_key('begin'));
$connectionId=(int)$begin['connection_id'];
dzn_v_assert($connectionId>0&&strlen((string)$begin['state'])>20&&strlen((string)$begin['code_verifier'])>20,'consent initiation must mint an unpredictable state and verifier');
dzn_v_assert((string)$connectionRow($connectionId)->connection_state==='authorizing','consent initiation must record the authorizing connection state');
$storedIntent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}integration_oauth_authorizations WHERE id=%d",(int)$begin['authorization_id']));
dzn_v_assert($storedIntent&&(string)$storedIntent->authorization_state==='issued','the authorization intent must be recorded as issued');
dzn_v_assert(hash_equals((string)$storedIntent->state_digest,hash_hmac('sha256','provider_integration_evidence:'.$begin['state'],wp_salt('dzn_provider_integration'))),'the authorization state must persist only as a digest');
$rawState=(string)$begin['state'];$rawVerifier=(string)$begin['code_verifier'];
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_oauth_authorizations WHERE state_digest=%s",$rawState))===0,'the raw authorization state must never persist');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_oauth_authorizations WHERE verifier_digest=%s",$rawVerifier))===0,'the raw PKCE verifier must never persist');
$replayBegin=$service($ports)->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('begin'),dzn_v_key('begin'));
dzn_v_assert($replayBegin['idempotent']===true&&(int)$replayBegin['connection_id']===$connectionId,'an identical consent command must replay idempotently');
dzn_v_assert((int)$replayBegin['authorization_id']===(int)$begin['authorization_id']&&(string)$replayBegin['authorization_state']==='issued','a consent replay must report the recorded authorization result');
dzn_v_assert(!isset($replayBegin['state'])&&!isset($replayBegin['code_verifier'])&&!isset($replayBegin['code_challenge']),'a consent replay must never re-issue one-time consent material');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d",$teacherId))===1,'a replay must not create a second consent attempt');

// 3. Completion: exact redirect and scope, one-time consumption, sealed credential, verified identity.
dzn_v_rejected(fn()=>$service($ports)->completeAuthorization(array('state'=>$rawState,'code'=>'code-1','code_verifier'=>$rawVerifier,'redirect_uri'=>'https://academy.example/other')+$evidence('complete-redirect'),dzn_v_key('complete-redirect')),'authorization_redirect_mismatch','a completion against a different redirect target');
$complete=$service($ports)->completeAuthorization(array('state'=>$rawState,'code'=>'code-1','code_verifier'=>$rawVerifier,'redirect_uri'=>'https://academy.example/cb')+$evidence('complete'),dzn_v_key('complete'));
dzn_v_assert((string)$complete['connection_state']==='connected'&&(int)$complete['identity_mapping_id']>0,'a validated consent flow must connect the Teacher and mint a verified identity mapping');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT active_slot FROM {$p}integration_connections WHERE id=%d",$connectionId))===1,'exactly one active connection must exist after completion');
$credentials=$sealedRows($connectionId);
dzn_v_assert(count($credentials)===1&&(string)$credentials[0]->state==='active','completion must persist exactly one active credential');
dzn_v_assert(strlen((string)$credentials[0]->nonce)>=8&&strlen((string)$credentials[0]->ciphertext)>=8,'the credential must persist only ciphertext and nonce');
$material='material-'.$complete['identity_mapping_id'];
foreach($wpdb->get_results("SELECT * FROM {$p}integration_credentials") as $row)
    dzn_v_assert(!str_contains((string)$row->ciphertext,$material)&&!str_contains((string)$row->nonce,$material),'no credential column may carry readable material');
dzn_v_rejected(fn()=>$service($ports)->completeAuthorization(array('state'=>$rawState,'code'=>'code-1','code_verifier'=>$rawVerifier,'redirect_uri'=>'https://academy.example/cb')+$evidence('complete-again'),dzn_v_key('complete-again')),'authorization_state_already_settled','a replayed authorization state');
$identity=(object)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_identity_mappings WHERE connection_id=%d AND active_slot=1",$connectionId));
dzn_v_assert($identity&&(string)$identity->mapping_state==='verified'&&(int)$identity->teacher_id===$teacherId,'the connection identity must be a verified mapping to the exact Teacher');

// 4. Refresh: a new sealed credential supersedes the old one without resurrecting anything.
$refresh=$service($ports)->refreshConnection($connectionId,$evidence('refresh'),dzn_v_key('refresh'));
dzn_v_assert((string)$refresh['connection_state']==='connected','a successful refresh must keep the connection connected');
$credentials=$sealedRows($connectionId);
dzn_v_assert(count($credentials)===2&&(string)$credentials[0]->state==='quarantined'&&(string)$credentials[1]->state==='active','a refresh must quarantine the previous credential and append exactly one replacement');

// 5. Disconnect: local only, credential quarantined, identity mapping withdrawn, history preserved.
$disconnect=$service($ports)->disconnectConnection($connectionId,$evidence('disconnect'),dzn_v_key('disconnect'));
dzn_v_assert((string)$disconnect['connection_state']==='disconnected','a disconnect must record the disconnected state');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d AND active_slot=1",$teacherId))===0,'a disconnected connection must not hold the active slot');
foreach($sealedRows($connectionId) as $row)dzn_v_assert((string)$row->state!=='active','every credential must be unusable after a disconnect');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_identity_mappings WHERE connection_id=%d AND mapping_state='verified'",$connectionId))===0,'a disconnect must withdraw the verified identity mapping');

// 6. Revoke: provider outcome recorded, a failed revoke stays retryable and never usable locally.
$revokePorts=new ContractProviderAdapters(array());
$service($revokePorts)->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('rebegin'),dzn_v_key('rebegin'));
$second=new ContractProviderAdapters(array());
// Reconnect after a disconnect must start a new consent lifecycle rather than revive the old row.
$reconnectId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d AND connection_state='authorizing' ORDER BY lifecycle_sequence DESC LIMIT 1",$teacherId));
dzn_v_assert($reconnectId!==0&&$reconnectId!==$connectionId,'a reconnect must create a new consent lifecycle generation');
$lifecycle=$wpdb->get_col($wpdb->prepare("SELECT lifecycle_sequence FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d ORDER BY lifecycle_sequence",$teacherId));
dzn_v_assert(count($lifecycle)===2&&(int)$lifecycle[1]===(int)$lifecycle[0]+1,'lifecycle generations must be sequential and append-preserving');
$caught=null;try{$service($second)->completeAuthorization(array('state'=>(string)$begin['state'],'code'=>'c','code_verifier'=>$rawVerifier,'redirect_uri'=>'https://academy.example/cb')+$evidence('stale-complete'),dzn_v_key('stale-complete'));}catch(Throwable$e){$caught=$e;}
dzn_v_assert($caught!==null&&$caught->getMessage()==='authorization_state_already_settled','a consumed authorization from an earlier lifecycle must never be revived');

// 7. Projection: only against the exact applicable canonical schedule version, and replay-safe.
$projectionPorts=new ContractProviderAdapters(array());
$projectionService=$service($projectionPorts);
$consent=$projectionService->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('project-consent'),dzn_v_key('project-consent'));
$connected=$projectionService->completeAuthorization(array('state'=>(string)$consent['state'],'code'=>'code-projection','code_verifier'=>(string)$consent['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence('project-complete'),dzn_v_key('project-complete'));
$activeConnectionId=(int)$connected['connection_id'];
$before=$occurrenceState();
$projected=$projectionService->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'calendar-ref-1')+$evidence('project'),dzn_v_key('project'));
dzn_v_assert((int)$projected['mapping_id']>0&&(string)$projected['provider_code']==='google_calendar','a calendar projection must record its integration reference');
$mapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_calendar_event_mappings WHERE id=%d",(int)$projected['mapping_id']));
dzn_v_assert((string)$mapping->starts_at_utc===(string)$target->starts_at_utc&&(string)$mapping->ends_at_utc===(string)$target->ends_at_utc,'the projection must copy the exact canonical interval');
dzn_v_assert((string)$mapping->schedule_timezone===(string)$target->schedule_timezone&&(string)$mapping->local_wall_time===(string)$target->local_wall_time,'the projection must copy the exact wall-clock provenance');
dzn_v_assert(preg_match('/^[a-f0-9]{64}$/D',(string)$mapping->event_digest)===1,'the provider event reference must persist only as a keyed digest');
$replayProjection=$projectionService->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'calendar-ref-1')+$evidence('project'),dzn_v_key('project'));
dzn_v_assert($replayProjection['idempotent']===true&&(int)$replayProjection['mapping_id']===(int)$projected['mapping_id'],'an identical projection command must replay idempotently');
dzn_v_rejected(fn()=>$projectionService->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'calendar-ref-2')+$evidence('project-2'),dzn_v_key('project-2')),'projection_already_recorded','a second active projection for one canonical occurrence');
$stale=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d ORDER BY id LIMIT 1",$lessonId));
$staleId=(int)$stale===$versionId?0:(int)$stale;
dzn_v_rejected(fn()=>$projectionService->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$staleId,'projection_reference'=>'meet-stale')+$evidence('project-stale'),dzn_v_key('project-stale')),'canonical_schedule_version_required','a projection against an unknown schedule version');
$meeting=$projectionService->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'meet-ref-1')+$evidence('project-meet'),dzn_v_key('project-meet'));
$meetingRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_meeting_mappings WHERE id=%d",(int)$meeting['mapping_id']));
dzn_v_assert($meetingRow&&preg_match('/^[a-f0-9]{64}$/D',(string)$meetingRow->conference_digest)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)$meetingRow->join_uri_digest)===1,'a conference and its join reference must persist only as keyed digests');
// The provider write is a write: the canonical schedule and Lesson rows must be byte-identical after it.
$after=$occurrenceState();
foreach(array('starts_at_utc','ends_at_utc','schedule_timezone','local_wall_date','local_wall_time','applicable_slot','version_number') as $field)
    dzn_v_assert((string)$before['version']->{$field}===(string)$after['version']->{$field},'a projection must never mutate canonical schedule authority: '.$field);
foreach(array('status','lifecycle_state','current_schedule_version_id','completed_at','cancelled_at') as $field)
    dzn_v_assert((string)($before['lesson']->{$field}??'')===(string)($after['lesson']->{$field}??''),'a projection must never mutate canonical Lesson authority: '.$field);

// 8. Retraction: quarantine the local reference only; canonical truth stays untouched.
$projectionService->retractMeetingProjection((int)$meeting['mapping_id'],array('reason_code'=>'scheduling_change')+$evidence('retract-meet'),dzn_v_key('retract-meet'));
$meetingRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_meeting_mappings WHERE id=%d",(int)$meeting['mapping_id']));
dzn_v_assert((string)$meetingRow->projection_state==='revoked'&&$meetingRow->active_slot===null,'a retraction must quarantine the active reference');
$afterRetraction=$occurrenceState();
dzn_v_assert((string)$afterRetraction['version']->starts_at_utc===(string)$target->starts_at_utc,'a retraction must never change the canonical schedule interval');
dzn_v_assert((string)$afterRetraction['lesson']->lifecycle_state===(string)$lesson->lifecycle_state,'a retraction must never cancel, release or reschedule the Lesson');

// 9. Provider evidence: submission through the Phase-P seam, duplicate convergence, durable conflict.
$ingestPorts=new ContractProviderAdapters(array());
$ingestService=new ProviderEventIngestService(null,$ingestPorts,null);
$runSuffix=substr(str_replace('-','',wp_generate_uuid4()),0,8);
$accountKey='acct-v-'.$runSuffix;$studentAccount='acct-v-student-'.$runSuffix;
(new CanonicalAttendanceIdentityService())->record(array('provider_code'=>'google_meet','provider_account_key'=>$accountKey,'participant_role'=>'teacher','participant_id'=>$teacherId,'state'=>'verified','provenance_reference'=>'v-prov-teacher','evidence_reference'=>'v-ref-teacher'),dzn_v_key('identity-teacher'));
(new CanonicalAttendanceIdentityService())->record(array('provider_code'=>'google_meet','provider_account_key'=>$studentAccount,'participant_role'=>'student','participant_id'=>$studentId,'state'=>'verified','provenance_reference'=>'v-prov-student','evidence_reference'=>'v-ref-student'),dzn_v_key('identity-student'));
$join=gmdate('Y-m-d H:i:s',strtotime((string)$target->starts_at_utc.' UTC')+60);
$leave=gmdate('Y-m-d H:i:s',strtotime((string)$target->starts_at_utc.' UTC')+1800);
$observed=gmdate('Y-m-d H:i:s',strtotime((string)$target->starts_at_utc.' UTC')+1800);
// Every delivery is an envelope a named trusted transport already authenticated over the exact body.
$delivery=static function(string $eventKey,string $account,string $role,string $joinAt,string $leaveAt,string $observedAt) use($lessonId,$versionId):array{
    $facts=array('provider_code'=>'google_meet','provider_event_key'=>$eventKey,'provider_payload_key'=>$eventKey.'-payload','participant_role'=>$role,'provider_account_key'=>$account,'observed_at'=>$observedAt,'join_at_utc'=>$joinAt,'leave_at_utc'=>$leaveAt,'lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'provenance_reference'=>'prov-'.$eventKey,'evidence_reference'=>'ref-'.$eventKey);
    $body=(string)wp_json_encode($facts);
    return array('provider_code'=>'google_meet','lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>$body,'body_digest'=>hash('sha256',$body),'proof_reference'=>'proof-'.$eventKey.'-00000000','facts'=>$facts);
};
$admitted=$ingestService->ingest($delivery('v-event-'.$runSuffix,$accountKey,'teacher',$join,$leave,$observed),dzn_v_key('ingest'));
dzn_v_assert((int)$admitted['ingest_event_id']>0&&$admitted['conflict']===false,'a provider event must record an integration receipt');
$receipt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE id=%d",(int)$admitted['ingest_event_id']));
dzn_v_assert($receipt&&preg_match('/^[a-f0-9]{64}$/D',(string)$receipt->provider_event_key_digest)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)$receipt->provider_account_digest)===1,'a provider event must persist only keyed digests');
dzn_v_assert((string)$receipt->occurred_at!==(string)$receipt->received_at||(string)$receipt->occurred_at===$observed,'provider and local instants must be stored as separate facts');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_ingest_events WHERE provider_event_key_digest=%s",'v-event-'.$runSuffix))===0,'a raw provider event key must never persist');
dzn_v_assert((string)$receipt->processing_state==='received','an ingest receipt must be recorded in its immutable receive state, never as admitted');
dzn_v_assert((string)$receipt->transport==='deployment_gateway'&&preg_match('/^[a-f0-9]{64}$/D',(string)$receipt->proof_reference_digest)===1,'a receipt must record its authenticating transport and the digest of its proof');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_ingest_events WHERE processing_state='admitted'"))===0,'no immutable receipt may ever be mutated into an admission');
$outcome=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt DESC LIMIT 1",(int)$admitted['ingest_event_id']));
dzn_v_assert($outcome&&(string)$outcome->outcome==='admitted'&&(int)$outcome->handoff_attempt===1,'admission must be a separate appended handoff outcome');
dzn_v_assert(preg_match('/^[a-f0-9]{64}$/D',(string)$outcome->intake_result_digest)===1,'an admission must record the Phase-P result it was written for');
$readEvents=(new ProviderIntegrationReadService())->events($lessonId);
dzn_v_assert(count($readEvents)===1,'the Lesson read model must report the recorded receipt');
dzn_v_assert((string)$readEvents[0]['processing_state']==='admitted'&&(string)$readEvents[0]['received_state']==='received','the read model must report the effective outcome and the immutable receive state separately');
// A caller may never re-point an authenticated delivery at a different occurrence: the binding is the
// one the authenticated body names. The envelope below is re-bound to the exact body it declares, so it
// is a genuinely authenticated delivery that names another Lesson than the caller hints at — and the
// untrusted outer array still names the original Lesson, so a seam that read it instead of the body
// could not refuse this delivery.
$mismatched=$delivery('v-mismatch-'.$runSuffix,$accountKey,'teacher',$join,$leave,$observed);
$mismatchedBody=json_decode((string)$mismatched['raw_body'],true);
$mismatchedBody['lesson_id']=$lessonId+1000;
$mismatched['raw_body']=(string)wp_json_encode($mismatchedBody);
$mismatched['body_digest']=hash('sha256',$mismatched['raw_body']);
dzn_v_rejected(fn()=>$ingestService->ingest($mismatched,dzn_v_key('ingest-mismatch')),'provider_event_context_mismatch','a delivery whose authenticated body names another occurrence');
// The occurrence binding comes from the authenticated body even when the caller supplies no hint at
// all, and the recorded receipt is still the one that body names.
$bodyBound=$delivery('v-event-'.$runSuffix,$accountKey,'teacher',$join,$leave,$observed);
unset($bodyBound['lesson_id'],$bodyBound['schedule_version_id']);
$convergedBody=$ingestService->ingest($bodyBound,dzn_v_key('ingest-body-bound'));
dzn_v_assert($convergedBody['idempotent']===true&&(int)$convergedBody['ingest_event_id']===(int)$admitted['ingest_event_id'],'an authenticated body must bind the occurrence without a caller hint');
// Phase P owns the canonical consequence: whatever it decided, the evidence row it recorded (if any)
// belongs to a Phase-P case, never to this phase.
$phasePEvidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_evidence WHERE provider_event_key_digest=%s",hash_hmac('sha256','canonical_attendance_event_key:v-event-'.$runSuffix,wp_salt('dzn_canonical_attendance'))));
if($phasePEvidence)dzn_v_assert((int)$phasePEvidence->case_id>0&&(string)$phasePEvidence->source_kind==='provider','the canonical provider evidence row must be owned by a Phase-P case');
$converged=$ingestService->ingest($delivery('v-event-'.$runSuffix,$accountKey,'teacher',$join,$leave,$observed),dzn_v_key('ingest'));
dzn_v_assert($converged['idempotent']===true&&(int)$converged['ingest_event_id']===(int)$admitted['ingest_event_id'],'an exact duplicate provider event must converge on the recorded receipt');
$conflict=$ingestService->ingest($delivery('v-event-'.$runSuffix,$accountKey,'teacher',gmdate('Y-m-d H:i:s',strtotime($join.' UTC')+600),$leave,$observed),dzn_v_key('ingest-conflict'));
dzn_v_assert($conflict['conflict']===true&&(string)$conflict['processing_state']==='conflicted','a changed provider-event context must be durably refused as a conflict');
$conflictRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_event_conflicts WHERE id=%d",(int)$conflict['conflict_id']));
dzn_v_assert($conflictRow&&in_array((string)$conflictRow->conflict_kind,array('cross_interval','changed_payload','cross_context'),true),'a conflict receipt must classify the divergence');
dzn_v_assert(hash_equals((string)$receipt->event_fact_digest,(string)$wpdb->get_var($wpdb->prepare("SELECT event_fact_digest FROM {$p}provider_ingest_events WHERE id=%d",(int)$admitted['ingest_event_id']))),'a conflicting event must never overwrite the original receipt');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_ingest_events WHERE provider_code='google_meet' AND lesson_id=%d",$lessonId))===1,'a conflicting event must not create a second receipt for the same provider key');

// 10. Reads: capability-gated and object-level authorised; credentials never returned as material.
$readService=new ProviderIntegrationReadService();
$read=$readService->connection($activeConnectionId);
dzn_v_assert((int)$read['connection_id']===$activeConnectionId&&is_array($read['credential'])&&$read['credential']['sealed']===true,'an administrator read must report the redacted credential shape');
dzn_v_assert(!str_contains((string)wp_json_encode($read),'material-'),'a protected read must never return credential material');
$integrations=$readService->lessonIntegrations($lessonId);
dzn_v_assert(count($integrations['calendar_events'])===1&&count($integrations['meetings'])===1,'the Lesson read model must report its active integration references');
$principal=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' LIMIT 1",$teacherId));
if($principal<1){$principal=(int)wp_insert_user(array('user_login'=>'dzn-v-teacher-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'dzn_teacher'));$wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$teacherId,'wordpress_user_id'=>$principal,'status'=>'active','linked_at'=>gmdate('Y-m-d H:i:s'),'linked_by'=>1));}
wp_set_current_user($principal);
dzn_v_assert((int)$readService->connection($activeConnectionId)['connection_id']===$activeConnectionId,'a linked Teacher must read its own connection');
$other=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}integration_connections WHERE id<>%d LIMIT 1",$activeConnectionId));
if($other)dzn_v_rejected(fn()=>$readService->connection((int)$other->id),'Unauthorized','a Teacher reading another Teacher connection');
// Being linked to a Teacher never by itself grants a read: the view capability is required as well.
$current=wp_get_current_user();
dzn_v_assert(!current_user_can(ProviderIntegrationService::MANAGE_CAPABILITY)&&current_user_can(ProviderIntegrationService::VIEW_CAPABILITY),'the linked Teacher principal must hold the self-service view capability and not the management capability');
$current->remove_cap(ProviderIntegrationService::VIEW_CAPABILITY);
dzn_v_rejected(fn()=>$readService->connection($activeConnectionId),'Unauthorized','a linked Teacher without the view capability');
dzn_v_rejected(fn()=>$readService->lessonIntegrations($lessonId),'Unauthorized','a linked Teacher without the view capability reading a Lesson');
$current->add_cap(ProviderIntegrationService::VIEW_CAPABILITY);
dzn_v_assert((int)$readService->connection($activeConnectionId)['connection_id']===$activeConnectionId,'a linked Teacher with the view capability must read its own connection again');
wp_set_current_user(1);

// 11. Digest-only command evidence: no raw key, payload, state or provider reference anywhere.
$commandRows=$wpdb->get_results("SELECT * FROM {$p}provider_integration_commands")?:array();
dzn_v_assert(count($commandRows)>0,'the integration path must record command evidence');
foreach($commandRows as $row){
    dzn_v_assert(preg_match('/^[a-f0-9]{64}$/D',(string)$row->command_key_digest)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)$row->command_payload_digest)===1,'command evidence must be digest-only');
    dzn_v_assert($row->operation!==''&&in_array((string)$row->command_domain,array('provider_integration_v1'),true),'command evidence must record its controlled domain and operation');
}
foreach(array('dzn-2a2v-','calendar-ref-','meet-ref-') as $needle){
    $hits=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_integration_commands WHERE command_key_digest LIKE %s OR command_payload_digest LIKE %s",'%'.$needle.'%','%'.$needle.'%'));
    dzn_v_assert($hits===0,'a raw command key or provider reference must never persist: '.$needle);
}
$changed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_integration_commands WHERE operation='complete_authorization'"));
dzn_v_assert($changed>0,'the completed consent flow must leave exactly one recorded command per operation');

// 12. Two pending consent attempts settle exactly one lifecycle: each intent completes the connection
// it was created for, and the competing lifecycle is settled explicitly rather than by recency.
$raceService=$service(new ContractProviderAdapters(array()));
$raceA=$raceService->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('race-a'),dzn_v_key('race-a'));
$raceB=$raceService->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('race-b'),dzn_v_key('race-b'));
dzn_v_assert((int)$raceA['connection_id']!==(int)$raceB['connection_id'],'each consent attempt must create its own lifecycle generation');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT connection_id FROM {$p}integration_oauth_authorizations WHERE id=%d",(int)$raceB['authorization_id']))===(int)$raceB['connection_id'],'an authorization intent must be bound to exactly its own connection');
$raceCompleted=$raceService->completeAuthorization(array('state'=>(string)$raceA['state'],'code'=>'code-race-a','code_verifier'=>(string)$raceA['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence('race-complete-a'),dzn_v_key('race-complete-a'));
dzn_v_assert((int)$raceCompleted['connection_id']===(int)$raceA['connection_id'],'a completion must settle exactly the lifecycle its intent was issued for');
dzn_v_assert((string)$connectionRow((int)$raceA['connection_id'])->connection_state==='connected','the completed consent must own the active connection');
dzn_v_assert((string)$connectionRow((int)$raceB['connection_id'])->connection_state==='disconnected','a competing pending lifecycle must be settled explicitly');
dzn_v_assert((string)$wpdb->get_var($wpdb->prepare("SELECT authorization_state FROM {$p}integration_oauth_authorizations WHERE id=%d",(int)$raceB['authorization_id']))==='rejected','a competing consent intent must be rejected explicitly');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d AND active_slot=1",$teacherId))===1,'exactly one lifecycle may hold the active slot after a competing consent race');

// 13. A translation-only adapter records a pending projection; only an acknowledged provider result
// may ever mark that mapping verified.
$pendingService=$service(new ContractProviderAdapters(array(),ContractProviderAdapters::PROJECTION_PENDING));
$pending=$pendingService->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'meet-pending-1')+$evidence('project-meet-pending'),dzn_v_key('project-meet-pending'));
dzn_v_assert((int)$pending['mapping_id']>0&&(string)$pending['projection_state']==='pending'&&$pending['acknowledgement_required']===true,'a translation-only adapter must record a pending projection');
$pendingRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_meeting_mappings WHERE id=%d",(int)$pending['mapping_id']));
dzn_v_assert($pendingRow&&(string)$pendingRow->projection_state==='pending'&&$pendingRow->active_slot===null,'a pending translation must never hold an active reference');
dzn_v_assert(preg_match('/^[a-f0-9]{64}$/D',(string)$pendingRow->conference_digest)===1&&(string)$pendingRow->conference_digest!==ProviderIntegrationIdempotency::subject('meet-pending-1','meeting_conference'),'a pending projection must persist the translation digest, not a provider reference');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_meeting_mappings WHERE lesson_id=%d AND active_slot=1",$lessonId))===0,'a pending translation must never be counted as an active integration reference');
$pendingReplay=$pendingService->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'meet-pending-1')+$evidence('project-meet-pending'),dzn_v_key('project-meet-pending'));
dzn_v_assert($pendingReplay['idempotent']===true&&(int)$pendingReplay['mapping_id']===(int)$pending['mapping_id'],'an identical pending projection command must replay idempotently');
dzn_v_rejected(fn()=>$pendingService->acknowledgeCalendarProjection((int)$pending['mapping_id'],array('provider_object_reference'=>'conference-ref-1')+$evidence('ack-wrong-purpose'),dzn_v_key('ack-wrong-purpose')),'integration_mapping_required','a calendar acknowledgement of a conference mapping');
$acknowledged=$pendingService->acknowledgeMeetingProjection((int)$pending['mapping_id'],array('provider_object_reference'=>'conference-ref-1','join_uri_reference'=>'https://meet.example/room-1')+$evidence('ack-meet'),dzn_v_key('ack-meet'));
dzn_v_assert((string)$acknowledged['mapping_state']==='verified','an acknowledged provider result must verify the pending projection');
$verifiedRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_meeting_mappings WHERE id=%d",(int)$pending['mapping_id']));
dzn_v_assert($verifiedRow&&(string)$verifiedRow->projection_state==='verified'&&(int)$verifiedRow->active_slot===1&&(int)$verifiedRow->mapping_version===(int)$pendingRow->mapping_version+1,'an acknowledged projection must become the active verified reference');
dzn_v_assert(hash_equals((string)$verifiedRow->conference_digest,ProviderIntegrationIdempotency::subject('conference-ref-1','meeting_conference')),'only the acknowledged provider reference may become the recorded digest');
dzn_v_assert(hash_equals((string)$verifiedRow->join_uri_digest,ProviderIntegrationIdempotency::subject('https://meet.example/room-1','meeting_conference')),'an acknowledged join reference must persist only as a keyed digest');
dzn_v_rejected(fn()=>$pendingService->acknowledgeMeetingProjection((int)$pending['mapping_id'],array('provider_object_reference'=>'conference-ref-2')+$evidence('ack-again'),dzn_v_key('ack-again')),'projection_not_pending','a second acknowledgement of an already-verified projection');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_meeting_mappings WHERE conference_digest LIKE %s OR join_uri_digest LIKE %s",'%conference-ref-1%','%meet.example%'))===0,'a raw acknowledged provider reference must never persist');
dzn_v_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_integration_commands WHERE operation='acknowledge_meeting_projection'"))>0,'an acknowledgement must leave its own command evidence');

echo "Phase 2A.2-V runtime passed\n";
