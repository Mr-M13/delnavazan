<?php
/** Disposable Phase-Q failure injection: every material write boundary must roll back completely. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-Q failure runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationService,CanonicalContinuationReadService,LessonScheduleService,LessonService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_qf_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_qf_key(string $label):string{return 'dzn-2a2qf-'.$label.'-'.wp_generate_uuid4();}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_qf_assert(is_array($fixture)&&count($fixture['sources']??array())>=3,'Phase-J production fixture required');
$sources=$fixture['sources'];
foreach(array('canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities')as$t)dzn_qf_assert($wpdb->query("DELETE FROM {$p}{$t}")!==false,'Failed to reset disposable Phase Q storage');
$svc=new CanonicalContinuationService();$read=new CanonicalContinuationReadService();
$previous=get_current_user_id();wp_set_current_user(1);
$principalOf=static function(int $studentId) use($wpdb,$p):int{$id=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",$studentId));if($id<1)throw new RuntimeException('principal fixture required');return $id;};
$introOf=function(int $index,int $sequence,string $label,bool $withSlot=true) use($sources,$wpdb,$p,$svc):array{
    $src=$sources[$index%count($sources)];
    $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    $wall=gmdate('Y-m-d H:i:s',strtotime('-3 days')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    // An explicit authorised first regular slot so a continuing decision can hold real capacity.
    $slotWall=gmdate('Y-m-d H:i:s',time()+7200+$sequence*3600);
    if($withSlot)$svc->recordFirstRegularSlot($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'fail-slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_qf_key('fail-slot-'.$label));
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$src['student_id']);
};
$counts=static function() use($wpdb,$p):array{return array(
    'cases'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_cases"),
    'decisions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_decisions"),
    'reservations'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations"),
    'interventions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions"),
    'commands'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_commands"),
);};
$inject=static function(string $hook):void{add_action($hook,static function() use($hook):void{throw new RuntimeException('injected:'.$hook);},10,2);};
$clear=static function(string $hook):void{remove_all_actions($hook);};
$reservationsBeforeAll=$counts()['reservations'];

// 1. Continuation-case write boundary.
$first=$introOf(0,1,'fail-case');
$before=$counts();
$inject('dzn_phase_2a2q_after_case_insert');
$caught=false;
wp_set_current_user($principalOf((int)$first['student_id']));
try{$svc->continueWithTeacher((int)$first['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'case-failure'),dzn_qf_key('case-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2q_after_case_insert';}
wp_set_current_user(1);
$clear('dzn_phase_2a2q_after_case_insert');
dzn_qf_assert($caught,'case-creation failure injection was not observed');
dzn_qf_assert($counts()===$before,'case-creation failure left partial continuation authority');

// 2. Decision/history write boundary.
$second=$introOf(1,2,'fail-decision');
$before=$counts();
$inject('dzn_phase_2a2q_after_decision_insert');
$caught=false;
wp_set_current_user($principalOf((int)$second['student_id']));
try{$svc->continueWithTeacher((int)$second['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'decision-failure'),dzn_qf_key('decision-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2q_after_decision_insert';}
wp_set_current_user(1);
$clear('dzn_phase_2a2q_after_decision_insert');
dzn_qf_assert($caught,'decision-write failure injection was not observed');
dzn_qf_assert($counts()===$before,'decision-write failure left partial continuation authority or a leaked hold');
// Exact retry with a fresh key must converge cleanly after a rolled-back attempt.
wp_set_current_user($principalOf((int)$second['student_id']));
$retry=$svc->continueWithTeacher((int)$second['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'decision-failure'),dzn_qf_key('decision-failure-retry'));
wp_set_current_user(1);
dzn_qf_assert((int)$retry['reservation']['reservation_id']>0&&$retry['reservation']['state']==='active','a clean retry after a decision failure must hold capacity');

// 3. Reservation write boundary.
$third=$introOf(2,3,'fail-reservation');
$before=$counts();
$inject('dzn_phase_2a2q_after_reservation_insert');
$caught=false;
wp_set_current_user($principalOf((int)$third['student_id']));
try{$svc->continueWithTeacher((int)$third['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'reservation-failure'),dzn_qf_key('reservation-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2q_after_reservation_insert';}
wp_set_current_user(1);
$clear('dzn_phase_2a2q_after_reservation_insert');
dzn_qf_assert($caught,'reservation-write failure injection was not observed');
dzn_qf_assert($counts()===$before,'reservation failure left a partial active hold');

// 4. Administrator-intervention write boundary.
$fourth=$introOf(0,4,'fail-intervention');
$before=$counts();
$inject('dzn_phase_2a2q_after_intervention_insert');
$caught=false;
wp_set_current_user($principalOf((int)$fourth['student_id']));
try{$svc->requestContact((int)$fourth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'intervention-failure'),dzn_qf_key('intervention-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2q_after_intervention_insert';}
wp_set_current_user(1);
$clear('dzn_phase_2a2q_after_intervention_insert');
dzn_qf_assert($caught,'intervention-write failure injection was not observed');
dzn_qf_assert($counts()===$before,'intervention failure left a false administrator item or a partial decision');

// 5. Command-evidence write boundary.
$fifth=$introOf(1,5,'fail-command');
$before=$counts();
$inject('dzn_phase_2a2q_after_command_insert');
$caught=false;
wp_set_current_user($principalOf((int)$fifth['student_id']));
try{$svc->continueWithTeacher((int)$fifth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'command-failure'),dzn_qf_key('command-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2q_after_command_insert';}
wp_set_current_user(1);
$clear('dzn_phase_2a2q_after_command_insert');
dzn_qf_assert($caught,'command-evidence failure injection was not observed');
dzn_qf_assert($counts()===$before,'command failure left a false successful decision');
// No false idempotent replay: a retry with a fresh key must genuinely succeed.
wp_set_current_user($principalOf((int)$fifth['student_id']));
$commandRetry=$svc->continueWithTeacher((int)$fifth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'command-failure'),dzn_qf_key('command-failure-retry'));
wp_set_current_user(1);
dzn_qf_assert(empty($commandRetry['idempotent'])&&(int)$commandRetry['decision_id']>0,'a retry after a command failure must be a genuine new success');
dzn_qf_assert($counts()['reservations']===$reservationsBeforeAll+2,'only the genuinely successful continuing decisions may hold capacity');

// 6. Idempotency under failure: an exact replay of a successful command converges, a changed one fails.
// 5b. The explicit first-regular-slot authority write boundary must also roll back completely.
$slotFailure=$introOf(0,10,'fail-slot-boundary');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$slotFailure['lesson_id']));
$slotBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities");
$inject('dzn_phase_2a2q_after_slot_authority_insert');
$slotCaught=false;
try{$svc->recordFirstRegularSlot((int)$slotFailure['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>gmdate('Y-m-d',time()+9000),'local_wall_time'=>gmdate('H:i:s',time()+9000),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'slot-boundary','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_qf_key('slot-boundary'));}
catch(RuntimeException$e){$slotCaught=$e->getMessage()==='injected:dzn_phase_2a2q_after_slot_authority_insert';}
$clear('dzn_phase_2a2q_after_slot_authority_insert');
dzn_qf_assert($slotCaught,'slot-authority failure injection was not observed');
dzn_qf_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities")===$slotBefore,'a failed slot-authority write must leave no partial future-slot authority');
dzn_qf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$slotFailure['lesson_id']))===0,'a failed slot-authority write must leave no authority row');

// 5c. Delayed-convergence boundaries: a fault at any point must roll back the whole convergence.
$converge=$introOf(1,11,'fail-converge',false);
wp_set_current_user($principalOf((int)$converge['student_id']));
$svc->continueWithTeacher((int)$converge['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'fail-converge'),dzn_qf_key('fail-converge'));
wp_set_current_user(1);
$convergeCase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",(int)$converge['lesson_id']));
dzn_qf_assert($convergeCase!==null,'the delayed-convergence fixture must record a continuing case');
$convergeDecision=(int)$convergeCase->latest_decision_id;
$slotOffset=180000;
foreach(array('dzn_phase_2a2q_teacher_root_held','dzn_phase_2a2q_after_reservation_insert','dzn_phase_2a2q_after_intervention_resolve','dzn_phase_2a2q_after_command_insert')as$hook){
    $before=$counts();
    $slotWall=gmdate('Y-m-d H:i:s',time()+$slotOffset);$slotOffset+=3600;
    $inject($hook);
    $caught=false;
    try{$svc->recordFirstRegularSlot((int)$converge['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'fail-converge-'.$hook,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_qf_key('fail-converge-'.$hook));}
    catch(RuntimeException$e){$caught=$e->getMessage()==='injected:'.$hook;}
    $clear($hook);
    dzn_qf_assert($caught,'delayed-convergence failure injection was not observed: '.$hook);
    dzn_qf_assert($counts()===$before,'a fault at '.$hook.' must roll back the whole convergence');
    dzn_qf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$converge['lesson_id']))===0,'a fault at '.$hook.' must leave no slot authority');
    dzn_qf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$convergeCase->id))===0,'a fault at '.$hook.' must leave no reservation');
    dzn_qf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND state='required'",(int)$convergeCase->id))===1,'a fault at '.$hook.' must leave the requirement current');
}
$convergeWall=gmdate('Y-m-d H:i:s',time()+$slotOffset);
$converged=$svc->recordFirstRegularSlot((int)$converge['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($convergeWall,0,10),'local_wall_time'=>substr($convergeWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'fail-converge-retry','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_qf_key('fail-converge-retry'));
dzn_qf_assert($converged['converged']===true&&(int)$converged['decision_id']===$convergeDecision,'retry after a fault must converge and preserve the original decision');

$sixth=$introOf(2,6,'fail-replay');
wp_set_current_user($principalOf((int)$sixth['student_id']));
$key=dzn_qf_key('replay');
$firstPass=$svc->continueWithTeacher((int)$sixth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay'),$key);
$secondPass=$svc->continueWithTeacher((int)$sixth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay'),$key);
dzn_qf_assert(!empty($secondPass['idempotent'])&&(int)$secondPass['decision_id']===(int)$firstPass['decision_id'],'an exact replay must converge on the recorded decision');
$conflict=false;
try{$svc->continueWithTeacher((int)$sixth['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay-changed'),$key);}catch(Throwable$e){$conflict=$e->getMessage()==='Idempotency conflict';}
dzn_qf_assert($conflict,'a changed context under the same command key must fail closed');
wp_set_current_user(1);
$case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",(int)$sixth['lesson_id']));
dzn_qf_assert($case&&$read->forIntroLesson((int)$sixth['lesson_id'])['case']['decision_count']===1,'an exact replay must not append a second decision');

wp_set_current_user($previous);
echo "failure_injection_boundaries=6\nrollback_complete=pass\nno_false_admin_item=pass\nno_partial_future_slot_authority=pass\nno_capacity_leak=pass\nretry_recovery=pass\nidempotency_under_failure=pass\nPhase 2A.2-Q failure runtime passed\n";
