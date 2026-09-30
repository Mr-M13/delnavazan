<?php
/**
 * Disposable Phase-U corruption proof: every declared corrupt Finance shape fails closed through its
 * owning validator, is never silently repaired, and converges after exact restoration — and every
 * command family's §15.3 replay re-loads and re-proves its recorded typed result before it converges.
 * Synthetic local data only.
 */
if(getenv('DZN_PHASE_2A2U_CORRUPTION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U corruption runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\Finance\{FinanceCorrectionService,FinancePolicyService,FinanceReconciliationService,LessonFinanceSnapshotService,LessonPayabilityService,TeacherRateService,TeacherStatementService};
use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceRateIntegrity,FinanceSnapshotIntegrity,FinanceStatementIntegrity};
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$statements=new TeacherStatementService();$rates=new TeacherRateService();$policies=new FinancePolicyService();$corrections=new FinanceCorrectionService();$reconciliation=new FinanceReconciliationService();
$chain=dzn_u_fix_chain($fixture);
$teacherId=(int)$chain['teacher_id'];
// One-minute occurrences keep the elapsed-period wait short. The canonical occurrence helper releases
// the schedule version, so the Lesson's own recorded delivery outcome is what anchors a capture: each
// occurrence is ended, delivered and then completed before anything is captured.
$lesson=dzn_u_fix_occurrence($chain,$fixture,'corrupt-1',2,1);
$other=dzn_u_fix_occurrence($chain,$fixture,'corrupt-2',2,1);
$replay=dzn_u_fix_occurrence($chain,$fixture,'replay-1',2,1);
$rate=dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-3600),'compensation_basis'=>'per_session'),'corrupt');
dzn_u_fix_settle(array($lesson,$other,$replay));
foreach(array(array($lesson,'corrupt-1'),array($other,'corrupt-2'),array($replay,'replay-1')) as $pair){
    dzn_u_fix_outcome($pair[0],'delivered',$pair[1]);
    dzn_u_fix_complete($pair[0],$pair[1]);
}
$snapshots->capture((int)$lesson['lesson_id'],dzn_u_fix_key('cap-1'));
$snapshots->capture((int)$other['lesson_id'],dzn_u_fix_key('cap-2'));
$evaluation=$payability->evaluate((int)$lesson['lesson_id'],dzn_u_fix_key('eval-1'));
$payability->evaluate((int)$other['lesson_id'],dzn_u_fix_key('eval-2'));
$snapshotRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$lesson['lesson_id']));
$evaluationRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_payability_evaluations WHERE id=%d",(int)$evaluation['evaluation_id']));
$failsClosed=static function(callable $call,string $expected,string $message):void{
    $caught=null;try{$call();}catch(Throwable$e){$caught=$e;}
    dzn_u_fix_assert($caught!==null,$message.' was accepted');
    dzn_u_fix_assert(str_contains($caught->getMessage(),$expected),$message.' failed closed with an unexpected code: '.$caught->getMessage());
};

// Two applicable evaluations of one Lesson, and a broken sequence, are refused by the chain validator.
$secondEvaluationId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_payability_evaluations WHERE lesson_id=%d AND applicable_slot IS NULL ORDER BY id LIMIT 1",(int)$other['lesson_id']));
if($secondEvaluationId<1){
    $wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_payability_evaluations (lesson_id,evaluation_sequence,applicable_slot,disposition,basis_code,lesson_kind,snapshot_id,derivation_digest,rule_version,reason_code,recorded_at,recorded_by,created_at,created_by) VALUES (%d,2,NULL,'non_payable','academy_obligation','standard',%d,%s,'finance_payability_v1','operator_decision',%s,1,%s,1)",(int)$other['lesson_id'],(int)$snapshotRow->id,str_repeat('a',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
    $secondEvaluationId=(int)$wpdb->insert_id;
}
$wpdb->update($p.'finance_payability_evaluations',array('applicable_slot'=>1),array('id'=>$secondEvaluationId));
$failsClosed(fn()=>FinancePayabilityIntegrity::effective((int)$other['lesson_id']),'payability_supersession_conflict','two applicable evaluations of one Lesson');
$wpdb->update($p.'finance_payability_evaluations',array('applicable_slot'=>null),array('id'=>$secondEvaluationId));
$originalSequence=(int)$wpdb->get_var($wpdb->prepare("SELECT evaluation_sequence FROM {$p}finance_payability_evaluations WHERE id=%d",$secondEvaluationId));
$wpdb->update($p.'finance_payability_evaluations',array('evaluation_sequence'=>7),array('id'=>$secondEvaluationId));
$failsClosed(fn()=>FinancePayabilityIntegrity::effective((int)$other['lesson_id']),'payability_supersession_conflict','a broken evaluation sequence');
$wpdb->update($p.'finance_payability_evaluations',array('evaluation_sequence'=>$originalSequence),array('id'=>$secondEvaluationId));
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT applicable_slot FROM {$p}finance_payability_evaluations WHERE id=%d",(int)$evaluationRow->id))===1,'exact restoration converges on the applicable row');

