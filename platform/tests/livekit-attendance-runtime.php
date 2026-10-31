<?php
/**
 * Disposable production-path proof for the LiveKit canonical attendance adapter.
 *
 * Synthetic local data only. It exercises the real signed webhook, durable correlation, the Phase-P
 * intake authority, staged Phase-O settlement and the cancellation/reschedule/late-evidence paths.
 * No LiveKit server, provider, paid service, deployment or production data is contacted.
 *
 *   DZN_LIVEKIT_ATTENDANCE_RUNTIME=authority wp eval-file .../livekit-attendance-runtime.php
 */
if(getenv('DZN_LIVEKIT_ATTENDANCE_RUNTIME')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"LiveKit attendance runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{
    CanonicalAttendanceIdempotency,
    CanonicalAttendanceIdentityService,
    CanonicalAttendanceIntakeService,
    CanonicalAttendanceSettlementSystemActor,
    CanonicalEnrolmentLifecycleService,
    CanonicalLessonAuthorityService,
    CanonicalLessonScheduleService,
    CanonicalTermAuthorityService,
    ProviderEvidenceSystemActor,
    TeacherAcceptingStateService,
    TeacherAssignmentService,
    TeacherAvailabilityService,
    TeacherService,
    TeachingEligibilityService
};
use Delnavazan\Platform\Integrations\LessonCompletionAutomationWorker;
use Delnavazan\Platform\Integrations\LiveKit\{
    LiveKitAttendanceIntegration,
    LiveKitAttendanceReconciliationWorker,
    LiveKitClassroomAuthorizer,
    LiveKitWebhookController
};

if(!defined('DZN_LIVEKIT_URL'))define('DZN_LIVEKIT_URL','ws://127.0.0.1:7880');
if(!defined('DZN_LIVEKIT_API_URL'))define('DZN_LIVEKIT_API_URL','http://127.0.0.1:1');
if(!defined('DZN_LIVEKIT_API_KEY'))define('DZN_LIVEKIT_API_KEY','devkey');
if(!defined('DZN_LIVEKIT_API_SECRET'))define('DZN_LIVEKIT_API_SECRET','runtime-livekit-secret');

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_lka_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_lka_key(string $label):string{return 'dzn-livekit-attendance-'.$label.'-'.wp_generate_uuid4();}
function dzn_lka_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_lka_b64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function dzn_lka_webhook(array $event,?string $secret=null):array{
    $body=(string)wp_json_encode($event);
    $header=dzn_lka_b64((string)wp_json_encode(array('alg'=>'HS256','typ'=>'JWT')));
    $payload=dzn_lka_b64((string)wp_json_encode(array('iss'=>DZN_LIVEKIT_API_KEY,'exp'=>time()+180,'sha256'=>base64_encode(hash('sha256',$body,true)))));
    $signature=dzn_lka_b64(hash_hmac('sha256',$header.'.'.$payload,$secret??DZN_LIVEKIT_API_SECRET,true));
    $request=new WP_REST_Request('POST','/delnavazan-platform/v1/livekit/webhook');
    $request->set_body($body);
    $request->set_header('authorization',$header.'.'.$payload.'.'.$signature);
    $response=LiveKitWebhookController::receive($request);
    if($response instanceof WP_Error)return array('status'=>(int)(($response->get_error_data()['status']??500)),'code'=>$response->get_error_code());
    return array('status'=>(int)$response->get_status(),'data'=>$response->get_data());
}
function dzn_lka_join(array $grant,string $sid,int $at,string $eventId,?string $roomSid=null):array{
    return array('event'=>'participant_joined','room'=>array('name'=>(string)$grant['room'],'sid'=>$roomSid??('RM_'.substr(hash('sha256',(string)$grant['room']),0,12))),'participant'=>array('identity'=>(string)$grant['identity'],'sid'=>$sid,'joinedAt'=>$at),'id'=>$eventId,'createdAt'=>$at);
}
function dzn_lka_left(array $grant,string $sid,int $at,string $eventId,?string $roomSid=null):array{
    return array('event'=>'participant_left','room'=>array('name'=>(string)$grant['room'],'sid'=>$roomSid??('RM_'.substr(hash('sha256',(string)$grant['room']),0,12))),'participant'=>array('identity'=>(string)$grant['identity'],'sid'=>$sid,'joinedAt'=>$at-60),'id'=>$eventId,'createdAt'=>$at);
}
function dzn_lka_finished(string $room,int $at,string $eventId):array{
    return array('event'=>'room_finished','room'=>array('name'=>$room,'sid'=>'RM_'.substr(hash('sha256',$room),0,12)),'id'=>$eventId,'createdAt'=>$at);
}

$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_lka_assert(is_array($fixture)&&count($fixture['sources']??array())>=2,'Phase-J production fixture required');
wp_set_current_user(1);

