<?php
/**
 * Shared disposable Phase-R1 fixture helpers for the failure, corruption and concurrency suites.
 *
 * Synthetic local data only: one introductory occurrence with its explicit authorised first regular
 * slot and the Student's continuing decision, plus a commercial package for the same Course.
 */
use Delnavazan\Platform\Core\Application\{CanonicalContinuationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalTermAuthorityService,CommercialCapacityService,CommercialCatalogueService,CommercialOfferService,CommercialPatternService,CommercialPaymentService,CommercialTermFundingService,LessonScheduleService,LessonService,TeacherAssignmentService,TeacherAvailabilityService};

function dzn_r1_fix_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_r1_fix_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_r1_fix_key(string $label):string{return 'dzn-2a2r1-'.$label.'-'.wp_generate_uuid4();}
function dzn_r1_fix_reset(array $tables):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    foreach($tables as $table)dzn_r1_fix_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable storage: '.$table);
}
function dzn_r1_fix_availability(array $teacherIds):void{
    $availability=new TeacherAvailabilityService();
    foreach($teacherIds as $teacherId){
        try{$availability->setProfile(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_r1'));}catch(Throwable$ignored){}
        foreach(array(1,2,3,4,5,6,7) as $weekday){
            try{$availability->setRecurringRule(array('teacher_id'=>(int)$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_r1'));}catch(Throwable$ignored){}
        }
    }
}
/** One payable continuation scenario: intro occurrence + authorised slot + continuing decision. */
function dzn_r1_fix_scenario(array $source,string $label,int $sequence):array{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $continuation=new CanonicalContinuationService();
    $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$source['student_id'],'teacher_id'=>(int)$source['teacher_id'],'course_id'=>(int)$source['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    // Fixed mid-day past anchor with a distinct per-scenario offset: derived intervals stay clear of
    // the daily availability window boundary regardless of the time of day the suite runs at.
    $wall=gmdate('Y-m-d H:i:s',strtotime(gmdate('Y-m-d',strtotime('-4 days')).' 12:00:00 UTC')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    // The disposable runtime shares one database across suites and concurrency modes, so a fixture must
    // never assume a pristine Teacher calendar: an earlier suite legitimately schedules or holds the
    // same Teacher at its own computed slot, and a fixed interval would then collide with it
    // (`teacher_slot_conflict`) purely because of fixture order. Authorise the first whole-week
    // candidate whose interval is free of applicable canonical Lesson schedules, effective Phase-Q
    // holds and active protected R1 capacity intervals, so the fixture is order-independent while the
    // slot remains an explicitly administrator-authorised record.
    $course=$wpdb->get_row($wpdb->prepare("SELECT default_duration_minutes,default_buffer_minutes FROM {$p}courses WHERE id=%d",(int)$source['course_id']));
    $duration=(int)($course->default_duration_minutes??30);$buffer=(int)($course->default_buffer_minutes??15);
    $teacherId=(int)$source['teacher_id'];$now=gmdate('Y-m-d H:i:s');$slotWall=null;
    for($week=0;$week<=52;$week++){
        $candidate=gmdate('Y-m-d H:i:s',strtotime($wall)+7*86400+($week*7*86400));
        $resolved=\Delnavazan\Platform\Core\Application\CanonicalContinuationRule::resolveWallClock('UTC',substr($candidate,0,10),substr($candidate,11,8),$duration,$buffer);
        $starts=(string)$resolved['starts_at_utc'];$occupied=(string)$resolved['occupied_ends_at_utc'];
        $scheduled=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE teacher_id=%d AND applicable_slot=1 AND starts_at_utc<%s AND occupied_ends_at_utc>%s",$teacherId,$occupied,$starts));
        $held=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations WHERE teacher_id=%d AND state='active' AND expires_at>%s AND starts_at_utc<%s AND occupied_ends_at_utc>%s",$teacherId,$now,$occupied,$starts));
        $protected=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals interval_row INNER JOIN {$p}commercial_capacity_claims claim ON claim.id=interval_row.claim_id WHERE interval_row.teacher_id=%d AND interval_row.state='protected' AND claim.state='active' AND interval_row.starts_at_utc<%s AND interval_row.occupied_ends_at_utc>%s",$teacherId,$occupied,$starts));
        if($scheduled===0&&$held===0&&$protected===0){$slotWall=$candidate;break;}
    }
    dzn_r1_fix_assert($slotWall!==null,'no free authorised first regular slot is available for this Teacher');
    $slot=$continuation->recordFirstRegularSlot($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('slot-'.$label));
    $principal=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",(int)$source['student_id']));
    dzn_r1_fix_assert($principal>0,'Phase-J principal fixture required');
    $previous=get_current_user_id();
    wp_set_current_user($principal);
    try{
        $decision=$continuation->continueWithTeacher($lessonId,array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'continue-'.$label),dzn_r1_fix_key('continue-'.$label));
    }finally{
        wp_set_current_user($previous>0?$previous:1);
    }
    dzn_r1_fix_assert($decision['decision']==='continue_with_teacher'&&(int)($decision['reservation']['reservation_id']??0)>0,'the continuing Student must hold the expected first regular slot');
    $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));
    $reservation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$case->id));
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$source['student_id'],'teacher_id'=>(int)$source['teacher_id'],'course_id'=>(int)$source['course_id'],'enrolment_id'=>(int)$source['enrolment_id'],'case_id'=>(int)$case->id,'reservation_id'=>(int)$reservation->id,'slot_authority_id'=>(int)$slot['slot_authority_id'],'reservation'=>$reservation);
}
function dzn_r1_fix_product(int $courseId,string $region,int $amount,string $label):int{
    $product=(new CommercialCatalogueService())->createProduct(array('course_id'=>$courseId,'name_en'=>'Synthetic R1 '.$label,'status'=>'active','evidence_channel'=>'staff_record','evidence_reference'=>'product-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('product-'.$label));
    (new CommercialCatalogueService())->setPrice(array('product_id'=>(int)$product['product_id'],'region_code'=>$region,'amount_minor'=>(string)$amount,'evidence_channel'=>'staff_record','evidence_reference'=>'price-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('price-'.$label));
    return (int)$product['product_id'];
}
function dzn_r1_fix_pattern(array $scenario,string $label):array{
    return (new CommercialPatternService())->establish(array('slot_authority_id'=>(int)$scenario['slot_authority_id'],'course_id'=>(int)$scenario['course_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'pattern-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('pattern-'.$label));
}
function dzn_r1_fix_offer(array $scenario,int $productId,string $plan,string $label):array{
    return (new CommercialOfferService())->issue(array('continuation_case_id'=>(int)$scenario['case_id'],'product_id'=>$productId,'region_code'=>'AU','plan_kind'=>$plan,'evidence_channel'=>'staff_record','evidence_reference'=>'offer-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('offer-'.$label));
}
function dzn_r1_fix_obligation(array $offer,int $sequence):array{
    foreach($offer['obligations'] as $row)if((int)$row['obligation_sequence']===$sequence)return $row;
    throw new RuntimeException('Offer obligation missing');
}
function dzn_r1_fix_settle(array $offer,int $sequence,string $reference,?int $amount=null,string $kind='success'):array{
    $obligation=dzn_r1_fix_obligation($offer,$sequence);
    return (new CommercialPaymentService())->ingest(array(
        'provider_key'=>'synthetic_provider','provider_reference'=>$reference,'evidence_kind'=>$kind,
        'amount_minor'=>$kind==='success'?(string)($amount??(int)$obligation['amount_minor']):null,
        'currency'=>$kind==='success'?$offer['currency']:null,
        'obligation_reference'=>$offer['offer_uid'].':'.$sequence,'provider_occurred_at'=>gmdate('Y-m-d H:i:s'),
        'evidence_channel'=>'provider_evidence','evidence_reference'=>'evidence-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_r1_fix_key('evidence-'.$reference));
}
function dzn_r1_fix_entitlement(int $offerId):int{
    global $wpdb;
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT e.id FROM {$wpdb->prefix}dzn_commercial_entitlements e INNER JOIN {$wpdb->prefix}dzn_commercial_purchases c ON c.id=e.purchase_id WHERE c.offer_id=%d",$offerId));
    dzn_r1_fix_assert($id>0,'the accepted payment must hold an entitlement');
    return $id;
}
function dzn_r1_fix_activate_enrolment(int $enrolmentId,string $label):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d",$enrolmentId));
    if($state==='current')return;
    dzn_r1_fix_assert($state==='authorised','Disposable runtime Enrolment state is not reusable: '.$state);
    (new CanonicalEnrolmentLifecycleService())->activate($enrolmentId,'authorised',dzn_r1_fix_evidence('activate-'.$label),dzn_r1_fix_key('activate-'.$label));
}
function dzn_r1_fix_handoff(int $entitlementId,string $label):array{
    return (new CommercialCapacityService())->handoffFromEntitlement($entitlementId,dzn_r1_fix_evidence('handoff-'.$label),dzn_r1_fix_key('handoff-'.$label));
}
function dzn_r1_fix_bind(int $entitlementId,string $label):array{
    return (new CommercialTermFundingService())->bindEntitlementToTerm($entitlementId,dzn_r1_fix_evidence('bind-'.$label),dzn_r1_fix_key('bind-'.$label));
}
function dzn_r1_fix_release_claim(int $claimId,string $label):array{
    return (new CommercialCapacityService())->releaseClaim($claimId,dzn_r1_fix_evidence('release-'.$label)+array('release_reason_code'=>'commercial_resolution'),dzn_r1_fix_key('release-'.$label));
}
/** A commercial-free canonical Term with one unscheduled Lesson, used as competing capacity. */
function dzn_r1_fix_free_lesson(array $source,string $label):array{
    $enrolmentId=(int)$source['enrolment_id'];
    dzn_r1_fix_activate_enrolment($enrolmentId,$label);
    $terms=new CanonicalTermAuthorityService();
    $term=$terms->create($enrolmentId,null,null,dzn_r1_fix_evidence('free-term-'.$label),dzn_r1_fix_key('free-term-'.$label));
    $terms->activate((int)$term['term_id'],'authorised',dzn_r1_fix_evidence('free-term-active-'.$label),dzn_r1_fix_key('free-term-active-'.$label));
    $assignment=(new TeacherAssignmentService())->assignInitial($enrolmentId,dzn_r1_fix_key('free-assignment-'.$label));
    $lesson=(new CanonicalLessonAuthorityService())->createStandard((int)$term['term_id'],(int)$assignment['assignment_id'],dzn_r1_fix_evidence('free-lesson-'.$label),dzn_r1_fix_key('free-lesson-'.$label));
    return array('term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$assignment['assignment_id'],'lesson_id'=>(int)$lesson['lesson_id']);
}
