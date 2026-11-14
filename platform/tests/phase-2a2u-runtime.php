<?php
/**
 * Disposable production-path Phase-U authority proof: effective-dated rate lifecycle and interval
 * resolution, the policy model and its §6.3 temporal admissibility guard, the per-Lesson snapshot
 * capture matrix and the audited payability override. Synthetic local data only; no provider, no
 * network and no production environment.
 */
if(getenv('DZN_PHASE_2A2U_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,FinanceRule,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
dzn_u_fix_reset();
$rates=new TeacherRateService();$policies=new FinancePolicyService();$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();
$chain=dzn_u_fix_chain($fixture);
$teacherId=(int)$chain['teacher_id'];$courseId=(int)$chain['course_id'];
$now=time();

// §7 rate lifecycle and interval resolution. The successor's effective instant is deliberately in the
// near future so the captured occurrences fall inside the predecessor's interval.
$base=dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now-172800),'compensation_basis'=>'per_session'),'base');
$successorFrom=gmdate('Y-m-d H:i:s',$now+600);
$successor=dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>15000,'currency'=>'AUD','effective_from'=>$successorFrom,'compensation_basis'=>'per_session'),'successor');
$baseRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rates WHERE id=%d",(int)$base['rate_id']));
dzn_u_fix_assert((string)$baseRow->status==='superseded'&&$baseRow->active_slot===null,'a successor must close and supersede its predecessor');
dzn_u_fix_assert((string)$baseRow->effective_until===$successorFrom,'the successor must close the predecessor at exactly its own effective instant');
$successorRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rates WHERE id=%d",(int)$successor['rate_id']));
dzn_u_fix_assert((int)$successorRow->active_slot===1&&(string)$successorRow->status==='active','the successor must hold the scope\'s live slot');
// §7.2/§15.3: the successor takes the slot only after its predecessor released it, and the record command
// row records its declared success state and names the predecessor it superseded.
dzn_u_fix_assert(dzn_u_fix_count('finance_teacher_rates','teacher_id=%d AND scope_kind=\'teacher\' AND active_slot=1',array($teacherId))===1,'exactly one live rate row per scope after a succession');
$rateCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rate_commands WHERE result_rate_id=%d AND operation='record' ORDER BY id DESC LIMIT 1",(int)$successor['rate_id']));
dzn_u_fix_assert($rateCommand!==null&&(string)$rateCommand->result_state===FinanceRule::commandSuccessState('record'),'a recorded rate command row carries its declared success state');
dzn_u_fix_assert((int)$rateCommand->rate_id===(int)$base['rate_id'],'the successor\'s command row names the predecessor it superseded');
dzn_u_fix_assert((int)$rates->resolveFor($teacherId,$courseId,$now+1200)['rate']->id===(int)$successor['rate_id'],'an instant at or after the successor resolves to it');
// (a) a delayed capture resolves the interval that covers its own locked instant, even though the
// predecessor is superseded and the successor is the scope's live row.
$historical=dzn_u_fix_occurrence($chain,$fixture,'historical',2,30);
dzn_u_fix_assert((string)$historical['starts_at_utc']<$successorFrom,'the captured instant must lie inside the predecessor interval');
dzn_u_fix_assert((string)$historical['starts_at_utc']>gmdate('Y-m-d H:i:s',$now-172800),'the captured instant must be covered by the predecessor');
dzn_u_fix_complete($historical,'historical');
$captured=dzn_u_fix_accepted(fn()=>$snapshots->capture((int)$historical['lesson_id'],dzn_u_fix_key('capture-historical')),'a delayed capture');
dzn_u_fix_assert((int)$captured['rate_id']===(int)$base['rate_id']&&(int)$captured['rate_version']===(int)$base['rate_version'],'the capture must resolve the interval that covers its own instant');
dzn_u_fix_assert((int)$captured['derived_amount_minor']===12000,'the per-session derived amount is the exact recorded rate amount');
// (b) a future-effective successor is safe: an instant before it still resolves to its predecessor.
$future=dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>18000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now+172800),'compensation_basis'=>'per_session'),'future');
dzn_u_fix_assert((int)$rates->resolveFor($teacherId,$courseId,$now+3600)['rate']->id===(int)$successor['rate_id'],'an instant before a future successor resolves to its predecessor');
dzn_u_fix_assert((int)$rates->resolveFor($teacherId,$courseId,$now+259200)['rate']->id===(int)$future['rate_id'],'an instant at or after a future successor resolves to it');
// A rate referenced by a snapshot can never be withdrawn; a superseded, unreferenced row can.
dzn_u_fix_refused(fn()=>$rates->withdraw((int)$base['rate_id'],array('reason_code'=>'operator_recorded_error','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-1','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-1')),'rate_referenced_by_snapshot','a rate a snapshot references');
$retired=$rates->withdraw((int)$successor['rate_id'],array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-2'));
$retiredRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rates WHERE id=%d",(int)$retired['rate_id']));
dzn_u_fix_assert((string)$retiredRow->status==='withdrawn'&&$retiredRow->active_slot===null&&$retiredRow->effective_until!==null,'a withdrawn rate releases its live slot and always carries a closed interval');
$withdrawnScope=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rate_events WHERE rate_id=%d ORDER BY event_sequence",(int)$retired['rate_id']));
dzn_u_fix_assert($withdrawnScope!==null,'a status move always writes its own event row');
// (c) an instant covered only by a withdrawn row has no rate at all, and the blocker is a recorded gap.
$coverage=$rates->resolveFor($teacherId,$courseId,$now+3600);
dzn_u_fix_assert(!$coverage['resolved']&&$coverage['reason']==='rate_missing_for_lesson'&&$coverage['gap'],'a withdrawn interval must resolve to no rate and a recorded gap');
// (c2) a withdrawn course-scoped row blocks its instant and never falls back to a broader active row.
$second=dzn_u_fix_chain($fixture);
$courseScopeRate=dzn_u_fix_rate((int)$second['teacher_id'],array('scope_kind'=>'teacher_course','course_scope_id'=>(int)$second['course_id'],'amount_minor'=>20000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now-7200),'compensation_basis'=>'per_session'),'course-scope');
$broadRate=dzn_u_fix_rate((int)$second['teacher_id'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>9000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now-7200),'compensation_basis'=>'per_session'),'broad');
$rates->withdraw((int)$courseScopeRate['rate_id'],array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-3','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-3'));
$blocked=$rates->resolveFor((int)$second['teacher_id'],(int)$second['course_id'],$now-3600);
dzn_u_fix_assert(!$blocked['resolved']&&$blocked['gap'],'a withdrawn course-scoped row must block its instant and never fall back to the broader teacher-scoped row');
dzn_u_fix_assert((int)$broadRate['rate_id']>0,'the broader active rate must exist for the fallback test to be meaningful');
// The rate registry's own consumption guard: no rate may be recorded into a consumed snapshot instant.
$otherCourse=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}courses WHERE id<>%d ORDER BY id LIMIT 1",$courseId));
dzn_u_fix_assert($otherCourse>0,'a second Course is required for the free-scope guard probe');
dzn_u_fix_refused(fn()=>dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher_course','course_scope_id'=>$otherCourse,'amount_minor'=>1,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',$now+1),'compensation_basis'=>'per_session'),'pre-snapshot'),'rate_effective_from_precedes_snapshot','a rate recorded into an instant a committed snapshot already consumed');

