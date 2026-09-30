<?php
/**
 * Disposable Phase-V corruption proof.
 *
 * Every stored Phase-V fact is corrupted in turn — connection state and identity digest, credential
 * key/cipher version and sealed material, identity/calendar/meeting mapping shape, provider-event
 * context, conflict kind and command evidence — and each path must fail closed visibly. After the
 * row is repaired the same path must work again, so no corruption silently widens authority.
 */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V corruption runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{ProviderEventIngestService,ProviderIntegrationReadService,ProviderIntegrationService};
use Delnavazan\Platform\Integrations\ContractProviderAdapters;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_vc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_vc_key(string $label):string{return 'dzn-2a2vc-'.$label.'-'.wp_generate_uuid4();}
function dzn_vc_refused(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_vc_assert($caught!==null,$message.' was accepted');dzn_vc_assert($caught->getMessage()===$expected,$message.' was refused with an unexpected error: '.$caught->getMessage());}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_vc_assert(is_array($fixture)&&count($fixture['sources']??array())>=1,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('provider_event_conflicts','provider_ingest_events','provider_integration_commands','provider_calendar_event_mappings','provider_meeting_mappings','provider_identity_mappings','integration_oauth_authorizations','integration_credentials','integration_connections') as $table)
    dzn_vc_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase V storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],'corruption',1);
$target=$wpdb->get_row($wpdb->prepare("SELECT version.* FROM {$p}canonical_lesson_schedule_versions version INNER JOIN {$p}lessons lesson ON lesson.id=version.lesson_id WHERE lesson.term_id=%d AND version.applicable_slot=1 ORDER BY version.id LIMIT 1",(int)$funded['term_id']));
dzn_vc_assert($target!==null,'the fixture must leave one applicable canonical schedule version');
$lessonId=(int)$target->lesson_id;$versionId=(int)$target->id;
$teacherId=(int)$wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}lessons WHERE id=%d",$lessonId));
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$ports=new ContractProviderAdapters(array());
$service=new ProviderIntegrationService(null,null,$ports,$ports,$ports);
$read=new ProviderIntegrationReadService();

