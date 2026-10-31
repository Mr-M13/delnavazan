<?php
if(getenv('DZN_RECORDING_DECISION_BEHAVIOR_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Recording decision behavior runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Support\Identifier;
global $wpdb;$p=$wpdb->prefix.'dzn_';$GLOBALS['dzn_rda_prefix']=$p;$now=gmdate('Y-m-d H:i:s');$service=new RecordingDecisionAuthorityService();

function dzn_rda_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_rda_expect(callable $action,string $code,string $message):void{try{$action();}catch(Throwable $e){if($e->getMessage()!==$code)throw new RuntimeException($message.' expected '.$code.' got '.$e->getMessage());return;}throw new RuntimeException($message.' expected refusal '.$code);}
function dzn_rda_user(string $label):int{$id=wp_insert_user(array('user_login'=>'dzn-rda-'.$label.'-'.substr(Identifier::uid(),0,10),'user_pass'=>wp_generate_password(24),'user_email'=>'dzn-rda-'.$label.'-'.substr(Identifier::uid(),0,10).'@example.invalid','role'=>'subscriber'));if(is_wp_error($id)||(int)$id<1)throw new RuntimeException('fixture user failed '.$label);return(int)$id;}
function dzn_rda_p():string{return(string)($GLOBALS['dzn_rda_prefix']??'');}
function dzn_rda_teacher(string $label,string $name):int{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$ok=$wpdb->insert($p.'teachers',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1));if($ok===false)throw new RuntimeException('fixture teacher failed '.$label.' '.$wpdb->last_error);return(int)$wpdb->insert_id;}
function dzn_rda_student(string $label,string $name,string $capacity):int{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$ok=$wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1));if($ok===false)throw new RuntimeException('fixture student failed '.$label.' '.$wpdb->last_error);$id=(int)$wpdb->insert_id;if($capacity!=='none'){dzn_rda_capacity($id,$capacity);}return$id;}
function dzn_rda_capacity(int $student,string $classification):int{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$ok=$wpdb->insert($p.'student_acceptance_capacity_classifications',array('uid'=>Identifier::uid(),'student_id'=>$student,'classification'=>$classification,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'recording-decision-behavior','evidence_at'=>$now,'classified_at'=>$now,'classified_by'=>1,'created_at'=>$now,'created_by'=>1));if($ok===false)throw new RuntimeException('fixture capacity failed '.$wpdb->last_error);$id=(int)$wpdb->insert_id;if($wpdb->update($p.'students',array('current_acceptance_capacity_classification_id'=>$id),array('id'=>$student))===false)throw new RuntimeException('fixture capacity projection failed');return$id;}
function dzn_rda_link_teacher(int $teacher,int $user):int{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$ok=$wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$teacher,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1));if($ok===false)throw new RuntimeException('fixture teacher link failed '.$wpdb->last_error);return(int)$wpdb->insert_id;}
function dzn_rda_link_student(int $student,int $user,int $sequence=1):int{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$ok=$wpdb->insert($p.'student_principal_links',array('student_id'=>$student,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1,'link_sequence'=>$sequence,'active_slot'=>1,'version'=>1,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'recording-decision-behavior','evidence_at'=>$now,'updated_at'=>$now,'updated_by'=>1));if($ok===false)throw new RuntimeException('fixture student link failed '.$wpdb->last_error);return(int)$wpdb->insert_id;}
function dzn_rda_notice(string $audience,string $language,int $version,string $state,string $body,?string $published=null,?string $expires=null,?string $withdrawn=null):array{global $wpdb;$p=dzn_rda_p();$now=gmdate('Y-m-d H:i:s');$uid=Identifier::uid();$ok=$wpdb->insert($p.'recording_notice_versions',array('uid'=>$uid,'audience_code'=>$audience,'language_code'=>$language,'version_number'=>$version,'publication_state'=>$state,'body_text'=>$body,'text_digest'=>hash('sha256',$body),'published_at'=>$published,'expires_at'=>$expires,'withdrawn_at'=>$withdrawn,'created_at'=>$now,'created_by'=>1));if($ok===false)throw new RuntimeException('fixture notice failed '.$wpdb->last_error);return array('id'=>(int)$wpdb->insert_id,'uid'=>$uid);}
function dzn_rda_count(string $subject,int $id,?string $purpose=null):int{global $wpdb;$p=dzn_rda_p();if($purpose===null)return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type=%s AND subject_id=%d",$subject,$id));return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type=%s AND subject_id=%d AND purpose_code=%s",$subject,$id,$purpose));}