// The disposable harness resets only the tables this slice owns so the proof is deterministic.
foreach(array('livekit_attendance_connections','livekit_attendance_events','livekit_attendance_rooms')as$table)dzn_lka_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset LiveKit correlation storage: '.$table);
foreach(array('canonical_attendance_conflicts','canonical_attendance_case_anomalies','canonical_attendance_decisions','canonical_attendance_commands','canonical_attendance_evidence','canonical_attendance_cases','canonical_attendance_participant_mappings','canonical_attendance_cutover_policies')as$table)dzn_lka_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset Phase-P storage: '.$table);

$lessons=new CanonicalLessonAuthorityService();$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();
$assignments=new TeacherAssignmentService();$schedules=new CanonicalLessonScheduleService();$availability=new TeacherAvailabilityService();
$accepting=new TeacherAcceptingStateService();$intake=new CanonicalAttendanceIntakeService();$identity=new CanonicalAttendanceIdentityService();
$integration=new LiveKitAttendanceIntegration();

$allocate=static function()use($wpdb,$p):int{
    $id=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
    if($id<1)throw new RuntimeException('no_available_source');
    return $id;
};
$chain=function(string $label)use($allocate,$enrolments,$terms,$assignments,$availability,$accepting,$wpdb,$p):array{
    $id=$allocate();
    $enrolments->activate($id,'authorised',dzn_lka_evidence('activate-'.$label),dzn_lka_key('activate-'.$label));
    $term=$terms->create($id,null,null,dzn_lka_evidence('term-'.$label),dzn_lka_key('term-'.$label));
    $terms->activate((int)$term['term_id'],'authorised',dzn_lka_evidence('term-active-'.$label),dzn_lka_key('term-active-'.$label));
    $assignment=$assignments->assignInitial($id,dzn_lka_key('assignment-'.$label));
    $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$id));
    $teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic LiveKit Attendance Teacher','email'=>'lka-'.wp_generate_uuid4().'@dzn.invalid'));
    $now=gmdate('Y-m-d H:i:s');
    dzn_lka_assert($wpdb->insert($p.'teacher_onboarding_states',array('teacher_id'=>$teacher,'state'=>'active','readiness_state'=>'ready','profile_state'=>'complete','availability_state'=>'complete','agreement_state'=>'not_required','activated_at'=>$now,'version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))!==false,'Teacher onboarding fixture failed');
    $accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_livekit_attendance'));
    $availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_livekit_attendance'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_livekit_attendance'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_livekit_attendance'));
    $moved=$assignments->replace($id,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'lka-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_lka_key('isolate-'.$label));
    $student=(int)$wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}enrolments WHERE id=%d",$id));
    return array('enrolment_id'=>$id,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id'],'student_id'=>$student,'teacher_id'=>$teacher);
};
$occurrence=function(array $chain,string $label,int $durationMinutes,int $offsetSeconds)use($lessons,$schedules,$wpdb,$p):array{
    $lessonId=(int)$lessons->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],dzn_lka_evidence('lesson-'.$label),dzn_lka_key('lesson-'.$label))['lesson_id'];
    $wall=gmdate('Y-m-d H:i:s',time()+$offsetSeconds);
    $scheduled=$schedules->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>$durationMinutes,'reason_code'=>'synthetic_livekit_attendance')+dzn_lka_evidence('schedule-'.$label),dzn_lka_key('schedule-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $lesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$lessonId));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id,'lesson_uid'=>(string)$lesson->uid,'version_uid'=>(string)$version->uid,'start'=>(string)$version->starts_at_utc,'end'=>(string)$version->ends_at_utc,'start_ts'=>(int)strtotime($version->starts_at_utc.' UTC'),'teacher_id'=>(int)$lesson->teacher_id,'student_id'=>(int)$lesson->student_id);
};
$user=function(string $login,string $email,string $role='subscriber'):int{
    $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$email,'role'=>$role));
    if(is_wp_error($id))throw new RuntimeException('Synthetic principal creation failed');
    return (int)$id;
};
$linkPrincipals=function(array $chain)use($wpdb,$p,$user):array{
    $now=gmdate('Y-m-d H:i:s');
    $teacherUser=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",(int)$chain['teacher_id']));
    if($teacherUser<1){
        $teacherUser=$user('lka-teacher-'.substr(wp_generate_uuid4(),0,8),'lka-teacher-'.wp_generate_uuid4().'@dzn.invalid','dzn_teacher');
        dzn_lka_assert($wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>(int)$chain['teacher_id'],'wordpress_user_id'=>$teacherUser,'status'=>'active','linked_at'=>$now,'linked_by'=>1))!==false,'Teacher principal link failed');
    }
    $studentUser=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 LIMIT 1",(int)$chain['student_id']));
    if($studentUser<1){
        $studentUser=$user('lka-student-'.substr(wp_generate_uuid4(),0,8),'lka-student-'.wp_generate_uuid4().'@dzn.invalid');
        dzn_lka_assert($wpdb->insert($p.'student_principal_links',array('student_id'=>(int)$chain['student_id'],'wordpress_user_id'=>$studentUser,'status'=>'active','link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'runtime_fixture','evidence_channel'=>'authenticated_platform','evidence_reference'=>'lka-runtime-'.$studentUser,'evidence_at'=>$now,'linked_at'=>$now,'linked_by'=>1,'updated_at'=>$now,'updated_by'=>1))!==false,'Student principal link failed');
    }
    return array('teacher'=>$teacherUser,'student'=>$studentUser);
};
$grants=function(array $o,array $users)use($wpdb,$p):array{
    $authorizer=new LiveKitClassroomAuthorizer();
    $input=array('kind'=>'lesson','lesson_uid'=>$o['lesson_uid'],'schedule_version_uid'=>$o['version_uid']);
    wp_set_current_user($users['teacher']);$teacher=$authorizer->authorize('teacher',$input);
    wp_set_current_user($users['student']);$student=$authorizer->authorize('student',$input);
    wp_set_current_user(1);
    return array('teacher'=>$teacher,'student'=>$student);
};
$bind=function(array $grant)use($integration):void{
    $result=$integration->bindGrant($grant);
    dzn_lka_assert(($result['state']??'')==='bound','the server-owned grant must bind durably');
};
$case=static function(array $o)use($wpdb,$p):?object{return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d",(int)$o['lesson_id'],(int)$o['version_id']));};
$outcome=static function(array $o)use($wpdb,$p):?object{return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1",(int)$o['lesson_id']));};
$outcomeCount=static function(array $o)use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",(int)$o['lesson_id']));};
$lessonState=static function(array $o)use($wpdb,$p):string{return(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d",(int)$o['lesson_id']));};
$completedEvents=static function(array $o)use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed'",(int)$o['lesson_id']));};
$anomalies=static function(object $case)use($wpdb,$p):array{return array_map(static fn($row)=>(string)$row->code,$wpdb->get_results($wpdb->prepare("SELECT code FROM {$p}canonical_attendance_case_anomalies WHERE case_id=%d ORDER BY id",(int)$case->id))?:array());};
$conflicts=static function(array $o)use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_conflicts WHERE lesson_id=%d",(int)$o['lesson_id']));};
$connection=static function(string $room,string $identity)use($wpdb,$p):?object{
    $roomRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}livekit_attendance_rooms WHERE room_name=%s",$room));
    if(!$roomRow)return null;
    $digest=\Delnavazan\Platform\Integrations\LiveKit\LiveKitAttendanceIdempotency::identity($identity);
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}livekit_attendance_connections WHERE room_id=%d AND identity_digest=%s ORDER BY id DESC LIMIT 1",(int)$roomRow->id,$digest));
};
$eventCount=static function(string $room)use($wpdb,$p):int{
    $roomRow=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}livekit_attendance_rooms WHERE room_name=%s",$room));
    return $roomRow?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}livekit_attendance_events WHERE room_id=%d",(int)$roomRow->id)):0;
};

// ---------------------------------------------------------------------------
// Server-owned integration actor and explicit capture flag. Cutover is prospective only.
// ---------------------------------------------------------------------------
$actorId=$user('lka-provider-actor-'.substr(wp_generate_uuid4(),0,8),'lka-actor-'.wp_generate_uuid4().'@dzn.invalid');
$actorUser=get_user_by('id',$actorId);
dzn_lka_assert($actorUser instanceof WP_User,'Integration actor creation failed');
$actorUser->add_cap('dzn_ingest_canonical_attendance_evidence');
$actorUser->add_cap('dzn_manage_canonical_attendance_identity');
update_option(ProviderEvidenceSystemActor::USER_OPTION,$actorId,false);
$settlementActorId=$user('lka-settlement-actor-'.substr(wp_generate_uuid4(),0,8),'lka-settlement-'.wp_generate_uuid4().'@dzn.invalid');
$settlementUser=get_user_by('id',$settlementActorId);
dzn_lka_assert($settlementUser instanceof WP_User,'Settlement actor creation failed');
$settlementUser->add_cap('dzn_ingest_canonical_attendance_evidence');
$settlementUser->add_cap('dzn_manage_canonical_lesson_delivery');
$settlementUser->add_cap('dzn_manage_canonical_lessons');
update_option(CanonicalAttendanceSettlementSystemActor::USER_OPTION,$settlementActorId,false);
update_option(LiveKitAttendanceIntegration::CAPTURE_OPTION,LiveKitAttendanceIntegration::CAPTURE_ENABLED,false);
dzn_lka_assert(ProviderEvidenceSystemActor::isConfigured(),'The narrow integration actor must be configured');
dzn_lka_assert(CanonicalAttendanceSettlementSystemActor::isConfigured(),'The narrow settlement actor must be configured');

$policy=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',time()+2),dzn_lka_key('cutover'));
dzn_lka_assert((int)$policy['policy_id']>0,'A prospective cutover policy is required');

// Each occurrence gets its own canonical chain/Teacher so a single Teacher occupancy cannot
// collide across the synthetic same-time scenarios. The runtime seeds a fresh Phase-J fixture
// immediately before this suite for exactly this reason.
$chains=array();$usersBy=array();
foreach(array('settle','below','reconnect','presume','reschedule','cancel','ambiguous','mismatch','meet','late','open','orphan')as$label){
    $chains[$label]=$chain($label);
    $usersBy[$label]=$linkPrincipals($chains[$label]);
}
$o=array();
foreach(array('settle'=>5,'below'=>5,'reconnect'=>5,'presume'=>5,'reschedule'=>5,'cancel'=>2,'ambiguous'=>2,'mismatch'=>2,'meet'=>2,'late'=>2,'open'=>2,'orphan'=>2)as$label=>$minutes)$o[$label]=$occurrence($chains[$label],$label,$minutes,5);

// Wait for the cutover instant and every scheduled start.
$start=max(array_map(static fn($item)=>$item['start_ts'],$o));
dzn_lka_assert($start>time(),'Synthetic occurrences must start in the future');
while(time()<$start+1)sleep(1);

$grantsBy=array();
foreach($o as$label=>$item)$grantsBy[$label]=$grants($item,$usersBy[$label]);
foreach($o as$label=>$item){$bind($grantsBy[$label]['teacher']);}

// A cancelled occurrence is revoked before delivery; its binding still accepts the leave fact.
$schedules->release((int)$o['cancel']['lesson_id'],array('expected_schedule_version_id'=>(int)$o['cancel']['version_id'],'reason_code'=>'synthetic_livekit_cancel')+dzn_lka_evidence('release-cancel'),dzn_lka_key('release-cancel'));
$lessons->cancel((int)$o['cancel']['lesson_id'],'authorised',array('reason_code'=>'canonical_lesson_cancelled')+dzn_lka_evidence('cancel-cancel'),dzn_lka_key('cancel-cancel'));

// Ambiguity: the same server-issued Student identity is bound to a second canonical Student.
$otherStudent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d ORDER BY id DESC LIMIT 1",(int)$o['ambiguous']['student_id']));
dzn_lka_assert($otherStudent>0,'A distinct Student is required for the ambiguity fixture');
wp_set_current_user(1);
$identity->record(array('provider_code'=>'livekit','provider_account_key'=>(string)$grantsBy['ambiguous']['student']['identity'],'participant_role'=>'student','participant_id'=>$otherStudent,'state'=>'verified','provenance_reference'=>'ambiguous-fixture','evidence_reference'=>'ambiguous-fixture'),dzn_lka_key('ambiguous-mapping'));

// Mismatch: revoke the correct Student mapping and replace it with a verified wrong participant.
$wrongStudent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d ORDER BY id ASC LIMIT 1",(int)$o['mismatch']['student_id']));
$mismatchAccount=CanonicalAttendanceIdempotency::evidence((string)$grantsBy['mismatch']['student']['identity']);
$mismatchMapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_participant_mappings WHERE provider_code='livekit' AND provider_account_digest=%s AND participant_role='student' AND participant_id=%d ORDER BY id DESC LIMIT 1",$mismatchAccount,(int)$o['mismatch']['student_id']));
dzn_lka_assert($mismatchMapping!==null,'The correct Student mapping must exist before the mismatch fixture');
$identity->revoke((int)$mismatchMapping->id,array(),dzn_lka_key('mismatch-revoke'));
$identity->record(array('provider_code'=>'livekit','provider_account_key'=>(string)$grantsBy['mismatch']['student']['identity'],'participant_role'=>'student','participant_id'=>$wrongStudent,'state'=>'verified','provenance_reference'=>'mismatch-fixture','evidence_reference'=>'mismatch-fixture'),dzn_lka_key('mismatch-mapping'));

$events=array();
$send=function(array $event,string $label)use(&$events):array{
    $result=dzn_lka_webhook($event);
    $events[$label]=$event;
    return $result;
};

// Invalid signature is refused before any durable fact.
$beforeInvalid=$eventCount((string)$grantsBy['settle']['teacher']['room']);
$invalid=dzn_lka_webhook(dzn_lka_join($grantsBy['settle']['teacher'],'PA_INVALID',(int)$o['settle']['start_ts'],'EV_INVALID'),'not-the-secret');
dzn_lka_assert((int)$invalid['status']===401,'An invalid webhook signature must be refused');
dzn_lka_assert($eventCount((string)$grantsBy['settle']['teacher']['room'])===$beforeInvalid,'An invalid signature must not leave a correlation event');

// Unknown third participant is still rejected and leaves no attendance correlation.
$third=array('room'=>(string)$grantsBy['settle']['teacher']['room'],'identity'=>'PA_THIRD_PARTY');
$thirdEvent=array('event'=>'participant_joined','room'=>array('name'=>$third['room'],'sid'=>'RM_THIRD'),'participant'=>array('identity'=>'x-third-party','sid'=>'PA_THIRD_PARTY','joinedAt'=>time()),'id'=>'EV_THIRD','createdAt'=>time());
$thirdResult=dzn_lka_webhook($thirdEvent);
dzn_lka_assert((int)$thirdResult['status']===200&&($thirdResult['data']['action']??'')==='removed_unauthorized_identity','A third participant must be rejected');
dzn_lka_assert($connection($third['room'],'x-third-party')===null,'A rejected third participant must not create a connection');
$crossLesson=dzn_lka_join($grantsBy['below']['teacher'],'PA_T_SETTLE_1',(int)$o['below']['start_ts'],'EV_CROSS_LESSON');
$crossLesson['participant']['identity']=(string)$grantsBy['settle']['student']['identity'];
$crossLessonResult=dzn_lka_webhook($crossLesson);
dzn_lka_assert((int)$crossLessonResult['status']===200&&($crossLessonResult['data']['action']??'')==='removed_unauthorized_identity','Another Lesson identity must be rejected');
dzn_lka_assert($connection((string)$grantsBy['below']['teacher']['room'],(string)$grantsBy['settle']['student']['identity'])===null,'Cross-Lesson evidence must not create a connection');

// 1200 seconds exactly, with a reconnect and duplicate overlapping tab.
$s=$o['settle'];$sg=$grantsBy['settle'];
$send(dzn_lka_join($sg['teacher'],'PA_T_SETTLE_1',$s['start_ts'],'EV_T_SETTLE_JOIN_1'),'settle_t_join');
$send(dzn_lka_join($sg['student'],'PA_S_SETTLE_1',$s['start_ts'],'EV_S_SETTLE_JOIN_1'),'settle_s_join');
$send(dzn_lka_left($sg['teacher'],'PA_T_SETTLE_1',$s['start_ts']+1200,'EV_T_SETTLE_LEFT_1'),'settle_t_left');
$send(dzn_lka_left($sg['student'],'PA_S_SETTLE_1',$s['start_ts']+1200,'EV_S_SETTLE_LEFT_1'),'settle_s_left');

// 1199 seconds with the same shape.
$b=$o['below'];$bg=$grantsBy['below'];
$send(dzn_lka_join($bg['teacher'],'PA_T_BELOW',$b['start_ts'],'EV_T_BELOW_JOIN'),'below_t_join');
$send(dzn_lka_join($bg['student'],'PA_S_BELOW',$b['start_ts'],'EV_S_BELOW_JOIN'),'below_s_join');
$send(dzn_lka_left($bg['teacher'],'PA_T_BELOW',$b['start_ts']+1199,'EV_T_BELOW_LEFT'),'below_t_left');
$send(dzn_lka_left($bg['student'],'PA_S_BELOW',$b['start_ts']+1199,'EV_S_BELOW_LEFT'),'below_s_left');

// Reconnect + duplicate tab: union is 1200, never 1800.
$r=$o['reconnect'];$rg=$grantsBy['reconnect'];
$send(dzn_lka_join($rg['teacher'],'PA_T_RECONNECT_1',$r['start_ts'],'EV_T_RECONNECT_JOIN_1'),'reconnect_t_join_1');
$send(dzn_lka_left($rg['teacher'],'PA_T_RECONNECT_1',$r['start_ts']+600,'EV_T_RECONNECT_LEFT_1'),'reconnect_t_left_1');
$send(dzn_lka_join($rg['teacher'],'PA_T_RECONNECT_2',$r['start_ts']+300,'EV_T_RECONNECT_JOIN_2'),'reconnect_t_join_2');
$send(dzn_lka_join($rg['teacher'],'PA_T_RECONNECT_DUP',$r['start_ts'],'EV_T_RECONNECT_JOIN_DUP'),'reconnect_t_join_dup');
$send(dzn_lka_left($rg['teacher'],'PA_T_RECONNECT_DUP',$r['start_ts']+600,'EV_T_RECONNECT_LEFT_DUP'),'reconnect_t_left_dup');
$send(dzn_lka_join($rg['student'],'PA_S_RECONNECT',$r['start_ts'],'EV_S_RECONNECT_JOIN'),'reconnect_s_join');
$send(dzn_lka_left($rg['teacher'],'PA_T_RECONNECT_2',$r['start_ts']+1200,'EV_T_RECONNECT_LEFT_2'),'reconnect_t_left_2');
$send(dzn_lka_left($rg['student'],'PA_S_RECONNECT',$r['start_ts']+1200,'EV_S_RECONNECT_LEFT'),'reconnect_s_left');

// Cancelled occurrence: evidence is retained for review, never settled.
$c=$o['cancel'];$cg=$grantsBy['cancel'];
$send(dzn_lka_join($cg['teacher'],'PA_T_CANCEL',$c['start_ts'],'EV_T_CANCEL_JOIN'),'cancel_t_join');
$send(dzn_lka_join($cg['student'],'PA_S_CANCEL',$c['start_ts'],'EV_S_CANCEL_JOIN'),'cancel_s_join');
$send(dzn_lka_left($cg['teacher'],'PA_T_CANCEL',$c['start_ts']+120,'EV_T_CANCEL_LEFT'),'cancel_t_left');
$send(dzn_lka_left($cg['student'],'PA_S_CANCEL',$c['start_ts']+120,'EV_S_CANCEL_LEFT'),'cancel_s_left');

// Ambiguous and mismatched identities must remain review-only.
foreach(array('ambiguous','mismatch')as$label){
    $item=$o[$label];$g=$grantsBy[$label];
    $send(dzn_lka_join($g['teacher'],'PA_T_'.strtoupper($label),$item['start_ts'],'EV_T_'.strtoupper($label).'_JOIN'),$label.'_t_join');
    $send(dzn_lka_join($g['student'],'PA_S_'.strtoupper($label),$item['start_ts'],'EV_S_'.strtoupper($label).'_JOIN'),$label.'_s_join');
    $send(dzn_lka_left($g['teacher'],'PA_T_'.strtoupper($label),$item['start_ts']+120,'EV_T_'.strtoupper($label).'_LEFT'),$label.'_t_left');
    $send(dzn_lka_left($g['student'],'PA_S_'.strtoupper($label),$item['start_ts']+120,'EV_S_'.strtoupper($label).'_LEFT'),$label.'_s_left');
}

// Reschedule: old evidence is bound to a superseded version and must be refused; the new
// version admits fresh evidence and settles normally.
$rs=$o['reschedule'];$rsg=$grantsBy['reschedule'];
$send(dzn_lka_join($rsg['teacher'],'PA_T_RESCHEDULE_OLD',$rs['start_ts'],'EV_T_RESCHEDULE_OLD_JOIN'),'reschedule_old_t_join');
$newWall=gmdate('Y-m-d H:i:s',time()+8);
$revised=$schedules->revise((int)$rs['lesson_id'],(int)$chains['reschedule']['assignment_id'],array('expected_schedule_version_id'=>(int)$rs['version_id'],'schedule_timezone'=>'UTC','local_wall_date'=>substr($newWall,0,10),'local_wall_time'=>substr($newWall,11),'duration_minutes'=>5,'reason_code'=>'synthetic_livekit_reschedule')+dzn_lka_evidence('reschedule-livekit'),dzn_lka_key('reschedule-livekit'));
$newVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$revised['schedule_version_id']));
$newStart=(int)strtotime($newVersion->starts_at_utc.' UTC');
$send(dzn_lka_left($rsg['teacher'],'PA_T_RESCHEDULE_OLD',$rs['start_ts']+600,'EV_T_RESCHEDULE_OLD_LEFT'),'reschedule_old_t_left');
wp_set_current_user(1);
$newOccurrence=array('lesson_id'=>$rs['lesson_id'],'version_id'=>(int)$newVersion->id,'lesson_uid'=>(string)$rs['lesson_uid'],'version_uid'=>(string)$newVersion->uid,'start'=>(string)$newVersion->starts_at_utc,'end'=>(string)$newVersion->ends_at_utc,'start_ts'=>$newStart,'teacher_id'=>$rs['teacher_id'],'student_id'=>$rs['student_id']);
$newGrants=$grants($newOccurrence,$usersBy['reschedule']);$bind($newGrants['teacher']);
$send(dzn_lka_join($newGrants['teacher'],'PA_T_RESCHEDULE_NEW',$newStart,'EV_T_RESCHEDULE_NEW_JOIN'),'reschedule_new_t_join');
$send(dzn_lka_join($newGrants['student'],'PA_S_RESCHEDULE_NEW',$newStart,'EV_S_RESCHEDULE_NEW_JOIN'),'reschedule_new_s_join');
$send(dzn_lka_left($newGrants['teacher'],'PA_T_RESCHEDULE_NEW',$newStart+1200,'EV_T_RESCHEDULE_NEW_LEFT'),'reschedule_new_t_left');
$send(dzn_lka_left($newGrants['student'],'PA_S_RESCHEDULE_NEW',$newStart+1200,'EV_S_RESCHEDULE_NEW_LEFT'),'reschedule_new_s_left');