// Fixture: one connected integration with a sealed credential and a verified identity mapping.
$scope='https://www.googleapis.com/auth/calendar.events';
$begin=$service->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence('begin'),dzn_vc_key('begin'));
$connected=$service->completeAuthorization(array('state'=>(string)$begin['state'],'code'=>'code-corruption','code_verifier'=>(string)$begin['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence('complete'),dzn_vc_key('complete'));
$connectionId=(int)$connected['connection_id'];
$mappingId=(int)$connected['identity_mapping_id'];
$credential=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}integration_credentials WHERE connection_id=%d AND state='active'",$connectionId));
dzn_vc_assert($credential!==null,'the corruption fixture requires one active credential');
$projected=$service->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corruption')+$evidence('project'),dzn_vc_key('project'));
$meeting=$service->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corruption-meet')+$evidence('project-meet'),dzn_vc_key('project-meet'));
$receipt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE lesson_id=%d LIMIT 1",$lessonId));
$ingestService=new ProviderEventIngestService(null,new ContractProviderAdapters(array()),null);
$eventKey='v-corruption-'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
/** One authenticated delivery envelope over the exact body a named trusted transport validated. */
$envelope=static function(array $facts) use($lessonId,$versionId):array{
    $body=(string)wp_json_encode($facts);
    return array('provider_code'=>'google_meet','lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>$body,'body_digest'=>hash('sha256',$body),'proof_reference'=>'proof-'.$facts['provider_event_key'].'-0000','facts'=>$facts);
};
$eventFacts=static fn(string $joinAt):array=>array('provider_code'=>'google_meet','provider_event_key'=>$eventKey,'provider_payload_key'=>$eventKey,'participant_role'=>'teacher','provider_account_key'=>'acct-'.$eventKey,'observed_at'=>gmdate('Y-m-d H:i:s'),'join_at_utc'=>$joinAt,'leave_at_utc'=>gmdate('Y-m-d H:i:s'));
$admitted=$ingestService->ingest($envelope($eventFacts(gmdate('Y-m-d H:i:s'))),dzn_vc_key('ingest'));
$ingestId=(int)$admitted['ingest_event_id'];

// 1. Connection state: an uncontrolled lifecycle state and a missing identity digest both fail closed.
$wpdb->update($p.'integration_connections',array('connection_state'=>'whatever'),array('id'=>$connectionId));
dzn_vc_refused(fn()=>$read->connection($connectionId),'provider_connection_malformed','a read of a connection with an uncontrolled lifecycle state');
dzn_vc_refused(fn()=>$service->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corrupt-state')+$evidence('project-state'),dzn_vc_key('project-state')),'provider_connection_unusable','a projection through an uncontrolled connection state');
$wpdb->update($p.'integration_connections',array('connection_state'=>'connected'),array('id'=>$connectionId));
dzn_vc_assert((int)$read->connection($connectionId)['connection_id']===$connectionId,'a repaired connection must read again');
$wpdb->update($p.'integration_connections',array('identity_digest'=>'not-a-digest'),array('id'=>$connectionId));
dzn_vc_refused(fn()=>$read->connection($connectionId),'provider_connection_malformed','a read of a connection with a malformed identity digest');
$wpdb->update($p.'integration_connections',array('identity_digest'=>str_repeat('a',64)),array('id'=>$connectionId));
dzn_vc_assert((int)$read->connection($connectionId)['connection_id']===$connectionId,'a repaired identity digest must read again');

// 2. Credential: an unknown key version, an unknown cipher version and cleared sealed material.
$wpdb->update($p.'integration_credentials',array('key_version'=>'dzn_unknown_v9'),array('id'=>(int)$credential->id));
dzn_vc_refused(fn()=>$service->refreshConnection($connectionId,$evidence('refresh-corrupt-key'),dzn_vc_key('refresh-corrupt-key')),'Integration credential key material unavailable','a refresh with an unknown credential key version');
$wpdb->update($p.'integration_credentials',array('key_version'=>(string)$credential->key_version),array('id'=>(int)$credential->id));
$wpdb->update($p.'integration_credentials',array('cipher_version'=>'rot13_v0'),array('id'=>(int)$credential->id));
dzn_vc_refused(fn()=>$read->connection($connectionId),'provider_credential_malformed','a read of a credential with an unknown cipher version');
$wpdb->update($p.'integration_credentials',array('cipher_version'=>(string)$credential->cipher_version),array('id'=>(int)$credential->id));
$wpdb->update($p.'integration_credentials',array('nonce'=>''),array('id'=>(int)$credential->id));
dzn_vc_refused(fn()=>$read->connection($connectionId),'provider_credential_malformed','a read of a credential with cleared sealed material');
dzn_vc_refused(fn()=>$service->projectMeetingConference(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corrupt-cred')+$evidence('project-cred'),dzn_vc_key('project-cred')),'provider_credential_malformed','a projection through a credential with cleared sealed material');
$wpdb->update($p.'integration_credentials',array('nonce'=>(string)$credential->nonce),array('id'=>(int)$credential->id));
dzn_vc_assert((int)$read->connection($connectionId)['connection_id']===$connectionId,'a repaired credential must read again');

// 3. Identity mapping: a malformed subject digest and a verified mapping without an active slot.
$originalSubject=(string)$wpdb->get_var($wpdb->prepare("SELECT subject_digest FROM {$p}provider_identity_mappings WHERE id=%d",$mappingId));
$wpdb->update($p.'provider_identity_mappings',array('subject_digest'=>'short'),array('id'=>$mappingId));
$corruptMapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_identity_mappings WHERE id=%d",$mappingId));
dzn_vc_assert(!\Delnavazan\Platform\Core\Application\ProviderIntegrationValidator::mappingShape($corruptMapping,'connection_identity'),'a verified identity mapping with a malformed subject digest must fail closed');
$wpdb->update($p.'provider_identity_mappings',array('subject_digest'=>$originalSubject),array('id'=>$mappingId));
$wpdb->update($p.'provider_identity_mappings',array('active_slot'=>null),array('id'=>$mappingId));
$corruptMapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_identity_mappings WHERE id=%d",$mappingId));
dzn_vc_assert(!\Delnavazan\Platform\Core\Application\ProviderIntegrationValidator::mappingShape($corruptMapping,'connection_identity'),'an identity mapping declared verified without an active slot must fail closed');
$wpdb->update($p.'provider_identity_mappings',array('active_slot'=>1),array('id'=>$mappingId));
dzn_vc_assert(\Delnavazan\Platform\Core\Application\ProviderIntegrationValidator::mappingShape($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_identity_mappings WHERE id=%d",$mappingId)),'connection_identity'),'a repaired identity mapping must validate again');

// 4. Calendar and meeting mappings: a malformed provider digest fails closed and a repaired row reads.
$calendarId=(int)$projected['mapping_id'];
$calendarDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT event_digest FROM {$p}provider_calendar_event_mappings WHERE id=%d",$calendarId));
$wpdb->update($p.'provider_calendar_event_mappings',array('event_digest'=>'pending'),array('id'=>$calendarId));
dzn_vc_refused(fn()=>$read->lessonIntegrations($lessonId),'integration_mapping_malformed','a calendar mapping with a malformed provider digest');
dzn_vc_refused(fn()=>$service->retractCalendarProjection($calendarId,array('reason_code'=>'corrupt')+$evidence('retract-corrupt'),dzn_vc_key('retract-corrupt')),'integration_mapping_malformed','a retraction of a malformed calendar mapping');
$wpdb->update($p.'provider_calendar_event_mappings',array('event_digest'=>$calendarDigest),array('id'=>$calendarId));
dzn_vc_assert((int)$read->lessonIntegrations($lessonId)['calendar_events'][0]['mapping_id']===$calendarId,'a repaired calendar mapping must read again');
$meetingId=(int)$meeting['mapping_id'];
$conferenceDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT conference_digest FROM {$p}provider_meeting_mappings WHERE id=%d",$meetingId));
$wpdb->update($p.'provider_meeting_mappings',array('conference_digest'=>'x'),array('id'=>$meetingId));
dzn_vc_refused(fn()=>$read->lessonIntegrations($lessonId),'integration_mapping_malformed','a conference mapping with a malformed provider digest');
$wpdb->update($p.'provider_meeting_mappings',array('conference_digest'=>$conferenceDigest),array('id'=>$meetingId));

// 5. Provider event: a receipt whose provider instant is after its local receipt, and an uncontrolled state.
$receiptRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE id=%d",$ingestId));
dzn_vc_assert($receiptRow!==null,'the corruption fixture requires one provider ingest receipt');
$wpdb->update($p.'provider_ingest_events',array('occurred_at'=>gmdate('Y-m-d H:i:s',strtotime((string)$receiptRow->received_at.' UTC')+3600)),array('id'=>$ingestId));
dzn_vc_refused(fn()=>$read->events($lessonId),'provider_event_receipt_corrupt','a read of a receipt whose provider instant follows its local receipt');
$wpdb->update($p.'provider_ingest_events',array('occurred_at'=>(string)$receiptRow->occurred_at),array('id'=>$ingestId));
$wpdb->update($p.'provider_ingest_events',array('processing_state'=>'settled'),array('id'=>$ingestId));
dzn_vc_refused(fn()=>$read->events($lessonId),'provider_event_receipt_corrupt','a read of a receipt in an uncontrolled processing state');
$wpdb->update($p.'provider_ingest_events',array('processing_state'=>(string)$receiptRow->processing_state),array('id'=>$ingestId));
dzn_vc_assert(count($read->events($lessonId))===1,'a repaired provider-event receipt must read again');

// 5b. A receipt without its transport proof, and an uncontrolled handoff outcome, both fail closed.
$proofDigest=(string)$receiptRow->proof_reference_digest;
$wpdb->update($p.'provider_ingest_events',array('proof_reference_digest'=>'not-a-proof-digest'),array('id'=>$ingestId));
dzn_vc_refused(fn()=>$read->events($lessonId),'provider_event_receipt_corrupt','a receipt whose transport proof digest is malformed');
$wpdb->update($p.'provider_ingest_events',array('proof_reference_digest'=>$proofDigest),array('id'=>$ingestId));
dzn_vc_assert(count($read->events($lessonId))===1,'a repaired proof digest must read again');
$outcomeRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt DESC LIMIT 1",$ingestId));
dzn_vc_assert($outcomeRow!==null,'the corruption fixture requires one recorded handoff outcome');
$wpdb->update($p.'provider_ingest_outcomes',array('outcome'=>'maybe'),array('id'=>(int)$outcomeRow->id));
dzn_vc_refused(fn()=>$read->events($lessonId),'provider_event_outcome_corrupt','a receipt whose handoff outcome is uncontrolled');
$wpdb->update($p.'provider_ingest_outcomes',array('outcome'=>(string)$outcomeRow->outcome),array('id'=>(int)$outcomeRow->id));
dzn_vc_assert(count($read->events($lessonId))===1,'a repaired handoff outcome must read again');

// 6. Conflict receipt: an uncontrolled conflict kind must never be reported as a receipt.
$conflict=$ingestService->ingest($envelope($eventFacts(gmdate('Y-m-d H:i:s',strtotime((string)$receiptRow->occurred_at.' UTC')+600))),dzn_vc_key('ingest-conflict'));
dzn_vc_assert($conflict['conflict']===true,'the corruption fixture requires one conflict receipt');
$conflictId=(int)$conflict['conflict_id'];
$conflictKind=(string)$wpdb->get_var($wpdb->prepare("SELECT conflict_kind FROM {$p}provider_event_conflicts WHERE id=%d",$conflictId));
$wpdb->update($p.'provider_event_conflicts',array('conflict_kind'=>'guess'),array('id'=>$conflictId));
dzn_vc_refused(fn()=>$read->conflicts($lessonId),'provider_conflict_receipt_corrupt','a read of a conflict receipt with an uncontrolled kind');
$wpdb->update($p.'provider_event_conflicts',array('conflict_kind'=>$conflictKind),array('id'=>$conflictId));
dzn_vc_assert(count($read->conflicts($lessonId))===1,'a repaired conflict receipt must read again');

// 7. Command evidence: a mutated payload digest must refuse the replay as a conflict.
$command=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_integration_commands WHERE operation='project_calendar_event' ORDER BY id LIMIT 1"));
dzn_vc_assert($command!==null,'the corruption fixture requires one projection command');
$payloadDigest=(string)$command->command_payload_digest;
$wpdb->update($p.'provider_integration_commands',array('command_payload_digest'=>str_repeat('0',64)),array('id'=>(int)$command->id));
$direct=new ProviderIntegrationService(null,null,$ports,$ports,$ports);
$caught=null;try{$direct->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corruption')+$evidence('project'),dzn_vc_key('project'));}catch(Throwable$e){$caught=$e;}
dzn_vc_assert($caught!==null&&$caught instanceof \Delnavazan\Platform\Core\Application\IdempotencyConflictException,'a corrupted command payload digest must refuse the replay as an idempotency conflict');
$wpdb->update($p.'provider_integration_commands',array('command_payload_digest'=>$payloadDigest),array('id'=>(int)$command->id));
$replayed=$direct->projectCalendarEvent(array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'projection_reference'=>'ref-corruption')+$evidence('project'),dzn_vc_key('project'));
dzn_vc_assert($replayed['idempotent']===true&&(int)$replayed['mapping_id']===$calendarId,'a repaired command evidence row must replay idempotently again');

// 8. No corruption path silently widened authority.
dzn_vc_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}integration_connections WHERE provider_code='google_calendar' AND teacher_id=%d AND active_slot=1",$teacherId))===1,'corruption must never create a second active connection');
dzn_vc_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_ingest_events WHERE provider_code='google_meet' AND lesson_id=%d",$lessonId))===1,'corruption must never create a second provider-event receipt');

echo "Phase 2A.2-V corruption runtime passed\n";