// Every fixture below is created after the runner reset this runtime's own database.
dzn_rda_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='56','behavior runtime requires Schema 56');
dzn_rda_assert(RecordingDecisionAuthorityService::CAPTURE_ENABLED===false,'capture must stay disabled');
dzn_rda_assert(RecordingDecisionAuthorityService::RESEARCH_EXPORT_ENABLED===false,'research export must stay disabled');

$teacherUser=dzn_rda_user('teacher');$teacherA=dzn_rda_teacher('a','Teacher A');$teacherB=dzn_rda_teacher('b','Teacher B');dzn_rda_link_teacher($teacherA,$teacherUser);
$adultUser=dzn_rda_user('adult');$adult=dzn_rda_student('adult','Adult Student','adult');dzn_rda_link_student($adult,$adultUser);
$minorUser=dzn_rda_user('minor');$minor=dzn_rda_student('minor','Minor Student','minor');dzn_rda_link_student($minor,$minorUser);
$unknownUser=dzn_rda_user('unknown');$unknown=dzn_rda_student('unknown','Unknown Student','unknown');dzn_rda_link_student($unknown,$unknownUser);
$missingCapacityUser=dzn_rda_user('no-capacity');$missingCapacity=dzn_rda_student('no-capacity','No Capacity Student','none');dzn_rda_link_student($missingCapacity,$missingCapacityUser);
$unlinkedUser=dzn_rda_user('unlinked');
$noticeAdultUser=dzn_rda_user('notice-adult');$noticeAdult=dzn_rda_student('notice-adult','Notice Adult','adult');dzn_rda_link_student($noticeAdult,$noticeAdultUser);

$teacherTerms=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',1,'published','Teacher recording terms v1',$now);
$teacherTermsFa=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'fa',1,'published','شرایط ضبط درس نسخه ۱',$now);
$teacherDraft=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',2,'draft','Draft teacher terms',null);
$teacherExpired=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',3,'published','Expired teacher terms',$now,gmdate('Y-m-d H:i:s',time()-3600));
$teacherWithdrawn=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',4,'published','Withdrawn teacher terms',$now,null,$now);
$teacherFuture=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',5,'published','Future teacher terms',gmdate('Y-m-d H:i:s',time()+3600));
$recording=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',1,'published','Student recording notice v1',$now);
$recordingDraft=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',2,'draft','Draft recording notice',null);
$recordingExpired=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',3,'published','Expired recording notice',$now,gmdate('Y-m-d H:i:s',time()-3600));
$recordingWithdrawn=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',4,'published','Withdrawn recording notice',$now,null,$now);
$recordingFuture=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',5,'published','Future recording notice',gmdate('Y-m-d H:i:s',time()+3600));
$recordingFa=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'fa',1,'published','اطلاعیه ضبط درس نسخه ۱',$now);
$research=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,'en',1,'published','Student research notice v1',$now);

// Unauthenticated callers cannot resolve an actor at all.
wp_set_current_user(0);
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','unauthenticated-teacher-01'),'recording_authenticated_principal_required','unauthenticated teacher acceptance');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','unauthenticated-student-01'),'recording_authenticated_principal_required','unauthenticated student choice');

// A missing Teacher link fails closed before any terms can be recorded.
wp_set_current_user($unlinkedUser);
$state=$service->teacherTermsState();dzn_rda_assert($state['available']===false&&$state['reason']==='recording_teacher_principal_required','unlinked Teacher state must be unavailable');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','missing-teacher-link-01'),'recording_teacher_principal_required','unlinked Teacher acceptance');