// Missing leave: a join with no trusted leave must never become a qualifying interval.
$openOccurrence=$o['open'];$openGrant=$grantsBy['open'];
$send(dzn_lka_join($openGrant['teacher'],'PA_T_OPEN',$openOccurrence['start_ts'],'EV_T_OPEN_JOIN'),'open_t_join');
$send(dzn_lka_finished((string)$openGrant['teacher']['room'],time(),'EV_OPEN_ROOM_FINISHED'),'open_room_finished');

// Missing join: a leave with no join is an orphan fact, never a closed interval.
$orphanOccurrence=$o['orphan'];$orphanGrant=$grantsBy['orphan'];
$send(dzn_lka_left($orphanGrant['student'],'PA_S_ORPHAN',$orphanOccurrence['start_ts']+60,'EV_S_ORPHAN_LEFT'),'orphan_s_left');

// Room cleanup must not erase the leave fact or the binding.
$send(dzn_lka_finished((string)$sg['teacher']['room'],time(),'EV_SETTLE_ROOM_FINISHED'),'settle_room_finished');

// Meet fallback: no LiveKit participation evidence exists for this occurrence.
dzn_lka_assert($case($o['meet'])===null,'Missing LiveKit evidence must not create an attendance case');

// ---------------------------------------------------------------------------
// Wait for every occurrence to end, then run the durable retry worker.
// ---------------------------------------------------------------------------
$latest=0;foreach($o as$item)$latest=max($latest,(int)strtotime($item['end'].' UTC'));$latest=max($latest,$newStart+300);
while(time()<$latest+3)sleep(1);

