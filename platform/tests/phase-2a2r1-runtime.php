<?php
/** Disposable production-path Phase-R1 commercial purchase, funding & capacity proof; synthetic local data only. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,CommercialAdjustmentService,CommercialCapacityAuthority,CommercialCapacityService,CommercialCatalogueService,CommercialExceptionService,CommercialOfferService,CommercialPatternService,CommercialPaymentService,CommercialPolicyService,CommercialPromotionService,CommercialReadService,CommercialTermFundingService,LessonScheduleService,LessonService,TeacherAssignmentService,TeacherAvailabilityService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_r1_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_r1_key(string $label):string{return 'dzn-2a2r1-'.$label.'-'.wp_generate_uuid4();}
function dzn_r1_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_r1_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_r1_assert($caught!==null,$message.' was accepted');dzn_r1_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
function dzn_r1_refused(callable $call,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_r1_assert($caught!==null,$message.' was accepted');}

$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r1_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
$sources=$fixture['sources'];
// Disposable harness: reset only Phase-R1 and Phase-Q continuation storage so every scenario's
// derived slot, hold, offer and claim are deterministic for this run.
foreach(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities') as $table){
    dzn_r1_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable storage: '.$table);
}
$admin=1;wp_set_current_user($admin);
$continuation=new CanonicalContinuationService();
$enrolments=new CanonicalEnrolmentLifecycleService();
$terms=new CanonicalTermAuthorityService();
$assignments=new TeacherAssignmentService();
$lessons=new CanonicalLessonAuthorityService();
$schedules=new CanonicalLessonScheduleService();
$catalogue=new CommercialCatalogueService();
$promotions=new CommercialPromotionService();
$adjustments=new CommercialAdjustmentService();
$offers=new CommercialOfferService();
$patterns=new CommercialPatternService();
$payments=new CommercialPaymentService();
$funding=new CommercialTermFundingService();
$capacity=new CommercialCapacityService();
$policies=new CommercialPolicyService();
$exceptions=new CommercialExceptionService();
$read=new CommercialReadService();
$principalOf=static function(int $studentId) use($wpdb,$p):int{
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",$studentId));
    if($id<1)throw new RuntimeException('Phase-J principal fixture required');
    return $id;
};
$count=static function(string $table) use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");};
/** Activate the canonical Enrolment only when it is still in its authorised state. */
$activate=static function(int $enrolmentId,string $label) use($enrolments,$wpdb,$p):void{
    $state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d",$enrolmentId));
    if($state==='current')return;
    if($state!=='authorised')throw new RuntimeException('Disposable runtime Enrolment state is not reusable: '.$state);
    $enrolments->activate($enrolmentId,'authorised',dzn_r1_evidence('activate-'.$label),dzn_r1_key('activate-'.$label));
};
// Canonical scheduling requires covering Teacher availability; the disposable fixture grants it for
// every weekday so the committed regular intervals are legitimately schedulable.
$availability=new TeacherAvailabilityService();
foreach(($fixture['teachers']??array()) as $teacherId){
    try{$availability->setProfile(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_r1'));}catch(Throwable$ignored){}
    foreach(array(1,2,3,4,5,6,7) as $weekday){
        try{$availability->setRecurringRule(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_r1'));}catch(Throwable$ignored){}
    }
}

/**
 * Build one payable continuation scenario: an introductory Lesson with a past occurrence, its
 * EXPLICIT administrator-authorised first regular slot, and the Student's continuing decision
 * (which creates the bounded Phase-Q hold). Nothing here derives a future slot from the intro date.
 */
$scenario=function(int $index,string $label,int $slotOffsetDays=7,?string $explicitSlotWall=null) use($sources,$continuation,$principalOf,$admin,$wpdb,$p):array{
    static $sequence=0;$sequence++;
    $src=$sources[$index%count($sources)];
    $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    // Anchor every scenario at a fixed mid-day past instant with a distinct offset so the derived
    // regular intervals never straddle the daily availability window boundary, whatever the time of
    // day the suite runs at.
    $wall=gmdate('Y-m-d H:i:s',strtotime(gmdate('Y-m-d',strtotime('-4 days')).' 12:00:00 UTC')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    $occurrence=$wpdb->get_row($wpdb->prepare("SELECT v.* FROM {$p}lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL",$lessonId));
    dzn_r1_assert($occurrence!==null,'introductory occurrence fixture missing');
    $slotWall=$explicitSlotWall??gmdate('Y-m-d H:i:s',strtotime($wall)+$slotOffsetDays*86400);
    $slot=$continuation->recordFirstRegularSlot($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('slot-'.$label));
    wp_set_current_user($principalOf((int)$src['student_id']));
    try{
        $decision=$continuation->continueWithTeacher($lessonId,array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'continue-'.$label),dzn_r1_key('continue-'.$label));
    }finally{
        // Always restore the administrative actor, even when the continuing decision is refused.
        wp_set_current_user($admin);
    }
    dzn_r1_assert($decision['decision']==='continue_with_teacher'&&(int)($decision['reservation']['reservation_id']??0)>0,'the continuing Student must hold the expected first regular slot');
    $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));
    $reservation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$case->id));
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'enrolment_id'=>(int)$src['enrolment_id'],'case_id'=>(int)$case->id,'reservation_id'=>(int)$reservation->id,'slot_authority_id'=>(int)$slot['slot_authority_id'],'interval'=>$slot,'occurrence'=>$occurrence,'reservation'=>$reservation);
};
$productFor=function(int $courseId,string $region,int $amount) use($catalogue):int{
    $product=(new CommercialCatalogueService())->createProduct(array('course_id'=>$courseId,'name_en'=>'Synthetic R1 package','status'=>'active','evidence_channel'=>'staff_record','evidence_reference'=>'product-'.$courseId,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('product-'.$courseId));
    $catalogue->setPrice(array('product_id'=>(int)$product['product_id'],'region_code'=>$region,'amount_minor'=>(string)$amount,'evidence_channel'=>'staff_record','evidence_reference'=>'price-'.$courseId,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('price-'.$courseId));
    return (int)$product['product_id'];
};
$settle=function(array $offer,int $sequence,string $reference,int $amount,string $occurredAt,string $kind='success') use($payments):array{
    $obligations=$offer['obligations'];
    $tail=array_values(array_filter($obligations,fn($row)=>$row['obligation_sequence']===$sequence));
    dzn_r1_assert(count($tail)===1,'the offer must expose the obligation being settled');
    return $payments->ingest(array(
        'provider_key'=>'synthetic_provider','provider_reference'=>$reference,'evidence_kind'=>$kind,
        'amount_minor'=>$kind==='success'?(string)$amount:null,'currency'=>$kind==='success'?$offer['currency']:null,
        'obligation_reference'=>$offer['offer_uid'].':'.$sequence,'provider_occurred_at'=>$occurredAt,
        'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_r1_key('evidence-'.$reference));
};
$readyTerm=function(array $scenario,array $offer,string $label) use($capacity,$funding,$terms,$assignments,$activate):array{
    $enrolmentId=(int)$scenario['enrolment_id'];
    $activate($enrolmentId,$label);
    $entitlementRow=$GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare("SELECT e.* FROM {$GLOBALS['wpdb']->prefix}dzn_commercial_entitlements e INNER JOIN {$GLOBALS['wpdb']->prefix}dzn_commercial_purchases c ON c.id=e.purchase_id WHERE c.offer_id=%d",(int)$offer['offer_id']));
    dzn_r1_assert($entitlementRow!==null,'the accepted payment must have issued one entitlement');
    $handoff=$capacity->handoffFromEntitlement((int)$entitlementRow->id,dzn_r1_evidence('handoff-'.$label),dzn_r1_key('handoff-'.$label));
    $binding=$funding->bindEntitlementToTerm((int)$entitlementRow->id,dzn_r1_evidence('bind-'.$label),dzn_r1_key('bind-'.$label));
    $terms->activate((int)$binding['term_id'],'authorised',dzn_r1_evidence('activate-term-'.$label),dzn_r1_key('activate-term-'.$label));
    $assignment=$assignments->assignInitial($enrolmentId,dzn_r1_key('assignment-'.$label));
    return array('term_id'=>(int)$binding['term_id'],'assignment_id'=>(int)$assignment['assignment_id'],'claim_id'=>(int)$handoff['claim_id'],'entitlement_id'=>(int)$entitlementRow->id);
};

// ---------------------------------------------------------------------------
// A. Full payment, Regular Student: one offer, one settlement, 12 funded, one Term, 12 lessons.
// ---------------------------------------------------------------------------
$a=$scenario(0,'full-a');
$productA=$productFor((int)$a['course_id'],'AU',25000);
$promotion=$promotions->define(array('promotion_code'=>'WELCOME10','name'=>'Welcome 10','kind'=>'percentage','percentage_bp'=>1000,'first_term_only'=>true,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-welcome10','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('promo'));
$adjustment=$adjustments->grant(array('beneficiary_student_id'=>(int)$a['student_id'],'kind'=>'percentage','percentage_bp'=>500,'reason_code'=>'service_inconvenience','evidence_channel'=>'staff_record','evidence_reference'=>'adjustment-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('adjustment'));
$offerA=$offers->issue(array('continuation_case_id'=>(int)$a['case_id'],'product_id'=>$productA,'region_code'=>'AU','plan_kind'=>'full','promotion_code'=>'WELCOME10','evidence_channel'=>'staff_record','evidence_reference'=>'offer-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-a'));
// Exact money: 25000 → 10% = 2500 → 22500 → 5% = 1125 → 21375. No float, no per-charge discount.
dzn_r1_assert((int)$offerA['base_amount_minor']===25000&&(int)$offerA['discount_total_minor']===3625&&(int)$offerA['amount_due_minor']===21375,'whole-Term pricing must apply each adjustment exactly once');
dzn_r1_assert(count($offerA['obligations'])===1&&(int)$offerA['obligations'][0]['sessions_covered']===12,'full payment must produce one obligation covering the whole Term');
dzn_r1_assert((int)$offerA['committed_sessions']===12&&(int)$offerA['expires_at']>0,'the offer must carry the 12-session commitment and the hold-bound window');
$offerRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offers WHERE id=%d",(int)$offerA['offer_id']));
dzn_r1_assert((string)$offerRow->expires_at===(string)$a['reservation']->expires_at,'the offer window must be the Phase-Q hold expiry: one timer, not two');
$adjustmentRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustment['adjustment_id']));
dzn_r1_assert((string)$adjustmentRow->state==='granted','issuing an offer must not consume an adjustment; acceptance does');
$pattern=$patterns->establish(array('slot_authority_id'=>(int)$a['slot_authority_id'],'course_id'=>(int)$a['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-a'));
dzn_r1_assert((string)$pattern['anchor_starts_at_utc']===(string)$a['interval']['starts_at_utc'],'the pattern anchor must be the authorised first regular slot, not the introduction');
$settledA=$settle($offerA,1,'prov-a-1',21375,gmdate('Y-m-d H:i:s'));
dzn_r1_assert($settledA['processing_state']==='accepted'&&(int)$settledA['effective_sessions']===12,'a verified full payment must make exactly 12 sessions effective');
dzn_r1_assert((int)$settledA['purchase_id']>0&&(int)$settledA['entitlement_id']>0,'acceptance must create one purchase and one entitlement');
$readyA=$readyTerm($a,$offerA,'a');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT lesson_allocation FROM {$p}terms WHERE id=%d",(int)$readyA['term_id']))===12,'the canonical Term allocation must remain 12');
$termRowA=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}terms WHERE id=%d",(int)$readyA['term_id']));
dzn_r1_assert((int)$termRowA->replacement_allowance===(int)CanonicalTermAuthorityService::REPLACEMENT_ALLOWANCE,'the Term must record the canonical change allowance');
$patternIntervals=$patterns->intervalsFor((object)array('schedule_timezone'=>'UTC','anchor_local_wall_date'=>$pattern['anchor_local_wall_date'],'local_wall_time'=>$pattern['local_wall_time'],'duration_minutes'=>$pattern['duration_minutes'],'buffer_minutes'=>$pattern['buffer_minutes']),12);
for($index=0;$index<12;$index++){
    $lesson=$lessons->createStandard((int)$readyA['term_id'],(int)$readyA['assignment_id'],dzn_r1_evidence('standard-'.$index),dzn_r1_key('standard-'.$index));
    $interval=$patternIntervals[$index];
    $schedules->schedule((int)$lesson['lesson_id'],(int)$readyA['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>$interval['local_wall_date'],'local_wall_time'=>$interval['local_wall_time'],'reason_code'=>'regular_term_slot','evidence_channel'=>'staff_record','evidence_reference'=>'schedule-'.$index,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('schedule-'.$index));
}
dzn_r1_assert($count('lessons')>0,'the canonical Lesson rows must exist');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='standard'",(int)$readyA['term_id']))===12,'a fully paid Regular Term must materialise exactly 12 standard Lessons');
dzn_r1_assert((int)$funding->standardAllowanceForTerm((int)$readyA['term_id'])===12,'the funded allowance must be exactly 12');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='satisfied'",(int)$readyA['claim_id']))===12,'each protected interval must be satisfied by its own canonical schedule');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$readyA['claim_id']))===0,'no protected interval may remain once every occurrence is materialised');
$releasedReservation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$a['reservation_id']));
dzn_r1_assert((string)$releasedReservation->state==='released','the Phase-Q predecessor hold must be released once its successor is durable');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE term_id=%d",(int)$readyA['term_id']))===1,'exactly one funding plan may authorise a Term');
dzn_r1_rejected(fn()=>$lessons->createStandard((int)$readyA['term_id'],(int)$readyA['assignment_id'],dzn_r1_evidence('thirteen'),dzn_r1_key('thirteen')),'standard_allocation_exhausted','a 13th standard Lesson');
$readA=$read->forTerm((int)$readyA['term_id']);
dzn_r1_assert($readA['commercially_funded']===true&&(int)$readA['effective_sessions']===12&&(int)$readA['allowance']===12,'the read seam must expose the derived funding state');

// ---------------------------------------------------------------------------
// B. Two instalments: tranche 1 funds exactly 6, Lesson 7 is impossible until tranche 2 settles.
// ---------------------------------------------------------------------------
$b=$scenario(1,'instalments-b');
$productB=$productFor((int)$b['course_id'],'AU',25000);
$offerB=$offers->issue(array('continuation_case_id'=>(int)$b['case_id'],'product_id'=>$productB,'region_code'=>'AU','plan_kind'=>'two_instalments','evidence_channel'=>'staff_record','evidence_reference'=>'offer-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-b'));
// A Regular commitment's pattern is established before the purchase is completed, so the successor
// claim can protect every committed interval from the moment the Phase-Q hold is handed over.
$patternB=$patterns->establish(array('slot_authority_id'=>(int)$b['slot_authority_id'],'course_id'=>(int)$b['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-b'));
dzn_r1_assert(count($offerB['obligations'])===2,'two instalments must decompose the commitment into two ordered obligations');
dzn_r1_assert((int)$offerB['obligations'][0]['sessions_from']===1&&(int)$offerB['obligations'][0]['sessions_to']===6,'tranche 1 must cover sessions 1-6');
dzn_r1_assert((int)$offerB['obligations'][1]['sessions_from']===7&&(int)$offerB['obligations'][1]['sessions_to']===12,'tranche 2 must cover sessions 7-12');
dzn_r1_assert((int)$offerB['obligations'][0]['amount_minor']+(int)$offerB['obligations'][1]['amount_minor']===(int)$offerB['amount_due_minor'],'the tranches must sum exactly to the amount due');
dzn_r1_assert((int)$offerB['obligations'][0]['amount_minor']===(int)ceil((int)$offerB['amount_due_minor']/2),'the rounding remainder must fall deterministically to the first tranche');
$settledB1=$settle($offerB,1,'prov-b-1',(int)$offerB['obligations'][0]['amount_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert((int)$settledB1['effective_sessions']===6,'the first instalment must fund exactly six sessions');
$readyB=$readyTerm($b,$offerB,'b');
dzn_r1_assert((int)$funding->standardAllowanceForTerm((int)$readyB['term_id'])===6,'a two-instalment Term must be funded for exactly six sessions');
for($index=0;$index<6;$index++)$lessons->createStandard((int)$readyB['term_id'],(int)$readyB['assignment_id'],dzn_r1_evidence('b-standard-'.$index),dzn_r1_key('b-standard-'.$index));
dzn_r1_rejected(fn()=>$lessons->createStandard((int)$readyB['term_id'],(int)$readyB['assignment_id'],dzn_r1_evidence('b-standard-seven'),dzn_r1_key('b-standard-seven')),'standard_funding_exhausted','the seventh standard Lesson before its tranche is funded');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1'",(int)$readyB['term_id']))===6,'an unpaid occurrence must never become a canonical Lesson');
$intervalRowsB=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence",(int)$readyB['claim_id']));
dzn_r1_assert(count($intervalRowsB)===12,'the committed Regular intervals must all be protected');
$termLessonsB=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' ORDER BY canonical_sequence",(int)$readyB['term_id']));
foreach($termLessonsB as $index=>$lessonRow){
    $interval=$intervalRowsB[$index];
    dzn_r1_assert((string)$interval->state==='protected','unfunded intervals must stay protected before their schedule exists');
    $lessonsScheduled=$schedules->schedule((int)$lessonRow->id,(int)$readyB['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$interval->local_wall_date,'local_wall_time'=>(string)$interval->local_wall_time,'reason_code'=>'regular_term_slot','evidence_channel'=>'staff_record','evidence_reference'=>'b-schedule-'.$index,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('b-schedule-'.$index));
    dzn_r1_assert((int)$lessonsScheduled['schedule_version_id']>0,'each funded occurrence must schedule canonically');
}
$protectedAfterB=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$readyB['claim_id']));
dzn_r1_assert($protectedAfterB===6,'the unpaid second half must remain protected capacity, never a Lesson');
$settledB2=$settle($offerB,2,'prov-b-2',(int)$offerB['obligations'][1]['amount_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert((int)$settledB2['effective_sessions']===12,'the second instalment must make the remaining sessions effective');
dzn_r1_assert((int)$funding->standardAllowanceForTerm((int)$readyB['term_id'])===12,'the funded allowance must become twelve');
for($index=6;$index<12;$index++)$lessons->createStandard((int)$readyB['term_id'],(int)$readyB['assignment_id'],dzn_r1_evidence('b-late-'.$index),dzn_r1_key('b-late-'.$index));
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='standard'",(int)$readyB['term_id']))===12,'the funded second half must materialise exactly six more Lessons');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$offerB['offer_id']))===2,'two instalments must settle exactly twice');

// ---------------------------------------------------------------------------
// C. Early instalment 2: financially settled, academically ineffective, then deterministic.
// ---------------------------------------------------------------------------
$c=$scenario(2,'early-c');
$productC=$productFor((int)$c['course_id'],'AU',20000);
$offerC=$offers->issue(array('continuation_case_id'=>(int)$c['case_id'],'product_id'=>$productC,'region_code'=>'AU','plan_kind'=>'two_instalments','evidence_channel'=>'staff_record','evidence_reference'=>'offer-c','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-c'));
$early=$settle($offerC,2,'prov-c-2',(int)$offerC['obligations'][1]['amount_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert($early['processing_state']==='accepted','early evidence must be retained as durable verified evidence');
dzn_r1_assert((int)$early['effective_sessions']===0,'a settled tranche 2 alone must never become academically effective');
$statusC=$funding->obligationStatus((int)$offerC['offer_id']);
dzn_r1_assert($statusC['settled']===1&&$statusC['effective']===0&&$statusC['prerequisites_satisfied']===false,'tranche 2 must be settled but prerequisite-pending');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='%s' AND state='open'",'instalment_prerequisite_unsettled'))>=1,'the approved informational reconciliation signal must be recorded');
$readyC=$readyTerm($c,$offerC,'c');
dzn_r1_assert((int)$funding->standardAllowanceForTerm((int)$readyC['term_id'])===0,'a prerequisite-pending Term must fund nothing');
dzn_r1_rejected(fn()=>$lessons->createStandard((int)$readyC['term_id'],(int)$readyC['assignment_id'],dzn_r1_evidence('c-standard-one'),dzn_r1_key('c-standard-one')),'standard_funding_exhausted','any standard Lesson from an ineffective tranche');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d",(int)$readyC['term_id']))===0,'no Lesson may exist from tranche 2 alone');
$lateC1=$settle($offerC,1,'prov-c-1',(int)$offerC['obligations'][0]['amount_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert((int)$lateC1['effective_sessions']===12,'settling tranche 1 must converge both tranches deterministically');
dzn_r1_assert((int)$funding->effectiveSessions((int)$offerC['offer_id'])===12,'the derived allowance must become twelve with no second Student action');

// ---------------------------------------------------------------------------
// D. Duplicate, mismatched, unattributed and refund evidence: never settle twice, never guess.
// ---------------------------------------------------------------------------
$settlementsBefore=(int)$count('commercial_obligation_settlements');
$duplicate=$settle($offerA,1,'prov-a-1',21375,gmdate('Y-m-d H:i:s'));
dzn_r1_assert($duplicate['idempotent']===true,'duplicate provider evidence must converge on the recorded outcome');
dzn_r1_assert((int)$count('commercial_obligation_settlements')===$settlementsBefore,'duplicate evidence must not settle twice');
$mismatch=$payments->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'prov-a-mismatch','evidence_kind'=>'success','amount_minor'=>'100','currency'=>'AUD','obligation_reference'=>$offerB['offer_uid'].':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-mismatch','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('mismatch'));
dzn_r1_assert($mismatch['processing_state']==='rejected'&&$mismatch['reason_code']==='amount_mismatch','an amount mismatch must fail closed with its controlled reason');
dzn_r1_assert((int)$count('commercial_obligation_settlements')===$settlementsBefore,'a mismatched amount must never settle');
$unmatched=$payments->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'prov-unknown','evidence_kind'=>'success','amount_minor'=>'100','currency'=>'AUD','obligation_reference'=>'unknown-offer-uid:1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-unknown','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('unmatched'));
dzn_r1_assert($unmatched['processing_state']==='unmatched'&&$unmatched['reason_code']==='unmatched_payment_evidence','unattributable evidence must be preserved and routed, never guessed');
$lessonsBefore=$count('lessons');
$refund=$settle($offerA,1,'prov-a-refund',21375,gmdate('Y-m-d H:i:s'),'refund');
dzn_r1_assert($refund['processing_state']==='accepted','refund evidence must be recorded without becoming academic truth');
dzn_r1_assert((int)$count('lessons')===$lessonsBefore,'refund evidence must not mutate Lessons');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='%s'",'refund_evidence_received'))>=1,'refund evidence must route into controlled commercial review');

// ---------------------------------------------------------------------------
// E. Capacity succession: no gap, no double sale, and paid-but-blocked is preserved.
// ---------------------------------------------------------------------------
$e=$scenario(3,'succession-e');
$productE=$productFor((int)$e['course_id'],'AU',25000);
$offerE=$offers->issue(array('continuation_case_id'=>(int)$e['case_id'],'product_id'=>$productE,'region_code'=>'AU','plan_kind'=>'full','evidence_channel'=>'staff_record','evidence_reference'=>'offer-e','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-e'));
$patterns->establish(array('slot_authority_id'=>(int)$e['slot_authority_id'],'course_id'=>(int)$e['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-e','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-e'));
$settledE=$settle($offerE,1,'prov-e-1',25000,gmdate('Y-m-d H:i:s'));
$readyE=$readyTerm($e,$offerE,'e');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$e['reservation_id']))==='released','the predecessor hold must be released after the successor is durable');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$readyE['claim_id']))===12,'the successor must protect every committed interval');
// A free (non-commercial) Lesson cannot be scheduled onto an interval protected by a paid commitment.
$free=function(int $index,string $label) use($sources,$terms,$assignments,$lessons,$wpdb,$activate):array{
    $id=(int)$sources[$index]['enrolment_id'];
    $activate($id,'free-'.$label);
    $term=$terms->create($id,null,null,dzn_r1_evidence('free-term-'.$label),dzn_r1_key('free-term-'.$label));
    $terms->activate((int)$term['term_id'],'authorised',dzn_r1_evidence('free-term-active-'.$label),dzn_r1_key('free-term-active-'.$label));
    $assignment=$assignments->assignInitial($id,dzn_r1_key('free-assignment-'.$label));
    $lesson=$lessons->createStandard((int)$term['term_id'],(int)$assignment['assignment_id'],dzn_r1_evidence('free-lesson-'.$label),dzn_r1_key('free-lesson-'.$label));
    dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_commercial_term_funding_plans WHERE term_id=%d",(int)$term['term_id']))===0,'a non-commercial Term must carry no funding plan');
    return array('term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$assignment['assignment_id'],'lesson_id'=>(int)$lesson['lesson_id']);
};
$freeLesson=$free(8,'protected-conflict');
$protectedFirst=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=1",(int)$readyE['claim_id']));
dzn_r1_rejected(fn()=>$schedules->schedule((int)$freeLesson['lesson_id'],(int)$freeLesson['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$protectedFirst->local_wall_date,'local_wall_time'=>(string)$protectedFirst->local_wall_time,'reason_code'=>'conflicting_claim','evidence_channel'=>'staff_record','evidence_reference'=>'conflict-claim','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('conflict-claim')),'teacher_slot_conflict','a schedule onto a protected paid interval');
dzn_r1_assert((int)CommercialCapacityAuthority::conflictingClaimCount((int)$e['teacher_id'],(string)$protectedFirst->starts_at_utc,(string)$protectedFirst->occupied_ends_at_utc)>=1,'the protected interval must be visible to capacity arbitration');

// A free Lesson cannot be scheduled onto a live Phase-Q pre-payment hold either.
$g=$scenario(4,'hold-g');
$holdRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$g['reservation_id']));
$freeLessonG=$free(9,'hold-conflict');
dzn_r1_rejected(fn()=>$schedules->schedule((int)$freeLessonG['lesson_id'],(int)$freeLessonG['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$holdRow->local_wall_date,'local_wall_time'=>(string)$holdRow->local_wall_time,'reason_code'=>'conflicting_hold','evidence_channel'=>'staff_record','evidence_reference'=>'conflict-hold','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('conflict-hold')),'teacher_slot_conflict','a schedule onto a live Phase-Q hold');
// A new pre-payment continuation hold may not take an interval that a paid commitment already protects.
$protectedForHold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=1",(int)$readyE['claim_id']));
dzn_r1_rejected(fn()=>$scenario(9,'hold-vs-claim',7,(string)$protectedForHold->starts_at_utc),'teacher_slot_conflict','a Phase-Q hold onto an interval protected by a paid commitment');

// A paid commitment whose successor capacity is gone keeps its money evidence and is routed.
$h=$scenario(5,'blocked-h');
$productH=$productFor((int)$h['course_id'],'AU',25000);
$offerH=$offers->issue(array('continuation_case_id'=>(int)$h['case_id'],'product_id'=>$productH,'region_code'=>'AU','plan_kind'=>'full','evidence_channel'=>'staff_record','evidence_reference'=>'offer-h','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-h'));
$patterns->establish(array('slot_authority_id'=>(int)$h['slot_authority_id'],'course_id'=>(int)$h['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-h','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-h'));
$settledH=$settle($offerH,1,'prov-h-1',25000,gmdate('Y-m-d H:i:s'));
$occupied=$free(10,'occupied-interval');
// Occupy the commitment's third committed interval with a legitimate free Lesson before handoff.
$thirdLocalDate=gmdate('Y-m-d',strtotime((string)$h['reservation']->starts_at_utc.' UTC +14 days'));
$thirdLocalTime=(string)$h['reservation']->local_wall_time;
$schedules->schedule((int)$occupied['lesson_id'],(int)$occupied['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>$thirdLocalDate,'local_wall_time'=>$thirdLocalTime,'reason_code'=>'legitimate_prior_booking','evidence_channel'=>'staff_record','evidence_reference'=>'occupying-booking','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('occupying-booking'));
$entitlementH=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_entitlements WHERE purchase_id=(SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d)",(int)$offerH['offer_id']));
dzn_r1_assert($entitlementH>0,'the settled payment must hold its entitlement');
dzn_r1_rejected(fn()=>$capacity->handoffFromEntitlement($entitlementH,dzn_r1_evidence('handoff-h'),dzn_r1_key('handoff-h')),'teacher_slot_conflict','a handoff onto an interval that is no longer free');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$h['reservation_id']))==='active','a failed handoff must never release the predecessor hold');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reconciliation_state FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offerH['offer_id']))==='capacity_lost','a paid commitment that cannot converge must be marked for reconciliation');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='%s' AND purchase_id=(SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d)",'capacity_handoff_failed',(int)$offerH['offer_id']))>=1,'the blocked handoff must be durably routed as a commercial exception');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_facts f INNER JOIN {$p}commercial_purchases c ON c.id=f.purchase_id WHERE c.offer_id=%d",(int)$offerH['offer_id']))===1,'the verified payment fact must survive the blocked handoff');

// Releasing a canonical schedule returns its interval to protection while the commitment stands.
$releaseTarget=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=1",(int)$readyA['claim_id']));
$lessonOne=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE term_id=%d AND canonical_sequence=1",(int)$readyA['term_id']));
$versionOne=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",(int)$lessonOne->id));
$schedules->release((int)$lessonOne->id,array('expected_schedule_version_id'=>$versionOne,'reason_code'=>'commercial_reschedule','evidence_channel'=>'staff_record','evidence_reference'=>'release-one','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('release-one'));
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claim_intervals WHERE id=%d",(int)$releaseTarget->id))==='protected','releasing the schedule must restore its protected interval');

// ---------------------------------------------------------------------------
// F. Flexible/Irregular: only the explicitly authorised interval is claimed; no dates invented.
// ---------------------------------------------------------------------------
$flex=$scenario(6,'flexible-f');
$productFlex=$productFor((int)$flex['course_id'],'AU',25000);
$offerFlex=$offers->issue(array('continuation_case_id'=>(int)$flex['case_id'],'product_id'=>$productFlex,'region_code'=>'AU','plan_kind'=>'full','evidence_channel'=>'staff_record','evidence_reference'=>'offer-flex','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-flex'));
$settledFlex=$settle($offerFlex,1,'prov-flex',25000,gmdate('Y-m-d H:i:s'));
dzn_r1_assert((int)$settledFlex['effective_sessions']===12,'a Flexible commitment is funded for the whole Term too');
$readyFlex=$readyTerm($flex,$offerFlex,'flex');
$flexIntervals=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d",(int)$readyFlex['claim_id']));
dzn_r1_assert(count($flexIntervals)===1,'a Flexible commitment must claim only the interval that was explicitly authorised');
dzn_r1_assert((string)$flexIntervals[0]->starts_at_utc===(string)$flex['reservation']->starts_at_utc,'the Flexible claim must be the frozen predecessor interval');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT committed_sessions FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$readyFlex['claim_id']))===12,'the Flexible commitment must still be a 12-session Term');

// ---------------------------------------------------------------------------
// G. Policy registry: class-B values are versioned, structural invariants are refused.
// ---------------------------------------------------------------------------
$policySet=$policies->set('MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS',array('policy_value'=>'4','value_type'=>'weeks','reason_code'=>'owner_locked','evidence_channel'=>'staff_record','evidence_reference'=>'policy-guarantee','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('policy-guarantee'));
$guarantee=$policies->current('MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS');
dzn_r1_assert($guarantee['set']===true&&$guarantee['value']==='4'&&(int)$guarantee['version']===1,'a runtime policy must be recorded with its version');
$unset=$policies->set('PAYMENT_RECOVERY_POLICY',array('reason_code'=>'owner_deferred','evidence_channel'=>'staff_record','evidence_reference'=>'policy-recovery','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('policy-recovery'));
dzn_r1_assert($policies->current('PAYMENT_RECOVERY_POLICY')['set']===false,'an unset policy must be a valid deliberate state');
dzn_r1_rejected(fn()=>$policies->set('TERM_SESSION_COUNT',array('policy_value'=>'12','value_type'=>'weeks','reason_code'=>'illegal','evidence_channel'=>'staff_record','evidence_reference'=>'policy-structural','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('policy-structural')),'Structural invariants are not configurable commercial policies','a structural invariant as a runtime policy');
dzn_r1_rejected(fn()=>$policies->set('AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME',array('policy_value'=>'999','value_type'=>'weeks','reason_code'=>'invalid','evidence_channel'=>'staff_record','evidence_reference'=>'policy-invalid','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('policy-invalid')),'Whole-week policy value out of range','an out-of-range policy value');

// ---------------------------------------------------------------------------
// H. Commercial exceptions are capability-gated, append-only in effect and replay-safe.
// ---------------------------------------------------------------------------
$openExceptions=$exceptions->open();
dzn_r1_assert(count($openExceptions)>=1,'the reconciliation signals must be visible to review');
$target=$openExceptions[0]['exception_id'];
$acknowledged=$exceptions->acknowledge((int)$target,array('reason_code'=>'review_started','resolution_note'=>'Synthetic review started','evidence_channel'=>'staff_record','evidence_reference'=>'review-ack','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('review-ack'));
dzn_r1_assert($acknowledged['state']==='acknowledged','an authorised reviewer must be able to acknowledge an exception');
$resolved=$exceptions->resolve((int)$target,array('reason_code'=>'review_complete','resolution_note'=>'Synthetic review complete','evidence_channel'=>'staff_record','evidence_reference'=>'review-resolve','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('review-resolve'));
dzn_r1_assert($resolved['state']==='resolved','an authorised reviewer must be able to resolve an exception');

// ---------------------------------------------------------------------------
// I. Student change/deferment allowance stays the canonical 2 per Term, academy debt separate.
// ---------------------------------------------------------------------------
// A deferment releases the canonical schedule first (which returns the protected interval to
// protection), then cancels the occurrence with controlled non-delivery evidence, then materialises
// the make-up occurrence. The allowance is the Term's recorded canonical value.
$defer=function(int $sequence) use($wpdb,$p,$lessons,$schedules,$readyA):int{
    $lesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE term_id=%d AND canonical_sequence=%d",(int)$readyA['term_id'],$sequence));
    dzn_r1_assert($lesson!==null,'the deferred occurrence must exist');
    $version=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",(int)$lesson->id));
    dzn_r1_assert($version>0,'the deferred occurrence must hold an applicable schedule version');
    $schedules->release((int)$lesson->id,array('expected_schedule_version_id'=>$version,'reason_code'=>'student_deferment','evidence_channel'=>'staff_record','evidence_reference'=>'release-'.$sequence,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('release-'.$sequence));
    $lessons->cancel((int)$lesson->id,'authorised',dzn_r1_evidence('defer-'.$sequence)+array('reason_code'=>'attested_non_delivery'),dzn_r1_key('defer-'.$sequence));
    return (int)$lesson->id;
};
$originFive=$defer(5);
$replacementOne=$lessons->createReplacement((int)$readyA['term_id'],(int)$readyA['assignment_id'],$originFive,dzn_r1_evidence('replacement-one'),dzn_r1_key('replacement-one'));
dzn_r1_assert((int)$replacementOne['lesson_id']>0,'a Student-initiated deferment must be materialisable within the allowance');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=5",(int)$readyA['claim_id']))==='protected','releasing a deferred occurrence must restore its protected interval');
$originSix=$defer(6);
$lessons->createReplacement((int)$readyA['term_id'],(int)$readyA['assignment_id'],$originSix,dzn_r1_evidence('replacement-two'),dzn_r1_key('replacement-two'));
$originSeven=$defer(7);
dzn_r1_rejected(fn()=>$lessons->createReplacement((int)$readyA['term_id'],(int)$readyA['assignment_id'],$originSeven,dzn_r1_evidence('replacement-three'),dzn_r1_key('replacement-three')),'replacement_allocation_exhausted','a third Student deferment in one Term');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE term_id=%d",(int)$readyA['term_id']))===0,'a Student deferment must never create academy-owed debt');

// ---------------------------------------------------------------------------
// J. Term closure is refused while unmaterialised protected capacity still stands.
// ---------------------------------------------------------------------------
$j=$scenario(7,'close-j');
$productJ=$productFor((int)$j['course_id'],'AU',25000);
$offerJ=$offers->issue(array('continuation_case_id'=>(int)$j['case_id'],'product_id'=>$productJ,'region_code'=>'AU','plan_kind'=>'full','evidence_channel'=>'staff_record','evidence_reference'=>'offer-j','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-j'));
$settle($offerJ,1,'prov-j-1',25000,gmdate('Y-m-d H:i:s'));
$readyJ=$readyTerm($j,$offerJ,'j');
dzn_r1_rejected(fn()=>$terms->close((int)$readyJ['term_id'],'current',dzn_r1_evidence('close-j'),dzn_r1_key('close-j')),'active_protected_capacity_exists','closing a Term that still protects unmaterialised capacity');
$capacity->releaseClaim((int)$readyJ['claim_id'],dzn_r1_evidence('release-j')+array('release_reason_code'=>'commercial_resolution'),dzn_r1_key('release-j'));
$released=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$readyJ['claim_id']));
dzn_r1_assert((string)$released->state==='released'&&(string)$released->release_reason_code==='commercial_resolution','an authorised resolution must release the claim with its controlled reason');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$readyJ['claim_id']))===0,'releasing a claim must release its protected intervals');
$terms->close((int)$readyJ['term_id'],'current',dzn_r1_evidence('close-j-allowed'),dzn_r1_key('close-j-allowed'));
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}terms WHERE id=%d",(int)$readyJ['term_id']))==='closed','the Term must close once its protected capacity is resolved');

// ---------------------------------------------------------------------------
// K. Correction round 1: benefit consumption, exact interval identity, Course continuity,
//    historical capacity rows, conflicting evidence and Teacher-root claim release.
// ---------------------------------------------------------------------------
$k=$scenario(11,'correction-k');
$productK=$productFor((int)$k['course_id'],'AU',25000);
$patterns->establish(array('slot_authority_id'=>(int)$k['slot_authority_id'],'course_id'=>(int)$k['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-k','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-k'));
$promotionK=$promotions->define(array('promotion_code'=>'CORRECTION10','kind'=>'percentage','percentage_bp'=>1000,'first_term_only'=>true,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-k','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('promo-k'));
$adjustmentK=$adjustments->grant(array('beneficiary_student_id'=>(int)$k['student_id'],'kind'=>'percentage','percentage_bp'=>500,'reason_code'=>'service_inconvenience','evidence_channel'=>'staff_record','evidence_reference'=>'adjustment-k','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('adjustment-k'));
$offerK=$offers->issue(array('continuation_case_id'=>(int)$k['case_id'],'product_id'=>$productK,'region_code'=>'AU','plan_kind'=>'full','promotion_code'=>'CORRECTION10','account_adjustment_id'=>(int)$adjustmentK['adjustment_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'offer-k','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-k'));
// Issuance snapshots the benefits but consumes nothing.
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE offer_id=%d",(int)$offerK['offer_id']))===0,'offer issuance must not create a promotion redemption');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustmentK['adjustment_id']))==='granted','offer issuance must not consume an account adjustment');
// Course identity continuity: a valid Course-A continuation may never take a Course-B package. This
// runs while the continuation hold is still live, so the only possible refusal is the Course conflict.
$otherCourseId=(int)$sources[10]['course_id'];
dzn_r1_assert($otherCourseId!==(int)$k['course_id'],'the fixture must expose two distinct Courses');
$productOther=$productFor($otherCourseId,'AU',25000);
$offersForKBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$k['case_id']));
dzn_r1_rejected(fn()=>$offers->issue(array('continuation_case_id'=>(int)$k['case_id'],'product_id'=>$productOther,'region_code'=>'AU','plan_kind'=>'full','evidence_channel'=>'staff_record','evidence_reference'=>'offer-cross-course','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('offer-cross-course')),'commercial_course_continuity_conflict','an offer combining a Course-A continuation with a Course-B product');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$k['case_id']))===$offersForKBefore,'a Course-mismatched offer must not be recorded');
dzn_r1_rejected(fn()=>$patterns->establish(array('slot_authority_id'=>(int)$k['slot_authority_id'],'course_id'=>$otherCourseId,'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-cross-course','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('pattern-cross-course')),'commercial_course_continuity_conflict','a recurring pattern for a Course other than its authorised slot');
$settledK=$settle($offerK,1,'prov-k-1',(int)$offerK['amount_due_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert($settledK['processing_state']==='accepted','the corrected acceptance must settle');
$redemptionK=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_promotion_redemptions WHERE offer_id=%d",(int)$offerK['offer_id']));
dzn_r1_assert($redemptionK!==null&&(string)$redemptionK->state==='consumed','accepted purchase convergence must consume exactly one promotion redemption');
dzn_r1_assert((int)$redemptionK->promotion_id===(int)$promotionK['promotion_id']&&(int)$redemptionK->redemption_sequence===1,'the redemption must reference the exact snapshotted promotion');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE promotion_id=%d",(int)$promotionK['promotion_id']))===1,'exactly one redemption may exist for the accepted offer');
$consumedK=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustmentK['adjustment_id']));
dzn_r1_assert((string)$consumedK->state==='consumed'&&(int)$consumedK->consumed_offer_id===(int)$offerK['offer_id']&&(string)$consumedK->consumed_at!=='','acceptance must atomically consume the exact snapshotted adjustment');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_account_adjustment_events WHERE adjustment_id=%d AND event_type='consumed'",(int)$adjustmentK['adjustment_id']))===1,'adjustment consumption must record exactly one append-only event');
// Replay converges on the same redemption and the same consumption.
$replayK=$settle($offerK,1,'prov-k-1',(int)$offerK['amount_due_minor'],gmdate('Y-m-d H:i:s'));
dzn_r1_assert($replayK['idempotent']===true,'an exact evidence replay must converge idempotently');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE promotion_id=%d",(int)$promotionK['promotion_id']))===1,'replay must not create a second redemption');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_account_adjustment_events WHERE adjustment_id=%d AND event_type='consumed'",(int)$adjustmentK['adjustment_id']))===1,'replay must not record a second consumption event');
// A materially different fact on the same provider reference is preserved and routed, never settled.
$settlementsK=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$offerK['offer_id']));
$evidenceK=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE offer_id=%d",(int)$offerK['offer_id']));
$originalDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT evidence_fact_digest FROM {$p}commercial_payment_evidence WHERE offer_id=%d LIMIT 1",(int)$offerK['offer_id']));
$conflictingK=$payments->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'prov-k-1','evidence_kind'=>'success','amount_minor'=>'1','currency'=>'AUD','obligation_reference'=>$offerK['offer_uid'].':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-k-conflict','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('evidence-k-conflict'));
dzn_r1_assert(($conflictingK['conflicting']??false)===true&&($conflictingK['conflict_reason']??'')==='conflicting_payment_evidence','a materially different replay must be reported as a conflicting evidence fact');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$offerK['offer_id']))===$settlementsK,'a conflicting replay must never manufacture a second settlement');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE offer_id=%d",(int)$offerK['offer_id']))===$evidenceK,'a conflicting replay must never rewrite or duplicate the original evidence');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT evidence_fact_digest FROM {$p}commercial_payment_evidence WHERE offer_id=%d LIMIT 1",(int)$offerK['offer_id']))===$originalDigest,'the original evidence fact identity must be preserved');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_exceptions WHERE reason_code='conflicting_payment_evidence' AND offer_id=%d",(int)$offerK['offer_id']))>=1,'a conflicting replay must be routed into durable review');

// The exact protected interval authorises the exact Phase-N occupancy, nothing else.
$readyK=$readyTerm($k,$offerK,'correction-k');
$intervalsK=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence",(int)$readyK['claim_id']));
dzn_r1_assert(count($intervalsK)===12,'the Regular commitment must protect every committed interval');
$lessonOneK=$lessons->createStandard((int)$readyK['term_id'],(int)$readyK['assignment_id'],dzn_r1_evidence('k-lesson-one'),dzn_r1_key('k-lesson-one'));
$wrongWallK=gmdate('Y-m-d H:i:s',strtotime((string)$intervalsK[0]->starts_at_utc.' UTC +1 day'));
dzn_r1_rejected(fn()=>$schedules->schedule((int)$lessonOneK['lesson_id'],(int)$readyK['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wrongWallK,0,10),'local_wall_time'=>substr($wrongWallK,11,8),'reason_code'=>'wrong_time','evidence_channel'=>'staff_record','evidence_reference'=>'k-wrong-time','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('k-wrong-time')),'protected_interval_mismatch','a correct session scheduled at the wrong time');
// The same commitment's other protected intervals are not an exemption either.
dzn_r1_rejected(fn()=>$schedules->schedule((int)$lessonOneK['lesson_id'],(int)$readyK['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$intervalsK[2]->local_wall_date,'local_wall_time'=>(string)$intervalsK[2]->local_wall_time,'reason_code'=>'wrong_interval','evidence_channel'=>'staff_record','evidence_reference'=>'k-wrong-interval','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('k-wrong-interval')),'protected_interval_mismatch','a session scheduled onto another protected interval of its own commitment');
$scheduledK=$schedules->schedule((int)$lessonOneK['lesson_id'],(int)$readyK['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$intervalsK[0]->local_wall_date,'local_wall_time'=>(string)$intervalsK[0]->local_wall_time,'reason_code'=>'exact_interval','evidence_channel'=>'staff_record','evidence_reference'=>'k-exact','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('k-exact'));
dzn_r1_assert((int)$scheduledK['schedule_version_id']>0,'the exact authorised interval must schedule');
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claim_intervals WHERE id=%d",(int)$intervalsK[0]->id))==='satisfied','the exact interval must become satisfied');
// A satisfied interval is history: concrete occupancy blocks, not commercial corruption.
dzn_r1_rejected(fn()=>$schedules->schedule((int)$freeLessonG['lesson_id'],(int)$freeLessonG['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$intervalsK[0]->local_wall_date,'local_wall_time'=>(string)$intervalsK[0]->local_wall_time,'reason_code'=>'occupancy_conflict','evidence_channel'=>'staff_record','evidence_reference'=>'k-occupancy','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('k-occupancy')),'teacher_slot_conflict','a schedule onto concrete Phase-N occupancy');

// Historical capacity rows are never blockers: a released claim leaves its intervals reusable.
$capacity->releaseClaim((int)$readyK['claim_id'],dzn_r1_evidence('release-k')+array('release_reason_code'=>'commercial_resolution'),dzn_r1_key('release-k'));
dzn_r1_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$readyK['claim_id']))==='released','the claim must release');
dzn_r1_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$readyK['claim_id']))===0,'no protected interval may survive an authorised release');
CommercialCapacityAuthority::assertNoConflictingClaim((int)$k['teacher_id'],(string)$intervalsK[1]->starts_at_utc,(string)$intervalsK[1]->occupied_ends_at_utc);
$reused=$schedules->schedule((int)$freeLesson['lesson_id'],(int)$freeLesson['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$intervalsK[1]->local_wall_date,'local_wall_time'=>(string)$intervalsK[1]->local_wall_time,'reason_code'=>'reuse_after_release','evidence_channel'=>'staff_record','evidence_reference'=>'k-reuse','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_key('k-reuse'));
dzn_r1_assert((int)$reused['schedule_version_id']>0,'a released historical interval must be reusable by a new legitimate booking');

echo "Phase 2A.2-R1 runtime passed\n";