// §6 policy model: the seeded defaults, the unseeded timezone key, resolution by covered instant, and
// the §6.3 temporal admissibility guard against a recorded dependent fact.
$intro=$policies->resolve('INTRO_PAYABILITY_POLICY',gmdate('Y-m-d H:i:s',$now-172800));
dzn_u_fix_assert($intro['set']&&$intro['value']==='non_payable'&&(int)$intro['version']===1,'the seeded intro default must resolve as recorded');
$timezone=$policies->resolve('FINANCE_STATEMENT_TIMEZONE',gmdate('Y-m-d H:i:s'));
dzn_u_fix_assert(!$timezone['set']&&$timezone['version']===null,'the unseeded timezone key must resolve to "never set" rather than to any row');
$recorded=$policies->record('STUDENT_NO_SHOW_COMPENSATION_POLICY',array('policy_value'=>'non_payable','value_type'=>'policy_reference','effective_from'=>gmdate('Y-m-d H:i:s',$now+600),'evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-1','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-1'));
dzn_u_fix_assert((int)$recorded['policy_version']===2,'a recorded policy version is the key\'s next version');
$stillPredecessor=$policies->resolve('STUDENT_NO_SHOW_COMPENSATION_POLICY',gmdate('Y-m-d H:i:s',$now-3600));
dzn_u_fix_assert($stillPredecessor['set']&&$stillPredecessor['value']==='payable'&&(int)$stillPredecessor['version']===1,'an instant covered by the predecessor still resolves to it');
dzn_u_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}finance_policies WHERE id=%d",(int)$recorded['policy_id']))==='active','the recorded successor must be active');
// §6.2: `record()` inserts one version and moves nothing; the predecessor's conditional status move is
// the separate, audited `supersede()` command, which therefore keeps its own command and audit evidence.
dzn_u_fix_assert((string)$wpdb->get_var("SELECT status FROM {$p}finance_policies WHERE policy_key='STUDENT_NO_SHOW_COMPENSATION_POLICY' AND policy_version=1")==='active','record() performs no status move of the version it replaces');
$recordPolicyCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_policy_commands WHERE operation='record' AND result_policy_id=%d ORDER BY id DESC LIMIT 1",(int)$recorded['policy_id']));
dzn_u_fix_assert($recordPolicyCommand!==null&&(string)$recordPolicyCommand->result_state==='recorded','a recorded policy command carries its declared success state');
dzn_u_fix_assert((int)$recordPolicyCommand->policy_id===(int)$recorded['policy_id'],'a recorded policy command names the version it wrote');
$superseded=$policies->supersede('STUDENT_NO_SHOW_COMPENSATION_POLICY',1,array(),dzn_u_fix_key('policy-supersede'));
dzn_u_fix_assert((int)$superseded['policy_id']>0&&$superseded['superseded'],'the explicit supersede command reports its conditional move');
dzn_u_fix_assert((string)$wpdb->get_var("SELECT status FROM {$p}finance_policies WHERE policy_key='STUDENT_NO_SHOW_COMPENSATION_POLICY' AND policy_version=1")==='superseded','the explicit supersede command performs the conditional status move');
$supersedePolicyCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_policy_commands WHERE operation='supersede' AND policy_id=%d ORDER BY id DESC LIMIT 1",(int)$superseded['policy_id']));
dzn_u_fix_assert($supersedePolicyCommand!==null&&(string)$supersedePolicyCommand->result_state==='superseded'&&(int)$supersedePolicyCommand->result_policy_id===(int)$superseded['policy_id'],'the supersede command records its own result state and typed result');
dzn_u_fix_refused(fn()=>$policies->supersede('STUDENT_NO_SHOW_COMPENSATION_POLICY',1,array(),dzn_u_fix_key('policy-supersede-again')),'finance_policy_version_conflict','a second supersession of one policy row');
$policies->withdraw('STUDENT_NO_SHOW_COMPENSATION_POLICY',2,array('reason_code'=>'operator_recorded_error','evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-withdraw','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-withdraw'));
$retracted=$policies->resolve('STUDENT_NO_SHOW_COMPENSATION_POLICY',gmdate('Y-m-d H:i:s',$now+900));
dzn_u_fix_assert(!$retracted['set']&&(int)$retracted['version']===2&&$retracted['withdrawn'],'a retracted version resolves to unset and reports the version it retracted');
$consumedInstant=(string)$wpdb->get_var($wpdb->prepare("SELECT snapshot_instant_utc FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$historical['lesson_id']));
dzn_u_fix_refused(fn()=>$policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>$consumedInstant,'evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-backdated','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-backdated')),'policy_effective_from_precedes_recorded_consumption','a policy version recorded into an instant a recorded snapshot consumed');
dzn_u_fix_assert(dzn_u_fix_count('finance_policies','policy_key=%s AND policy_version=%d',array('INTRO_PAYABILITY_POLICY',2))===0,'a refused record writes no version');
dzn_u_fix_assert((string)$wpdb->get_var("SELECT status FROM {$p}finance_policies WHERE policy_key='INTRO_PAYABILITY_POLICY' AND policy_version=1")==='active','a refused record supersedes no predecessor');
$command=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_policy_commands WHERE reason_code=%s ORDER BY id DESC LIMIT 1",'policy_effective_from_precedes_recorded_consumption'));
dzn_u_fix_assert($command!==null&&(string)$command->result_state==='refused'&&$command->result_policy_id===null,'a refused policy record commits its refused command row with a NULL typed result');
dzn_u_fix_assert(dzn_u_fix_count('finance_exceptions','reason_code=%s',array('policy_effective_from_precedes_recorded_consumption'))===1,'a refused policy record commits its teacher-less exception');
$control=$policies->record('INTRO_PAYABILITY_POLICY',array('policy_value'=>'payable','value_type'=>'policy_reference','effective_from'=>gmdate('Y-m-d H:i:s',$now+3600),'evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-control','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-control'));
dzn_u_fix_assert((int)$control['policy_version']===2,'the admissible control record must succeed');
$backdated=dzn_u_fix_accepted(fn()=>$policies->record('INTERRUPTION_COMPENSATION_POLICY',array('policy_value'=>'non_payable','value_type'=>'policy_reference','effective_from'=>gmdate('Y-m-d H:i:s',$now-604800),'evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-backdated-free','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-backdated-free')),'a back-dated version recorded where nothing has been consumed');
dzn_u_fix_assert((int)$backdated['policy_version']===2&&$policies->resolve('INTERRUPTION_COMPENSATION_POLICY',gmdate('Y-m-d H:i:s',$now-604800))['value']==='non_payable','a back-dated admissible version resolves for its own interval');
$missingKey=null;try{$policies->record('MAX_STATEMENT_PERIOD_DAYS',array('policy_value'=>'62','value_type'=>'policy_reference','effective_from'=>gmdate('Y-m-d H:i:s',$now+7200),'evidence_channel'=>'staff_record','evidence_reference'=>'u-policy-structural','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('policy-structural'));}catch(Throwable$e){$missingKey=$e->getMessage();}
dzn_u_fix_assert($missingKey==='finance_policy_key_not_allowed','a structural finance invariant is not a configurable policy');

// §8/§9 the snapshot capture and payability matrix over real canonical facts.
$cases=array(
    array('delivered','delivered','payable','delivered_occurrence'),
    array('student_no_show','student_no_show','payable','student_no_show'),
    array('interruption','interruption','payable','interruption'),
    array('teacher_non_delivery','teacher_non_delivery','non_payable','teacher_non_delivery'),
);
foreach($cases as $case){
    list($label,$outcomeCode,$expectedDisposition,$expectedBasis)=$case;
    $occurrence=dzn_u_fix_occurrence($chain,$fixture,'case-'.$label,2,30);
    dzn_u_fix_outcome($occurrence,$outcomeCode,$label,'authorised');
    $caseCapture=dzn_u_fix_accepted(fn()=>$snapshots->capture((int)$occurrence['lesson_id'],dzn_u_fix_key('capture-'.$label)),'capture of the '.$label.' Lesson');
    dzn_u_fix_assert((int)$caseCapture['snapshot_id']>0&&(int)$caseCapture['derived_amount_minor']===12000,'every snapshotted occurrence records the exact derived amount of its resolved interval');
    $evaluation=dzn_u_fix_accepted(fn()=>$payability->evaluate((int)$occurrence['lesson_id'],dzn_u_fix_key('evaluate-'.$label)),'the '.$label.' derivation');
    dzn_u_fix_assert((string)$evaluation['disposition']===$expectedDisposition&&(string)$evaluation['basis_code']===$expectedBasis,'the '.$label.' derivation must decide '.$expectedDisposition.'/'.$expectedBasis);
    $converged=$snapshots->capture((int)$occurrence['lesson_id'],dzn_u_fix_key('capture-again-'.$label));
    dzn_u_fix_assert((int)$converged['snapshot_id']===(int)$caseCapture['snapshot_id'],'an identical capture must converge on the recorded snapshot');
    dzn_u_fix_assert(dzn_u_fix_count('finance_lesson_snapshots','lesson_id=%d',array((int)$occurrence['lesson_id']))===1,'exactly one snapshot exists per Lesson');
}
// §15.3: a successful command row carries its declared success state and its typed result, because every
// command table's `result_state` is NOT NULL and an omitted state would roll the command back at insertion.
$captureCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_snapshot_commands WHERE operation='capture' AND result_snapshot_id=%d ORDER BY id DESC LIMIT 1",(int)$caseCapture['snapshot_id']));
dzn_u_fix_assert($captureCommand!==null&&(string)$captureCommand->result_state===FinanceRule::commandSuccessState('capture'),'a successful capture command row carries its declared success state');
$evaluationCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_payability_commands WHERE operation='evaluate' AND result_evaluation_id=%d ORDER BY id DESC LIMIT 1",(int)$evaluation['evaluation_id']));
dzn_u_fix_assert($evaluationCommand!==null&&(string)$evaluationCommand->result_state===FinanceRule::commandSuccessState('evaluate')&&(int)$evaluationCommand->result_evaluation_id===(int)$evaluation['evaluation_id'],'a successful evaluation command row carries its declared success state and typed result');
// An unresolved Phase-O review blocks finalisation, so the plan is never snapshotted: the derivation's
// pending state is reached through the corrupted-provenance guard or an audited override, never by
// pricing a plan.
$review=dzn_u_fix_occurrence($chain,$fixture,'review',2,30);
dzn_u_fix_outcome($review,'review_required','review','authorised');
dzn_u_fix_refused(fn()=>$snapshots->capture((int)$review['lesson_id'],dzn_u_fix_key('capture-review')),'snapshot_lesson_not_finalised','a Lesson whose review_required outcome blocks finalisation');
dzn_u_fix_assert(dzn_u_fix_count('finance_lesson_snapshots','lesson_id=%d',array((int)$review['lesson_id']))===0,'a refused capture writes no snapshot');
// A cancelled-before-occurrence Lesson is non-payable; an academy obligation outranks delivery facts.
$cancelled=dzn_u_fix_occurrence($chain,$fixture,'cancelled',2,30);
(new CanonicalLessonAuthorityService())->cancel((int)$cancelled['lesson_id'],'authorised',dzn_u_fix_evidence('u-cancel'),dzn_u_fix_key('cancel'));
$snapshots->capture((int)$cancelled['lesson_id'],dzn_u_fix_key('capture-cancelled'));
$cancelledEvaluation=$payability->evaluate((int)$cancelled['lesson_id'],dzn_u_fix_key('evaluate-cancelled'));
dzn_u_fix_assert((string)$cancelledEvaluation['disposition']==='non_payable'&&(string)$cancelledEvaluation['basis_code']==='occurrence_not_attempted','a cancelled occurrence that was never attempted is non-payable');
// A replacement Lesson is an ordinary compensable occurrence.
$origin=dzn_u_fix_occurrence($chain,$fixture,'origin',2,30);
(new CanonicalLessonAuthorityService())->cancel((int)$origin['lesson_id'],'authorised',dzn_u_fix_evidence('u-origin-cancel')+array('reason_code'=>'attested_non_delivery'),dzn_u_fix_key('origin-cancel'));
$replacementId=(int)(new CanonicalLessonAuthorityService())->createReplacement((int)$chain['term_id'],(int)$chain['assignment_id'],(int)$origin['lesson_id'],dzn_u_fix_evidence('u-replacement'),dzn_u_fix_key('replacement'))['lesson_id'];
$replacementRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$replacementId));
dzn_u_fix_assert((string)$replacementRow->lesson_type==='replacement','the replacement Lesson fixture must exist');
// An introductory Lesson is snapshotted with its intro policy pair and is non-payable under the default.
$introOccurrence=dzn_u_fix_intro($fixture,'intro',2,30);
dzn_u_fix_rate((int)$introOccurrence['teacher_id'],array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>11000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-7200),'compensation_basis'=>'per_session'),'intro-rate');
$introCapture=$snapshots->capture((int)$introOccurrence['lesson_id'],dzn_u_fix_key('capture-intro'));
$introPair=$wpdb->get_row($wpdb->prepare("SELECT intro_policy_key,intro_policy_version FROM {$p}finance_lesson_snapshots WHERE id=%d",(int)$introCapture['snapshot_id']));
dzn_u_fix_assert((string)$introPair->intro_policy_key==='INTRO_PAYABILITY_POLICY'&&(int)$introPair->intro_policy_version===1,'the snapshot records the exact intro policy version that covered its instant');
$introEvaluation=$payability->evaluate((int)$introOccurrence['lesson_id'],dzn_u_fix_key('evaluate-intro'));
dzn_u_fix_assert((string)$introEvaluation['disposition']==='non_payable'&&(string)$introEvaluation['basis_code']==='introductory_policy_non_payable','an introductory Lesson is non-payable under the recorded default');
// A Lesson whose Teacher holds no effective rate records the blocker and writes no snapshot.
$bareChain=dzn_u_fix_chain($fixture);
$bare=dzn_u_fix_occurrence($bareChain,$fixture,'no-rate',2,30);
dzn_u_fix_complete($bare,'no-rate');
dzn_u_fix_refused(fn()=>$snapshots->capture((int)$bare['lesson_id'],dzn_u_fix_key('capture-no-rate')),'rate_missing_for_lesson','a Lesson whose Teacher has no effective rate');
dzn_u_fix_assert(dzn_u_fix_count('finance_lesson_snapshots','lesson_id=%d',array((int)$bare['lesson_id']))===0,'a missing rate writes no snapshot');
dzn_u_fix_assert(dzn_u_fix_count('finance_exceptions','reason_code=%s',array('rate_missing_for_lesson'))>0,'a missing rate records its durable blocker');
// §15.8: a refusal commits the refused command row — the one declared refusal state, its exact reason code
// and a NULL typed result — while the attempted mutation leaves nothing behind.
$refusedCaptureCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_snapshot_commands WHERE lesson_id=%d AND result_state=%s ORDER BY id DESC LIMIT 1",(int)$bare['lesson_id'],FinanceRule::COMMAND_REFUSAL_STATE));
dzn_u_fix_assert($refusedCaptureCommand!==null&&$refusedCaptureCommand->result_snapshot_id===null&&$refusedCaptureCommand->result_correction_id===null,'a refused capture commits a refused command row with NULL typed results');
dzn_u_fix_assert((string)$refusedCaptureCommand->reason_code==='rate_missing_for_lesson','a refused command row records the exact reason code');
// A conflicting replay is refused and preserves the recorded fact.
$noShowLesson=(int)$wpdb->get_var("SELECT lesson_id FROM {$p}finance_payability_evaluations WHERE basis_code='student_no_show' ORDER BY id LIMIT 1");
dzn_u_fix_assert($noShowLesson>0,'the student_no_show evaluation fixture must exist');
$deliveredLesson=(int)$wpdb->get_var("SELECT lesson_id FROM {$p}finance_payability_evaluations WHERE basis_code='delivered_occurrence' ORDER BY id LIMIT 1");
$sharedKey=dzn_u_fix_key('replay-conflict');
$payability->evaluate($deliveredLesson,$sharedKey);
dzn_u_fix_refused(fn()=>$payability->evaluate($noShowLesson,$sharedKey),'command_replay_conflict','a materially different replay of one idempotency key');
$originalDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT command_key_digest FROM {$p}finance_payability_commands WHERE lesson_id=%d ORDER BY id DESC LIMIT 1",$deliveredLesson));
dzn_u_fix_assert($originalDigest!==''&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_payability_commands WHERE command_key_digest=%s",$originalDigest))===1,'the original command record is preserved after a conflicting replay');
// An audited override appends a new evaluation, leaves the derivation intact, and is reported.
$override=$payability->override($noShowLesson,'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-override','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('override'));
dzn_u_fix_assert((int)$override['override_id']>0&&(int)$override['evaluation_id']>0,'an override appends a new evaluation carrying its override row');
dzn_u_fix_assert(dzn_u_fix_count('finance_payability_evaluations','lesson_id=%d',array($noShowLesson))>=2,'an override appends history rather than editing it');
$effective=$payability->effective($noShowLesson);
dzn_u_fix_assert((string)$effective['disposition']==='non_payable'&&(int)$effective['override_id']===(int)$override['override_id'],'the effective payability is the override evaluation');
// §15.4: a later re-derivation appends one new evaluation, releases the previous row's one applicable slot
// and claims it for the successor — the declared `UNIQUE lesson_applicable` admits exactly one non-NULL slot,
// so the append must never pre-claim the slot of the row it replaces.
$beforeReEvaluation=dzn_u_fix_count('finance_payability_evaluations','lesson_id=%d',array($noShowLesson));
$reEvaluated=$payability->evaluate($noShowLesson,dzn_u_fix_key('re-evaluate'));
dzn_u_fix_assert((int)$reEvaluated['evaluation_id']!==(int)$override['evaluation_id']&&$reEvaluated['appended'],'a re-derivation after an override appends a new evaluation');
dzn_u_fix_assert(dzn_u_fix_count('finance_payability_evaluations','lesson_id=%d',array($noShowLesson))===$beforeReEvaluation+1,'a re-derivation appends exactly one evaluation row');
dzn_u_fix_assert(dzn_u_fix_count('finance_payability_evaluations','lesson_id=%d AND applicable_slot=1',array($noShowLesson))===1,'exactly one applicable evaluation survives a re-derivation');
$replacedEvaluation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_payability_evaluations WHERE id=%d",(int)$override['evaluation_id']));
dzn_u_fix_assert($replacedEvaluation->applicable_slot===null&&(int)$replacedEvaluation->superseded_by_evaluation_id===(int)$reEvaluated['evaluation_id'],'the replaced evaluation releases its slot and names its successor');
$reEffective=$payability->effective($noShowLesson);
dzn_u_fix_assert((int)$reEffective['evaluation_id']===(int)$reEvaluated['evaluation_id']&&$reEffective['override_id']===null,'the chain validator proves the re-derived evaluation as effective');
// Every recorded command row of all six command tables carries one of the declared result states.
foreach(array('finance_policy_commands','finance_teacher_rate_commands','finance_snapshot_commands','finance_payability_commands','finance_statement_commands','finance_reconciliation_commands') as $commandTable){
    dzn_u_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$commandTable} WHERE result_state IS NULL OR result_state=''")===0,'no '.$commandTable.' row may omit its result_state');
    foreach((array)$wpdb->get_col("SELECT DISTINCT result_state FROM {$p}{$commandTable}") as $recordedState)dzn_u_fix_assert(FinanceRule::commandResultState((string)$recordedState),'a recorded '.$commandTable.' state is a declared member: '.(string)$recordedState);
}
echo "phase-2a2u-runtime: OK (rate lifecycle, interval resolution, policy guard, snapshot matrix, override, command result states)\n";