// Presumed completion is not verified attendance; late LiveKit evidence must correct it append-only.
$presume=$o['presume'];
$presumed=LessonCompletionAutomationWorker::runLesson((int)$presume['lesson_id']);
dzn_lka_assert(($presumed['state']??'')==='presumed','The silence-only occurrence must become presumed completed');
dzn_lka_assert($lessonState($presume)==='completed'&&$outcome($presume)===null,'Presumed completion must not create delivered truth');
$pg=$grantsBy['presume'];
$send(dzn_lka_join($pg['teacher'],'PA_T_PRESUME',$presume['start_ts'],'EV_T_PRESUME_JOIN'),'presume_t_join');
$send(dzn_lka_join($pg['student'],'PA_S_PRESUME',$presume['start_ts'],'EV_S_PRESUME_JOIN'),'presume_s_join');
$send(dzn_lka_left($pg['teacher'],'PA_T_PRESUME',$presume['start_ts']+1200,'EV_T_PRESUME_LEFT'),'presume_t_left');
$send(dzn_lka_left($pg['student'],'PA_S_PRESUME',$presume['start_ts']+1200,'EV_S_PRESUME_LEFT'),'presume_s_left');

// Late evidence after Term closure remains reviewable and never settles automatically.
$late=$o['late'];
$presumedLate=LessonCompletionAutomationWorker::runLesson((int)$late['lesson_id']);
dzn_lka_assert(($presumedLate['state']??'')==='presumed','The late-evidence occurrence must first be presumed completed');
wp_set_current_user(1);
$terms->close((int)$chains['late']['term_id'],'current',dzn_lka_evidence('late-term-close'),dzn_lka_key('late-term-close'));
$lg=$grantsBy['late'];
$send(dzn_lka_join($lg['teacher'],'PA_T_LATE',$late['start_ts'],'EV_T_LATE_JOIN'),'late_t_join');
$send(dzn_lka_join($lg['student'],'PA_S_LATE',$late['start_ts'],'EV_S_LATE_JOIN'),'late_s_join');
$send(dzn_lka_left($lg['teacher'],'PA_T_LATE',$late['start_ts']+120,'EV_T_LATE_LEFT'),'late_t_left');
$send(dzn_lka_left($lg['student'],'PA_S_LATE',$late['start_ts']+120,'EV_S_LATE_LEFT'),'late_s_left');

