<?php
/**
 * Disposable Phase-U statement proof: draft → totals → issue over a clean elapsed period, the one-time
 * issuance evidence, the fail-closed gate, the timezone triple (set, unset and partially recorded), the
 * visible zero-value line, the counted archive exclusion and supersession that never rewrites its
 * predecessor. Synthetic local data only.
 */
if(getenv('DZN_PHASE_2A2U_STATEMENT_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U statement runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\Finance\{FinanceCorrectionService,FinancePolicyService,FinanceRule,LessonFinanceSnapshotService,LessonPayabilityService,TeacherStatementService};
use Delnavazan\Platform\Core\Application\Finance\Integrity\FinanceStatementIntegrity;
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$policies=new FinancePolicyService();$snapshots=new LessonFinanceSnapshotService();$payability=new LessonPayabilityService();$statements=new TeacherStatementService();
$chain=dzn_u_fix_chain($fixture);
$teacherId=(int)$chain['teacher_id'];
// One-minute occurrences keep the elapsed-period wait short and deterministic.
$payableA=dzn_u_fix_occurrence($chain,$fixture,'statement-1',2,60);
$payableB=dzn_u_fix_occurrence($chain,$fixture,'statement-2',2,60);
$nonPayable=dzn_u_fix_occurrence($chain,$fixture,'statement-3',2,60);
(new CanonicalLessonAuthorityService())->cancel((int)$nonPayable['lesson_id'],'authorised',dzn_u_fix_evidence('u-statement-cancel'),dzn_u_fix_key('statement-cancel'));
dzn_u_fix_rate($teacherId,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-3600),'compensation_basis'=>'per_session'),'statement-a');
foreach(array($payableA,$payableB,$nonPayable) as $index=>$occurrence){
    $snapshots->capture((int)$occurrence['lesson_id'],dzn_u_fix_key('cap-'.$index));
    $payability->evaluate((int)$occurrence['lesson_id'],dzn_u_fix_key('eval-'.$index));
}
$periodStart=gmdate('Y-m-d H:i:s',min(strtotime((string)$payableA['starts_at_utc']),strtotime((string)$payableB['starts_at_utc']),strtotime((string)$nonPayable['starts_at_utc']))-3600);
$periodEnd=gmdate('Y-m-d H:i:s',max(strtotime((string)$payableA['ends_at_utc']),strtotime((string)$payableB['ends_at_utc']),strtotime((string)$nonPayable['ends_at_utc']))+60);
dzn_u_fix_settle(array($payableA,$payableB,$nonPayable));
dzn_u_fix_assert(strtotime($periodEnd.' UTC')<time(),'the drafted period must have elapsed');

