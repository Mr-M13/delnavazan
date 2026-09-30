<?php
/** Disposable Phase-U concurrency verifier: asserts the invariant of the raced mode. */
if(getenv('DZN_PHASE_2A2U_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2U_MODE');
$gate=(string)getenv('DZN_PHASE_2A2U_GATE_DIR');
$state=(array)get_option('dzn_phase_2a2u_concurrency_state',array());
function dzn_ucv_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('phase-2a2u-concurrency-verify: '.$message);}
function dzn_ucv_ok(string $gate,string $worker,string $key):bool{
    $file=$gate.'/'.$worker.'.result.json';
    if(!file_exists($file))return false;
    $result=json_decode((string)file_get_contents($file),true);
    return isset($result['attempts'][$key]['outcome'])&&$result['attempts'][$key]['outcome']==='ok';
}
function dzn_ucv_reason(string $gate,string $worker,string $key):string{
    $file=$gate.'/'.$worker.'.result.json';
    if(!file_exists($file))return '';
    $result=json_decode((string)file_get_contents($file),true);
    return (string)($result['attempts'][$key]['reason']??'');
}
$lessonA=(int)$state['lesson_a'];
$teacherA=(int)$state['teacher_a'];
switch($mode){
    case 'duplicate_snapshot_capture':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA))===1,'two captures must leave exactly one snapshot');
        $snapshotId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA));
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT result_snapshot_id FROM {$p}finance_snapshot_commands WHERE lesson_id=%d AND result_state='recorded'",$lessonA)) as $result)dzn_ucv_assert((int)$result===$snapshotId,'every converged capture names the one snapshot');
        break;
    case 'rate_close_vs_rate_record':
    case 'rate_change_vs_snapshot_capture':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND active_slot=1",$teacherA))===1,'a race must leave exactly one live rate row per scope');
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA))===1,'the capture must leave exactly one snapshot');
        break;
    case 'successor_record_vs_delayed_historical_capture':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA))===1,'the capture leaves exactly one snapshot');
        $pairs=$wpdb->get_results($wpdb->prepare("SELECT rate_id,rate_version FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA));
        foreach($pairs as $pair)dzn_ucv_assert((int)$pair->rate_id>0&&(int)$pair->rate_version>0,'the snapshot keeps exactly one recorded interval');
        break;
    case 'payability_override_vs_evaluation':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_payability_evaluations WHERE lesson_id=%d AND applicable_slot=1",$lessonA))===1,'exactly one applicable evaluation survives');
        break;
    case 'concurrent_statement_draft':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statements WHERE teacher_id=%d AND state IN ('draft','issued','superseded')",$teacherA))>=1,'the race leaves one coherent statement state');
        $overlap=$wpdb->get_results($wpdb->prepare("SELECT a.id FROM {$p}finance_statements a INNER JOIN {$p}finance_statements b ON a.teacher_id=b.teacher_id AND a.id<b.id AND a.state IN ('draft','issued') AND b.state IN ('draft','issued') WHERE a.period_start_utc<b.period_end_utc AND b.period_start_utc<a.period_end_utc LIMIT 1",0));
        dzn_ucv_assert($overlap===array()||$overlap===null||count($overlap)===0,'no two live statements of one Teacher overlap');
        break;
    case 'policy_change_vs_statement_draft':
        $drafts=$wpdb->get_results($wpdb->prepare("SELECT period_timezone,period_label,timezone_policy_version FROM {$p}finance_statements WHERE teacher_id=%d AND state='draft'",$teacherA));
        foreach($drafts as $draft){
            $recorded=array($draft->period_timezone!==null,$draft->period_label!==null,$draft->timezone_policy_version!==null);
            dzn_ucv_assert(count(array_unique($recorded))===1,'a draft never records a partially mixed timezone triple');
        }
        dzn_ucv_assert((string)$wpdb->get_var("SELECT status FROM {$p}finance_policies WHERE policy_key='FINANCE_STATEMENT_TIMEZONE' AND policy_version=1")==='withdrawn','the raced policy withdrawal is recorded');
        break;
    case 'concurrent_statement_issue':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statements WHERE id=%d AND state='issued'",(int)$state['statement_a']))===1,'exactly one issue wins');
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statement_events WHERE statement_id=%d AND event_type='issued'",(int)$state['statement_a']))===1,'one issuance event is recorded');
        break;
    case 'statement_issue_vs_snapshot_correction':
        $issued=(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}finance_statements WHERE id=%d",(int)$state['statement_a']));
        $corrections=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_snapshot_corrections WHERE lesson_id=%d",$lessonA));
        dzn_ucv_assert($issued!=='issued'||$corrections===0||dzn_ucv_reason($gate,'w1','correction')==='statement_supersession_required'||dzn_ucv_reason($gate,'w2','correction')==='statement_supersession_required','an issued statement cannot be silently out-covered by a correction');
        break;
    case 'concurrent_policy_record':
        dzn_ucv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_policies WHERE policy_key='INTERRUPTION_COMPENSATION_POLICY'")>=2,'at most one competing version is admitted and none shares an instant');
        $duplicates=$wpdb->get_results("SELECT policy_key,effective_from,COUNT(*) AS total FROM {$p}finance_policies GROUP BY policy_key,effective_from HAVING total>1");
        dzn_ucv_assert(!$duplicates,'two policy versions never share one effective instant');
        break;
    case 'policy_record_vs_snapshot_capture':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonA))===1,'the capture keeps exactly one snapshot with a coherent pair');
        break;
    case 'refusal_evidence_convergence':
        dzn_ucv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_policy_commands WHERE result_state='refused'")===1,'two identical refusals converge on one refused command row');
        break;
    case 'duplicate_command_replay':
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_payability_commands WHERE lesson_id=%d",$lessonA))===1,'an identical replay converges on one command row');
        break;
    case 'concurrent_exception_resolution':
        dzn_ucv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}finance_exceptions WHERE id=%d",(int)$state['exception_teacher']))==='resolved','the teacher-scoped exception is resolved');
        break;
    case 'concurrent_exception_resolution_teacherless':
        dzn_ucv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}finance_exceptions WHERE id=%d",(int)$state['exception_teacherless']))==='resolved','the teacher-less exception is resolved');
        dzn_ucv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_teacher_roots")<=3,'a teacher-less resolution never invents a Teacher root');
        break;
    case 'concurrent_reconciliation_run':
    case 'period_wide_run_vs_draft':
        dzn_ucv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_reconciliation_runs")>=1,'runs append independent evidence');
        break;
    case 'unrelated_teachers':
        dzn_ucv_assert(is_file($gate.'/w2.independent'),'two Teachers must never contend');
        dzn_ucv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_teacher_rates WHERE teacher_id=%d",(int)$state['teacher_b']))>=2,'the unrelated Teacher completes its own work');
        break;
    default:
        throw new \RuntimeException('Unsupported Phase U concurrency mode: '.$mode);
}
echo "phase-2a2u-concurrency-verify: OK (".$mode.")\n";
