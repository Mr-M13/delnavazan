<?php
/**
 * Disposable production-path proof for owner-bound Student promotion application.
 *
 * Synthetic local data only. This exercises immutable offer supersession, authoritative discounted
 * obligations, stale Checkout rejection, settlement-time redemption and the read seam the Student
 * portal consumes.
 */
if(getenv('DZN_PROMOTION_APPLICATION_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Promotion application runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationService,CommercialCatalogueService,CommercialOfferService,CommercialPaymentService,CommercialPromotionService,LessonScheduleService,LessonService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Application\Checkout\StudentCommercialCheckoutReadService;
use Delnavazan\Platform\Core\Support\Identifier;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_promo_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_promo_key(string $label):string{return 'dzn-promotion-application-'.$label.'-'.wp_generate_uuid4();}
function dzn_promo_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_promo_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_promo_assert($caught!==null,$message.' was accepted');dzn_promo_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}

$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_promo_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
$sources=$fixture['sources'];
foreach(array('checkout_sessions','commercial_commands','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities') as $table){
    dzn_promo_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable storage: '.$table);
}
$admin=1;wp_set_current_user($admin);
$continuation=new CanonicalContinuationService();
$catalogue=new CommercialCatalogueService();
$promotions=new CommercialPromotionService();
$offers=new CommercialOfferService();
$payments=new CommercialPaymentService();
$principalOf=static function(int $studentId) use($wpdb,$p):int{
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",$studentId));
    if($id<1)throw new RuntimeException('Phase-J principal fixture required');
    return $id;
};
$availability=new TeacherAvailabilityService();
foreach(($fixture['teachers']??array()) as $teacherId){
    try{$availability->setProfile(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_promotion'));}catch(Throwable$ignored){}
    foreach(array(1,2,3,4,5,6,7) as $weekday){
        try{$availability->setRecurringRule(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_promotion'));}catch(Throwable$ignored){}
    }
}
$scenario=function(int $index,string $label,int $slotOffsetDays=7) use($sources,$continuation,$principalOf,$admin,$wpdb,$p):array{
    static $sequence=0;$sequence++;
    $src=$sources[$index%count($sources)];
    // The disposable Teacher calendar is shared with the Phase-R1 regression suite. Allocate a
    // synthetic introductory occurrence with a bounded retry so unrelated leftover occupancy can
    // never make this lifecycle test flaky; failed attempts simply leave an unscheduled draft.
    $lessonId=0;$occurrence=null;$wall='';$scheduled=false;
    for($attempt=0;$attempt<25&&!$scheduled;$attempt++){
        $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
        $seed=abs((int)crc32(wp_generate_uuid4().':'.$attempt));
        $anchor=strtotime(gmdate('Y-m-d',strtotime('-5 days')).' 00:00:00 UTC')+(($seed%(23*60))*60);
        $wall=gmdate('Y-m-d H:i:s',$anchor-($sequence*60));
        try{
            (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
            $occurrence=$wpdb->get_row($wpdb->prepare("SELECT v.* FROM {$p}lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL",$lessonId));
            $scheduled=$occurrence!==null;
        }catch(\InvalidArgumentException$error){
            if($error->getMessage()!=='teacher_slot_conflict')throw $error;
        }
    }
    dzn_promo_assert($scheduled&&$occurrence!==null,'introductory occurrence fixture missing');
    $slotWall=gmdate('Y-m-d H:i:s',strtotime($wall)+$slotOffsetDays*86400);
    $slot=$continuation->recordFirstRegularSlot($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('slot-'.$label));
    wp_set_current_user($principalOf((int)$src['student_id']));
    try{
        $decision=$continuation->continueWithTeacher($lessonId,array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'continue-'.$label),dzn_promo_key('continue-'.$label));
    }finally{
        wp_set_current_user($admin);
    }
    dzn_promo_assert($decision['decision']==='continue_with_teacher'&&(int)($decision['reservation']['reservation_id']??0)>0,'the continuing Student must hold the expected first regular slot');
    $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));
    $reservation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$case->id));
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'enrolment_id'=>(int)$src['enrolment_id'],'case_id'=>(int)$case->id,'reservation_id'=>(int)$reservation->id,'slot_authority_id'=>(int)$slot['slot_authority_id'],'reservation'=>$reservation);
};
$productFor=function(int $courseId,string $region,int $amount) use($catalogue):int{
    $product=$catalogue->createProduct(array('course_id'=>$courseId,'name_en'=>'Synthetic promotion package','status'=>'active','evidence_channel'=>'staff_record','evidence_reference'=>'product-'.$courseId,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('product-'.$courseId));
    $catalogue->setPrice(array('product_id'=>(int)$product['product_id'],'region_code'=>$region,'amount_minor'=>(string)$amount,'evidence_channel'=>'staff_record','evidence_reference'=>'price-'.$courseId,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('price-'.$courseId));
    return (int)$product['product_id'];
};
$offerFor=function(array $scenario,int $productId,string $label,bool $twoInstalments=false) use($offers):array{
    return $offers->issue(array(
        'continuation_case_id'=>(int)$scenario['case_id'],'product_id'=>$productId,'region_code'=>'AU',
        'plan_kind'=>$twoInstalments?'two_instalments':'full','evidence_channel'=>'staff_record',
        'evidence_reference'=>'offer-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_promo_key('offer-'.$label));
};
$settle=function(array $offer,string $reference,int $amount) use($payments):array{
    return $payments->ingest(array(
        'provider_key'=>'synthetic_provider','provider_reference'=>$reference,'evidence_kind'=>'success',
        'amount_minor'=>(string)$amount,'currency'=>$offer['currency'],
        'obligation_reference'=>$offer['offer_uid'].':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),
        'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_promo_key('evidence-'.$reference));
};
$apply=function(array $offer,string $code,int $studentId,int $principal) use($offers,$admin):array{
    wp_set_current_user($principal);
    try{return $offers->applyPromotion((int)$offer['obligations'][0]['obligation_id'],$code,$studentId);}finally{wp_set_current_user($admin);}
};

// ---------------------------------------------------------------------------
// A. Percentage promotion applied after issuance: immutable supersession and settlement.
// ---------------------------------------------------------------------------
$a=$scenario(0,'promo-a');
$productA=$productFor((int)$a['course_id'],'AU',25000);
$offerA=$offerFor($a,$productA,'a');
$principalA=$principalOf((int)$a['student_id']);
$pct10=$promotions->define(array('promotion_code'=>'PCT10','kind'=>'percentage','percentage_bp'=>1000,'per_beneficiary_limit'=>2,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-pct10','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-pct10'));

// The production post-intro workflow owns a preparation and plan choice for this case. They are
// keyed by offer id, so supersession must move them to the revised snapshot.
$prepInsert=$wpdb->insert($p.'post_intro_preparations',array(
    'uid'=>Identifier::uid(),'booking_request_id'=>9000000+wp_rand(1,99999),'outcome_id'=>9100000+wp_rand(1,99999),
    'student_id'=>(int)$a['student_id'],'teacher_id'=>(int)$a['teacher_id'],'intro_lesson_id'=>(int)$a['lesson_id'],
    'continuation_case_id'=>(int)$a['case_id'],'offer_id'=>(int)$offerA['offer_id'],'offer_uid'=>(string)$offerA['offer_uid'],
    'state'=>'checkout_pending','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
));
$preparationId=(int)$wpdb->insert_id;
$choiceInsert=$wpdb->insert($p.'post_intro_plan_choices',array(
    'uid'=>Identifier::uid(),'preparation_id'=>$preparationId,'active_slot'=>1,'student_id'=>(int)$a['student_id'],
    'plan_kind'=>'full','quote_digest'=>str_repeat('d',64),'quoted_amount_due_minor'=>25000,'currency'=>'AUD',
    'instalment_due_at'=>null,'policy_version'=>null,'offer_id'=>(int)$offerA['offer_id'],
    'chosen_at'=>gmdate('Y-m-d H:i:s'),'chosen_by'=>$admin,'created_at'=>gmdate('Y-m-d H:i:s'),
));
$choiceId=(int)$wpdb->insert_id;
dzn_promo_assert($prepInsert!==false&&$choiceInsert!==false&&$preparationId>0&&$choiceId>0,'post-intro preparation fixture required');

// A live attempt exists for the pre-discount obligation. Applying a code must retire it.
$staleInsert=$wpdb->insert($p.'checkout_sessions',array(
    'uid'=>Identifier::uid(),'student_id'=>(int)$a['student_id'],'offer_id'=>(int)$offerA['offer_id'],
    'obligation_id'=>(int)$offerA['obligations'][0]['obligation_id'],'amount_minor'=>25000,'currency'=>'AUD',
    'state'=>'open','active_slot'=>1,'request_key_digest'=>hash('sha256','stale-promotion-session'),
    'provider_key'=>'stripe','provider_reference_digest'=>null,'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$admin,
    'expires_at'=>gmdate('Y-m-d H:i:s',time()+300),'closed_at'=>null,'close_reason'=>null,
));
$staleSessionId=(int)$wpdb->insert_id;
dzn_promo_assert($staleInsert!==false&&$staleSessionId>0,'stale checkout fixture required');

wp_set_current_user($principalA);
$preview=$offers->previewPromotion((int)$offerA['obligations'][0]['obligation_id'],'pct10',(int)$a['student_id']);
dzn_promo_assert($preview['valid']===true&&(int)$preview['original_amount_minor']===25000&&(int)$preview['promotion_amount_minor']===2500&&(int)$preview['final_amount_minor']===22500,'preview must derive the discount from the canonical offer');
wp_set_current_user($admin);

$appliedA=$apply($offerA,'PCT10',(int)$a['student_id'],$principalA);
dzn_promo_assert($appliedA['applied']===true&&$appliedA['idempotent']===false,'the first application must create a revised offer');
dzn_promo_assert((int)$appliedA['offer_id']!==(int)$offerA['offer_id'],'the original offer must never be edited in place');
dzn_promo_assert((int)$appliedA['original_amount_minor']===25000&&(int)$appliedA['promotion_amount_minor']===2500&&(int)$appliedA['final_amount_minor']===22500,'the revised offer must carry exact server amounts');
dzn_promo_assert((int)$appliedA['obligations'][0]['amount_minor']===22500,'the payable obligation must be the discounted amount');

$oldRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offers WHERE id=%d",(int)$offerA['offer_id']));
$newRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offers WHERE id=%d",(int)$appliedA['offer_id']));
dzn_promo_assert((string)$oldRow->state==='superseded','the original snapshot must become superseded');
dzn_promo_assert((int)$oldRow->base_amount_minor===25000&&(int)$oldRow->amount_due_minor===25000,'the original snapshot amount must stay immutable');
dzn_promo_assert((string)$newRow->state==='issued'&&(int)$newRow->promotion_id===(int)$pct10['promotion_id'],'the revised offer must snapshot the exact promotion');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$a['case_id']))===2,'one immutable successor may exist');

$session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}checkout_sessions WHERE id=%d",$staleSessionId));
dzn_promo_assert($session!==null&&(string)$session->state==='failed'&&$session->active_slot===null&&(string)$session->close_reason==='offer_superseded','a stale checkout attempt must be retired when the offer is superseded');
$preparationRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}post_intro_preparations WHERE id=%d",$preparationId));
$choiceRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}post_intro_plan_choices WHERE id=%d",$choiceId));
dzn_promo_assert($preparationRow!==null&&(int)$preparationRow->offer_id===(int)$appliedA['offer_id']&&(string)$preparationRow->offer_uid===(string)$appliedA['offer_uid'],'the post-intro preparation must point at the revised offer');
dzn_promo_assert($choiceRow!==null&&(int)$choiceRow->offer_id===(int)$appliedA['offer_id'],'the active plan choice must point at the revised offer');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE promotion_id=%d",(int)$pct10['promotion_id']))===0,'applying a promotion must not consume a redemption');

$replayA=$apply($offerA,'PCT10',(int)$a['student_id'],$principalA);
dzn_promo_assert($replayA['idempotent']===true&&(int)$replayA['offer_id']===(int)$appliedA['offer_id'],'a repeated application must converge on the same revision');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$a['case_id']))===2,'a repeated application must not create a second revision');

