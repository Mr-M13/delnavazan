<?php
/** Disposable Phase-V concurrency pre-state builder. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V concurrency setup refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{ProviderIntegrationService,TeacherService};
use Delnavazan\Platform\Integrations\ContractProviderAdapters;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_vcs_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_vcs_key(string $label):string{return 'dzn-2a2vc-'.$label.'-'.wp_generate_uuid4();}
$mode=(string)getenv('DZN_PHASE_2A2V_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_vcs_assert(is_array($fixture)&&count($fixture['sources']??array())>=2,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('provider_event_conflicts','provider_ingest_events','provider_integration_commands','provider_calendar_event_mappings','provider_meeting_mappings','provider_identity_mappings','integration_oauth_authorizations','integration_credentials','integration_connections') as $table)
    dzn_vcs_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase V storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],$mode,1);
$target=$wpdb->get_row($wpdb->prepare("SELECT version.* FROM {$p}canonical_lesson_schedule_versions version INNER JOIN {$p}lessons lesson ON lesson.id=version.lesson_id WHERE lesson.term_id=%d AND version.applicable_slot=1 ORDER BY version.id LIMIT 1",(int)$funded['term_id']));
dzn_vcs_assert($target!==null,'the fixture must leave one applicable canonical schedule version');
$lessonId=(int)$target->lesson_id;$versionId=(int)$target->id;
$lesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$lessonId));
$teacherId=(int)$lesson->teacher_id;
$ports=new ContractProviderAdapters(array());
$service=new ProviderIntegrationService(null,null,$ports,$ports,$ports);
$scope='https://www.googleapis.com/auth/calendar.events';
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$connect=static function(int $teacher) use($service,$scope,$evidence,$mode):int{
    $begin=$service->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacher,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence($mode.'-setup-begin'),dzn_vcs_key($mode.'-setup-begin'));
    $done=$service->completeAuthorization(array('state'=>(string)$begin['state'],'code'=>'setup-'.$mode,'code_verifier'=>(string)$begin['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence($mode.'-setup-complete'),dzn_vcs_key($mode.'-setup-complete'));
    return(int)$done['connection_id'];
};
$state=array('mode'=>$mode,'teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'student_id'=>(int)$lesson->student_id,'start'=>(string)$target->starts_at_utc,'end'=>(string)$target->ends_at_utc,'schedule_timezone'=>(string)$target->schedule_timezone,'local_wall_date'=>(string)$target->local_wall_date,'local_wall_time'=>(string)$target->local_wall_time);
if($mode==='authorization_replay'){
    // Two processes race to consume one identical authorization state. The fixture holds the
    // disposable one-time material only in a harness option: the integration storage itself never
    // keeps anything but the state and verifier digests.
    $pending=$service->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacherId,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence($mode.'-setup-begin'),dzn_vcs_key($mode.'-setup-begin'));
    $state['connection_id']=(int)$pending['connection_id'];
    $state['pending_authorization']=array('authorization_id'=>(int)$pending['authorization_id'],'state'=>(string)$pending['state'],'code_verifier'=>(string)$pending['code_verifier']);
}else{
    // Every other mode starts from one connected Teacher A; the unrelated mode also needs a Teacher B.
    $connectionA=$connect($teacherId);
    $state['connection_id']=$connectionA;
    if($mode==='unrelated_teacher'){
        $other=(int)(new TeacherService())->create(array('display_name'=>'Synthetic V Teacher B','email'=>'v-b-'.wp_generate_uuid4().'@phase-2a2v.invalid'));
        $state['other_teacher_id']=$other;$state['other_connection_id']=$connect($other);
    }
    if($mode==='mapping_revoke_vs_ingest'){
        $state['identity_mapping_id']=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}provider_identity_mappings WHERE connection_id=%d AND mapping_state='verified' LIMIT 1",$connectionA));
    }
    if($mode==='provider_event_sequence_race'||$mode==='cross_lesson_event_key_race'){
        // A second occurrence in the same provider on a *different* Lesson: the two contenders therefore
        // lock different canonical chains, and the only shared serialisation is the provider-scoped
        // lock the ingest takes over the provider event key — which is also what serialises the receipt
        // sequence it allocates before it writes an immutable receipt. The cross-lesson key race
        // delivers the *same* event key to both of those Lessons with a materially different context.
        $fundedB=dzn_r2_fix_funded_enrolment($fixture['sources'][1],$mode.'-b',2);
        $targetB=$wpdb->get_row($wpdb->prepare("SELECT version.* FROM {$p}canonical_lesson_schedule_versions version INNER JOIN {$p}lessons lesson ON lesson.id=version.lesson_id WHERE lesson.term_id=%d AND version.applicable_slot=1 ORDER BY version.id LIMIT 1",(int)$fundedB['term_id']));
        dzn_vcs_assert($targetB!==null,'the second occurrence must leave one applicable canonical schedule version');
        $state['occurrence_b']=array('lesson_id'=>(int)$targetB->lesson_id,'schedule_version_id'=>(int)$targetB->id);
    }
}
// One deterministic provider-event delivery for the ingest modes, so an exact duplicate is genuinely
// exact and only the deliberate conflict variant differs.
$state['delivery']=array('event_key'=>$mode.'-event-'.substr(str_replace('-','',wp_generate_uuid4()),0,8),'observed_at'=>gmdate('Y-m-d H:i:s'),'leave_at_utc'=>gmdate('Y-m-d H:i:s'),'join_at_utc'=>gmdate('Y-m-d H:i:s'));
if($mode==='provider_event_sequence_race')
    $state['delivery_b']=array('event_key'=>$mode.'-event-b-'.substr(str_replace('-','',wp_generate_uuid4()),0,8),'observed_at'=>gmdate('Y-m-d H:i:s'),'leave_at_utc'=>gmdate('Y-m-d H:i:s'),'join_at_utc'=>gmdate('Y-m-d H:i:s'));
$state['baseline']=array(
    'versions'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",$lessonId)),
    'events'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_events WHERE lesson_id=%d",$lessonId)),
    'lesson_state'=>(string)$lesson->lifecycle_state,
    'applicable_version'=>(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",$lessonId)),
);
update_option('dzn_phase_2a2v_concurrency',$state,false);
echo "Phase 2A.2-V concurrency setup prepared: ".$mode."\n";