// Teacher terms: exactly one active authenticated Teacher link, published and correctly targeted.
wp_set_current_user($teacherUser);
$state=$service->teacherTermsState();dzn_rda_assert($state['available']===true&&$state['accepted']===false,'new Teacher must begin unaccepted');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'fr','teacher-invalid-language-01'),'recording_decision_language_invalid','unsupported Teacher language');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','short'),'recording_decision_key_required','short Teacher idempotency key');
$result=$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001');
dzn_rda_assert($result['state']==='accepted'&&$result['idempotent']===false&&$result['decision_id']>0,'Teacher acceptance must write evidence');
$state=$service->teacherTermsState();dzn_rda_assert($state['accepted']===true&&$state['notice_version']===1&&$state['language']==='en','Teacher state must expose the recorded terms version');
dzn_rda_assert(dzn_rda_count('teacher',$teacherA)===1,'Teacher acceptance must write exactly one decision');
$replay=$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001');
dzn_rda_assert($replay['idempotent']===true&&$replay['decision_id']===$result['decision_id'],'Exact Teacher replay must return the original result');
dzn_rda_assert(dzn_rda_count('teacher',$teacherA)===1,'Exact Teacher replay must not duplicate evidence');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTermsFa['uid'],'fa','teacher-accept-0001'),'recording_decision_replay_conflict','Changed Teacher replay payload');
$command=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decision_commands WHERE result_decision_id=%d",$result['decision_id']));
dzn_rda_assert($command!==null,'Teacher command evidence must exist for tamper checks');
dzn_rda_assert($wpdb->update($p.'recording_decision_commands',array('result_state'=>'decline'),array('id'=>$command->id))===1,'fixture must tamper the command state');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001'),'recording_decision_replay_conflict','Tampered Teacher command state');
dzn_rda_assert($wpdb->update($p.'recording_decision_commands',array('result_state'=>$result['state']),array('id'=>$command->id))===1,'fixture must restore the command state');
dzn_rda_assert($wpdb->update($p.'recording_decisions',array('notice_uid'=>str_repeat('X',26)),array('id'=>$result['decision_id']))===1,'fixture must tamper the decision notice identity');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001'),'recording_decision_replay_conflict','Tampered Teacher decision notice identity');
dzn_rda_assert($wpdb->update($p.'recording_decisions',array('notice_uid'=>$teacherTerms['uid']),array('id'=>$result['decision_id']))===1,'fixture must restore the decision notice identity');
$restoredReplay=$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001');
dzn_rda_assert($restoredReplay['idempotent']===true&&$restoredReplay['decision_id']===$result['decision_id'],'restored Teacher evidence must replay exactly');
foreach(array($teacherDraft,$teacherExpired,$teacherWithdrawn,$teacherFuture,$recording)as$notice)dzn_rda_expect(fn()=>$service->acceptTeacherTerms($notice['uid'],'en','teacher-notice-'.substr($notice['uid'],0,10)),'recording_notice_unavailable','unavailable Teacher notice');
dzn_rda_assert(dzn_rda_count('teacher',$teacherA)===1,'Refused Teacher notices must not write evidence');
dzn_rda_assert($service->publishedNotice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'fa')['version']===1,'Published Persian Teacher terms must be discoverable');
dzn_rda_assert($service->publishedNotice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en')['uid']===$teacherTerms['uid'],'Published Teacher terms must resolve the approved current version');
dzn_rda_assert($service->publishedNotice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en')['uid']===$recording['uid'],'Published recording notice must resolve');

// A newly published terms version makes the older acceptance stale; the old notice cannot authorise a new decision.
$teacherTermsNext=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',6,'published','Teacher recording terms v2',$now);
$state=$service->teacherTermsState();dzn_rda_assert($state['available']===true&&$state['accepted']===false,'older Teacher acceptance must become stale after a newer published version');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-stale-notice-01'),'recording_notice_unavailable','older Teacher notice must not authorise a new decision');
$nextAccept=$service->acceptTeacherTerms($teacherTermsNext['uid'],'en','teacher-accept-next-01');
dzn_rda_assert($nextAccept['state']==='accepted','current Teacher terms version must be acceptable');
$state=$service->teacherTermsState();dzn_rda_assert($state['accepted']===true&&$state['notice_version']===6,'current Teacher acceptance must cite the newer version');
$priorAccept=$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001');
dzn_rda_assert($priorAccept['idempotent']===true&&$priorAccept['decision_id']===$result['decision_id'],'exact Teacher replay must still return the original decision while authority is current');

// Revocation must fail before an exact replay can return a stored Teacher result.
$wpdb->update($p.'teacher_principal_links',array('status'=>'revoked','revoked_at'=>$now,'revoked_by'=>1,'reason_code'=>'synthetic_revocation'),array('teacher_id'=>$teacherA));
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','teacher-accept-0001'),'recording_teacher_principal_required','revoked Teacher exact replay');
dzn_rda_assert(dzn_rda_count('teacher',$teacherA)===2,'Revoked Teacher replay must not rewrite evidence');
$state=$service->teacherTermsState();dzn_rda_assert($state['available']===false,'Revoked Teacher must not remain writable');

// Student recording and research are independent purposes with append-only history.
wp_set_current_user($adultUser);
$state=$service->studentDecisionState();dzn_rda_assert($state['available']===true&&$state['capture_eligible']===false,'unanswered recording must be ineligible for capture');
dzn_rda_assert($state['recording']['decision']===null&&$state['research']['decision']===null,'new Student must have no recorded choices');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'fr','student-invalid-language-01'),'recording_decision_language_invalid','unsupported Student language');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','short'),'recording_decision_key_required','short Student idempotency key');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',str_repeat('?',26),'en','student-malformed-notice-01'),'recording_notice_unavailable','malformed notice identifier');
$allow=$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','student-record-allow-01');
dzn_rda_assert($allow['state']==='allow'&&$allow['idempotent']===false,'Student recording allow must persist');
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===true,'recorded allow with current notice must be eligible for capture');
dzn_rda_assert($state['research']['decision']===null,'recording permission must not create research participation');
$replay=$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','student-record-allow-01');
dzn_rda_assert($replay['idempotent']===true&&$replay['decision_id']===$allow['decision_id'],'Exact Student replay must return the original result');
dzn_rda_assert(dzn_rda_count('student',$adult)===1,'Exact Student replay must not duplicate evidence');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','decline',$recording['uid'],'en','student-record-allow-01'),'recording_decision_replay_conflict','Changed Student replay payload');
$researchOpt=$service->recordOwnStudentChoice('research_model_training','opt_in',$research['uid'],'en','student-research-optin-01');
dzn_rda_assert($researchOpt['state']==='opt_in','separate research opt-in must persist');
$state=$service->studentDecisionState();dzn_rda_assert($state['recording']['decision']==='allow'&&$state['research']['decision']==='opt_in','recording and research choices must remain separate');
$decline=$service->recordOwnStudentChoice('lesson_recording','decline',$recording['uid'],'en','student-record-decline-01');
dzn_rda_assert($decline['state']==='decline'&&$decline['decision_id']!==$allow['decision_id'],'recording decline must append a later decision');
$state=$service->studentDecisionState();dzn_rda_assert($state['recording']['decision']==='decline'&&$state['research']['decision']==='opt_in'&&$state['capture_eligible']===false,'decline must not alter research and must be ineligible for capture');
$withdraw=$service->recordOwnStudentChoice('research_model_training','withdraw',$research['uid'],'en','student-research-withdraw-01');
dzn_rda_assert($withdraw['state']==='withdraw','research withdrawal must append a later decision');
$state=$service->studentDecisionState();dzn_rda_assert($state['recording']['decision']==='decline'&&$state['research']['decision']==='withdraw','latest separate choices must be visible');
dzn_rda_assert(dzn_rda_count('student',$adult)===4,'append-only history must retain allow, opt-in, decline and withdrawal');
dzn_rda_assert(dzn_rda_count('student',$adult,'research_model_training')===2,'research history must retain opt-in and withdrawal');
dzn_rda_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type='student' AND subject_id=%d AND purpose_code='research_model_training' AND decision_code='opt_in'",$adult))===1,'research withdrawal must not rewrite the original opt-in');
$original=$service->recordOwnStudentChoice('research_model_training','opt_in',$research['uid'],'en','student-research-optin-01');
dzn_rda_assert($original['idempotent']===true&&$original['decision_id']===$researchOpt['decision_id'],'exact research replay must return the original decision');
dzn_rda_assert(dzn_rda_count('student',$adult)===4,'exact research replay must not append history');
foreach(array(array('lesson_recording','opt_in'),array('research_model_training','allow'),array('lesson_recording','withdrawn'))as$bad)dzn_rda_expect(fn()=>$service->recordOwnStudentChoice($bad[0],$bad[1],$recording['uid'],'en','student-invalid-'.$bad[0].'-'.$bad[1]),'recording_decision_choice_invalid','choice/purpose separation');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('unapproved_purpose','allow',$recording['uid'],'en','student-invalid-purpose-01'),'recording_decision_purpose_invalid','unknown purpose');