$pct20=$promotions->define(array('promotion_code'=>'PCT20','kind'=>'percentage','percentage_bp'=>2000,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-pct20','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-pct20'));
dzn_promo_rejected(fn()=>$apply($offerA,'PCT20',(int)$a['student_id'],$principalA),'promotion_already_applied','a different code on an already-discounted offer');

// The Student portal read exposes only the live, discounted obligation set.
wp_set_current_user($principalA);
$readA=(new StudentCommercialCheckoutReadService())->read();
wp_set_current_user($admin);
dzn_promo_assert(count($readA['obligations'])===1,'superseded offers must not appear as a second payable plan');
dzn_promo_assert((string)$readA['obligations'][0]['offer_uid']===(string)$appliedA['offer_uid'],'the read seam must expose the successor offer');
dzn_promo_assert($readA['obligations'][0]['offer_has_promotion']===true&&(int)$readA['obligations'][0]['amount_minor']===22500,'the read seam must expose the confirmed discounted amount');

// A verified payment against the superseded obligation is preserved but refused.
$stale=$payments->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'promo-a-stale','evidence_kind'=>'success','amount_minor'=>'25000','currency'=>'AUD','obligation_reference'=>$offerA['offer_uid'].':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-promo-a-stale','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-a-stale'));
dzn_promo_assert($stale['processing_state']==='rejected'&&$stale['reason_code']==='invalid_or_expired_offer','a superseded offer must never settle');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$offerA['obligations'][0]['obligation_id']))===0,'a stale checkout payment must not create settlement truth');