$reconcile=LiveKitAttendanceReconciliationWorker::run();
dzn_lka_assert(!empty($reconcile['rooms'])||!empty($reconcile['open']),'The reconciliation worker must process durable work');
dzn_lka_assert($case($o['open'])===null&&$outcome($o['open'])===null,'A missing leave must not create attendance or an outcome before retention');
dzn_lka_assert($case($o['orphan'])===null&&$outcome($o['orphan'])===null,'A missing join must not create attendance or an outcome before retention');
$staleNow=gmdate('Y-m-d H:i:s',$latest+8*86400);
$integration->reconcile($staleNow);
$openCase=$case($o['open']);dzn_lka_assert($openCase!==null&&(string)$openCase->state==='ready_for_review','A retained open interval must become review-only');
dzn_lka_assert(in_array('open_interval',$anomalies($openCase),true),'A missing leave must be recorded as open_interval');
dzn_lka_assert($outcome($o['open'])===null&&$lessonState($o['open'])==='authorised','A missing leave must never settle or complete');
$orphanCase=$case($o['orphan']);dzn_lka_assert($orphanCase!==null&&(string)$orphanCase->state==='ready_for_review','A retained orphan leave must become review-only');
dzn_lka_assert(in_array('teacher_participation_unproven',$anomalies($orphanCase),true),'A missing join must leave the counterpart unproven and reviewable');
dzn_lka_assert($outcome($o['orphan'])===null&&$lessonState($o['orphan'])==='authorised','A missing join must never settle or complete');

