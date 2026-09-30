<?php
/**
 * Disposable Phase-U reconciliation proof: a run that appends findings and repairs nothing, exact-integer
 * comparison with no tolerance, the declared finding vocabulary, `resolveException` under the root its
 * own target scope selects, and the read-only cross-check surfaces. Synthetic local data only.
 */
if(getenv('DZN_PHASE_2A2U_RECONCILIATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U reconciliation runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,FinanceReconciliationService,FinanceRule,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService,TeacherStatementService};
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceReconciliationReadService;
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$statements=new TeacherStatementService();$reconciliation=new FinanceReconciliationService();$rates=new TeacherRateService();$policies=new FinancePolicyService();
$chain=dzn_u_fix_chain($fixture);
$teacherId=(int)$chain['teacher_id'];
$first=dzn_u_fix_occurrence($chain,$fixture,'recon-1',2,60);
$second=dzn_u_fix_occurrence($chain,$fixture,'recon-2',2,60);
$captured=dzn_u_fix_occurrence($chain,$fixture,'recon-3',2,60);
dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-3600),'compensation_basis'=>'per_session'),'recon');
foreach(array($first,$second) as $index=>$occurrence){
    $snapshots->capture((int)$occurrence['lesson_id'],dzn_u_fix_key('cap-'.$index));
    $payability->evaluate((int)$occurrence['lesson_id'],dzn_u_fix_key('eval-'.$index));
}
// The statement covers the two captured Lessons; the reconciliation run covers a wider period that also
// contains the deliberately uncaptured third Lesson, so snapshot debt is a finding rather than a refusal.
$draftStart=gmdate('Y-m-d H:i:s',min(strtotime((string)$first['starts_at_utc']),strtotime((string)$second['starts_at_utc']))-3600);
$draftEnd=gmdate('Y-m-d H:i:s',max(strtotime((string)$first['ends_at_utc']),strtotime((string)$second['ends_at_utc']))+60);
$periodStart=gmdate('Y-m-d H:i:s',min(strtotime((string)$first['starts_at_utc']),strtotime((string)$second['starts_at_utc']),strtotime((string)$captured['starts_at_utc']))-3600);
$periodEnd=gmdate('Y-m-d H:i:s',max(strtotime((string)$first['ends_at_utc']),strtotime((string)$second['ends_at_utc']),strtotime((string)$captured['ends_at_utc']))+60);
dzn_u_fix_settle(array($first,$second,$captured));
$draft=$statements->draft($teacherId,$draftStart,$draftEnd,dzn_u_fix_key('recon-draft'));
$statementId=(int)$draft['statement_id'];
// The third Lesson belongs to the period but is never captured: snapshot debt is a finding, not a skip.
$snapshotCountBefore=dzn_u_fix_count('finance_lesson_snapshots');
$statementTotalsBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT payable_amount_minor FROM {$p}finance_statements WHERE id=%d",$statementId));
$run=$reconciliation->run($periodStart,$periodEnd,$teacherId,dzn_u_fix_key('run-1'),array('legacy_comparison'=>array(array('lesson_id'=>(int)$first['lesson_id'],'legacy_amount_minor'=>1))));
// The controlled legacy comparison differs from the recorded platform amount, so it is reported.
$findings=$reconciliation->findings((int)$run['run_id']);
$codes=array_column($findings['findings'],'finding_code');
dzn_u_fix_assert(in_array('snapshot_missing_for_lesson',$codes,true),'a Lesson of the period with no snapshot is reported');
dzn_u_fix_assert(in_array('legacy_flag_differs',$codes,true),'a controlled legacy comparison difference is reported rather than auto-resolved');
dzn_u_fix_assert((int)$run['mismatch_count']===count($findings['findings']),'the run mismatches its own findings exactly');
dzn_u_fix_assert(dzn_u_fix_count('finance_lesson_snapshots')===$snapshotCountBefore,'a run repairs nothing: the snapshot set is unchanged');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT payable_amount_minor FROM {$p}finance_statements WHERE id=%d",$statementId))===$statementTotalsBefore,'a run repairs nothing: the statement totals are unchanged');
// §15.3: a run's command row carries its declared success state and typed result, an identical replay
// converges on the recorded run, and a materially different replay of one key is refused.
$runCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_reconciliation_commands WHERE operation='run' AND result_run_id=%d ORDER BY id DESC LIMIT 1",(int)$run['run_id']));
dzn_u_fix_assert($runCommand!==null&&(string)$runCommand->result_state===FinanceRule::commandSuccessState('run')&&(int)$runCommand->result_run_id===(int)$run['run_id'],'a completed run command row carries its declared success state and typed result');
$replayKey=dzn_u_fix_key('run-replay');
$replayedRun=$reconciliation->run($periodStart,$periodEnd,$teacherId,$replayKey);
$runsBeforeReplay=dzn_u_fix_count('finance_reconciliation_runs');
$convergedRun=$reconciliation->run($periodStart,$periodEnd,$teacherId,$replayKey);
dzn_u_fix_assert((int)$convergedRun['run_id']===(int)$replayedRun['run_id'],'an identical reconciliation replay converges on the recorded run');
dzn_u_fix_assert(dzn_u_fix_count('finance_reconciliation_runs')===$runsBeforeReplay,'an identical reconciliation replay appends no second run');
dzn_u_fix_assert(dzn_u_fix_count('finance_reconciliation_commands','result_run_id=%d',array((int)$replayedRun['run_id']))===1,'an identical reconciliation replay writes exactly one command row');
dzn_u_fix_refused(fn()=>$reconciliation->run(gmdate('Y-m-d H:i:s',strtotime($periodStart)-86400),$periodEnd,$teacherId,$replayKey),'command_replay_conflict','a materially different replay of one reconciliation key');
dzn_u_fix_assert(dzn_u_fix_count('finance_reconciliation_commands','result_run_id=%d',array((int)$replayedRun['run_id']))===1,'a conflicting replay preserves the recorded run command');
// No tolerance: a one-minor-unit difference is a finding with both exact values.
$line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence LIMIT 1",$statementId));
$wpdb->update($p.'finance_statement_lines',array('line_amount_minor'=>(int)$line->line_amount_minor+1),array('id'=>(int)$line->id));
$tampered=$reconciliation->run($periodStart,$periodEnd,$teacherId,dzn_u_fix_key('run-tampered'));
$tamperedFindings=$reconciliation->findings((int)$tampered['run_id']);
$diff=null;foreach($tamperedFindings['findings'] as $finding)if($finding['finding_code']==='line_amount_differs_from_recomputation')$diff=$finding;
dzn_u_fix_assert($diff!==null&&(int)$diff['observed_amount_minor']-(int)$diff['expected_amount_minor']===1,'a one-minor-unit difference is a finding carrying both exact values');
$wpdb->update($p.'finance_statement_lines',array('line_amount_minor'=>(int)$line->line_amount_minor,'derivation_digest'=>(string)$line->derivation_digest),array('id'=>(int)$line->id));
// A finding code outside the declared vocabulary can never be stored.
dzn_u_fix_assert(!\Delnavazan\Platform\Core\Application\Finance\FinanceRule::findingCode('not_a_declared_finding'),'a finding code outside the declared view is refused by the vocabulary');
// A pending payability is reported, never resolved by a run.
$payability->override((int)$first['lesson_id'],'pending','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-recon-pending','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('recon-pending'));
$pendingRun=$reconciliation->run($periodStart,$periodEnd,$teacherId,dzn_u_fix_key('run-pending'));
$pendingFindings=$reconciliation->findings((int)$pendingRun['run_id']);
dzn_u_fix_assert(in_array('payability_pending',array_column($pendingFindings['findings'],'finding_code'),true),'a pending payability is reported as a blocking finding');
// The read models are read-only visibility.
$crossCheck=(new FinanceReconciliationReadService())->providerEvidenceCrossCheck($periodStart,$periodEnd);
dzn_u_fix_assert(array_key_exists('attempted_without_result',$crossCheck)&&$crossCheck['asserts_finance_authority']===false,'the provider cross-check is visibility only');
// resolveException takes the root its own target scope selects, and moves only the exception row.
$teacherExceptionId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_exceptions WHERE state='open' AND teacher_id=%d ORDER BY id LIMIT 1",$teacherId));
if($teacherExceptionId<1){
    $teacherExceptionId=\Delnavazan\Platform\Core\Application\Finance\FinanceSupport::exception('snapshot_missing_for_lesson','A synthetic teacher-scoped exception',array('teacher_id'=>$teacherId),1,gmdate('Y-m-d H:i:s'),null);
}
$resolved=$reconciliation->resolveException($teacherExceptionId,array('resolution_note'=>'checked'),dzn_u_fix_key('resolve-teacher'));
dzn_u_fix_assert((string)$resolved['state']==='resolved','resolveException moves its target exception');
$resolveCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_reconciliation_commands WHERE exception_id=%d ORDER BY id DESC LIMIT 1",$teacherExceptionId));
dzn_u_fix_assert($resolveCommand!==null&&(int)$resolveCommand->result_exception_id===$teacherExceptionId&&(int)$resolveCommand->teacher_id===$teacherId,'a teacher-scoped resolution names its typed selector and result with the target\'s own Teacher');
// A teacher-less exception (a policy-command refusal) resolves under the shared global policy root.
try{$policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>(string)$wpdb->get_var("SELECT MIN(snapshot_instant_utc) FROM {$p}finance_lesson_snapshots"),'evidence_channel'=>'staff_record','evidence_reference'=>'u-recon-policy-refusal','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('recon-policy-refusal'));}catch(Throwable$e){dzn_u_fix_assert($e->getMessage()==='policy_effective_from_precedes_recorded_consumption','the teacher-less refusal probe must be the admissibility refusal');}
$teacherlessId=(int)$wpdb->get_var("SELECT id FROM {$p}finance_exceptions WHERE teacher_id IS NULL AND state='open' ORDER BY id DESC LIMIT 1");
dzn_u_fix_assert($teacherlessId>0,'a policy-command refusal records a teacher-less exception');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_teacher_roots WHERE teacher_id=0"))===0,'a teacher-less command never invents a Teacher root');
$resolvedTeacherless=$reconciliation->resolveException($teacherlessId,array('resolution_note'=>'policy reviewed'),dzn_u_fix_key('resolve-teacherless'));
dzn_u_fix_assert((string)$resolvedTeacherless['state']==='resolved','a teacher-less exception resolves under the shared global policy root');
$teacherlessCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_reconciliation_commands WHERE exception_id=%d AND teacher_id IS NULL ORDER BY id DESC LIMIT 1",$teacherlessId));
dzn_u_fix_assert($teacherlessCommand!==null&&(int)$teacherlessCommand->result_exception_id===$teacherlessId,'a teacher-less resolution records a NULL teacher scope');
echo "phase-2a2u-reconciliation-runtime: OK (runs, exact differences, no repair, root-selected exception resolution)\n";