// Revocation must fail before an exact replay can return a stored Student result.
$wpdb->update($p.'student_principal_links',array('status'=>'revoked','active_slot'=>null,'revoked_at'=>$now,'revoked_by'=>1,'reason_code'=>'synthetic_revocation','updated_at'=>$now,'updated_by'=>1),array('student_id'=>$adult));
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','student-record-allow-01'),'recording_student_principal_required','revoked Student exact replay');
dzn_rda_assert(dzn_rda_count('student',$adult)===4,'revoked Student replay must not rewrite evidence');
$state=$service->studentDecisionState();dzn_rda_assert($state['available']===false&&$state['capture_eligible']===false,'revoked Student must not remain writable');

// Minor, unknown capacity, missing capacity, and unlinked accounts fail closed.
foreach(array(array($minorUser,'recording_student_capacity_unavailable'),array($unknownUser,'recording_student_capacity_unavailable'),array($missingCapacityUser,'recording_student_capacity_unavailable'),array($unlinkedUser,'recording_student_principal_required'))as$case){wp_set_current_user($case[0]);$state=$service->studentDecisionState();dzn_rda_assert($state['available']===false&&$state['capture_eligible']===false,'closed Student state must stay unavailable');dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','closed-student-'.substr((string)$case[0],0,8)),$case[1],'closed Student choice');}
foreach(array($minor,$unknown,$missingCapacity)as$student)dzn_rda_assert(dzn_rda_count('student',$student)===0,'closed Student must not gain a decision');

// Published, current, correctly targeted notices are mandatory; a valid Student still gets no decision otherwise.
wp_set_current_user($noticeAdultUser);
foreach(array($recordingDraft,$recordingExpired,$recordingWithdrawn,$recordingFuture,$teacherTerms)as$notice)dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$notice['uid'],'en','notice-adult-'.substr($notice['uid'],0,10)),'recording_notice_unavailable','unavailable Student notice');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recordingFa['uid'],'en','notice-adult-wrong-language'),'recording_notice_unavailable','wrong-language notice');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('research_model_training','opt_in',$recording['uid'],'en','notice-adult-wrong-audience'),'recording_notice_unavailable','wrong-audience research notice');
dzn_rda_assert(dzn_rda_count('student',$noticeAdult)===0,'refused notices must not write Student evidence');

