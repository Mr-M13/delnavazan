<?php
/** Disposable Phase-R2 concurrency verifier: consumes worker artefacts and asserts the invariant. */
if(getenv('DZN_PHASE_2A2R2_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R2 concurrency verifier refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2R2_MODE');
$gate=(string)getenv('DZN_PHASE_2A2R2_GATE_DIR');
$state=get_option('dzn_phase_2a2r2_concurrency');
dzn_r2_fix_assert(is_array($state)&&(string)($state['mode']??'')===$mode,'Phase R2 concurrency setup required');
$worker=static function(string $name) use($gate):array{
    $path=$gate.'/'.$name.'.result';
    dzn_r2_fix_assert(is_file($path),'missing worker result artefact: '.$name);
    $decoded=json_decode((string)file_get_contents($path),true);
    dzn_r2_fix_assert(is_array($decoded),'malformed worker result artefact: '.$name);
    return $decoded;
};
$count=static function(string $table,string $column='',mixed $value=null):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    if($column==='')return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}{$table} WHERE {$column}=%s",$value));
};
/**
 * Every aggregate that has a version column must also expose a gap-free event sequence: a serialised
 * aggregate never skips or duplicates a history step, whatever the interleaving of the two workers.
 */
$contiguous=static function(string $table,string $column,int $id) use($wpdb,$p):void{
    $sequences=$wpdb->get_col($wpdb->prepare("SELECT event_sequence FROM {$p}{$table} WHERE {$column}=%d ORDER BY event_sequence",$id))?:array();
    foreach($sequences as $index=>$sequence)dzn_r2_fix_assert((int)$sequence===($index+1),'event sequence gap in '.$table.' for '.$column.'='.$id);
};
/** Every accepted aggregate transition records exactly one event and one command. */
$paired=static function(string $eventTable,string $commandTable,string $column,int $id) use($count):void{
    dzn_r2_fix_assert($count($eventTable,$column,$id)===$count($commandTable,$column,$id),'every accepted transition must record one event and one command: '.$id);
};
$w1=$worker('w1');$w2=$worker('w2');
$w1Ok=$w1['ok']===true;
$w2Ok=$w2['ok']===true;
$w2Reason=(string)($w2['message']??'');

