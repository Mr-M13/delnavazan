<?php
/**
 * Disposable Phase-U failure proof: an injected write failure at each mutation boundary and at the two
 * declared evidence seams leaves no partial state, a business refusal commits exactly its refusal
 * evidence and raises no intent, and a retry converges on the same recorded facts. Synthetic local data.
 */
if(getenv('DZN_PHASE_2A2U_FAILURE_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U failure runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService,TeacherStatementService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$rates=new TeacherRateService();$policies=new FinancePolicyService();$statements=new TeacherStatementService();
$chain=dzn_u_fix_chain($fixture);
$teacherId=(int)$chain['teacher_id'];
$lesson=dzn_u_fix_occurrence($chain,$fixture,'failure-1',2,60);
$lesson2=dzn_u_fix_occurrence($chain,$fixture,'failure-2',2,60);
dzn_u_fix_complete($lesson,'failure-1');
dzn_u_fix_complete($lesson2,'failure-2');
$inject=null;
$writeHook=static function($table,$data=null)use(&$inject):void{if(is_array($inject)&&$inject['kind']==='write'&&$table===$inject['table'])throw new \RuntimeException('Injected Phase U write failure: '.$table);};
$evidenceHook=static function($kind,$aggregate=null,$id=null)use(&$inject):void{if(is_array($inject)&&$inject['kind']===$kind&&$inject['aggregate']===$aggregate)throw new \RuntimeException('Injected Phase U evidence failure: '.$kind);};
add_action('dzn_phase_2a2u_write',$writeHook,10,2);
add_action('dzn_phase_2a2u_evidence',$evidenceHook,10,3);
$probes=array('finance_teacher_rates','finance_teacher_rate_events','finance_teacher_rate_commands','finance_lesson_snapshots','finance_snapshot_commands','finance_payability_evaluations','finance_payability_overrides','finance_payability_commands','finance_statement_commands','platform_audit_events','platform_outbox');

// An injected failure at each mutation boundary leaves no partial state anywhere.
$boundaries=array('finance_teacher_rates','finance_lesson_snapshots','finance_payability_evaluations','finance_payability_overrides');
foreach($boundaries as $table){
    $before=array();
    foreach($probes as $probe)$before[$probe]=dzn_u_fix_count($probe);
    $inject=array('kind'=>'write','table'=>$table);
    $caught=null;
    try{
        if($table==='finance_teacher_rates')$rates->record($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>13000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-1800),'compensation_basis'=>'per_session','evidence_channel'=>'staff_record','evidence_reference'=>'u-failure','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-rate'));
        elseif($table==='finance_lesson_snapshots')$snapshots->capture((int)$lesson['lesson_id'],dzn_u_fix_key('failure-capture'));
        elseif($table==='finance_payability_evaluations'){if(dzn_u_fix_count('finance_lesson_snapshots','lesson_id=%d',array((int)$lesson['lesson_id']))===0)$snapshots->capture((int)$lesson['lesson_id'],dzn_u_fix_key('failure-capture-pre'));$payability->evaluate((int)$lesson['lesson_id'],dzn_u_fix_key('failure-evaluate'));}
        else{$payability->override((int)$lesson['lesson_id'],'payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-override','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-override'));}
    }catch(\Throwable$e){$caught=$e;}
    $inject=null;
    dzn_u_fix_assert($caught!==null&&str_contains($caught->getMessage(),'Injected Phase U write failure'),'an injected failure at '.$table.' must surface');
    foreach($before as $probe=>$count)dzn_u_fix_assert(dzn_u_fix_count($probe)===$count,'an injected failure at '.$table.' left partial state in '.$probe);
}
// A failed audit write rolls back the Finance row it would have evidenced.
$beforeSnapshots=dzn_u_fix_count('finance_lesson_snapshots');
$inject=array('kind'=>'audit','aggregate'=>'finance_lesson_snapshots');
$caught=null;try{$snapshots->capture((int)$lesson2['lesson_id'],dzn_u_fix_key('failure-audit'));}catch(\Throwable$e){$caught=$e;}
$inject=null;
dzn_u_fix_assert($caught!==null&&str_contains($caught->getMessage(),'Injected Phase U evidence failure'),'a failed audit write must surface');
dzn_u_fix_assert(dzn_u_fix_count('finance_lesson_snapshots')===$beforeSnapshots,'a failed audit write leaves no unevidenced Finance fact');
// The same work retried after the injected failure commits exactly the intended facts.
$captured=$snapshots->capture((int)$lesson2['lesson_id'],dzn_u_fix_key('failure-retry'));
dzn_u_fix_assert((int)$captured['snapshot_id']>0,'a retry after an injected failure commits the intended snapshot');
$evaluation=$payability->evaluate((int)$lesson2['lesson_id'],dzn_u_fix_key('failure-retry-evaluate'));
dzn_u_fix_assert((int)$evaluation['evaluation_id']>0,'a retry after an injected failure commits the intended evaluation');
$override=$payability->override((int)$lesson2['lesson_id'],'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-retry-override','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-retry-override'));
dzn_u_fix_assert((int)$override['override_id']>0,'a retry after an injected failure commits the intended override');