// Guardian and default-on paths remain explicitly unavailable in this slice.
dzn_rda_expect(fn()=>$service->recordGuardianChoiceForResolvedStudent('lesson_recording','allow',$recording['uid'],'en','guardian-unavailable-01'),'recording_guardian_scope_unavailable','guardian path');
dzn_rda_expect(fn()=>$service->recordApprovedOptOutBasisForResolvedStudent($recording['uid'],'default-on-unavailable-01'),'recording_default_on_policy_unavailable','default-on path');
wp_set_current_user(0);
dzn_rda_expect(fn()=>$service->recordGuardianChoiceForResolvedStudent('lesson_recording','allow',$recording['uid'],'en','guardian-unauthenticated-01'),'recording_authenticated_principal_required','unauthenticated guardian path');
dzn_rda_expect(fn()=>$service->recordApprovedOptOutBasisForResolvedStudent($recording['uid'],'default-on-unauthenticated-01'),'recording_authenticated_principal_required','unauthenticated default-on path');
wp_set_current_user($noticeAdultUser);
dzn_rda_assert(dzn_rda_count('student',$noticeAdult)===0,'unavailable guardian/default-on paths must not write evidence');

// A newer published notice supersedes the older version for new decisions and capture eligibility.
$versionUser=dzn_rda_user('version-student');$versionStudent=dzn_rda_student('version-student','Version Student','adult');dzn_rda_link_student($versionStudent,$versionUser);
wp_set_current_user($versionUser);
$versionAllow=$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','version-student-allow-01');
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===true,'current v1 notice must initially be eligible');
$recordingNext=dzn_rda_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',6,'published','Student recording notice v2',$now);
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===false,'older decision must become ineligible after a newer published notice');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','version-student-stale-01'),'recording_notice_unavailable','older notice must not authorise a new decision');
$versionNext=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','version-student-next-01');
dzn_rda_assert($versionNext['state']==='allow','newer notice must authorise a new decision');
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===true&&$state['recording']['notice_version']===6,'newer decision must cite and use the current notice');
dzn_rda_assert(dzn_rda_count('student',$versionStudent)===2,'notice supersession must append evidence, not rewrite it');