// ---------------------------------------------------------------------------
// Canonical consequences.
// ---------------------------------------------------------------------------
$settleCase=$case($s);dzn_lka_assert($settleCase!==null,'The 1200-second occurrence must create a case');
dzn_lka_assert((string)$settleCase->state==='settled','Exactly 1200 seconds must settle');
dzn_lka_assert((string)$outcome($s)->outcome_code==='delivered','Settlement must record Phase-O delivered truth');
dzn_lka_assert($lessonState($s)==='completed','Settlement must complete the Lesson through Lesson authority');
dzn_lka_assert($outcomeCount($s)===1&&$completedEvents($s)===1,'Settlement must create exactly one outcome and one completion event');
dzn_lka_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d",(int)$s['lesson_id']))===0,'Ordinary delivery must not create academy debt');

$belowCase=$case($b);dzn_lka_assert($belowCase!==null&&(string)$belowCase->state==='ready_for_review','1199 seconds must remain in review');
dzn_lka_assert($outcome($b)===null&&$lessonState($b)==='authorised','1199 seconds must not create truth or complete the Lesson');
dzn_lka_assert(in_array('overlap_below_threshold',$anomalies($belowCase),true),'1199 seconds must record the below-threshold anomaly');
dzn_lka_assert($outcomeCount($b)===0,'1199 seconds must not create a finance-bearing outcome');

