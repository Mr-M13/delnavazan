<?php
/** Disposable Phase-R1 concurrency verifier: consumes worker artefacts and asserts the invariant. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 concurrency verifier refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService,CommercialTermFundingService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2R1_MODE');
$gate=(string)getenv('DZN_PHASE_2A2R1_GATE_DIR');
$state=get_option('dzn_phase_2a2r1_concurrency');
dzn_r1_fix_assert(is_array($state)&&(string)($state['mode']??'')===$mode,'Phase R1 concurrency setup required');
$worker=static function(string $name) use($gate):array{
    $path=$gate.'/'.$name.'.result';
    dzn_r1_fix_assert(is_file($path),'missing worker result artefact: '.$name);
    $decoded=json_decode((string)file_get_contents($path),true);
    dzn_r1_fix_assert(is_array($decoded),'malformed worker result artefact: '.$name);
    return $decoded;
};
$count=static function(string $table) use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");};
$w1=$worker('w1');$w2=$worker('w2');
if($mode==='duplicate_evidence'){
    dzn_r1_fix_assert($w1['ok']===true,'the first settlement must succeed');
    dzn_r1_fix_assert($w2['ok']===true&&($w2['outcome']['idempotent']??false)===true,'duplicate evidence must converge idempotently, not fail');
    dzn_r1_fix_assert($count('commercial_obligation_settlements')===1,'duplicate evidence must produce exactly one settlement');
    dzn_r1_fix_assert($count('commercial_payment_evidence')===1,'duplicate evidence must produce exactly one evidence row');
    dzn_r1_fix_assert($count('commercial_purchases')===1&&$count('commercial_entitlements')===1,'duplicate evidence must produce exactly one purchase and entitlement');
    dzn_r1_fix_assert((new CommercialTermFundingService())->effectiveSessions((int)$state['offer']['offer_id'])===12,'duplicate evidence must not fund twice');
}elseif($mode==='handoff_vs_schedule'){
    dzn_r1_fix_assert($w1['ok']===true,'the protected-capacity handoff must succeed');
    dzn_r1_fix_assert($w2['ok']===false&&($w2['message']??'')==='teacher_slot_conflict','a competing schedule must lose to the successor claim');
    dzn_r1_fix_assert($count('commercial_capacity_claims')===1,'exactly one successor claim must exist');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT interval_count FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",(int)$state['entitlement_id']))===12,'the successor must protect every committed interval');
    dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$state['scenario']['reservation_id']))==='released','the predecessor hold must be released once the successor is durable');
}elseif($mode==='settlement_vs_lesson_seven'){
    dzn_r1_fix_assert($w1['ok']===true,'the second-tranche settlement must succeed');
    dzn_r1_fix_assert($w2['ok']===false&&($w2['message']??'')==='standard_funding_exhausted','the seventh Lesson must be impossible while its tranche is uncommitted');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND canonical_sequence=7",(int)$state['term_id']))===0,'no unpaid seventh Lesson may exist');
    dzn_r1_fix_assert((new CommercialTermFundingService())->standardAllowanceForTerm((int)$state['term_id'])===12,'the committed settlement must fund twelve sessions');
    wp_set_current_user(1);
    (new CanonicalLessonAuthorityService())->createStandard((int)$state['term_id'],(int)$state['assignment_id'],dzn_r1_fix_evidence('after-race-seven'),dzn_r1_fix_key('after-race-seven'));
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND canonical_sequence=7",(int)$state['term_id']))===1,'the seventh Lesson must be materialisable after the committed settlement');
}elseif($mode==='promotion_global_limit'){
    dzn_r1_fix_assert($w1['ok']===true,'the first acceptance must succeed');
    dzn_r1_fix_assert($w2['ok']===false&&str_contains((string)($w2['message']??''),'promotion_usage_limit_reached'),'the second acceptance must fail closed on the global redemption limit');
    dzn_r1_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions")===1,'a global promotion maximum must never be exceeded under concurrency');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$state['offer_b']['offer_id']))===0,'the refused acceptance must not create a purchase');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$state['offer_a']['offer_id']))===1,'the winning acceptance must create exactly one purchase');
}elseif($mode==='conflicting_evidence_replay'){
    dzn_r1_fix_assert($w1['ok']===true,'the original evidence must settle');
    dzn_r1_fix_assert($w2['ok']===true&&($w2['outcome']['conflicting']??false)===true,'a concurrent conflicting replay must be reported as conflicting');
    dzn_r1_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements")===1,'a conflicting replay must never manufacture a second settlement');
    dzn_r1_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_payment_evidence")===1,'a conflicting replay must never rewrite or duplicate the original evidence');
    dzn_r1_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE state='open'")>=1,'a conflicting replay must be routed into durable review');
}elseif($mode==='release_vs_satisfaction'){
    dzn_r1_fix_assert($w1['ok']===true,'the authorised claim release must succeed');
    dzn_r1_fix_assert($w2['ok']===true,'a schedule after an authorised release must succeed');
    dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$state['claim_id']))==='released','the claim must remain released');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$state['claim_id']))===0,'no protected interval may survive the release');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",(int)$state['lesson_id']))===1,'the released interval must be reusable by the surviving schedule');
}elseif($mode==='unrelated_commitments'){
    dzn_r1_fix_assert($w1['ok']===true&&$w2['ok']===true,'independent commitments must both establish their capacity');
    dzn_r1_fix_assert($count('commercial_capacity_claims')===2,'each commitment must own exactly one claim');
    foreach(array((int)$state['scenario_a']['reservation_id'],(int)$state['scenario_b']['reservation_id']) as $reservationId)dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",$reservationId))==='released','each predecessor hold must be released');
}elseif($mode==='unattributed_conflict'||$mode==='unattributed_convergence'){
    $providerKey='synthetic_provider';
    $referenceDigest=\Delnavazan\Platform\Core\Application\CommercialIdempotency::providerReference($providerKey,(string)$state['provider_reference']);
    $obligationDigest=\Delnavazan\Platform\Core\Application\CommercialIdempotency::obligationReference((string)$state['obligation_reference']);
    // The canonical fact identity is recomputed here exactly as the intake boundary computes it.
    $factDigest=static function(int $amount) use($providerKey,$referenceDigest,$obligationDigest,$state):string{
        return \Delnavazan\Platform\Core\Application\CommercialIdempotency::providerFact(array(
            'provider_key'=>$providerKey,'provider_reference_digest'=>$referenceDigest,'evidence_kind'=>'success',
            'amount_minor'=>$amount,'currency'=>'AUD','obligation_reference_digest'=>$obligationDigest,
            'provider_occurred_at'=>(string)$state['provider_occurred_at'],'provider_account_digest'=>null,
            'offer_id'=>null,'obligation_id'=>null,
        ));
    };
    dzn_r1_fix_assert($w1['ok']===true,'the winning unattributed evidence fact must be recorded');
    dzn_r1_fix_assert($w2['ok']===true,'the losing worker must return a controlled outcome rather than fail');
    // Genuine overlap, not a sequential replay: the holder is gated inside its own open transaction
    // (its evidence row is written but uncommitted) before the contender's call is launched.
    dzn_r1_fix_assert(is_file($gate.'/w1.started'),'the holder must be gated inside its own open transaction');
    dzn_r1_fix_assert(is_file($gate.'/w2.finished'),'the contender must have completed its own competing intake');
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_payment_evidence WHERE provider_key=%s AND evidence_reference_digest=%s",$providerKey,$referenceDigest));
    dzn_r1_fix_assert(count($rows)===1,'exactly one immutable winning evidence row may exist for the provider reference');
    dzn_r1_fix_assert((string)$rows[0]->evidence_fact_digest===$factDigest((int)$state['amount_a']),'the winning evidence row must remain unchanged');
    dzn_r1_fix_assert((string)$rows[0]->processing_state==='unmatched'&&$rows[0]->offer_id===null&&$rows[0]->obligation_id===null,'initially-unattributed evidence must stay unattributed and routed for reconciliation');
    dzn_r1_fix_assert($count('commercial_obligation_settlements')===0&&$count('commercial_purchases')===0&&$count('commercial_entitlements')===0,'unattributed evidence must never manufacture settlement, purchase or entitlement truth');
    if($mode==='unattributed_conflict'){
        dzn_r1_fix_assert($factDigest((int)$state['amount_b'])!==$factDigest((int)$state['amount_a']),'the conflicting fact set must actually differ');
        dzn_r1_fix_assert(($w2['outcome']['conflicting']??false)===true&&($w2['outcome']['conflict_reason']??'')==='conflicting_payment_evidence','a materially different unattributed fact must be reported as an explicit conflict');
        dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE evidence_fact_digest=%s",$factDigest((int)$state['amount_b'])))===0,'the losing fact set must never be stored as a second evidence row');
        $fingerprint=\Delnavazan\Platform\Core\Application\CommercialIdempotency::fingerprint('conflicting_payment_evidence','commercial',$providerKey.':'.$referenceDigest);
        dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='conflicting_payment_evidence' AND state='open' AND fingerprint=%s",$fingerprint))===1,'the conflict must be durably routed into controlled commercial review');
    }else{
        dzn_r1_fix_assert(!isset($w2['outcome']['conflicting']),'identical unattributed facts must converge idempotently');
        dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='conflicting_payment_evidence'"))===0,'identical facts must not be routed as a conflict');
    }
}
echo "Phase 2A.2-R1 concurrency verified: ".$mode."\n";