// A snapshot whose recorded digest no longer matches its inputs, or which names a missing rate version.
$originalDigest=(string)$snapshotRow->derivation_digest;
$wpdb->update($p.'finance_lesson_snapshots',array('derivation_digest'=>str_repeat('b',64)),array('id'=>(int)$snapshotRow->id));
$failsClosed(fn()=>FinanceSnapshotIntegrity::assertDigest($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_lesson_snapshots WHERE id=%d",(int)$snapshotRow->id))),'snapshot_derivation_mismatch','a tampered snapshot digest');
$wpdb->update($p.'finance_lesson_snapshots',array('derivation_digest'=>$originalDigest),array('id'=>(int)$snapshotRow->id));
$originalRateVersion=(int)$snapshotRow->rate_version;
$wpdb->update($p.'finance_lesson_snapshots',array('rate_version'=>$originalRateVersion+9),array('id'=>(int)$snapshotRow->id));
$failsClosed(fn()=>FinanceSnapshotIntegrity::effective((int)$lesson['lesson_id']),'snapshot_derivation_mismatch','a snapshot naming a rate version that does not exist');
$wpdb->update($p.'finance_lesson_snapshots',array('rate_version'=>$originalRateVersion),array('id'=>(int)$snapshotRow->id));

// A rate scope violation, an overlapping interval, a withdrawn row holding the live slot and a
// non-resolvable live index all fail the rate integrity proof closed.
$originalScope=(string)$wpdb->get_var($wpdb->prepare("SELECT scope_kind FROM {$p}finance_teacher_rates WHERE id=%d",(int)$rate['rate_id']));
$wpdb->update($p.'finance_teacher_rates',array('scope_kind'=>'teacher','course_scope_id'=>$chain['course_id']),array('id'=>(int)$rate['rate_id']));
$failsClosed(fn()=>FinanceRateIntegrity::validate($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rates WHERE id=%d",(int)$rate['rate_id']))),'rate_scope_violation','a teacher-scoped rate carrying a real Course');
$wpdb->update($p.'finance_teacher_rates',array('scope_kind'=>$originalScope,'course_scope_id'=>0),array('id'=>(int)$rate['rate_id']));
$originalStatus=(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}finance_teacher_rates WHERE id=%d",(int)$rate['rate_id']));
$wpdb->update($p.'finance_teacher_rates',array('status'=>'withdrawn'),array('id'=>(int)$rate['rate_id']));
$failsClosed(fn()=>FinanceRateIntegrity::validate($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_teacher_rates WHERE id=%d",(int)$rate['rate_id']))),'teacher_rate_state_not_resolvable','a withdrawn rate keeping its live slot and an open interval');
$wpdb->update($p.'finance_teacher_rates',array('status'=>$originalStatus),array('id'=>(int)$rate['rate_id']));
$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_teacher_rates (uid,teacher_id,scope_kind,course_scope_id,compensation_basis,amount_minor,currency,effective_from,status,rate_version,active_slot,recorded_at,recorded_by,created_at,created_by,updated_at) VALUES (%s,%d,'teacher',0,'per_session',1,'AUD',%s,'superseded',99,NULL,%s,1,%s,1,%s)",wp_generate_uuid4(),$teacherId,gmdate('Y-m-d H:i:s',time()-1800),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$overlapId=(int)$wpdb->insert_id;
$failsClosed(function()use($rates,$teacherId){
    $rows=$rates->timeline($teacherId);
    unset($rows);
    FinanceRateIntegrity::timeline((new Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRateRepository())->ratesForTeacher($teacherId));
},'teacher_rate_timeline_overlap','two overlapping rate intervals in one scope');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_teacher_rates WHERE id=%d",$overlapId));
FinanceRateIntegrity::timeline((new Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRateRepository())->ratesForTeacher($teacherId));

// A statement whose state and issuance evidence disagree, and a line naming a foreign snapshot.
// This period contains no finance-relevant Lesson, so the draft is refused `statement_derivation_mismatch`
// (a recorded business refusal, never a silent empty statement). The statement-shape corruption probes
// below therefore run against whatever statement the period does carry, when one exists.
try{$statements->draft($teacherId,gmdate('Y-m-d H:i:s',time()-7200),gmdate('Y-m-d H:i:s',time()-60),dzn_u_fix_key('corrupt-draft'));}catch(Throwable$e){dzn_u_fix_assert($e->getMessage()==='statement_derivation_mismatch','an empty period must be refused statement_derivation_mismatch, not silently drafted');}
$statementId=(int)($wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_statements WHERE teacher_id=%d ORDER BY id DESC LIMIT 1",$teacherId))??0);
if($statementId>0){
    $originalIssued=$wpdb->get_row($wpdb->prepare("SELECT state,issued_at,issued_by FROM {$p}finance_statements WHERE id=%d",$statementId));
    if((string)$originalIssued->state==='issued'){
        $wpdb->update($p.'finance_statements',array('issued_at'=>null),array('id'=>$statementId));
        $failsClosed(fn()=>FinanceStatementIntegrity::assertTotals($statementId),'statement_derivation_mismatch','an issued statement with no issuance evidence');
        $wpdb->update($p.'finance_statements',array('issued_at'=>$originalIssued->issued_at,'issued_by'=>$originalIssued->issued_by),array('id'=>$statementId));
    }
    $wpdb->update($p.'finance_statements',array('issued_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$statementId,'state'=>'draft'));
    $failsClosed(fn()=>FinanceStatementIntegrity::assertTotals($statementId),'statement_derivation_mismatch','a draft statement carrying issuance evidence');
    $wpdb->update($p.'finance_statements',array('issued_at'=>$originalIssued->issued_at,'issued_by'=>$originalIssued->issued_by),array('id'=>$statementId));
    $line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence LIMIT 1",$statementId));
    if($line){
        $foreignSnapshot=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_lesson_snapshots WHERE lesson_id<>%d LIMIT 1",(int)$line->lesson_id));
        if($foreignSnapshot>0){
            $wpdb->update($p.'finance_statement_lines',array('snapshot_id'=>$foreignSnapshot),array('id'=>(int)$line->id));
            $failsClosed(fn()=>FinanceStatementIntegrity::assertTotals($statementId),'statement_derivation_mismatch','a line naming a snapshot of another Lesson');
            $wpdb->update($p.'finance_statement_lines',array('snapshot_id'=>(int)$line->snapshot_id),array('id'=>(int)$line->id));
        }
    }
    $wpdb->update($p.'finance_statements',array('period_label'=>null),array('id'=>$statementId));
    $failsClosed(fn()=>FinanceStatementIntegrity::assertTimezoneTriple($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",$statementId))),'statement_timezone_representation_invalid','a partially recorded statement timezone triple');
    $wpdb->update($p.'finance_statements',array('period_label'=>'restored'),array('id'=>$statementId));
}

// Corrupt policy and root shapes are refused by the phase verifier, and converge after restoration.
$originalEffective=(string)$wpdb->get_var("SELECT effective_from FROM {$p}finance_policies WHERE policy_key='INTRO_PAYABILITY_POLICY'");
$wpdb->query("UPDATE {$p}finance_policies SET effective_from=NULL WHERE policy_key='INTRO_PAYABILITY_POLICY'");
$failsClosed(fn()=>Migrator::maybe_upgrade(),'Migration verification failed','a policy version with a NULL effective instant');
$wpdb->query($wpdb->prepare("UPDATE {$p}finance_policies SET effective_from=%s WHERE policy_key='INTRO_PAYABILITY_POLICY'",$originalEffective));
$originalValue=(string)$wpdb->get_var("SELECT policy_value FROM {$p}finance_policies WHERE policy_key='INTERRUPTION_COMPENSATION_POLICY'");
$wpdb->query("UPDATE {$p}finance_policies SET policy_value=NULL WHERE policy_key='INTERRUPTION_COMPENSATION_POLICY'");
$failsClosed(fn()=>Migrator::maybe_upgrade(),'Migration verification failed','a null-valued policy version');
$wpdb->query($wpdb->prepare("UPDATE {$p}finance_policies SET policy_value=%s WHERE policy_key='INTERRUPTION_COMPENSATION_POLICY'",$originalValue));
$wpdb->query("DELETE FROM {$p}finance_policy_roots");
$failsClosed(fn()=>Migrator::maybe_upgrade(),'Migration verification failed','a missing global policy root');
$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_policy_roots (root_key,created_at,created_by) VALUES ('finance_policy',%s,NULL)",gmdate('Y-m-d H:i:s')));
Migrator::maybe_upgrade();

// ---- §15.3 replay re-verification, one probe per command family and every typed result shape. -----
// Each probe records one real command, corrupts the typed result row that command recorded, proves the
// identical replay now fails closed instead of returning a recorded id, restores the row exactly, and
// proves the identical replay then converges on the same recorded result. A deleted result row is
// covered by the policy probe, which is the family whose result is a single row with no derivation of
// its own; the other families corrupt exactly the fact their own §15.3 re-derivation reads.
$replayProbe=static function(callable $call,callable $corrupt,callable $restore,string $expected,string $family,int $expectedId,string $idKey)use($failsClosed):void{
    $corrupt();
    $failsClosed($call,$expected,$family.' replay over a corrupt recorded result');
    $restore();
    $converged=$call();
    dzn_u_fix_assert(isset($converged[$idKey])&&(int)$converged[$idKey]===$expectedId,$family.' identical replay converges on the recorded typed result after exact restoration');
};
$replayKey=static fn(string $label):string=>'dzn-2a2u-replay-'.$label.'-'.wp_generate_uuid4();

// Policy (`record`): the recorded version row itself must be re-loaded and re-proved; a deleted row is
// the absent-result case and the payload must still reproduce the command's own recorded facts. The
// probe's effective instant is in the future so it can never race the migration's own seeded instant,
// which the version timeline's strictly-increasing rule would refuse.
$policyKey=$replayKey('policy');
$policyFrom=gmdate('Y-m-d H:i:s',time()+3600);
$policyRecord=$policies->record('STUDENT_NO_SHOW_COMPENSATION_POLICY',array('policy_value'=>'non_payable','value_type'=>'policy_reference','effective_from'=>$policyFrom,'evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-policy','evidence_at'=>gmdate('Y-m-d H:i:s')),$policyKey);
$policyRow=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_policies WHERE id=%d",(int)$policyRecord['policy_id']));
$replayProbe(
    static fn()=>$policies->record('STUDENT_NO_SHOW_COMPENSATION_POLICY',array('policy_value'=>'non_payable','value_type'=>'policy_reference','effective_from'=>$policyFrom,'evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-policy','evidence_at'=>gmdate('Y-m-d H:i:s')),$policyKey),
    static fn()=>$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_policies WHERE id=%d",(int)$policyRecord['policy_id'])),
    static function()use($wpdb,$p,$policyRow):void{dzn_u_fix_assert($wpdb->insert($p.'finance_policies',$policyRow)!==false,'the deleted policy version must restore exactly: '.$wpdb->last_error);},
    'command_replay_conflict','policy',(int)$policyRecord['policy_id'],'policy_id'
);

// Rate (`record`): the recorded row must still pass the §7 integrity proof and still reproduce the
// command's recorded scope, amount, currency, instant and basis. The probe uses the chain's Course scope
// with a future effective instant, so it neither consumes nor disturbs the snapshots already recorded.
$rateReplayKey=$replayKey('rate');
$rateReplayInput=array('scope_kind'=>'teacher_course','course_scope_id'=>(int)$chain['course_id'],'amount_minor'=>13500,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()+3600),'compensation_basis'=>'per_session','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-rate','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rateReplay=$rates->record($teacherId,$rateReplayInput,$rateReplayKey);
$rateReplayAmount=(int)$wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}finance_teacher_rates WHERE id=%d",(int)$rateReplay['rate_id']));
$replayProbe(
    static fn()=>$rates->record($teacherId,$rateReplayInput,$rateReplayKey),
    static fn()=>$wpdb->update($p.'finance_teacher_rates',array('amount_minor'=>$rateReplayAmount+1),array('id'=>(int)$rateReplay['rate_id'])),
    static fn()=>$wpdb->update($p.'finance_teacher_rates',array('amount_minor'=>$rateReplayAmount),array('id'=>(int)$rateReplay['rate_id'])),
    'command_replay_conflict','rate',(int)$rateReplay['rate_id'],'rate_id'
);

// Snapshot (`capture`): the recorded snapshot must still reproduce its derivation digest and still name
// a rate row/version that exists and covers its own locked instant.
$captureReplayKey=$replayKey('capture');
$captureReplay=$snapshots->capture((int)$replay['lesson_id'],$captureReplayKey);
$captureDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT derivation_digest FROM {$p}finance_lesson_snapshots WHERE id=%d",(int)$captureReplay['snapshot_id']));
$replayProbe(
    static fn()=>$snapshots->capture((int)$replay['lesson_id'],$captureReplayKey),
    static fn()=>$wpdb->update($p.'finance_lesson_snapshots',array('derivation_digest'=>str_repeat('b',64)),array('id'=>(int)$captureReplay['snapshot_id'])),
    static fn()=>$wpdb->update($p.'finance_lesson_snapshots',array('derivation_digest'=>$captureDigest),array('id'=>(int)$captureReplay['snapshot_id'])),
    'snapshot_derivation_mismatch','snapshot',(int)$captureReplay['snapshot_id'],'snapshot_id'
);

// Payability (`evaluate`): the recorded evaluation must still reproduce its own §9.1 derivation digest
// and its vocabulary, and must still be bound to a snapshot of its own Lesson.
$evaluateReplayKey=$replayKey('evaluate');
$evaluateReplay=$payability->evaluate((int)$replay['lesson_id'],$evaluateReplayKey);
$evaluateDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT derivation_digest FROM {$p}finance_payability_evaluations WHERE id=%d",(int)$evaluateReplay['evaluation_id']));
$replayProbe(
    static fn()=>$payability->evaluate((int)$replay['lesson_id'],$evaluateReplayKey),
    static fn()=>$wpdb->update($p.'finance_payability_evaluations',array('derivation_digest'=>str_repeat('c',64)),array('id'=>(int)$evaluateReplay['evaluation_id'])),
    static fn()=>$wpdb->update($p.'finance_payability_evaluations',array('derivation_digest'=>$evaluateDigest),array('id'=>(int)$evaluateReplay['evaluation_id'])),
    'upstream_aggregate_invalid','payability',(int)$evaluateReplay['evaluation_id'],'evaluation_id'
);

// Correction (`correct_snapshot`): the recorded correction must still reproduce the exact command payload
// the command recorded — including *every* material correction fact — and, independently, its §12.2
// derivation digest, name a corrected rate row/version that exists and name the digest of the snapshot it
// corrected. The first two probes move one payload fact each (the corrected rate amount, then the corrected
// derived amount), so a materially different correction can only fail closed `command_replay_conflict`; the
// third is the family's own probe, moving a fact the payload does not carry (the prior-snapshot digest) so
// that it exercises the family's own derivation-digest re-proof instead.
$correctionReplayKey=$replayKey('correction');
$correctionReplay=$corrections->correctSnapshot((int)$replay['lesson_id'],array('corrected_rate_id'=>(int)$rate['rate_id'],'corrected_rate_version'=>(int)$rate['rate_version'],'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-correction','evidence_at'=>gmdate('Y-m-d H:i:s')),$correctionReplayKey);
$correctionRateAmount=(int)$wpdb->get_var($wpdb->prepare("SELECT corrected_rate_amount_minor FROM {$p}finance_snapshot_corrections WHERE id=%d",(int)$correctionReplay['correction_id']));
$replayProbe(
    static fn()=>$corrections->correctSnapshot((int)$replay['lesson_id'],array('corrected_rate_id'=>(int)$rate['rate_id'],'corrected_rate_version'=>(int)$rate['rate_version'],'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-correction','evidence_at'=>gmdate('Y-m-d H:i:s')),$correctionReplayKey),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('corrected_rate_amount_minor'=>$correctionRateAmount+1),array('id'=>(int)$correctionReplay['correction_id'])),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('corrected_rate_amount_minor'=>$correctionRateAmount),array('id'=>(int)$correctionReplay['correction_id'])),
    'command_replay_conflict','correction substituted corrected rate amount',(int)$correctionReplay['correction_id'],'correction_id'
);
$correctionAmount=(int)$wpdb->get_var($wpdb->prepare("SELECT corrected_derived_amount_minor FROM {$p}finance_snapshot_corrections WHERE id=%d",(int)$correctionReplay['correction_id']));
$replayProbe(
    static fn()=>$corrections->correctSnapshot((int)$replay['lesson_id'],array('corrected_rate_id'=>(int)$rate['rate_id'],'corrected_rate_version'=>(int)$rate['rate_version'],'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-correction','evidence_at'=>gmdate('Y-m-d H:i:s')),$correctionReplayKey),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('corrected_derived_amount_minor'=>$correctionAmount+1),array('id'=>(int)$correctionReplay['correction_id'])),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('corrected_derived_amount_minor'=>$correctionAmount),array('id'=>(int)$correctionReplay['correction_id'])),
    'command_replay_conflict','correction substituted corrected derived amount',(int)$correctionReplay['correction_id'],'correction_id'
);
$correctionPriorDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT prior_snapshot_digest FROM {$p}finance_snapshot_corrections WHERE id=%d",(int)$correctionReplay['correction_id']));
$replayProbe(
    static fn()=>$corrections->correctSnapshot((int)$replay['lesson_id'],array('corrected_rate_id'=>(int)$rate['rate_id'],'corrected_rate_version'=>(int)$rate['rate_version'],'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-correction','evidence_at'=>gmdate('Y-m-d H:i:s')),$correctionReplayKey),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('prior_snapshot_digest'=>str_repeat('f',64)),array('id'=>(int)$correctionReplay['correction_id'])),
    static fn()=>$wpdb->update($p.'finance_snapshot_corrections',array('prior_snapshot_digest'=>$correctionPriorDigest),array('id'=>(int)$correctionReplay['correction_id'])),
    'snapshot_derivation_mismatch','correction',(int)$correctionReplay['correction_id'],'correction_id'
);

// Statement (`draft`): the recorded statement must still recompute its totals, line set and derivation
// digest exactly, and still carry an all-or-nothing timezone triple.
$statementStart=(string)$replay['starts_at_utc'];
$statementEnd=gmdate('Y-m-d H:i:s',strtotime((string)$replay['ends_at_utc'].' UTC')+1);
$draftReplayKey=$replayKey('draft');
$draftReplay=$statements->draft($teacherId,$statementStart,$statementEnd,$draftReplayKey);
$draftPayable=(int)$wpdb->get_var($wpdb->prepare("SELECT payable_amount_minor FROM {$p}finance_statements WHERE id=%d",(int)$draftReplay['statement_id']));
$replayProbe(
    static fn()=>$statements->draft($teacherId,$statementStart,$statementEnd,$draftReplayKey),
    static fn()=>$wpdb->update($p.'finance_statements',array('payable_amount_minor'=>$draftPayable+1),array('id'=>(int)$draftReplay['statement_id'])),
    static fn()=>$wpdb->update($p.'finance_statements',array('payable_amount_minor'=>$draftPayable),array('id'=>(int)$draftReplay['statement_id'])),
    'statement_totals_mismatch','statement',(int)$draftReplay['statement_id'],'statement_id'
);

// Reconciliation (`run`): the recorded run must still reproduce its period, scope and state, pass the
// §11.2 run/finding proof and reproduce its recorded findings digest.
$runReplayKey=$replayKey('run');
$runReplay=$reconciliation->run($statementStart,$statementEnd,$teacherId,$runReplayKey);
$runDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT findings_digest FROM {$p}finance_reconciliation_runs WHERE id=%d",(int)$runReplay['run_id']));
$replayProbe(
    static fn()=>$reconciliation->run($statementStart,$statementEnd,$teacherId,$runReplayKey),
    static fn()=>$wpdb->update($p.'finance_reconciliation_runs',array('findings_digest'=>str_repeat('d',64)),array('id'=>(int)$runReplay['run_id'])),
    static fn()=>$wpdb->update($p.'finance_reconciliation_runs',array('findings_digest'=>$runDigest),array('id'=>(int)$runReplay['run_id'])),
    'upstream_aggregate_invalid','reconciliation run',(int)$runReplay['run_id'],'run_id'
);

// Payability override (`override`): the recorded override must still name the evaluation it produced.
$overrideReplayKey=$replayKey('override');
$overrideReplay=$payability->override((int)$replay['lesson_id'],'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-override','evidence_at'=>gmdate('Y-m-d H:i:s')),$overrideReplayKey);
$overrideDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT derivation_digest FROM {$p}finance_payability_evaluations WHERE id=%d",(int)$overrideReplay['evaluation_id']));
$replayProbe(
    static fn()=>$payability->override((int)$replay['lesson_id'],'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-override','evidence_at'=>gmdate('Y-m-d H:i:s')),$overrideReplayKey),
    static fn()=>$wpdb->update($p.'finance_payability_evaluations',array('derivation_digest'=>str_repeat('e',64)),array('id'=>(int)$overrideReplay['evaluation_id'])),
    static fn()=>$wpdb->update($p.'finance_payability_evaluations',array('derivation_digest'=>$overrideDigest),array('id'=>(int)$overrideReplay['evaluation_id'])),
    'upstream_aggregate_invalid','payability override',(int)$overrideReplay['evaluation_id'],'evaluation_id'
);

// Exception resolution (`resolve_exception`): the recorded exception must still carry its resolution
// evidence, so a resolution that has been re-opened can never converge as a completed resolution.
$openException=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_exceptions WHERE state='open' AND teacher_id IS NULL AND reason_code=%s ORDER BY id DESC LIMIT 1",'command_replay_conflict'));
dzn_u_fix_assert($openException>0,'a replayed corrupt result records its own open exception evidence');
$resolveReplayKey=$replayKey('resolve');
$resolveReplay=$reconciliation->resolveException($openException,array('resolution_note'=>'replay corruption recorded and restored'),$resolveReplayKey);
$resolveRow=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_exceptions WHERE id=%d",$openException));
$replayProbe(
    static fn()=>$reconciliation->resolveException($openException,array('resolution_note'=>'replay corruption recorded and restored'),$resolveReplayKey),
    static fn()=>$wpdb->update($p.'finance_exceptions',array('state'=>'open','resolved_at'=>null,'resolved_by'=>null),array('id'=>$openException)),
    static fn()=>$wpdb->update($p.'finance_exceptions',array('state'=>$resolveRow['state'],'resolved_at'=>$resolveRow['resolved_at'],'resolved_by'=>$resolveRow['resolved_by']),array('id'=>$openException)),
    'command_replay_conflict','exception resolution',$openException,'exception_id'
);

// ---- §15.3 typed-result shape: a substituted secondary result id can never converge. ----------------
// Each probe below corrupts one *secondary* typed result (or selector) of a recorded command row to point
// at a real, valid row of the same table the operation never records, proves the identical replay fails
// closed instead of returning that unrelated id, restores the command row exactly and proves the identical
// replay then converges on the recorded typed result. The correction probe is the exact substitution the
// review named: a different, self-consistent correction of the same Lesson, snapshot and reason, which its
// own derivation digest accepts, so only the recorded command payload can refuse it.
$correctionReplayInput=array('corrected_rate_id'=>(int)$rate['rate_id'],'corrected_rate_version'=>(int)$rate['rate_version'],'corrected_rate_amount_minor'=>12000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>12000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-correction','evidence_at'=>gmdate('Y-m-d H:i:s'));
$substituteCorrectionKey=$replayKey('correction-substituted');
$substituteCorrection=$corrections->correctSnapshot((int)$replay['lesson_id'],array_merge($correctionReplayInput,array('corrected_derived_amount_minor'=>12001)),$substituteCorrectionKey);
dzn_u_fix_assert((int)$substituteCorrection['correction_id']!==(int)$correctionReplay['correction_id'],'a second correction of one snapshot is a distinct, valid result row');
$replayProbe(
    static fn()=>$corrections->correctSnapshot((int)$replay['lesson_id'],$correctionReplayInput,$correctionReplayKey),
    static fn()=>$wpdb->update($p.'finance_snapshot_commands',array('result_correction_id'=>(int)$substituteCorrection['correction_id']),array('id'=>(int)$correctionReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_snapshot_commands',array('result_correction_id'=>(int)$correctionReplay['correction_id']),array('id'=>(int)$correctionReplay['command_id'])),
    'command_replay_conflict','correction substituted secondary result',(int)$correctionReplay['correction_id'],'correction_id'
);
// A capture records one typed result only: a valid correction id smuggled onto its command row is refused.
$replayProbe(
    static fn()=>$snapshots->capture((int)$replay['lesson_id'],$captureReplayKey),
    static fn()=>$wpdb->update($p.'finance_snapshot_commands',array('result_correction_id'=>(int)$correctionReplay['correction_id']),array('id'=>(int)$captureReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_snapshot_commands',array('result_correction_id'=>null),array('id'=>(int)$captureReplay['command_id'])),
    'command_replay_conflict','capture substituted secondary result',(int)$captureReplay['snapshot_id'],'snapshot_id'
);
// A derivation records one typed result only: a valid override id smuggled onto its command row is refused.
$replayProbe(
    static fn()=>$payability->evaluate((int)$replay['lesson_id'],$evaluateReplayKey),
    static fn()=>$wpdb->update($p.'finance_payability_commands',array('result_override_id'=>(int)$overrideReplay['override_id']),array('id'=>(int)$evaluateReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_payability_commands',array('result_override_id'=>null),array('id'=>(int)$evaluateReplay['command_id'])),
    'command_replay_conflict','evaluate substituted secondary result',(int)$evaluateReplay['evaluation_id'],'evaluation_id'
);
// An override's own override row is the verified cross-link: a *different* valid override id is refused.
$substituteOverrideKey=$replayKey('override-substituted');
$substituteOverride=$payability->override((int)$replay['lesson_id'],'payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-override-substituted','evidence_at'=>gmdate('Y-m-d H:i:s')),$substituteOverrideKey);
dzn_u_fix_assert((int)$substituteOverride['override_id']!==(int)$overrideReplay['override_id'],'a second override of one Lesson is a distinct, valid result row');
$replayProbe(
    static fn()=>$payability->override((int)$replay['lesson_id'],'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-override','evidence_at'=>gmdate('Y-m-d H:i:s')),$overrideReplayKey),
    static fn()=>$wpdb->update($p.'finance_payability_commands',array('result_override_id'=>(int)$substituteOverride['override_id']),array('id'=>(int)$overrideReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_payability_commands',array('result_override_id'=>(int)$overrideReplay['override_id']),array('id'=>(int)$overrideReplay['command_id'])),
    'command_replay_conflict','override substituted secondary result',(int)$overrideReplay['evaluation_id'],'evaluation_id'
);
// A reconciliation run records the run only: a valid exception id smuggled onto its command row is refused.
$replayProbe(
    static fn()=>$reconciliation->run($statementStart,$statementEnd,$teacherId,$runReplayKey),
    static fn()=>$wpdb->update($p.'finance_reconciliation_commands',array('result_exception_id'=>$openException),array('id'=>(int)$runReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_reconciliation_commands',array('result_exception_id'=>null),array('id'=>(int)$runReplay['command_id'])),
    'command_replay_conflict','reconciliation run substituted secondary result',(int)$runReplay['run_id'],'run_id'
);
// A resolution records the exception only: a valid run id smuggled onto its command row is refused.
$replayProbe(
    static fn()=>$reconciliation->resolveException($openException,array('resolution_note'=>'replay corruption recorded and restored'),$resolveReplayKey),
    static fn()=>$wpdb->update($p.'finance_reconciliation_commands',array('result_run_id'=>(int)$runReplay['run_id']),array('id'=>(int)$resolveReplay['command_id'])),
    static fn()=>$wpdb->update($p.'finance_reconciliation_commands',array('result_run_id'=>null),array('id'=>(int)$resolveReplay['command_id'])),
    'command_replay_conflict','exception resolution substituted secondary result',$openException,'exception_id'
);
// A converged replay reports the *verified* secondary result — never a value read off the command row.
$overrideConverged=$payability->override((int)$replay['lesson_id'],'non_payable','operator_decision',array('evidence_channel'=>'staff_record','evidence_reference'=>'u-replay-override','evidence_at'=>gmdate('Y-m-d H:i:s')),$overrideReplayKey);
dzn_u_fix_assert((int)$overrideConverged['override_id']===(int)$overrideReplay['override_id'],'a converged override replay reports the override its own evaluation carries');
$captureConverged=$snapshots->capture((int)$replay['lesson_id'],$captureReplayKey);
dzn_u_fix_assert($captureConverged['correction_id']===null,'a converged capture replay reports no secondary correction result');
$runConverged=$reconciliation->run($statementStart,$statementEnd,$teacherId,$runReplayKey);
dzn_u_fix_assert($runConverged['exception_id']===null,'a converged run replay reports no secondary exception result');
$resolveConverged=$reconciliation->resolveException($openException,array('resolution_note'=>'replay corruption recorded and restored'),$resolveReplayKey);
dzn_u_fix_assert($resolveConverged['run_id']===null,'a converged resolution replay reports no secondary run result');

echo "phase-2a2u-corruption-runtime: OK (every corrupt shape fails closed through its owning validator, every command family's replay re-proves its recorded result and its exact typed-result shape, and exact restoration converges)\n";