// The authoritative discounted amount settles exactly and consumes exactly one redemption.
$settledA=$settle($appliedA,'promo-a-current',22500);
dzn_promo_assert($settledA['processing_state']==='accepted','the discounted obligation must settle at the authorised amount');
$redemptionA=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_promotion_redemptions WHERE offer_id=%d",(int)$appliedA['offer_id']));
dzn_promo_assert($redemptionA!==null&&(string)$redemptionA->state==='consumed'&&(int)$redemptionA->promotion_id===(int)$pct10['promotion_id'],'settlement must consume the exact promotion snapshot');
$duplicateA=$payments->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'promo-a-current','evidence_kind'=>'success','amount_minor'=>'22500','currency'=>'AUD','obligation_reference'=>$appliedA['offer_uid'].':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-promo-a-current','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-a-duplicate'));
dzn_promo_assert($duplicateA['idempotent']===true,'a duplicate verified webhook must converge');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE promotion_id=%d",(int)$pct10['promotion_id']))===1,'a duplicate webhook must not duplicate redemption');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$appliedA['offer_id']))===1,'a duplicate webhook must not duplicate settlement');
$activationJob=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}post_intro_activation_jobs WHERE purchase_id=%d",(int)$settledA['purchase_id']));
$preparationAfter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}post_intro_preparations WHERE id=%d",$preparationId));
dzn_promo_assert($activationJob!==null&&(int)$activationJob->offer_id===(int)$appliedA['offer_id']&&(int)$activationJob->preparation_id===$preparationId,'verified settlement must create the activation path for the revised offer');
dzn_promo_assert($preparationAfter!==null&&(string)$preparationAfter->state==='payment_received','verified settlement must advance the post-intro preparation');

