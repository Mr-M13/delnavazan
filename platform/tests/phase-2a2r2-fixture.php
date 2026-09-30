<?php
/**
 * Shared disposable Phase-R2 fixture: a funded canonical Enrolment plus a recurring enrolment and
 * renewal cycle built on top of the R1 commercial fixture. Synthetic local data only.
 */
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService,CanonicalTermAuthorityService,CommercialCatalogueService,CommercialOfferService,CommercialPaymentService,CommercialTermFundingService,LessonScheduleService,RecurringEnrolmentService,RenewalCycleService,TeacherAssignmentService};

function dzn_r2_fix_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_r2_fix_key(string $label):string{return 'dzn-2a2r2-'.$label.'-'.wp_generate_uuid4();}
function dzn_r2_fix_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_r2_fix_reset():void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $tables=array(
        'recurring_protection_commands','recurring_protection_events','recurring_protections',
        'refund_review_commands','refund_review_events','refund_review_cases',
        'recovery_case_commands','recovery_case_events','recovery_cases',
        'collection_intent_commands','collection_intent_events','collection_intents',
        'renewal_cycle_commands','renewal_cycle_events','renewal_cycles',
        'recurring_enrolment_commands','recurring_enrolment_events','recurring_enrolments',
    );
    foreach($tables as $table)dzn_r2_fix_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable storage: '.$table);
}
/**
 * Build a funded canonical Enrolment and Term via the R1 fixture path.
 *
 * The funded Term is also brought to its authoritative occupancy — activated, given a Teacher
 * Assignment, one standard canonical Lesson and that Lesson's first schedule version — because the
 * next-Term boundary is derived from the current Term's applicable schedule versions. Without those
 * facts the boundary command is *supposed* to fail closed, so a fixture that omitted them could not
 * exercise the derivation at all.
 */