$reconnectCase=$case($r);dzn_lka_assert($reconnectCase!==null&&(string)$reconnectCase->state==='settled','A 1200-second reconnect union must settle');
dzn_lka_assert((string)$outcome($r)->outcome_code==='delivered'&&$lessonState($r)==='completed','Reconnect settlement must converge');
dzn_lka_assert($outcomeCount($r)===1&&$completedEvents($r)===1,'Duplicate tabs must not duplicate outcomes or completion');

$cancelCase=$case($c);dzn_lka_assert($cancelCase!==null&&(string)$cancelCase->state==='ready_for_review','A cancelled occurrence must remain review-only');
dzn_lka_assert($outcome($c)===null&&$lessonState($c)==='cancelled','A cancelled occurrence must not settle');
dzn_lka_assert(in_array('lesson_cancelled',$anomalies($cancelCase),true),'A cancelled occurrence must record the cancellation anomaly');

$ambiguousCase=$case($o['ambiguous']);dzn_lka_assert($ambiguousCase!==null&&(string)$ambiguousCase->state==='ready_for_review','An ambiguous identity must remain review-only');
dzn_lka_assert($outcome($o['ambiguous'])===null,'An ambiguous identity must not settle');
dzn_lka_assert(in_array('participant_ambiguous',$anomalies($ambiguousCase),true),'An ambiguous identity must record the ambiguity anomaly');

