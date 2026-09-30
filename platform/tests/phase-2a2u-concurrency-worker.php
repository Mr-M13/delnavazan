<?php
/** Disposable Phase-U concurrency worker. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2U_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U concurrency worker refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\Finance\{FinanceCorrectionService,FinancePolicyService,FinanceReconciliationService,FinanceSupport,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService,TeacherStatementService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2U_MODE');
$worker=(string)getenv('DZN_PHASE_2A2U_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2U_GATE_DIR');
$state=(array)get_option('dzn_phase_2a2u_concurrency_state',array());
dzn_u_fix_assert($state!==array(),'concurrency state required');
function dzn_uc_gate(string $gate,string $file):void{file_put_contents($gate.'/'.$file, (string)time());}
function dzn_uc_wait(string $gate,string $file):bool{$deadline=microtime(true)+120;while(microtime(true)<$deadline){if(file_exists($gate.'/'.$file))return true;usleep(100000);}return false;}
function dzn_uc_attempt(callable $call):array{
    try{return array('outcome'=>'ok','result'=>$call());}catch(\Throwable$e){return array('outcome'=>'refused','reason'=>$e->getMessage());}
}
wp_set_current_user(1);
$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$rates=new TeacherRateService();$policies=new FinancePolicyService();$statements=new TeacherStatementService();$reconciliation=new FinanceReconciliationService();$corrections=new FinanceCorrectionService();
$sibling=$worker==='w1'?'w2.started':'w1.started';
$mine=$worker.'.started';
dzn_uc_gate($gate,$mine);
if(!in_array($mode,array('unrelated_teachers','concurrent_reconciliation_run','period_wide_run_vs_draft','concurrent_exception_resolution_teacherless'),true)){
    dzn_uc_wait($gate,$sibling);
}
$result=array('mode'=>$mode,'worker'=>$worker,'attempts'=>array());
switch($mode){
    case 'duplicate_snapshot_capture':
        $result['attempts']['capture']=dzn_uc_attempt(fn()=>$snapshots->capture((int)$state['lesson_a'],dzn_u_fix_key('race-capture-'.$worker)));
        break;
    case 'rate_close_vs_rate_record':
    case 'rate_change_vs_snapshot_capture':
        $result['attempts']['rate']=dzn_uc_attempt(fn()=>$rates->record((int)$state['teacher_a'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>14000+($worker==='w1'?0:1),'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()+120+($worker==='w1'?0:1)),'compensation_basis'=>'per_session','evidence_channel'=>'staff_record','evidence_reference'=>'race-rate-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-rate-'.$worker)));
        $result['attempts']['capture']=dzn_uc_attempt(fn()=>$snapshots->capture((int)$state['lesson_a'],dzn_u_fix_key('race-capture2-'.$worker)));
        break;
    case 'successor_record_vs_delayed_historical_capture':
        $result['attempts']['capture']=dzn_uc_attempt(fn()=>$snapshots->capture((int)$state['lesson_a'],dzn_u_fix_key('race-capture3-'.$worker)));
        $result['attempts']['rate']=dzn_uc_attempt(fn()=>$rates->record((int)$state['teacher_a'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>16000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()+300),'compensation_basis'=>'per_session','evidence_channel'=>'staff_record','evidence_reference'=>'race-rate3-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-rate3-'.$worker)));
        break;
    case 'payability_override_vs_evaluation':
        $result['attempts']['evaluate']=dzn_uc_attempt(fn()=>$payability->evaluate((int)$state['lesson_a'],dzn_u_fix_key('race-eval-'.$worker)));
        $result['attempts']['override']=dzn_uc_attempt(fn()=>$payability->override((int)$state['lesson_a'],'payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'race-override-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-override-'.$worker)));
        break;
    case 'concurrent_statement_draft':
    case 'policy_change_vs_statement_draft':
    case 'period_wide_run_vs_draft':
        $result['attempts']['draft']=dzn_uc_attempt(fn()=>$statements->draft((int)$state['teacher_a'],(string)$state['period_start'],(string)$state['period_end'],dzn_u_fix_key('race-draft-'.$worker)));
        if($mode==='policy_change_vs_statement_draft')$result['attempts']['policy']=dzn_uc_attempt(fn()=>$policies->withdraw('FINANCE_STATEMENT_TIMEZONE',1,array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'race-policy-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-policy-'.$worker)));
        break;
    case 'concurrent_statement_issue':
        $result['attempts']['issue']=dzn_uc_attempt(fn()=>$statements->issue((int)$state['statement_a'],dzn_u_fix_key('race-issue-'.$worker)));
        break;
    case 'statement_issue_vs_snapshot_correction':
        $result['attempts']['issue']=dzn_uc_attempt(fn()=>$statements->issue((int)$state['statement_a'],dzn_u_fix_key('race-issue2-'.$worker)));
        $result['attempts']['correction']=dzn_uc_attempt(fn()=>$corrections->correctSnapshot((int)$state['lesson_a'],array('corrected_rate_id'=>(int)$state['rate_a'],'corrected_rate_version'=>1,'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'race-correction-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-correction-'.$worker)));
        break;
    case 'concurrent_policy_record':
    case 'policy_record_vs_snapshot_capture':
    case 'refusal_evidence_convergence':
        // `concurrent_policy_record` races two competing versions of one key, so its instant must be
        // admissible for the winner (strictly later than the seeded version and than the key's recorded
        // consumption); the two refusal modes deliberately record into time already claimed, which the
        // declared monotonicity rule refuses for both workers so their refusals converge on one row.
        $policyEffectiveFrom=$mode==='concurrent_policy_record'?gmdate('Y-m-d H:i:s',time()+600):gmdate('Y-m-d H:i:s',time()-86400*30);
        $result['attempts']['policy']=dzn_uc_attempt(fn()=>$policies->record('INTERRUPTION_COMPENSATION_POLICY',array('policy_value'=>$worker==='w1'?'payable':'non_payable','value_type'=>'policy_reference','effective_from'=>$policyEffectiveFrom,'evidence_channel'=>'staff_record','evidence_reference'=>'race-policy2-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key($mode==='refusal_evidence_convergence'?'race-refusal':'race-policy2-'.$worker)));
        $result['attempts']['capture']=dzn_uc_attempt(fn()=>$snapshots->capture((int)$state['lesson_a'],dzn_u_fix_key('race-capture4-'.$worker)));
        break;
    case 'concurrent_reconciliation_run':
    case 'unrelated_teachers':
        $teacher=$mode==='unrelated_teachers'?($worker==='w1'?(int)$state['teacher_a']:(int)$state['teacher_b']):(int)$state['teacher_a'];
        $result['attempts']['run']=dzn_uc_attempt(fn()=>$reconciliation->run((string)$state['period_start'],(string)$state['period_end'],$mode==='unrelated_teachers'?$teacher:null,dzn_u_fix_key('race-run-'.$worker)));
        if($mode==='unrelated_teachers')$result['attempts']['rate']=dzn_uc_attempt(fn()=>$rates->record($teacher,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>21000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()+900),'compensation_basis'=>'per_session','evidence_channel'=>'staff_record','evidence_reference'=>'race-rate4-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('race-rate4-'.$worker)));
        break;
    case 'duplicate_command_replay':
        $result['attempts']['evaluate']=dzn_uc_attempt(fn()=>$payability->evaluate((int)$state['lesson_a'],'phase-2a2u-shared-replay-key'));
        break;
    case 'concurrent_exception_resolution':
    case 'concurrent_exception_resolution_teacherless':
        $exceptionId=$mode==='concurrent_exception_resolution'?(int)$state['exception_teacher']:(int)$state['exception_teacherless'];
        $result['attempts']['resolve']=dzn_uc_attempt(fn()=>$reconciliation->resolveException($exceptionId,array('resolution_note'=>'race resolution '.$worker),dzn_u_fix_key('race-resolve-'.$worker)));
        break;
    default:
        throw new \RuntimeException('Unsupported Phase U concurrency mode: '.$mode);
}
file_put_contents($gate.'/'.$worker.'.result.json',json_encode($result));
dzn_uc_gate($gate,$worker.'.finished');
fwrite(STDOUT,"phase-2a2u-concurrency-worker: ".$worker." ".$mode." done\n");