function dzn_r2_fix_funded_enrolment(array $source,string $label,int $sequence):array{
    $scenario=dzn_r1_fix_scenario($source,$label,$sequence);
    dzn_r1_fix_activate_enrolment((int)$scenario['enrolment_id'],$label);
    $product=dzn_r1_fix_product((int)$scenario['course_id'],'AU',25000,$label);
    dzn_r1_fix_pattern($scenario,$label);
    $offer=dzn_r1_fix_offer($scenario,$product,'full',$label);
    $obligation=dzn_r1_fix_obligation($offer,1);
    dzn_r1_fix_settle($offer,1,'r2-'.$label);
    $entitlementId=dzn_r1_fix_entitlement((int)$offer['offer_id']);
    $handoff=dzn_r1_fix_handoff($entitlementId,$label);
    $binding=dzn_r1_fix_bind($entitlementId,$label);
    $assignment=(new TeacherAssignmentService())->assignInitial((int)$scenario['enrolment_id'],dzn_r2_fix_key('assignment-'.$label));
    (new CanonicalTermAuthorityService())->activate((int)$binding['term_id'],'authorised',dzn_r2_fix_evidence('activate-term-'.$label),dzn_r2_fix_key('activate-term-'.$label));
    $lesson=(new CanonicalLessonAuthorityService())->createStandard((int)$binding['term_id'],(int)$assignment['assignment_id'],dzn_r2_fix_evidence('lesson-'.$label),dzn_r2_fix_key('lesson-'.$label));
    // The occupied interval sits one week before the authorised regular-slot anchor, so the next
    // pattern occurrence is strictly after it, exactly as a real progression would be.
    $wall=gmdate('Y-m-d H:i:s',strtotime(gmdate('Y-m-d',strtotime('-4 days')).' 12:00:00 UTC')-($sequence*3600));
    (new LessonScheduleService())->initial((int)$lesson['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>'synthetic_r2_boundary'));
    return array(
        'enrolment_id'=>(int)$scenario['enrolment_id'],'term_id'=>(int)$binding['term_id'],
        'entitlement_id'=>$entitlementId,'claim_id'=>(int)$handoff['claim_id'],
        'offer_id'=>(int)$offer['offer_id'],'product_id'=>(int)$product,'obligation_id'=>(int)$obligation['obligation_id'],
        'amount_minor'=>(int)$obligation['amount_minor'],'currency'=>(string)$offer['currency'],
    );
}
function dzn_r2_fix_establish(int $enrolmentId,string $label):int{
    $result=(new RecurringEnrolmentService())->establish(array('enrolment_id'=>$enrolmentId,'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'establish-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('establish-'.$label));
    return (int)$result['recurring_enrolment_id'];
}
function dzn_r2_fix_cycle(int $recurringId,int $sourceTermId,string $label):array{
    // No `collection_mode` is supplied: a cycle snapshots the mode recorded on its recurring enrolment.
    $result=(new RenewalCycleService())->openCycle($recurringId,array('source_term_id'=>$sourceTermId,'evidence_channel'=>'staff_record','evidence_reference'=>'cycle-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-'.$label));
    return array('cycle_id'=>(int)$result['renewal_cycle_id'],'boundary_derived_at'=>(string)$result['boundary_derived_at']);
}
/**
 * Record one *authoritative* R1 refund evidence fact for an obligation of an accepted offer.
 *
 * §5.5 reviews R1 `refund`/`reversal` evidence, so a review's subject must be a real R1 evidence row of
 * that kind (with its own exact amount and currency) rather than an ordinary successful payment.
 */
function dzn_r2_fix_refund_evidence(int $offerId,int $obligationId,string $reference,int $amount,string $currency):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $sequence=(int)$wpdb->get_var($wpdb->prepare("SELECT obligation_sequence FROM {$p}commercial_offer_obligations WHERE id=%d",$obligationId));
    $offerUid=(string)$wpdb->get_var($wpdb->prepare("SELECT offer_uid FROM {$p}commercial_offers WHERE id=%d",$offerId));
    dzn_r2_fix_assert($sequence>0&&$offerUid!=='','the refund evidence must resolve its own obligation');
    $result=(new CommercialPaymentService())->ingest(array(
        'provider_key'=>'synthetic_provider','provider_reference'=>'refund-'.$reference,'evidence_kind'=>'refund',
        'amount_minor'=>(string)$amount,'currency'=>$currency,
        'obligation_reference'=>$offerUid.':'.$sequence,'provider_occurred_at'=>gmdate('Y-m-d H:i:s'),
        'evidence_channel'=>'provider_evidence','evidence_reference'=>'refund-evidence-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_r1_fix_key('refund-evidence-'.$reference));
    $evidenceId=(int)($result['evidence_id']??0);
    dzn_r2_fix_assert($evidenceId>0&&(string)($result['processing_state']??'')==='accepted','the authoritative refund evidence must be recorded accepted');
    return $evidenceId;
}
/**
 * Build the next-Term R1 entitlement for the SAME canonical Enrolment: a second authorised
 * continuation case on the same Student + Course, settled through the ordinary R1 purchase chain and
 * handed off to its successor capacity claim. R2 never creates any of this itself.
 */
function dzn_r2_fix_next_term_entitlement(array $source,int $productId,string $label,int $sequence):array{
    $scenario=dzn_r1_fix_scenario($source,$label,$sequence);
    dzn_r1_fix_activate_enrolment((int)$scenario['enrolment_id'],$label);
    $offer=dzn_r1_fix_offer($scenario,$productId,'full',$label);
    dzn_r1_fix_settle($offer,1,'r2-'.$label);
    $entitlement=dzn_r1_fix_entitlement((int)$offer['offer_id']);
    $handoff=dzn_r1_fix_handoff($entitlement,$label);
    return array('enrolment_id'=>(int)$scenario['enrolment_id'],'entitlement_id'=>$entitlement,'claim_id'=>(int)$handoff['claim_id'],'offer_id'=>(int)$offer['offer_id'],'amount_minor'=>(int)dzn_r1_fix_obligation($offer,1)['amount_minor']);
}
/** Read one column of one disposable aggregate row. */
function dzn_r2_fix_column(string $table,int $id,string $column):mixed{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    return $wpdb->get_var($wpdb->prepare("SELECT {$column} FROM {$p}{$table} WHERE id=%d",$id));
}
/** Count the rows of one disposable aggregate table, optionally scoped to a column value. */
function dzn_r2_fix_count(string $table,string $column='',mixed $value=null):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    if($column==='')return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}{$table} WHERE {$column}=%s",$value));
}
/** Corrupt one stored column of one disposable aggregate row. */
function dzn_r2_fix_corrupt(string $table,int $id,string $column,mixed $value):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    dzn_r2_fix_assert($wpdb->query($wpdb->prepare("UPDATE {$p}{$table} SET {$column}=%s WHERE id=%d",$value,$id))!==false,'corruption probe failed for '.$table.'.'.$column);
}
/** Assert a callable fails closed, and report the controlled reason it used. */
function dzn_r2_fix_refused(callable $call,string $message):string{
    $caught=null;try{$call();}catch(Throwable$e){$caught=$e;}
    dzn_r2_fix_assert($caught!==null,$message.' was accepted');
    return $caught->getMessage();
}
/** Assert a callable fails closed with one exact controlled reason. */
function dzn_r2_fix_rejected(callable $call,string $expected,string $message):void{
    $reason=dzn_r2_fix_refused($call,$message);
    dzn_r2_fix_assert($reason===$expected,$message.' rejected with an unexpected error: '.$reason);
}
/** Assert a callable succeeds. */
function dzn_r2_fix_accepted(callable $call,string $message):mixed{
    try{return $call();}catch(Throwable$e){throw new RuntimeException($message.' failed: '.$e->getMessage());}
}
