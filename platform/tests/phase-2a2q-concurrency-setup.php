<?php
/** Prepare one deterministic gated Phase-Q continuation/slot race on synthetic production-path data. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-Q concurrency setup refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationRule,CanonicalContinuationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,LessonScheduleService,LessonService,StudentAcceptanceAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeachingEligibilityService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2Q_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
if(!is_array($fixture)||count($fixture['sources']??array())<3)throw new RuntimeException('Phase-J fixture required');
$sources=$fixture['sources'];
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$key=static fn(string $label):string=>'dzn-2a2q-race-'.$label.'-'.wp_generate_uuid4();
$admin=get_current_user_id();
$introOf=function(int $index,int $sequence,string $label) use($sources,$wpdb,$p):array{
    $src=$sources[$index%count($sources)];
    $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    $wall=gmdate('Y-m-d H:i:s',strtotime('-3 days')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    $occurrence=$wpdb->get_row($wpdb->prepare("SELECT v.* FROM {$p}lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL",$lessonId));
    $principal=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",(int)$src['student_id']));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$occurrence->id,'start'=>(string)$occurrence->starts_at_utc,'end'=>(string)$occurrence->ends_at_utc,'student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'principal'=>$principal);
};
$first=$introOf(0,1,'race-one');$second=$introOf(1,2,'race-two');
// Disposable harness: clear Phase-Q storage and give the baseline scenarios distinct Teachers so
// only the deliberately conflicting mode shares a Teacher and slot.
foreach(array('canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities')as$qTable)$wpdb->query("DELETE FROM {$p}{$qTable}");
$continuation=new CanonicalContinuationService();
$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();$assignments=new TeacherAssignmentService();
$lessonAuthority=new CanonicalLessonAuthorityService();$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$adminUser=(int)$admin;
/** A canonical Lesson chain whose Assignment belongs to one exact Teacher, ready to be scheduled. */
$raceChain=function(int $teacherId,string $label) use($wpdb,$p,$enrolments,$terms,$assignments,$lessonAuthority,$availability,$accepting,$evidence,$key):array{
    $enrolmentId=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
    if($enrolmentId<1)throw new RuntimeException('no_available_source');
    $enrolments->activate($enrolmentId,'authorised',$evidence('race-activate'),$key('race-activate'));
    $term=$terms->create($enrolmentId,null,null,$evidence('race-term'),$key('race-term'));
    $terms->activate((int)$term['term_id'],'authorised',$evidence('race-term-active'),$key('race-term-active'));
    $assignment=$assignments->assignInitial($enrolmentId,$key('race-assignment'));
    $courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$enrolmentId));
    (new \Delnavazan\Platform\Core\Application\TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacherId,'course_id'=>$courseId,'status'=>'active','reason_code'=>'synthetic_race'));
    $accepting->set(array('teacher_id'=>$teacherId,'state'=>'accepting','reason_code'=>'synthetic_race'));
    $availability->setProfile(array('teacher_id'=>$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_race'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_race'));
    $moved=$assignments->replace($enrolmentId,$teacherId,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'race-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),$key('race-isolate'));
    $lessonId=(int)$lessonAuthority->createStandard((int)$term['term_id'],(int)$moved['assignment_id'],$evidence('race-'.$label),$key('race-lesson'))['lesson_id'];
    return array('lesson_id'=>$lessonId,'assignment_id'=>(int)$moved['assignment_id'],'enrolment_id'=>$enrolmentId,'teacher_id'=>$teacherId);
};
// The first regular slot is an EXPLICIT authorised fact, never a derivation of the introduction time.
$authoriseSlot=function(array $occurrence,string $label,string $wall) use($continuation,$key,$evidence):array{
    return $continuation->recordFirstRegularSlot((int)$occurrence['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'race-slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),$key('slot-'.$label));
};
// Authorised slots must be genuinely in the future so the holds are capacity-effective.
$firstSlotWall=gmdate('Y-m-d H:i:s',time()+7200);
$secondSlotWall=gmdate('Y-m-d H:i:s',time()+10800);
$firstSlot=$authoriseSlot($first,'one',$firstSlotWall);
$secondSlot=$authoriseSlot($second,'two',$secondSlotWall);
$teacherUser=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",(int)$first['teacher_id']));
if($teacherUser>0)(new \WP_User($teacherUser))->add_cap('dzn_submit_own_continuation_match_exception');
if($mode!=='competing_hold_same_slot'&&(int)$first['teacher_id']===(int)$second['teacher_id']){
    $alternative=(int)($fixture['teachers'][2]??0);
    if($alternative<1||$alternative===(int)$first['teacher_id'])throw new RuntimeException('A distinct synthetic Teacher is required for the Phase-Q race fixture');
    $wpdb->update($p.'lessons',array('teacher_id'=>$alternative),array('id'=>(int)$second['lesson_id']));
    $wpdb->update($p.'canonical_continuation_slot_authorities',array('teacher_id'=>$alternative),array('intro_lesson_id'=>(int)$second['lesson_id']));
    $second['teacher_id']=$alternative;
}
$state=array('mode'=>$mode,'at'=>gmdate('Y-m-d H:i:s'),'first'=>$first,'second'=>$second,'teacher_user'=>$teacherUser,
    'keys'=>array('w1'=>$key('w1'),'w2'=>$key('w2')),'references'=>array('w1'=>'race-w1','w2'=>'race-w2'));