if($mode==='renewal_vs_schedule'){
    // The cycle open and the competitor's Phase-N schedule take different authority roots, so either
    // order is legal; what must hold is that the guaranteed cycle is recorded exactly once and that
    // the competing schedule either committed or lost with a controlled capacity reason.
    dzn_r2_fix_assert($w1Ok,'the guaranteeing worker must record the cycle and its guarantee');
    dzn_r2_fix_assert($w2Ok||in_array($w2Reason,array('teacher_slot_conflict','protected_interval_mismatch','protected_interval_missing','teacher_unavailable'),true),'the competing schedule must either commit or lose with a controlled reason: '.$w2Reason);
    dzn_r2_fix_assert($count('renewal_cycles','recurring_enrolment_id',(int)$state['recurring'])===1,'exactly one renewal cycle may exist for the recurring enrolment');
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",(int)$state['recurring']))==='guarantee_protected','the cycle must end guarantee-protected');
    $cycleId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",(int)$state['recurring']));
    $contiguous('renewal_cycle_events','renewal_cycle_id',$cycleId);
    $paired('renewal_cycle_events','renewal_cycle_commands','renewal_cycle_id',$cycleId);
}elseif($mode==='guarantee_vs_close'){
    // Both contenders serialise on the same R1 commercial account root, so the recurring enrolment
    // close can never interleave with the guarantee transition of its own cycle.
    dzn_r2_fix_assert($w1Ok,'the guaranteeing worker must record the cycle and its guarantee');
    dzn_r2_fix_assert($w1Ok&&$w2Ok,'both serialised transitions must commit');
    $recurring=(int)$state['recurring'];
    $contiguous('recurring_enrolment_events','recurring_enrolment_id',$recurring);
    $paired('recurring_enrolment_events','recurring_enrolment_commands','recurring_enrolment_id',$recurring);
    $cycleId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",(int)$recurring));
    $contiguous('renewal_cycle_events','renewal_cycle_id',$cycleId);
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId))==='guarantee_protected','the guarantee must survive the racing close');
    dzn_r2_fix_assert(in_array((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}recurring_enrolments WHERE id=%d",$recurring)),array('active','closed'),true),'the recurring enrolment must end in a valid state');
}elseif($mode==='recovery_vs_satisfaction'){
    dzn_r2_fix_assert($w1Ok,'the recovering worker must record the recovery');
    dzn_r2_fix_assert($w2Ok||in_array($w2Reason,array('teacher_slot_conflict','protected_interval_mismatch','protected_interval_missing','teacher_unavailable'),true),'the competing schedule must either commit or lose with a controlled reason: '.$w2Reason);
    $recoveryId=(int)$state['recovery_id'];
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}recovery_cases WHERE id=%d",$recoveryId))==='recovered','the recovery case must end recovered');
    $contiguous('recovery_case_events','recovery_case_id',$recoveryId);
    $paired('recovery_case_events','recovery_case_commands','recovery_case_id',$recoveryId);
    // No automatic lapse machinery exists: capacity stays owned by the R1 claim.
    dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recovery_cases WHERE id=%d AND state='lapsed'",$recoveryId))===0,'a recovered case must never lapse');
}elseif($mode==='release_vs_succession'){
    // A predecessor protection may only be released once its successor Term is durable, and a competing
    // succession must lose to the owning-cycle uniqueness rather than create a second protection for
    // the same cycle.
    dzn_r2_fix_assert($w1Ok,'the releasing worker must record the release');
    dzn_r2_fix_assert($w2Ok===false,'a competing protection for the same cycle must lose');
    dzn_r2_fix_assert(in_array($w2Reason,array('recurring_protection_already_exists','invalid_recurring_cycle_state','commercial_capacity_claim_not_active'),true),'the competing protection must fail with a controlled reason: '.$w2Reason);
    $protectionId=(int)$state['protection_id'];
    // The release was only authorised because the successor Term and its capacity claim are durable.
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$state['cycle']['cycle_id']))==='term_bound','the cycle must have bound its successor Term before the predecessor release');
    dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE term_id=%d AND enrolment_id=%d",(int)$state['next']['term_id'],(int)$state['funded']['enrolment_id']))===1,'the successor Term must carry its own R1 funding plan');
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$state['next']['claim_id']))===(string)(int)$state['next']['term_id'],'the successor capacity claim must be durable on the successor Term');
    dzn_r2_fix_assert($count('recurring_protections','renewal_cycle_id',(int)$state['cycle']['cycle_id'])===1,'exactly one protection may exist per renewal cycle');
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}recurring_protections WHERE id=%d",$protectionId))==='released','the released protection must stay released');
    dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$state['funded']['claim_id']))===0,'no protected interval may survive the release');
    $contiguous('recurring_protection_events','recurring_protection_id',$protectionId);
    $paired('recurring_protection_events','recurring_protection_commands','recurring_protection_id',$protectionId);
}elseif($mode==='mode_change_vs_cycle'){
    // A cycle snapshots the mode *recorded on its recurring enrolment*, so the serialised mode change
    // commits first and the cycle that opens afterwards inherits `automatic` rather than any
    // caller-supplied mode. A mode change must never rewrite a cycle that already exists.
    dzn_r2_fix_assert($w1Ok&&$w2Ok,'both serialised transitions must commit');
    $recurring=(int)$state['recurring'];
    $contiguous('recurring_enrolment_events','recurring_enrolment_id',$recurring);
    $paired('recurring_enrolment_events','recurring_enrolment_commands','recurring_enrolment_id',$recurring);
    $cycle=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d",$recurring));
    dzn_r2_fix_assert($cycle!==null&&in_array((string)$cycle->collection_mode,array('manual','automatic'),true),'the cycle must carry a valid frozen collection mode');
    dzn_r2_fix_assert((string)$cycle->collection_mode==='automatic','the cycle must snapshot the recorded mode of its recurring enrolment');
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT collection_mode FROM {$p}recurring_enrolments WHERE id=%d",$recurring))==='automatic','the serialised mode change must be the recorded mode');
    $contiguous('renewal_cycle_events','renewal_cycle_id',(int)$cycle->id);
}elseif($mode==='refund_vs_settlement'){
    // A concurrent settlement attempt must never be rewritten, duplicated or reversed by the refund
    // review trajectory; the review keeps the academic consequence unresolved.
    dzn_r2_fix_assert($w1Ok,'the refund-review worker must record its evidence');
    dzn_r2_fix_assert($w2Ok||str_contains($w2Reason,'conflict')||str_contains($w2Reason,'already'),'the competing settlement must commit or be reported as a controlled conflict: '.$w2Reason);
    $review=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}refund_review_cases WHERE purchase_id=%d",(int)$state['purchase_id']));
    dzn_r2_fix_assert($review!==null,'the refund review must exist');
    dzn_r2_fix_assert((int)$review->evidence_id===(int)$state['evidence_id']&&(string)$review->kind==='refund','the review must record its own authoritative refund evidence');
    dzn_r2_fix_assert((int)$review->amount_minor===(int)$wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}commercial_payment_evidence WHERE id=%d",(int)$review->evidence_id)),'the review sum must be the exact sum carried by the authoritative refund evidence');
    dzn_r2_fix_assert($review->academic_consequence===null,'the refund review must keep the academic consequence unresolved');
    dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$state['funded']['obligation_id']))===1,'exactly one settlement may exist for the obligation');
    dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",(int)$state['funded']['entitlement_id']))==='term_bound','a refund review must never reverse or rewrite the bound entitlement');
    $contiguous('refund_review_events','refund_review_id',(int)$review->id);
    $paired('refund_review_events','refund_review_commands','refund_review_id',(int)$review->id);
}elseif($mode==='unrelated_recurring_enrolments'){
    // Unrelated Students own unrelated commercial account roots, so neither worker may block the other.
    dzn_r2_fix_assert(is_file($gate.'/w1.started'),'the holder worker must be gated inside its own open transaction');
    dzn_r2_fix_assert($w1Ok&&$w2Ok,'independent recurring enrolments must both progress');
    foreach(array((int)$state['recurring_a'],(int)$state['recurring_b']) as $recurringId){
        dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}recurring_enrolments WHERE id=%d",$recurringId))==='suspended','each unrelated recurring enrolment must apply its own transition');
        $contiguous('recurring_enrolment_events','recurring_enrolment_id',$recurringId);
        $paired('recurring_enrolment_events','recurring_enrolment_commands','recurring_enrolment_id',$recurringId);
    }
    dzn_r2_fix_assert($count('commercial_account_roots')>=2,'unrelated recurring enrolments must own distinct commercial account roots');
}else{
    throw new RuntimeException('Unknown Phase R2 concurrency mode: '.$mode);
}
echo "Phase 2A.2-R2 concurrency verified: ".$mode."\n";
