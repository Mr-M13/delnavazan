<?php
/** Disposable Phase-U concurrency pre-state builder. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2U_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U concurrency setup refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,FinanceReconciliationService,FinanceSupport,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService,TeacherStatementService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2U_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$rates=new TeacherRateService();$statements=new TeacherStatementService();$reconciliation=new FinanceReconciliationService();$policies=new FinancePolicyService();
$first=dzn_u_fix_chain($fixture);
$second=dzn_u_fix_chain($fixture);
$now=time();
$rateA=dzn_u_fix_rate((int)$first['teacher_id'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now-7200),'compensation_basis'=>'per_session'),'conc-a');
$rateB=dzn_u_fix_rate((int)$second['teacher_id'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now-7200),'compensation_basis'=>'per_session'),'conc-b');
$lessonA=dzn_u_fix_occurrence($first,$fixture,'conc-a',2,60);
$lessonB=dzn_u_fix_occurrence($second,$fixture,'conc-b',2,60);
$snapshots->capture((int)$lessonA['lesson_id'],dzn_u_fix_key('conc-cap-a'));
$snapshots->capture((int)$lessonB['lesson_id'],dzn_u_fix_key('conc-cap-b'));
$payability->evaluate((int)$lessonA['lesson_id'],dzn_u_fix_key('conc-eval-a'));
$payability->evaluate((int)$lessonB['lesson_id'],dzn_u_fix_key('conc-eval-b'));
$periodStart=gmdate('Y-m-d H:i:s',strtotime((string)$lessonA['starts_at_utc'])-3600);
$periodEnd=gmdate('Y-m-d H:i:s',max(strtotime((string)$lessonA['ends_at_utc']),strtotime((string)$lessonB['ends_at_utc']))+60);
dzn_u_fix_settle(array($lessonA,$lessonB));
$policies->record('FINANCE_STATEMENT_TIMEZONE',array('policy_value'=>'UTC','value_type'=>'timezone','effective_from'=>gmdate('Y-m-d H:i:s',$now-7200),'evidence_channel'=>'staff_record','evidence_reference'=>'u-conc-timezone','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('conc-timezone'));
$statement=$statements->draft((int)$first['teacher_id'],$periodStart,$periodEnd,dzn_u_fix_key('conc-draft'));
$issued=$statements->issue((int)$statement['statement_id'],dzn_u_fix_key('conc-issue'));
$teacherException=FinanceSupport::exception('snapshot_missing_for_lesson','Concurrency fixture: teacher-scoped exception',array('teacher_id'=>(int)$first['teacher_id']),1,gmdate('Y-m-d H:i:s'),null);
$teacherless=FinanceSupport::exception('policy_effective_from_precedes_recorded_consumption','Concurrency fixture: teacher-less exception',array(),1,gmdate('Y-m-d H:i:s'),null);
// A policy key nothing has consumed yet, for the policy races.
$state=array(
    'mode'=>$mode,
    'teacher_a'=>(int)$first['teacher_id'],'course_a'=>(int)$first['course_id'],
    'teacher_b'=>(int)$second['teacher_id'],'course_b'=>(int)$second['course_id'],
    'lesson_a'=>(int)$lessonA['lesson_id'],'lesson_b'=>(int)$lessonB['lesson_id'],
    'rate_a'=>(int)$rateA['rate_id'],'rate_b'=>(int)$rateB['rate_id'],
    'statement_a'=>(int)$statement['statement_id'],'issued_a'=>(int)$issued['statement_id'],
    'period_start'=>$periodStart,'period_end'=>$periodEnd,
    'exception_teacher'=>$teacherException,'exception_teacherless'=>$teacherless,
);
update_option('dzn_phase_2a2u_concurrency_state',$state,false);
fwrite(STDOUT,"phase-2a2u-concurrency-setup: OK (".$mode.")\n");