switch($mode){
    case 'continue_exact_replay':
        $sharedExact=$key('shared');
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'first'));
        $state['keys']=array('w1'=>$sharedExact,'w2'=>$sharedExact);
        $state['references']=array('w1'=>'race-shared','w2'=>'race-shared');
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_after_decision_insert','w2'=>null);
        break;
    case 'command_key_changed_decision':
        $shared=$key('shared');
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'contact','target'=>'first'));
        $state['keys']=array('w1'=>$shared,'w2'=>$shared);
        $state['references']=array('w1'=>'race-shared','w2'=>'race-shared');
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_after_decision_insert','w2'=>null);
        break;
    case 'two_student_decisions':
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'contact','target'=>'first'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_after_reservation_insert','w2'=>null);
        break;
    case 'continue_vs_teacher_exception':
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'teacher_exception','target'=>'first'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_after_reservation_insert','w2'=>null);
        break;
    case 'competing_hold_same_slot':
        // Both Students expect the SAME Teacher and the SAME first regular slot.
        $wpdb->update($p.'lessons',array('teacher_id'=>(int)$first['teacher_id']),array('id'=>(int)$second['lesson_id']));
        // Re-authorise the second Student's slot at the FIRST Student's exact authorised wall clock so
        // the two holds compete for one exclusive Teacher interval.
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_slot_authorities WHERE id=%d",(int)$secondSlot['slot_authority_id']));
        $secondSlot=$authoriseSlot($second,'two-shared',$firstSlotWall);
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'second'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_teacher_root_held','w2'=>null);
        break;
    case 'unrelated_teachers':
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'second'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2q_after_reservation_insert','w2'=>null);
        break;
    case 'hold_vs_lesson_schedule_q_first':
    case 'hold_vs_lesson_schedule_n_first':
        $chain=$raceChain((int)$first['teacher_id'],'hold-schedule');
        // Re-authorise the first Student's slot well into the future so the hold is genuinely effective.
        $futureWall=gmdate('Y-m-d H:i:s',time()+7200);
        $wpdb->update($p.'canonical_continuation_slot_authorities',array('starts_at_utc'=>$futureWall,'ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+9000),'occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+9900),'local_wall_date'=>substr($futureWall,0,10),'local_wall_time'=>substr($futureWall,11,8)),array('intro_lesson_id'=>(int)$first['lesson_id']));
        $state['chain']=$chain;
        $state['schedule_wall']=$futureWall;
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'lesson_schedule','target'=>'first'));
        if($mode==='hold_vs_lesson_schedule_n_first')$state['actions']=array('w1'=>array('action'=>'lesson_schedule','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'first'));
        $state['hooks']=array('w1'=>$mode==='hold_vs_lesson_schedule_q_first'?'dzn_phase_2a2q_teacher_root_held':'dzn_phase_2a2n_teacher_root_held','w2'=>null);
        break;
    case 'expiry_vs_new_claim':
        // The hold is capacity-effective for about two seconds and then lazily stops blocking capacity.
        $expiring=$introOf(4,7,'expiry-claim');
        $authoriseSlot($expiring,'expiry',gmdate('Y-m-d H:i:s',time()+3600));
        // The introductory occurrence has already ended; its six-day bound is two seconds away, so the
        // frozen hold expiry is a genuine near-future boundary.
        $expiringEnd=gmdate('Y-m-d H:i:s',time()-6*86400+2);
        $expiringStart=gmdate('Y-m-d H:i:s',time()-6*86400+2-1800);
        $wpdb->update($p.'lesson_schedule_versions',array('starts_at_utc'=>$expiringStart,'ends_at_utc'=>$expiringEnd),array('lesson_id'=>(int)$expiring['lesson_id']));
        $wpdb->update($p.'canonical_continuation_slot_authorities',array('starts_at_utc'=>gmdate('Y-m-d H:i:s',time()+3600),'ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+5400),'occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+6300),'local_wall_date'=>substr(gmdate('Y-m-d H:i:s',time()+3600),0,10),'local_wall_time'=>substr(gmdate('Y-m-d H:i:s',time()+3600),11,8)),array('intro_lesson_id'=>(int)$expiring['lesson_id']));
        $chain=$raceChain((int)$first['teacher_id'],'expiry-claim');
        $wpdb->update($p.'lessons',array('teacher_id'=>(int)$first['teacher_id']),array('id'=>(int)$chain['lesson_id']));
        $holdSlot=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$expiring['lesson_id']));
        $holdPrincipal=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",(int)$expiring['student_id']));
        if($holdPrincipal<1)throw new RuntimeException('An adult Student principal fixture is required');
        wp_set_current_user($holdPrincipal);
        $continuation->continueWithTeacher((int)$expiring['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'expiry-claim'),$key('expiry-claim'));
        wp_set_current_user(1);
        $state['chain']=$chain;$state['expiring']=$expiring;$state['expiring_policy']=$holdSlot;
        $state['schedule_wall']=substr((string)$holdSlot->starts_at_utc,0,10).' '.substr((string)$holdSlot->starts_at_utc,11,8);
        $state['actions']=array('w1'=>array('action'=>'lesson_schedule','target'=>'first'),'w2'=>array('action'=>'lesson_schedule','target'=>'first'));
        $state['hooks']=array('w1'=>null,'w2'=>null);
        break;
    case 'principal_decision_first':
    case 'principal_revocation_first':
        $target=$mode==='principal_decision_first'?$first:$second;
        $link=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",(int)$target['student_id']));
        if(!$link)throw new RuntimeException('An adult Student principal fixture is required');
        $state['principal_link']=array('id'=>(int)$link->id,'version'=>(int)$link->version,'user_id'=>(int)$link->wordpress_user_id);
        $state['actors']=array('w1'=>(int)$link->wordpress_user_id,'w2'=>(int)$link->wordpress_user_id);
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'revoke_principal','target'=>'first'));
        if($mode==='principal_revocation_first')$state['actions']=array('w1'=>array('action'=>'revoke_principal','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'first'));
        $state['hooks']=array('w1'=>$mode==='principal_decision_first'?'dzn_phase_2a2q_authority_held':'dzn_phase_2a2f_authority_locks_held','w2'=>null);
        break;
    case 'guardian_decision_first':
    case 'guardian_revocation_first':
        $index=$mode==='guardian_decision_first'?2:3;
        $minor=$introOf($index,$mode==='guardian_decision_first'?8:9,$mode);
        $guardianUser=(int)wp_insert_user(array('user_login'=>'dzn-2a2q-g-'.substr(hash('sha256',wp_generate_uuid4()),0,12),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'guardian-'.substr(hash('sha256',wp_generate_uuid4()),0,12).'@phase-2a2q.invalid','role'=>'subscriber'));
        $authorityService=new StudentAcceptanceAuthorityService();
        $authorityService->classify((int)$minor['student_id'],'minor','synthetic_fixture','synthetic_fixture',gmdate('Y-m-d H:i:s'),(int)$admin);
        $grantId=$authorityService->grantGuardian((int)$minor['student_id'],$guardianUser,'synthetic_fixture','synthetic_fixture',gmdate('Y-m-d H:i:s'),null,(int)$admin);
        $grant=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d",$grantId));
        $state['first']=$minor;
        $state['guardian_grant']=array('id'=>(int)$grant->id,'version'=>(int)$grant->version,'user_id'=>$guardianUser);
        $state['actors']=array('w1'=>$guardianUser,'w2'=>$guardianUser);
        $state['actions']=array('w1'=>array('action'=>'continue','target'=>'first'),'w2'=>array('action'=>'revoke_guardian','target'=>'first'));
        if($mode==='guardian_revocation_first')$state['actions']=array('w1'=>array('action'=>'revoke_guardian','target'=>'first'),'w2'=>array('action'=>'continue','target'=>'first'));
        $state['hooks']=array('w1'=>$mode==='guardian_decision_first'?'dzn_phase_2a2q_authority_held':'dzn_phase_2a2f_authority_locks_held','w2'=>null);
        break;
    case 'slot_vs_lesson_schedule':
    case 'slot_vs_teacher_unsuitable':
    case 'slot_vs_terminal_decision':
        // The Student continues BEFORE any slot is authorised, then the delayed slot command races a
        // competing authority action on the same case.
        $delayed=$introOf(5,$mode==='slot_vs_lesson_schedule'?12:($mode==='slot_vs_teacher_unsuitable'?13:14),$mode);
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$delayed['lesson_id']));
        wp_set_current_user((int)$delayed['principal']);
        $continuation->continueWithTeacher((int)$delayed['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>$mode.'-continue'),$key($mode.'-continue'));
        wp_set_current_user(1);
        $delayedCase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",(int)$delayed['lesson_id']));
        if(!$delayedCase)throw new RuntimeException('The delayed-slot race requires a continuing case');
        $slotWall=gmdate('Y-m-d H:i:s',time()+7200+9000);
        $state['first']=$delayed;
        $state['slot_wall']=$slotWall;
        $state['delayed_case_id']=(int)$delayedCase->id;
        $state['delayed_decision_id']=(int)$delayedCase->latest_decision_id;
        if($mode==='slot_vs_lesson_schedule'){
            $chain=$raceChain((int)$delayed['teacher_id'],'slot-schedule');
            $wpdb->update($p.'lessons',array('teacher_id'=>(int)$delayed['teacher_id']),array('id'=>(int)$chain['lesson_id']));
            $state['chain']=$chain;
            $state['schedule_wall']=$slotWall;
            $state['actions']=array('w1'=>array('action'=>'record_slot','target'=>'first'),'w2'=>array('action'=>'lesson_schedule','target'=>'first'));
            $state['hooks']=array('w1'=>'dzn_phase_2a2q_teacher_root_held','w2'=>null);
        }elseif($mode==='slot_vs_teacher_unsuitable'){
            $state['actions']=array('w1'=>array('action'=>'record_slot','target'=>'first'),'w2'=>array('action'=>'teacher_exception','target'=>'first'));
            $state['hooks']=array('w1'=>'dzn_phase_2a2q_teacher_root_held','w2'=>null);
        }else{
            $state['actions']=array('w1'=>array('action'=>'record_slot','target'=>'first'),'w2'=>array('action'=>'stop','target'=>'first'));
            $state['hooks']=array('w1'=>'dzn_phase_2a2q_teacher_root_held','w2'=>null);
        }
        if((int)$teacherUser>0&&$mode!=='slot_vs_lesson_schedule'&&$mode==='slot_vs_teacher_unsuitable'){
            $introTeacherUser=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",(int)$delayed['teacher_id']));
            if($introTeacherUser>0)(new \WP_User($introTeacherUser))->add_cap('dzn_submit_own_continuation_match_exception');
            $state['teacher_user']=$introTeacherUser;
        }
        break;
    default:
        throw new RuntimeException('Unknown Phase-Q race mode: '.$mode);
}
update_option('dzn_phase_2a2q_concurrency_state',$state,false);
echo 'prepared='.$mode."\n";