// Capacity downgrade must fail before an exact replay can return a stored result.
$capacityUser=dzn_rda_user('capacity-downgrade');$capacityStudent=dzn_rda_student('capacity-downgrade','Capacity Downgrade','adult');dzn_rda_link_student($capacityStudent,$capacityUser);
wp_set_current_user($capacityUser);
$capacityAllow=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','capacity-downgrade-allow-01');
dzn_rda_capacity($capacityStudent,'minor');
$state=$service->studentDecisionState();dzn_rda_assert($state['available']===false&&$state['capture_eligible']===false,'capacity downgrade must close the Student state');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','capacity-downgrade-allow-01'),'recording_student_capacity_unavailable','capacity-downgrade exact replay');
dzn_rda_assert(dzn_rda_count('student',$capacityStudent)===1,'capacity-downgrade replay must not rewrite evidence');
dzn_rda_assert($capacityAllow['idempotent']===false,'capacity fixture must begin with a fresh decision');

// A mispointed capacity projection must not borrow another Student's adult classification.
$donorStudent=dzn_rda_student('capacity-donor','Capacity Donor','adult');
$donorCapacity=(int)$wpdb->get_var($wpdb->prepare("SELECT current_acceptance_capacity_classification_id FROM {$p}students WHERE id=%d",$donorStudent));
$mispointedUser=dzn_rda_user('mispointed-capacity');$mispointedStudent=dzn_rda_student('mispointed-capacity','Mispointed Capacity','none');dzn_rda_link_student($mispointedStudent,$mispointedUser);
dzn_rda_assert($wpdb->update($p.'students',array('current_acceptance_capacity_classification_id'=>$donorCapacity),array('id'=>$mispointedStudent))===1,'fixture must mispoint the capacity projection');
wp_set_current_user($mispointedUser);
$state=$service->studentDecisionState();dzn_rda_assert($state['available']===false&&$state['reason']==='recording_student_capacity_unavailable'&&$state['capture_eligible']===false,'mispointed capacity must fail closed');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','mispointed-capacity-01'),'recording_student_capacity_unavailable','mispointed capacity choice');
dzn_rda_assert(dzn_rda_count('student',$mispointedStudent)===0,'mispointed capacity must not write evidence');