// §10.1/§10.5 #8: a draft recorded while the timezone policy is unset records the unset triple and
// cannot be issued; the operator records the policy, withdraws and re-drafts.
$unsetDraft=$statements->draft($teacherId,$periodStart,$periodEnd,dzn_u_fix_key('draft-unset'));
dzn_u_fix_assert((int)$unsetDraft['statement_id']>0&&!$unsetDraft['timezone_recorded'],'a draft with the timezone policy unset records the unset representation');
$unsetRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",(int)$unsetDraft['statement_id']));
dzn_u_fix_assert($unsetRow->period_timezone===null&&$unsetRow->period_label===null&&$unsetRow->timezone_policy_version===null,'the unset representation is all three columns NULL');
dzn_u_fix_refused(fn()=>$statements->issue((int)$unsetDraft['statement_id'],dzn_u_fix_key('issue-unset')),'policy_unset_for_statement_period','issuance of a draft whose timezone policy is unset');
$statements->withdraw((int)$unsetDraft['statement_id'],array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-unset','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-unset'));
// The refused issuance left its durable blocking exception; the operator resolves it before the re-draft,
// exactly as §10.5 #9 requires.
$staleException=(int)$wpdb->get_var("SELECT id FROM {$p}finance_exceptions WHERE reason_code='policy_unset_for_statement_period' AND state='open' ORDER BY id DESC LIMIT 1");
dzn_u_fix_assert($staleException>0,'a refused issuance records its blocking exception');
$policies->record('FINANCE_STATEMENT_TIMEZONE',array('policy_value'=>'Australia/Brisbane','value_type'=>'timezone','effective_from'=>gmdate('Y-m-d H:i:s',time()-7200),'evidence_channel'=>'staff_record','evidence_reference'=>'u-tz-policy','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('tz-policy'));
$reconciliationForGate=new \Delnavazan\Platform\Core\Application\Finance\FinanceReconciliationService();
$reconciliationForGate->resolveException($staleException,array('resolution_note'=>'policy recorded and draft withdrawn'),dzn_u_fix_key('resolve-stale'));

// A clean draft records the triple, the totals and one immutable line per Lesson in deterministic order.
$draft=$statements->draft($teacherId,$periodStart,$periodEnd,dzn_u_fix_key('draft-clean'));
$statementId=(int)$draft['statement_id'];
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",$statementId));
dzn_u_fix_assert((string)$row->period_timezone==='Australia/Brisbane'&&(string)$row->period_label!==''&&(int)$row->timezone_policy_version===1,'a draft with the policy set records the zone, its label and the exact policy version');
dzn_u_fix_assert((int)$row->total_line_count===3,'every finance-relevant Lesson of the period is stated exactly once');
dzn_u_fix_assert((int)$row->payable_line_count===2&&(int)$row->payable_amount_minor===24000,'payable lines total their exact snapshot amounts');
dzn_u_fix_assert((int)$row->non_payable_line_count===1&&(int)$row->pending_line_count===0,'a non-payable line is visible and no line is pending');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statement_lines WHERE statement_id=%d AND disposition='non_payable' AND line_amount_minor=0",$statementId))===1,'a non-payable line is visible with a zero amount and keeps its snapshot amount readable');
$totals=FinanceStatementIntegrity::assertTotals($statementId);
dzn_u_fix_assert((int)$totals['total_line_count']===3&&(int)$totals['payable_amount_minor']===24000,'assertTotals recomputes the recorded totals exactly');
$orderCheck=$wpdb->get_results($wpdb->prepare("SELECT lesson_id,line_sequence FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence",$statementId));
dzn_u_fix_assert(count($orderCheck)===3&&(int)$orderCheck[0]->line_sequence===1,'lines are sequenced in the deterministic order');

// §10.5: the one-time issuance evidence is stamped by the conditional transition, exactly once.
$issued=$statements->issue($statementId,dzn_u_fix_key('issue-clean'));
dzn_u_fix_assert((string)$issued['state']==='issued'&&(int)$issued['issued_by']===1,'issuance records its actor');
$issuedRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",$statementId));
dzn_u_fix_assert($issuedRow->issued_at!==null&&(int)$issuedRow->issued_by===1,'the issued statement carries its issuance evidence');
dzn_u_fix_refused(fn()=>$statements->issue($statementId,dzn_u_fix_key('issue-again')),'statement_state_transition_conflict','a second issuance attempt');
dzn_u_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT issued_at FROM {$p}finance_statements WHERE id=%d",$statementId))===(string)$issuedRow->issued_at,'a refused second issuance never re-stamps the evidence');
dzn_u_fix_refused(fn()=>$statements->withdraw($statementId,array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-issued','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-issued')),'statement_state_transition_conflict','withdrawing an issued statement');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_outbox WHERE aggregate_type='finance_statements' AND aggregate_id=%d AND event_type='TEACHER_STATEMENT_ISSUED'",$statementId))===1,'issuance raises exactly one identity-only notification intent');
$intent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE aggregate_id=%d AND event_type='TEACHER_STATEMENT_ISSUED'",$statementId));
dzn_u_fix_assert($intent!==null&&(string)$intent->status==='pending'&&(int)$intent->attempt_count===0&&$intent->leased_at===null&&$intent->processed_at===null&&$intent->invitation_id===null&&$intent->generation_id===null,'the intent row carries only the seam\'s mandatory initial metadata');

// §10.6 supersession: a new version plus one conditional `issued → superseded` move; the predecessor's
// lines, totals and issuance evidence never change.
$successor=$statements->supersede($statementId,array('reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-supersede','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('supersede'));
$predecessor=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",$statementId));
dzn_u_fix_assert((string)$predecessor->state==='superseded'&&(int)$predecessor->superseded_by_statement_id===(int)$successor['statement_id'],'the supersession names its successor');
dzn_u_fix_assert((string)$predecessor->issued_at===(string)$issuedRow->issued_at&&(int)$predecessor->issued_by===(int)$issuedRow->issued_by,'a supersession leaves the issuance evidence untouched');
dzn_u_fix_assert((int)$predecessor->payable_amount_minor===(int)$issuedRow->payable_amount_minor&&(int)$predecessor->total_line_count===(int)$issuedRow->total_line_count,'a supersession leaves the recorded totals and lines untouched');
$successorRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",(int)$successor['statement_id']));
dzn_u_fix_assert((int)$successorRow->statement_version===(int)$issuedRow->statement_version+1&&(int)$successorRow->superseded_statement_id===$statementId,'the successor is the next version of the same period');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_outbox WHERE aggregate_id=%d AND event_type='TEACHER_STATEMENT_SUPERSEDED'",$statementId))===1,'a supersession raises exactly one predecessor intent');

// A tampered line or triple fails the recorded proof closed, and exact restoration converges.
$lineId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence LIMIT 1",(int)$successor['statement_id']));
$line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_lines WHERE id=%d",$lineId));
$wpdb->update($p.'finance_statement_lines',array('line_amount_minor'=>(int)$line->line_amount_minor+1),array('id'=>$lineId));
$caught=null;try{FinanceStatementIntegrity::assertTotals((int)$successor['statement_id']);}catch(Throwable$e){$caught=$e;}
dzn_u_fix_assert($caught!==null&&in_array($caught->getMessage(),array('statement_totals_mismatch','statement_derivation_mismatch'),true),'a tampered line fails assertTotals closed');
$wpdb->update($p.'finance_statement_lines',array('line_amount_minor'=>(int)$line->line_amount_minor,'derivation_digest'=>(string)$line->derivation_digest),array('id'=>$lineId));
$wpdb->update($p.'finance_statements',array('period_timezone'=>null),array('id'=>(int)$successor['statement_id']));
$caught=null;try{FinanceStatementIntegrity::assertTimezoneTriple($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d",(int)$successor['statement_id'])));}catch(Throwable$e){$caught=$e;}
dzn_u_fix_assert($caught!==null&&$caught->getMessage()==='statement_timezone_representation_invalid','a partially recorded timezone triple is refused');
$wpdb->update($p.'finance_statements',array('period_timezone'=>'Australia/Brisbane'),array('id'=>(int)$successor['statement_id']));
FinanceStatementIntegrity::assertTotals((int)$successor['statement_id']);

// §10.4 archive exclusion: an archived member is excluded from a *new* draft and counted, and the
// already-issued statement stays exactly as issued.
$statements->withdraw((int)$successor['statement_id'],array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-withdraw-successor','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('withdraw-successor'));
$archivedLesson=(int)$wpdb->get_var($wpdb->prepare("SELECT lesson_id FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence LIMIT 1",$statementId));
$wpdb->update($p.'lessons',array('archived_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$archivedLesson));
$excluded=$statements->draft($teacherId,$periodStart,$periodEnd,dzn_u_fix_key('draft-archived'));
dzn_u_fix_assert((int)$excluded['totals']['excluded_archived_count']===1,'an archived member is counted in the exclusion, never silently dropped');
dzn_u_fix_assert((int)$excluded['totals']['total_line_count']===2,'the remaining members are stated');
$wpdb->update($p.'lessons',array('archived_at'=>null),array('id'=>$archivedLesson));
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT total_line_count FROM {$p}finance_statements WHERE id=%d",$statementId))===(int)$issuedRow->total_line_count,'archiving a Lesson never changes an issued statement');

// §12.2/§15.4/§10.5: the second correction of one snapshot supersedes the first and leaves exactly one
// applicable row, and a draft the correction has out-covered is refused at issuance (§10.5 rule 6) instead
// of issuing stale lines and totals — the operator withdraws it (§10.6) and re-drafts.
$staleChain=dzn_u_fix_chain($fixture);
$staleTeacher=(int)$staleChain['teacher_id'];
$staleRate=dzn_u_fix_rate($staleTeacher,array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>12000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s',time()-3600),'compensation_basis'=>'per_session'),'stale');
$staleLesson=dzn_u_fix_occurrence($staleChain,$fixture,'stale-1',2,1);
dzn_u_fix_settle(array($staleLesson));
dzn_u_fix_outcome($staleLesson,'delivered','stale-1','authorised');
dzn_u_fix_complete($staleLesson,'stale-1');
$staleCapture=dzn_u_fix_accepted(fn()=>$snapshots->capture((int)$staleLesson['lesson_id'],dzn_u_fix_key('stale-capture')),'the stale-gate capture');
$payability->evaluate((int)$staleLesson['lesson_id'],dzn_u_fix_key('stale-evaluate'));
$staleStart=gmdate('Y-m-d H:i:s',strtotime((string)$staleLesson['starts_at_utc'])-3600);
$staleEnd=gmdate('Y-m-d H:i:s',strtotime((string)$staleLesson['ends_at_utc'])+60);
$staleDraft=$statements->draft($staleTeacher,$staleStart,$staleEnd,dzn_u_fix_key('stale-draft'));
$staleStatementId=(int)$staleDraft['statement_id'];
$draftCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_commands WHERE operation='draft' AND result_statement_id=%d ORDER BY id DESC LIMIT 1",$staleStatementId));
dzn_u_fix_assert($draftCommand!==null&&(string)$draftCommand->result_state===FinanceRule::commandSuccessState('draft'),'a drafted statement command row carries its declared success state');
$corrections=new FinanceCorrectionService();
$correctionOne=$corrections->correctSnapshot((int)$staleLesson['lesson_id'],array('corrected_rate_id'=>(int)$staleRate['rate_id'],'corrected_rate_version'=>1,'corrected_rate_amount_minor'=>10000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>10000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-stale-correction-1','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('stale-correction-1'));
$correctionTwo=$corrections->correctSnapshot((int)$staleLesson['lesson_id'],array('corrected_rate_id'=>(int)$staleRate['rate_id'],'corrected_rate_version'=>1,'corrected_rate_amount_minor'=>11000,'corrected_currency'=>'AUD','corrected_derived_amount_minor'=>11000,'reason_code'=>'operator_evidence_correction','evidence_channel'=>'staff_record','evidence_reference'=>'u-stale-correction-2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('stale-correction-2'));
dzn_u_fix_assert((int)$correctionOne['correction_id']!==(int)$correctionTwo['correction_id'],'a second correction of one snapshot appends a second row');
dzn_u_fix_assert(dzn_u_fix_count('finance_snapshot_corrections','snapshot_id=%d AND applicable_slot=1',array((int)$staleCapture['snapshot_id']))===1,'exactly one applicable correction survives the second correction');
$firstCorrectionRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_snapshot_corrections WHERE id=%d",(int)$correctionOne['correction_id']));
dzn_u_fix_assert($firstCorrectionRow->applicable_slot===null&&(int)$firstCorrectionRow->superseded_by_correction_id===(int)$correctionTwo['correction_id'],'the superseded correction releases its slot and names its successor');
$correctionCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_snapshot_commands WHERE operation='correct_snapshot' AND result_correction_id=%d ORDER BY id DESC LIMIT 1",(int)$correctionTwo['correction_id']));
dzn_u_fix_assert($correctionCommand!==null&&(string)$correctionCommand->result_state===FinanceRule::commandSuccessState('correct_snapshot'),'a recorded correction command row carries its declared success state');
dzn_u_fix_refused(fn()=>$statements->issue($staleStatementId,dzn_u_fix_key('stale-issue')),'statement_derivation_mismatch','issuance of a draft a correction has out-covered');
dzn_u_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT payable_amount_minor FROM {$p}finance_statements WHERE id=%d",$staleStatementId))===12000,'a refused issuance never rewrites the stale draft\'s totals');
$statements->withdraw($staleStatementId,array('reason_code'=>'operator_decision','evidence_channel'=>'staff_record','evidence_reference'=>'u-stale-withdraw','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('stale-withdraw'));
$withdrawCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_commands WHERE operation='withdraw' AND statement_id=%d ORDER BY id DESC LIMIT 1",$staleStatementId));
dzn_u_fix_assert($withdrawCommand!==null&&(string)$withdrawCommand->result_state===FinanceRule::commandSuccessState('withdraw'),'a withdrawn statement command row carries its declared success state');
$staleGateException=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_exceptions WHERE reason_code='statement_derivation_mismatch' AND state='open' AND teacher_id=%d ORDER BY id DESC LIMIT 1",$staleTeacher));
dzn_u_fix_assert($staleGateException>0,'a refused issuance records its blocking exception');
$reconciliationForStale=new \Delnavazan\Platform\Core\Application\Finance\FinanceReconciliationService();
$reconciliationForStale->resolveException($staleGateException,array('resolution_note'=>'correction recorded and stale draft withdrawn'),dzn_u_fix_key('resolve-stale-gate'));
$reDraft=$statements->draft($staleTeacher,$staleStart,$staleEnd,dzn_u_fix_key('stale-redraft'));
$reDraftLine=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence LIMIT 1",(int)$reDraft['statement_id']));
dzn_u_fix_assert((int)$reDraftLine->line_amount_minor===11000&&(int)$reDraftLine->snapshot_correction_id===(int)$correctionTwo['correction_id'],'the re-draft records the current effective correction and its exact recomputed amount');
$reIssued=$statements->issue((int)$reDraft['statement_id'],dzn_u_fix_key('stale-reissue'));
dzn_u_fix_assert((string)$reIssued['state']==='issued','the re-draft issues once its stale lines are replaced');
$issueCommand=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_commands WHERE operation='issue' AND statement_id=%d ORDER BY id DESC LIMIT 1",(int)$reDraft['statement_id']));
dzn_u_fix_assert($issueCommand!==null&&(string)$issueCommand->result_state===FinanceRule::commandSuccessState('issue'),'an issued statement command row carries its declared success state');
foreach(array('finance_policy_commands','finance_teacher_rate_commands','finance_snapshot_commands','finance_payability_commands','finance_statement_commands','finance_reconciliation_commands') as $commandTable)
    dzn_u_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$commandTable} WHERE result_state IS NULL OR result_state=''")===0,'no '.$commandTable.' row may omit its result_state');
echo "phase-2a2u-statement-runtime: OK (draft, totals, issuance evidence, gate, timezone triple, archive exclusion, supersession, stale-draft correction gate)\n";
