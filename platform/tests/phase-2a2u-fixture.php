<?php
/**
 * Disposable Phase 2A.2-U fixture: synthetic canonical Lesson chains and recorded finance facts.
 *
 * It builds the canonical facts Finance derives from — an activated canonical Enrolment, canonical
 * Term, provisioned Teacher Assignment, issued and scheduled canonical Lessons, recorded delivery
 * outcomes and academy obligations — plus the legacy-classified introductory Lesson Phase Q treats as
 * authoritative. Every helper writes only synthetic local data, and no helper writes a Finance row
 * except `dzn_u_fix_rate()`, which records a real teacher rate through the phase's own service.
 */
use Delnavazan\Platform\Core\Application\{CanonicalAcademyObligationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
use Delnavazan\Platform\Core\Application\Finance\TeacherRateService;

function dzn_u_fix_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_u_fix_key(string $label):string{return 'dzn-2a2u-'.$label.'-'.wp_generate_uuid4();}
function dzn_u_fix_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_u_fix_count(string $table,string $where='',array $args=array()):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $sql="SELECT COUNT(*) FROM {$p}{$table}".($where===''?'':' WHERE '.$where);
    return (int)($args===array()?$wpdb->get_var($sql):$wpdb->get_var($wpdb->prepare($sql,...$args)));
}
function dzn_u_fix_refused(callable $call,string $expected,string $message):void{
    $caught=null;try{$call();}catch(Throwable$e){$caught=$e;}
    dzn_u_fix_assert($caught!==null,$message.' was accepted');
    dzn_u_fix_assert($caught->getMessage()===$expected,$message.' refused with an unexpected code: '.$caught->getMessage());
}
function dzn_u_fix_accepted(callable $call,string $message):mixed{
    try{return $call();}catch(Throwable$e){throw new RuntimeException($message.' was refused: '.$e->getMessage());}
}

/** Remove every recorded Finance fact and every Finance serialisation root; canonical data is preserved. */
function dzn_u_fix_reset():void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    foreach(array('finance_reconciliation_commands','finance_reconciliation_findings','finance_reconciliation_runs','finance_statement_commands','finance_statement_events','finance_statement_lines','finance_statements','finance_payability_commands','finance_payability_overrides','finance_payability_evaluations','finance_snapshot_commands','finance_snapshot_corrections','finance_lesson_snapshots','finance_teacher_rate_commands','finance_teacher_rate_events','finance_teacher_rates','finance_policy_commands','finance_exceptions','finance_teacher_roots') as $table){
        dzn_u_fix_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase U storage: '.$table);
    }
    dzn_u_fix_assert($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}dzn_platform_audit_events WHERE aggregate_type LIKE %s",'finance_%'))!==false,'Failed to reset disposable Phase U audit evidence');
}