// Withdrawing the notice invalidates capture but does not rewrite an exact replay.
$withdrawUser=dzn_rda_user('notice-withdrawal');$withdrawStudent=dzn_rda_student('notice-withdrawal','Notice Withdrawal','adult');dzn_rda_link_student($withdrawStudent,$withdrawUser);
wp_set_current_user($withdrawUser);
$withdrawAllow=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','notice-withdrawal-allow-01');
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===true,'withdrawal fixture must begin eligible');
$wpdb->update($p.'recording_notice_versions',array('withdrawn_at'=>$now),array('id'=>$recordingNext['id']));
$state=$service->studentDecisionState();dzn_rda_assert($state['capture_eligible']===false,'withdrawn notice must invalidate capture eligibility');
$withdrawReplay=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNext['uid'],'en','notice-withdrawal-allow-01');
dzn_rda_assert($withdrawReplay['idempotent']===true&&$withdrawReplay['decision_id']===$withdrawAllow['decision_id'],'exact replay must remain stable after notice withdrawal');
dzn_rda_assert(dzn_rda_count('student',$withdrawStudent)===1,'withdrawn-notice replay must not append evidence');

// Ambiguous identity links fail closed. The disposable fixture briefly relaxes only
// its own unique link index so the service's ambiguity guard can be exercised.
$ambiguousTeacherUser=dzn_rda_user('ambiguous-teacher');$teacherC=dzn_rda_teacher('c','Teacher C');$teacherD=dzn_rda_teacher('d','Teacher D');
dzn_rda_assert($wpdb->query("ALTER TABLE {$p}teacher_principal_links DROP INDEX wordpress_user_id")!==false,'fixture must relax the Teacher link uniqueness index');
dzn_rda_link_teacher($teacherC,$ambiguousTeacherUser);dzn_rda_link_teacher($teacherD,$ambiguousTeacherUser);
wp_set_current_user($ambiguousTeacherUser);
$state=$service->teacherTermsState();dzn_rda_assert($state['available']===false&&$state['reason']==='recording_teacher_principal_ambiguous','ambiguous Teacher state must be unavailable');
dzn_rda_expect(fn()=>$service->acceptTeacherTerms($teacherTerms['uid'],'en','ambiguous-teacher-01'),'recording_teacher_principal_ambiguous','ambiguous Teacher acceptance');
dzn_rda_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_principal_links WHERE wordpress_user_id=%d",$ambiguousTeacherUser))!==false,'fixture must clean ambiguous Teacher links');
dzn_rda_assert($wpdb->query("ALTER TABLE {$p}teacher_principal_links ADD UNIQUE KEY wordpress_user_id(wordpress_user_id)")!==false,'fixture must restore the Teacher link uniqueness index');

$ambiguousStudentUser=dzn_rda_user('ambiguous-student');$studentI1=dzn_rda_student('ambiguous-one','Ambiguous One','adult');$studentI2=dzn_rda_student('ambiguous-two','Ambiguous Two','adult');
dzn_rda_assert($wpdb->query("ALTER TABLE {$p}student_principal_links DROP INDEX principal_active")!==false,'fixture must relax the Student link uniqueness index');
dzn_rda_link_student($studentI1,$ambiguousStudentUser);dzn_rda_link_student($studentI2,$ambiguousStudentUser);
wp_set_current_user($ambiguousStudentUser);
$state=$service->studentDecisionState();dzn_rda_assert($state['available']===false&&$state['reason']==='recording_student_principal_ambiguous'&&$state['capture_eligible']===false,'ambiguous Student state must be unavailable');
dzn_rda_expect(fn()=>$service->recordOwnStudentChoice('lesson_recording','allow',$recording['uid'],'en','ambiguous-student-01'),'recording_student_principal_ambiguous','ambiguous Student choice');
dzn_rda_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}student_principal_links WHERE wordpress_user_id=%d",$ambiguousStudentUser))!==false,'fixture must clean ambiguous Student links');
dzn_rda_assert($wpdb->query("ALTER TABLE {$p}student_principal_links ADD UNIQUE KEY principal_active(wordpress_user_id,active_slot)")!==false,'fixture must restore the Student link uniqueness index');
foreach(array($studentI1,$studentI2)as$student)dzn_rda_assert(dzn_rda_count('student',$student)===0,'ambiguous Student must not gain a decision');

echo "Recording decision behavior runtime: PASS\n";