// ---------------------------------------------------------------------------
// B. Fixed amount, currency, expiry, product scope, cap and rounding boundaries.
// ---------------------------------------------------------------------------
$b=$scenario(1,'promo-b');
$productB=$productFor((int)$b['course_id'],'AU',30000);
$offerB=$offerFor($b,$productB,'b');
$principalB=$principalOf((int)$b['student_id']);
$fixed=$promotions->define(array('promotion_code'=>'FIX5000','kind'=>'fixed','fixed_amount_minor'=>5000,'currency'=>'AUD','per_beneficiary_limit'=>2,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-fixed','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-fixed'));
$appliedB=$apply($offerB,'FIX5000',(int)$b['student_id'],$principalB);
dzn_promo_assert((int)$appliedB['promotion_amount_minor']===5000&&(int)$appliedB['final_amount_minor']===25000,'a fixed promotion must apply its exact minor-unit amount');

$c=$scenario(2,'promo-c');
$productC=$productFor((int)$c['course_id'],'AU',30000);
$offerC=$offerFor($c,$productC,'c');
$principalC=$principalOf((int)$c['student_id']);

$usd=$promotions->define(array('promotion_code'=>'FIXUSD','kind'=>'fixed','fixed_amount_minor'=>5000,'currency'=>'USD','per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-usd','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-usd'));
dzn_promo_rejected(fn()=>$apply($offerC,'FIXUSD',(int)$c['student_id'],$principalC),'promotion_ineligible:promotion_currency_mismatch','a fixed promotion in another currency');

$expired=$promotions->define(array('promotion_code'=>'EXPIRED10','kind'=>'percentage','percentage_bp'=>1000,'valid_from'=>gmdate('Y-m-d H:i:s',time()-172800),'valid_until'=>gmdate('Y-m-d H:i:s',time()-86400),'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-expired','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-expired'));
dzn_promo_rejected(fn()=>$apply($offerC,'EXPIRED10',(int)$c['student_id'],$principalC),'promotion_ineligible:promotion_expired','an expired promotion');

$otherProduct=$promotions->define(array('promotion_code'=>'OTHERPRODUCT','kind'=>'percentage','percentage_bp'=>1000,'product_id'=>$productB,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-other-product','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-other-product'));
dzn_promo_rejected(fn()=>$apply($offerC,'OTHERPRODUCT',(int)$c['student_id'],$principalC),'promotion_ineligible:promotion_product_mismatch','a promotion scoped to another product');

$tooBig=$promotions->define(array('promotion_code'=>'TOOBIG','kind'=>'fixed','fixed_amount_minor'=>40000,'currency'=>'AUD','per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-too-big','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-too-big'));
dzn_promo_rejected(fn()=>$apply($offerC,'TOOBIG',(int)$c['student_id'],$principalC),'promotion_ineligible:commercial_offer_amount_invalid','a discount larger than the whole payable amount');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$c['case_id']))===1,'a refused promotion must not create a revision');

$round=$promotions->define(array('promotion_code'=>'ROUND3333','kind'=>'percentage','percentage_bp'=>3333,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-round','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-round'));

// REST security boundary: nonce, authentication, object ownership and no client amounts.
$rest=function(array $params,?string $nonce):\WP_REST_Request{
    $request=new \WP_REST_Request('POST','/delnavazan-platform/v1/commercial/promotion/apply');
    $request->set_header('Content-Type','application/json');
    if($nonce!==null)$request->set_header('X-WP-Nonce',$nonce);
    $request->set_body(wp_json_encode($params));
    return $request;
};
wp_set_current_user($principalA);
$nonce=wp_create_nonce('wp_rest');
dzn_promo_assert(\Delnavazan\Platform\Public\CommercialPromotionRestController::permitted($rest(array(),$nonce))===true,'a valid Student nonce must be accepted');
$invalidNonce=\Delnavazan\Platform\Public\CommercialPromotionRestController::permitted($rest(array(),'invalid-nonce'));
dzn_promo_assert($invalidNonce instanceof \WP_Error&&(int)($invalidNonce->get_error_data()['status']??0)===403,'an invalid nonce must be refused');
$missingNonce=\Delnavazan\Platform\Public\CommercialPromotionRestController::permitted($rest(array(),null));
dzn_promo_assert($missingNonce instanceof \WP_Error&&(int)($missingNonce->get_error_data()['status']??0)===403,'a missing nonce must be refused');
$extraField=\Delnavazan\Platform\Public\CommercialPromotionRestController::apply($rest(array('obligation_uid'=>(string)$appliedA['obligations'][0]['obligation_uid'],'promotion_code'=>'PCT10','amount_minor'=>1),$nonce));
dzn_promo_assert($extraField->get_status()===400&&($extraField->get_data()['reason']??'')==='promotion_request_invalid','a client-supplied amount must be refused before any application');
$forgedUid=\Delnavazan\Platform\Public\CommercialPromotionRestController::apply($rest(array('obligation_uid'=>str_repeat('Z',26),'promotion_code'=>'PCT10'),$nonce));
dzn_promo_assert($forgedUid->get_status()===404&&($forgedUid->get_data()['reason']??'')==='promotion_obligation_unavailable','a forged obligation identifier must fail closed');
$offerCObligationUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence,id LIMIT 1",(int)$offerC['offer_id']));
$crossStudent=\Delnavazan\Platform\Public\CommercialPromotionRestController::apply($rest(array('obligation_uid'=>$offerCObligationUid,'promotion_code'=>'ROUND3333'),$nonce));
dzn_promo_assert($crossStudent->get_status()===404&&($crossStudent->get_data()['reason']??'')==='promotion_obligation_unavailable','a Student must not apply a promotion to another Student obligation');
$reapply=\Delnavazan\Platform\Public\CommercialPromotionRestController::apply($rest(array('obligation_uid'=>(string)$appliedA['obligations'][0]['obligation_uid'],'promotion_code'=>'PCT20'),$nonce));
dzn_promo_assert($reapply->get_status()===409&&($reapply->get_data()['reason']??'')==='promotion_already_applied','the REST surface must keep the no-stacking rule');
wp_set_current_user(0);
$anonymous=\Delnavazan\Platform\Public\CommercialPromotionRestController::permitted($rest(array(),'anything'));
dzn_promo_assert($anonymous instanceof \WP_Error&&(int)($anonymous->get_error_data()['status']??0)===401,'an unauthenticated request must be refused');
wp_set_current_user($admin);

$appliedC=$apply($offerC,'ROUND3333',(int)$c['student_id'],$principalC);
dzn_promo_assert((int)$appliedC['promotion_amount_minor']===9999&&(int)$appliedC['final_amount_minor']===20001,'33.33% of 30000 must be exact integer minor units');

// ---------------------------------------------------------------------------
// C. A promotion reaching its global redemption limit after one settlement.
// ---------------------------------------------------------------------------
$d=$scenario(3,'promo-d');
$productD=$productFor((int)$d['course_id'],'AU',25000);
$offerD=$offerFor($d,$productD,'d');
$principalD=$principalOf((int)$d['student_id']);
$e=$scenario(4,'promo-e');
$productE=$productFor((int)$e['course_id'],'AU',25000);
$offerE=$offerFor($e,$productE,'e');
$principalE=$principalOf((int)$e['student_id']);
$once=$promotions->define(array('promotion_code'=>'ONCE10','kind'=>'percentage','percentage_bp'=>1000,'max_redemptions'=>1,'per_beneficiary_limit'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-once','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_promo_key('promo-once'));
$appliedD=$apply($offerD,'ONCE10',(int)$d['student_id'],$principalD);
$settledD=$settle($appliedD,'promo-d-current',22500);
dzn_promo_assert($settledD['processing_state']==='accepted','the first limited redemption must settle');
dzn_promo_rejected(fn()=>$apply($offerE,'ONCE10',(int)$e['student_id'],$principalE),'promotion_ineligible:promotion_usage_limit_reached','a promotion whose global limit is already consumed');
dzn_promo_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_offers WHERE continuation_case_id=%d",(int)$e['case_id']))===1,'a limit refusal must not create a revision');

echo "Promotion application runtime passed\n";