// A failed outbox insert rolls the raising transition back whole; the retry commits exactly one row.
dzn_u_fix_settle(array($lesson2));
$policies->record('FINANCE_STATEMENT_TIMEZONE',array('policy_value'=>'UTC','value_type'=>'timezone','effective_from'=>gmdate('Y-m-d H:i:s',time()-7200),'evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-timezone','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-timezone'));
$periodStart=gmdate('Y-m-d H:i:s',strtotime((string)$lesson2['starts_at_utc'])-3600);
$periodEnd=gmdate('Y-m-d H:i:s',strtotime((string)$lesson2['ends_at_utc'])+60);
$draft=$statements->draft($teacherId,$periodStart,$periodEnd,dzn_u_fix_key('failure-draft'));
$statementId=(int)$draft['statement_id'];
$outboxBefore=dzn_u_fix_count('platform_outbox');
$inject=array('kind'=>'outbox','aggregate'=>'finance_statements');
$caught=null;try{$statements->issue($statementId,dzn_u_fix_key('failure-issue'));}catch(\Throwable$e){$caught=$e;}
$inject=null;
dzn_u_fix_assert($caught!==null&&str_contains($caught->getMessage(),'Injected Phase U evidence failure'),'a failed outbox insert must surface');
dzn_u_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}finance_statements WHERE id=%d",$statementId))==='draft','a failed outbox insert rolls the issuance back whole');
dzn_u_fix_assert(dzn_u_fix_count('platform_outbox')===$outboxBefore,'a failed outbox insert writes no intent');
$issued=$statements->issue($statementId,dzn_u_fix_key('failure-issue-retry'));
dzn_u_fix_assert((string)$issued['state']==='issued','the retry after a failed outbox insert issues the statement');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_outbox WHERE aggregate_type='finance_statements' AND aggregate_id=%d AND event_type='TEACHER_STATEMENT_ISSUED'",$statementId))===1,'the retry commits exactly one seam-compatible intent row');

// A business refusal commits exactly its refusal evidence, and raises no notification intent.
$outboxBefore=dzn_u_fix_count('platform_outbox');
$refused=null;
try{
    $policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>(string)$wpdb->get_var($wpdb->prepare("SELECT snapshot_instant_utc FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$lesson2['lesson_id'])),'evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-refusal','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-refusal'));
}catch(\Throwable$e){$refused=$e;}
dzn_u_fix_assert($refused!==null&&$refused->getMessage()==='policy_effective_from_precedes_recorded_consumption','the admissibility refusal must surface with its exact code');
dzn_u_fix_assert(dzn_u_fix_count('finance_policy_commands','reason_code=%s',array('policy_effective_from_precedes_recorded_consumption'))===1,'a refusal commits exactly one refused command row');
// §15.8: that row is the declared refusal state with a NULL typed result and the exact reason code — a
// refusal whose evidence row kept the attempt's success state could never satisfy the refusal contract.
dzn_u_fix_assert(dzn_u_fix_count('finance_policy_commands','reason_code=%s AND result_state=\'refused\' AND result_policy_id IS NULL',array('policy_effective_from_precedes_recorded_consumption'))===1,'a refusal commits its command row as refused with a NULL typed result');
dzn_u_fix_assert(dzn_u_fix_count('finance_exceptions','reason_code=%s',array('policy_effective_from_precedes_recorded_consumption'))===1,'a refusal commits exactly one matching exception row');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE reason_code=%s",'policy_effective_from_precedes_recorded_consumption'))>=1,'a refusal commits its digest-only audit evidence');
dzn_u_fix_assert(dzn_u_fix_count('platform_outbox')===$outboxBefore,'a refusal raises no notification intent');
try{$policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>(string)$wpdb->get_var($wpdb->prepare("SELECT snapshot_instant_utc FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$lesson2['lesson_id'])),'evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-refusal','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-refusal'));}catch(\Throwable$e){}
dzn_u_fix_assert(dzn_u_fix_count('finance_policy_commands','reason_code=%s',array('policy_effective_from_precedes_recorded_consumption'))===1,'an identical replay of a refusal converges on the same evidence');
// A failure of the refusal-evidence write itself leaves no half-written evidence and fails closed.
$inject=array('kind'=>'write','table'=>'finance_policy_commands');
$failed=null;
try{$policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>(string)$wpdb->get_var($wpdb->prepare("SELECT snapshot_instant_utc FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$lesson2['lesson_id'])),'evidence_channel'=>'staff_record','evidence_reference'=>'u-failure-refusal-2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('failure-refusal-2'));}catch(\Throwable$e){$failed=$e;}
$inject=null;
dzn_u_fix_assert($failed!==null,'a failed refusal-evidence write must fail the command closed');
dzn_u_fix_assert(dzn_u_fix_count('finance_policies','policy_key=%s',array('INTRO_PAYABILITY_POLICY'))===1,'a failed command leaves no Finance row');
remove_action('dzn_phase_2a2u_write',$writeHook,10);
remove_action('dzn_phase_2a2u_evidence',$evidenceHook,10);
// §15.3/§15.6: both declared outcome classes leave a declared result state behind — a success state for a
// committed command and the one declared refusal state for a business refusal — and never an omitted one.
foreach(array('finance_policy_commands','finance_teacher_rate_commands','finance_snapshot_commands','finance_payability_commands','finance_statement_commands','finance_reconciliation_commands') as $commandTable){
    dzn_u_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$commandTable} WHERE result_state IS NULL OR result_state=''")===0,'no '.$commandTable.' row may omit its result_state');
    foreach((array)$wpdb->get_col("SELECT DISTINCT result_state FROM {$p}{$commandTable}") as $recordedState)dzn_u_fix_assert(\Delnavazan\Platform\Core\Application\Finance\FinanceRule::commandResultState((string)$recordedState),'a recorded '.$commandTable.' state is a declared member: '.(string)$recordedState);
}
echo "phase-2a2u-failure-runtime: OK (injected failures leave no partial state; refusals commit exactly their evidence)\n";