$mismatchCase=$case($o['mismatch']);dzn_lka_assert($mismatchCase!==null&&(string)$mismatchCase->state==='ready_for_review','A mismatched identity must remain review-only');
dzn_lka_assert($outcome($o['mismatch'])===null,'A mismatched identity must not settle');
dzn_lka_assert(in_array('participant_identity_mismatch',$anomalies($mismatchCase),true),'A mismatched identity must record the mismatch anomaly');

$oldRescheduleCase=$case($rs);dzn_lka_assert($oldRescheduleCase===null,'Superseded-schedule evidence must never create a current case');
$oldConnection=$connection((string)$rsg['teacher']['room'],(string)$rsg['teacher']['identity']);
dzn_lka_assert($oldConnection!==null&&(string)$oldConnection->closed_ingest_state==='rejected','Superseded-schedule evidence must be durably refused');
$newCase=$case($newOccurrence);dzn_lka_assert($newCase!==null&&(string)$newCase->state==='settled','Current-schedule evidence must settle');
dzn_lka_assert((string)$outcome($newOccurrence)->outcome_code==='delivered','Current-schedule evidence must record delivered truth');
dzn_lka_assert($outcomeCount($newOccurrence)===1&&$completedEvents($newOccurrence)===1,'Reschedule settlement must be unique');

dzn_lka_assert((string)$outcome($presume)->outcome_code==='delivered','Late LiveKit evidence must verify a presumed completion');
dzn_lka_assert($lessonState($presume)==='completed'&&$completedEvents($presume)===1,'Presumed completion must not be duplicated');
dzn_lka_assert($outcomeCount($presume)===1,'Presumed completion must gain exactly one delivered outcome');

$lateCase=$case($late);dzn_lka_assert($lateCase!==null&&(string)$lateCase->state==='ready_for_review','Late evidence must remain reviewable');
dzn_lka_assert(in_array('late_evidence',$anomalies($lateCase),true),'Late evidence must be flagged late_evidence');
dzn_lka_assert($outcome($late)===null,'Late evidence must not create delivered truth');

// Exact replay and a changed interval are both deterministic and append-only.
$settleEventsBefore=$eventCount((string)$sg['teacher']['room']);
$financeBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$s['lesson_id']));
$replay=dzn_lka_webhook($events['settle_t_left']);
dzn_lka_assert((int)$replay['status']===200,'An exact webhook replay must be accepted idempotently');
dzn_lka_assert($eventCount((string)$sg['teacher']['room'])===$settleEventsBefore,'An exact replay must not duplicate the correlation event');
$conflictsBefore=$conflicts($s);
$changed=dzn_lka_left($sg['teacher'],'PA_T_SETTLE_1',$s['start_ts']+1000,'EV_T_SETTLE_CHANGED_LEFT');
$changedResult=dzn_lka_webhook($changed);
dzn_lka_assert((int)$changedResult['status']===200,'A changed interval must be durably receipted');
dzn_lka_assert($conflicts($s)>$conflictsBefore,'A changed interval must create a Phase-P conflict receipt');
dzn_lka_assert($outcomeCount($s)===1&&$completedEvents($s)===1,'A changed interval must not duplicate outcomes or completion');
$financeAfter=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",(int)$s['lesson_id']));
dzn_lka_assert($financeAfter===$financeBefore,'Replay and conflicts must not duplicate finance capture');

// Meet fallback remains intact and creates no attendance authority.
dzn_lka_assert($case($o['meet'])===null&&$outcome($o['meet'])===null,'Meet fallback must not create LiveKit attendance or absence');

wp_set_current_user(1);
echo "LiveKit canonical attendance runtime passed\n";
