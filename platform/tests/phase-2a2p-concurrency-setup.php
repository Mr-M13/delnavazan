<?php
/** Prepare one deterministic gated Phase-P attendance intake race on synthetic production-path chains. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-P concurrency setup refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdentityService,CanonicalAttendanceIntakeService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2P_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
if(!is_array($fixture)||count($fixture['sources']??array())<2)throw new RuntimeException('Phase-J fixture required');
$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();$assignments=new TeacherAssignmentService();
$lessons=new CanonicalLessonAuthorityService();$schedules=new CanonicalLessonScheduleService();$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$intake=new CanonicalAttendanceIntakeService();$identity=new CanonicalAttendanceIdentityService();
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$key=static fn(string $label):string=>'dzn-2a2p-race-'.$label.'-'.wp_generate_uuid4();
$allocate=static function() use($fixture,$wpdb,$p):int{foreach($fixture['sources'] as$source){$id=(int)$source['enrolment_id'];$state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d",$id));$terms=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'",$id));if($state==='authorised'&&$terms===0)return$id;}throw new RuntimeException('no_available_source');};
$chain=function() use($allocate,$enrolments,$terms,$assignments,$availability,$accepting,$wpdb,$p):array{
    $id=$allocate();
    $enrolments->activate($id,'authorised',$GLOBALS['dzn_race_evidence']('activate'),$GLOBALS['dzn_race_key']('activate'));
    $term=$terms->create($id,null,null,$GLOBALS['dzn_race_evidence']('term'),$GLOBALS['dzn_race_key']('term'));
    $terms->activate((int)$term['term_id'],'authorised',$GLOBALS['dzn_race_evidence']('term-active'),$GLOBALS['dzn_race_key']('term-active'));
    $assignment=$assignments->assignInitial($id,$GLOBALS['dzn_race_key']('assignment'));
    $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$id));
    $teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic P Race Teacher','email'=>'p-race-'.wp_generate_uuid4().'@phase-2a2p.invalid'));
    $accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_race'));
    $availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_race'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_race'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_race'));
    $moved=$assignments->replace($id,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'isolated-race-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),$GLOBALS['dzn_race_key']('isolate'));
    return array('enrolment_id'=>$id,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id']);
};
$occurrence=function(array $chain,string $label) use($lessons,$schedules,$wpdb,$p):array{
    $lessonId=(int)$lessons->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],$GLOBALS['dzn_race_evidence']($label),$GLOBALS['dzn_race_key']($label))['lesson_id'];
    $wall=gmdate('Y-m-d H:i:s',strtotime('+2 hours'));
    $scheduled=$schedules->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>30,'reason_code'=>'synthetic_race')+$GLOBALS['dzn_race_evidence']('s-'.$label),$GLOBALS['dzn_race_key']('s-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $schedules->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_race_release')+$GLOBALS['dzn_race_evidence']('r-'.$label),$GLOBALS['dzn_race_key']('r-'.$label));
    $row=$wpdb->get_row($wpdb->prepare("SELECT student_id,teacher_id FROM {$p}lessons WHERE id=%d",$lessonId));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id,'start'=>(string)$version->starts_at_utc,'end'=>(string)$version->ends_at_utc,'student_id'=>(int)$row->student_id,'teacher_id'=>(int)$row->teacher_id);
};
$GLOBALS['dzn_race_evidence']=$evidence;$GLOBALS['dzn_race_key']=$key;
if(!$intake){} // silence unused notices
$policy=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cutover_policies ORDER BY cutover_utc DESC,id DESC LIMIT 1"));
if(!$policy)$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),$key('cutover'));
$one=$chain();$two=$chain();
$first=$occurrence($one,'race-one');$second=$occurrence($two,'race-two');
$suffix=substr(str_replace('-','',wp_generate_uuid4()),0,10);
// Provider identity is authoritative: both race accounts must resolve through the durable registry.
$accounts=array('w1'=>'race-acct-w1-'.$suffix,'w2'=>'race-acct-w2-'.$suffix);
$identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$accounts['w1'],'participant_role'=>'teacher','participant_id'=>(int)$first['teacher_id'],'state'=>'verified','provenance_reference'=>'race-prov-w1','evidence_reference'=>'race-ref-w1'),$key('map-w1'));
$identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$accounts['w2'],'participant_role'=>'teacher','participant_id'=>(int)$second['teacher_id'],'state'=>'verified','provenance_reference'=>'race-prov-w2','evidence_reference'=>'race-ref-w2'),$key('map-w2'));
// The same-event races must share ONE provider account, otherwise they are genuine context conflicts.
$sharedAccount='race-acct-shared-'.$suffix;
$identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$sharedAccount,'participant_role'=>'teacher','participant_id'=>(int)$first['teacher_id'],'state'=>'verified','provenance_reference'=>'race-prov-shared','evidence_reference'=>'race-ref-shared'),$key('map-shared'));
$state=array('mode'=>$mode,'at'=>gmdate('Y-m-d H:i:s'),'chain'=>$one,'second_chain'=>$two,'first'=>$first,'second'=>$second,'keys'=>array('w1'=>$key('w1'),'w2'=>$key('w2')),'accounts'=>$accounts,'references'=>array('w1'=>'race-w1','w2'=>'race-w2'));
switch($mode){
    case 'same_event_same_payload':
        $state['accounts']=array('w1'=>$sharedAccount,'w2'=>$sharedAccount);
        $state['references']=array('w1'=>'race-shared','w2'=>'race-shared');
        $state['actions']=array('w1'=>array('action'=>'ingest','target'=>'first'),'w2'=>array('action'=>'ingest','target'=>'first'));
        $state['event_keys']=array('w1'=>'race-event-one-'.$suffix,'w2'=>'race-event-one-'.$suffix);
        $state['payload_keys']=array('w1'=>'race-payload-one-'.$suffix,'w2'=>'race-payload-one-'.$suffix);
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>null);
        break;
    case 'same_event_changed_payload':
        $state['accounts']=array('w1'=>$sharedAccount,'w2'=>$sharedAccount);
        $state['references']=array('w1'=>'race-shared','w2'=>'race-shared');
        $state['actions']=array('w1'=>array('action'=>'ingest','target'=>'first'),'w2'=>array('action'=>'ingest','target'=>'first'));
        $state['event_keys']=array('w1'=>'race-event-two-'.$suffix,'w2'=>'race-event-two-'.$suffix);
        $state['payload_keys']=array('w1'=>'race-payload-two-'.$suffix,'w2'=>'race-payload-two-changed-'.$suffix);
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>null);
        break;
    case 'claim_vs_adjudication':
        $intake->submitClaim((int)$first['lesson_id'],(int)$first['version_id'],array('claim_kind'=>'review_request','reason_code'=>'race_bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'race-bootstrap'),$key('bootstrap'));
        $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d",(int)$first['lesson_id']));
        $state['case_id']=(int)$case->id;$state['case_version']=(int)$case->case_version;
        $state['actions']=array('w1'=>array('action'=>'claim','target'=>'first'),'w2'=>array('action'=>'adjudicate','target'=>'first','adjudication'=>'record_no_change'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>'dzn_phase_2a2p_after_decision_insert');
        break;
    case 'adjudication_vs_adjudication':
        $intake->submitClaim((int)$first['lesson_id'],(int)$first['version_id'],array('claim_kind'=>'review_request','reason_code'=>'race_bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'race-bootstrap'),$key('bootstrap'));
        $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d",(int)$first['lesson_id']));
        $state['case_id']=(int)$case->id;$state['case_version']=(int)$case->case_version;
        $state['actions']=array('w1'=>array('action'=>'adjudicate','target'=>'first','adjudication'=>'record_no_change'),'w2'=>array('action'=>'adjudicate','target'=>'first','adjudication'=>'record_no_change'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_after_decision_insert','w2'=>null);
        break;
    case 'command_key_cross_lesson':
        $crossKey=$key('cross');
        $state['actions']=array('w1'=>array('action'=>'ingest','target'=>'first'),'w2'=>array('action'=>'ingest','target'=>'second'));
        $state['event_keys']=array('w1'=>'race-cross-event-'.$suffix,'w2'=>'race-cross-event-b-'.$suffix);
        $state['payload_keys']=array('w1'=>'race-cross-payload-'.$suffix,'w2'=>'race-cross-payload-b-'.$suffix);
        $state['keys']=array('w1'=>$crossKey,'w2'=>$crossKey);
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>null);
        break;
    case 'command_key_exact':
    case 'command_key_changed_payload':
    case 'command_key_changed_event':
    case 'command_key_changed_account':
    case 'command_key_changed_interval':
    case 'command_key_changed_observed':
    case 'command_key_changed_provenance':
        $altAccount='race-acct-alt-'.$suffix;
        $identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$altAccount,'participant_role'=>'teacher','participant_id'=>(int)$first['teacher_id'],'state'=>'verified','provenance_reference'=>'race-prov-alt','evidence_reference'=>'race-ref-alt'),$key('map-alt'));
        $sharedKey=$key('command-'.$mode);
        $state['actions']=array('w1'=>array('action'=>'ingest','target'=>'first'),'w2'=>array('action'=>'ingest','target'=>'first'));
        $state['accounts']=array('w1'=>$sharedAccount,'w2'=>$sharedAccount);
        $state['references']=array('w1'=>'race-shared','w2'=>'race-shared');
        $state['event_keys']=array('w1'=>'race-cmd-event-'.$suffix,'w2'=>'race-cmd-event-'.$suffix);
        $state['payload_keys']=array('w1'=>'race-cmd-payload-'.$suffix,'w2'=>'race-cmd-payload-'.$suffix);
        $state['keys']=array('w1'=>$sharedKey,'w2'=>$sharedKey);
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>null);
        $state['overrides']=array('w2'=>match($mode){
            'command_key_exact'=>array(),
            'command_key_changed_payload'=>array('provider_payload_key'=>'race-cmd-payload-changed-'.$suffix),
            'command_key_changed_event'=>array('provider_event_key'=>'race-cmd-event-changed-'.$suffix),
            'command_key_changed_account'=>array('provider_account_key'=>$altAccount),
            'command_key_changed_interval'=>array('leave_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($first['start'].' UTC')+90)),
            'command_key_changed_observed'=>array('observed_at'=>gmdate('Y-m-d H:i:s',strtotime($state['at'].' UTC')+1)),
            'command_key_changed_provenance'=>array('provenance_reference'=>'prov-race-shared-alt'),
            default=>array(),
        });
        break;
    case 'unrelated_lessons':
        $state['actions']=array('w1'=>array('action'=>'claim','target'=>'first'),'w2'=>array('action'=>'claim','target'=>'second'));
        $state['hooks']=array('w1'=>'dzn_phase_2a2p_occurrence_locks_held','w2'=>null);
        break;
    default:
        throw new RuntimeException('Unknown Phase-P race mode: '.$mode);
}
update_option('dzn_phase_2a2p_concurrency_state',$state,false);
echo 'prepared='.$mode."\n";