/** Activate one canonical Enrolment, create its Term, Assignment and provisioned Teacher, and return the chain. */
function dzn_u_fix_chain(array $fixture):array{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $enrolmentId=0;
    foreach($fixture['sources'] as $source){
        $id=(int)$source['enrolment_id'];
        $state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d",$id));
        $terms=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'",$id));
        if($state==='authorised'&&$terms===0){$enrolmentId=$id;break;}
    }
    if($enrolmentId<1)throw new RuntimeException('no_available_canonical_source');
    $enrolmentService=new CanonicalEnrolmentLifecycleService();
    $termService=new CanonicalTermAuthorityService();
    $assignmentService=new TeacherAssignmentService();
    $enrolmentService->activate($enrolmentId,'authorised',dzn_u_fix_evidence('u-activate'),dzn_u_fix_key('activate'));
    $term=$termService->create($enrolmentId,null,null,dzn_u_fix_evidence('u-term'),dzn_u_fix_key('term'));
    $termService->activate((int)$term['term_id'],'authorised',dzn_u_fix_evidence('u-term-active'),dzn_u_fix_key('term-active'));
    $initial=$assignmentService->assignInitial($enrolmentId,dzn_u_fix_key('assignment'));
    $courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$enrolmentId));
    $teacherId=(int)(new TeacherService())->create(array('display_name'=>'Synthetic U Chain Teacher','email'=>'u-chain-'.wp_generate_uuid4().'@phase-2a2u.invalid'));
    (new TeacherAcceptingStateService())->set(array('teacher_id'=>$teacherId,'state'=>'accepting','reason_code'=>'synthetic_provision'));
    (new TeacherAvailabilityService())->setProfile(array('teacher_id'=>$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
    for($weekday=1;$weekday<=7;$weekday++)(new TeacherAvailabilityService())->setRecurringRule(array('teacher_id'=>$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacherId,'course_id'=>$courseId,'status'=>'active','reason_code'=>'synthetic_provision'));
    $moved=$assignmentService->replace($enrolmentId,$teacherId,array('expected_assignment_id'=>(int)$initial['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'u-chain-'.$teacherId,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('isolate'));
    return array('enrolment_id'=>$enrolmentId,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id'],'teacher_id'=>$teacherId,'course_id'=>$courseId);
}

/** Issue and schedule one canonical occurrence; the chain's dedicated Teacher is never occupied twice. */
function dzn_u_fix_occurrence(array $chain,array $fixture,string $label,int $leadSeconds=2,int $durationMinutes=30):array{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $lessonService=new CanonicalLessonAuthorityService();
    $scheduleService=new CanonicalLessonScheduleService();
    $lessonId=(int)$lessonService->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],dzn_u_fix_evidence('u-lesson-'.$label),dzn_u_fix_key('lesson-'.$label))['lesson_id'];
    $midnight=strtotime('tomorrow UTC');
    $start=time()+max(2,$leadSeconds);
    if($start+$durationMinutes*60+5>=$midnight)$start=$midnight+30;
    $wall=gmdate('Y-m-d H:i:s',$start);
    $scheduled=$scheduleService->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>$durationMinutes,'reason_code'=>'synthetic_schedule')+dzn_u_fix_evidence('u-schedule-'.$label),dzn_u_fix_key('schedule-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $scheduleService->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_schedule_release')+dzn_u_fix_evidence('u-release-'.$label),dzn_u_fix_key('release-'.$label));
    unset($fixture);
    return array('lesson_id'=>$lessonId,'schedule_version_id'=>(int)$version->id,'starts_at_utc'=>(string)$version->starts_at_utc,'ends_at_utc'=>(string)$version->ends_at_utc);
}

/** Complete a scheduled canonical Lesson. */
function dzn_u_fix_complete(array $occurrence,string $label):void{
    (new CanonicalLessonAuthorityService())->complete((int)$occurrence['lesson_id'],'authorised',dzn_u_fix_evidence('u-complete-'.$label),dzn_u_fix_key('complete-'.$label));
}

/** Record one controlled delivery outcome, from the state the canonical Lesson currently holds. */
function dzn_u_fix_outcome(array $occurrence,string $outcomeCode,string $label,string $expectedState='authorised'):array{
    $input=array('outcome_code'=>$outcomeCode,'reason_code'=>'synthetic_outcome')+dzn_u_fix_evidence('u-outcome-'.$label);
    return (new CanonicalLessonDeliveryService())->record((int)$occurrence['lesson_id'],$expectedState,$input,dzn_u_fix_key('outcome-'.$label));
}

/** Establish the academy obligation an academy-caused non-delivery owes. */
function dzn_u_fix_obligation(array $occurrence,int $outcomeId,string $label):int{
    return (new CanonicalAcademyObligationService())->owe((int)$occurrence['lesson_id'],'teacher_non_delivery',$outcomeId,null,dzn_u_fix_evidence('u-owe-'.$label));
}

/** A legacy-classified introductory Lesson with one current (non-superseded) occurrence. */
function dzn_u_fix_intro(array $fixture,string $label,int $leadSeconds=2,int $durationMinutes=30):array{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $source=null;
    foreach($fixture['sources'] as $candidate){
        $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",(int)$candidate['enrolment_id']));
        $type=(string)$wpdb->get_var($wpdb->prepare("SELECT course_type FROM {$p}courses WHERE id=%d",$course));
        if($type==='introductory'){$source=$candidate;break;}
    }
    if($source===null)$source=$fixture['sources'][0];
    $now=gmdate('Y-m-d H:i:s');
    $wpdb->insert($p.'lessons',array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'reference_code'=>null,'student_id'=>(int)$source['student_id'],'teacher_id'=>(int)$source['teacher_id'],'course_id'=>(int)$source['course_id'],'enrolment_id'=>null,'term_id'=>null,'lesson_type'=>'introductory','status'=>'completed','sequence_number'=>null,'current_schedule_version_id'=>null,'replacement_for_lesson_id'=>null,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1,'archived_at'=>null,'archived_by'=>null));
    dzn_u_fix_assert($wpdb->last_error==='','Failed to record the synthetic introductory Lesson');
    $lessonId=(int)$wpdb->insert_id;
    $start=time()+max(2,$leadSeconds);
    $ends=$start+$durationMinutes*60;
    $wpdb->insert($p.'lesson_schedule_versions',array('lesson_id'=>$lessonId,'version_number'=>1,'starts_at_utc'=>gmdate('Y-m-d H:i:s',$start),'ends_at_utc'=>gmdate('Y-m-d H:i:s',$ends),'schedule_timezone'=>'UTC','local_wall_date'=>gmdate('Y-m-d', $start),'local_wall_time'=>gmdate('H:i:s',$start),'reason'=>'synthetic_intro','changed_by'=>1,'created_at'=>$now,'superseded_at'=>null));
    $versionId=(int)$wpdb->insert_id;
    $wpdb->update($p.'lessons',array('current_schedule_version_id'=>$versionId),array('id'=>$lessonId));
    return array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'starts_at_utc'=>gmdate('Y-m-d H:i:s',$start),'ends_at_utc'=>gmdate('Y-m-d H:i:s',$ends),'teacher_id'=>(int)$source['teacher_id'],'course_id'=>(int)$source['course_id']);
}

/** Record one teacher rate through the phase's own service. */
function dzn_u_fix_rate(int $teacherId,array $input,string $label):array{
    return (new TeacherRateService())->record($teacherId,$input+array('evidence_channel'=>'staff_record','evidence_reference'=>'u-rate-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_u_fix_key('rate-'.$label));
}
/** Wait until every supplied occurrence has ended, so an elapsed statement period can cover them. */
function dzn_u_fix_settle(array $occurrences):void{
    $latest=0;
    foreach($occurrences as $occurrence)$latest=max($latest,strtotime((string)$occurrence['ends_at_utc'].' UTC'));
    while(time()<$latest+2)sleep(1);
}
