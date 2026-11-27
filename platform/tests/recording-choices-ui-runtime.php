<?php
if(getenv('DZN_RECORDING_UI_TEST')!=='choices'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Recording choices UI runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Application\RecordingNoticePublicationService;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\RecordingDecisionUiReadService;
global $wpdb;$p=$wpdb->prefix.'dzn_';$GLOBALS['dzn_rda_ui_prefix']=$p;$service=new RecordingDecisionAuthorityService();$ui=new RecordingDecisionUiReadService($service);
function dzn_rda_ui_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_rda_ui_p():string{return(string)($GLOBALS['dzn_rda_ui_prefix']??'');}
function dzn_rda_ui_user(string $label):int{$id=wp_insert_user(array('user_login'=>'dzn-rda-ui-'.$label.'-'.substr(Identifier::uid(),0,10),'user_pass'=>wp_generate_password(24),'user_email'=>'dzn-rda-ui-'.$label.'-'.substr(Identifier::uid(),0,10).'@example.invalid','role'=>'subscriber'));if(is_wp_error($id)||(int)$id<1)throw new RuntimeException('UI fixture user failed '.$label);return(int)$id;}
function dzn_rda_ui_teacher(string $name):int{global $wpdb;$p=dzn_rda_ui_p();$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'teachers',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('UI fixture teacher failed');return(int)$wpdb->insert_id;}
function dzn_rda_ui_student(string $name,string $capacity):int{global $wpdb;$p=dzn_rda_ui_p();$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('UI fixture student failed');$id=(int)$wpdb->insert_id;if($capacity!=='none'){if($wpdb->insert($p.'student_acceptance_capacity_classifications',array('uid'=>Identifier::uid(),'student_id'=>$id,'classification'=>$capacity,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'recording-choices-ui','evidence_at'=>$now,'classified_at'=>$now,'classified_by'=>1,'created_at'=>$now,'created_by'=>1))===false)throw new RuntimeException('UI fixture capacity failed');$cid=(int)$wpdb->insert_id;if($wpdb->update($p.'students',array('current_acceptance_capacity_classification_id'=>$cid),array('id'=>$id))===false)throw new RuntimeException('UI fixture capacity projection failed');}return$id;}
function dzn_rda_ui_teacher_link(int $teacher,int $user):void{global $wpdb;$p=dzn_rda_ui_p();$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$teacher,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1))===false)throw new RuntimeException('UI fixture teacher link failed');}
function dzn_rda_ui_student_link(int $student,int $user):void{global $wpdb;$p=dzn_rda_ui_p();$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'student_principal_links',array('student_id'=>$student,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1,'link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'recording-choices-ui','evidence_at'=>$now,'updated_at'=>$now,'updated_by'=>1))===false)throw new RuntimeException('UI fixture student link failed');}
function dzn_rda_ui_notice(string $audience,string $language,int $version,string $body,string $state='published'):array{global $wpdb;$p=dzn_rda_ui_p();$now=gmdate('Y-m-d H:i:s');$uid=Identifier::uid();if($wpdb->insert($p.'recording_notice_versions',array('uid'=>$uid,'audience_code'=>$audience,'language_code'=>$language,'version_number'=>$version,'publication_state'=>$state,'body_text'=>$body,'text_digest'=>hash('sha256',$body),'published_at'=>'published'===$state?$now:null,'expires_at'=>null,'withdrawn_at'=>null,'created_at'=>$now,'created_by'=>1))===false)throw new RuntimeException('UI fixture notice failed');return array('id'=>(int)$wpdb->insert_id,'uid'=>$uid);}
function dzn_rda_ui_render_student(array $model):string{ob_start();dzn_theme_portal_component('recording-choices',array('recording'=>$model));return(string)ob_get_clean();}
function dzn_rda_ui_render_teacher(array $model):string{ob_start();dzn_theme_teacher_portal_component('recording-terms',array('recording'=>$model));return(string)ob_get_clean();}

dzn_rda_ui_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='61','UI runtime requires the current package schema');
dzn_rda_ui_assert('Delnavazan Production Theme'===(string)wp_get_theme()->get('Name'),'Delnavazan theme must be active for component rendering');

$teacherUser=dzn_rda_ui_user('teacher');$teacher=dzn_rda_ui_teacher('UI Teacher');dzn_rda_ui_teacher_link($teacher,$teacherUser);
$adultUser=dzn_rda_ui_user('adult');$adult=dzn_rda_ui_student('UI Adult','adult');dzn_rda_ui_student_link($adult,$adultUser);
$minorUser=dzn_rda_ui_user('minor');$minor=dzn_rda_ui_student('UI Minor','minor');dzn_rda_ui_student_link($minor,$minorUser);
$noCapacityUser=dzn_rda_ui_user('no-capacity');$noCapacity=dzn_rda_ui_student('UI No Capacity','none');dzn_rda_ui_student_link($noCapacity,$noCapacityUser);
$unlinkedUser=dzn_rda_ui_user('unlinked');
$teacherEn=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',1,'Teacher recording terms v1');
$teacherFa=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'fa',1,'شرایط ضبط درس برای مدرسان نسخه ۱');
$teacherRecEn=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING,'en',1,'Teacher recording v1');
$teacherRecFa=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING,'fa',1,'ضبط کلاس مدرس نسخه ۱');
$teacherResEn=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH,'en',1,'Teacher research v1');
$teacherResFa=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH,'fa',1,'پژوهش مدرس نسخه ۱');
$recordingNotice=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',1,'Student recording notice v1');
$researchNotice=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,'en',1,'Student research notice v1');
$privacyNotice=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_PRIVACY_TERMS,'en',1,'Privacy v1');

// Enrolment: default-on recording with an explicit decline control and an unchecked research opt-in.
wp_set_current_user($adultUser);
$enrolment=$ui->student('enrolment','en');
dzn_rda_ui_assert($enrolment['available']===true,'adult enrolment choices must be available');
dzn_rda_ui_assert($enrolment['default_recording_choice']===null&&$enrolment['default_research_choice']===null,'enrolment must not preselect any choice');
dzn_rda_ui_assert($enrolment['capture_eligible']===false,'unanswered recording must be ineligible for capture');
dzn_rda_ui_assert($enrolment['forms']['recording']['notice_uid']===$recordingNotice['uid'],'recording form must cite the published notice');
dzn_rda_ui_assert($enrolment['forms']['research']['notice_uid']===$researchNotice['uid'],'research form must cite the separate published notice');
dzn_rda_ui_assert(!empty($enrolment['unified']['available'])===true,'unified enrolment consent must be available');
$html=dzn_rda_ui_render_student($enrolment);
foreach(array('ضبط کلاس، پژوهش و حریم خصوصی','privacy-policy','recording-and-ai-policy','unified_agree')as$text)dzn_rda_ui_assert(str_contains($html,$text),'enrolment component missing '.$text);
dzn_rda_ui_assert(!preg_match('/name="unified_agree"[^>]*checked/',$html),'optional research consent must not be pre-checked');
dzn_rda_ui_assert(str_contains($html,'ضبط کلاس به‌طور پیش‌فرض فعال است'),'default-on recording state must be stated');
dzn_rda_ui_assert(str_contains($html,'value="decline"'),'default-on recording must expose a decline control');
dzn_rda_ui_assert(!str_contains($html,'guardian'),'no guardian form may be exposed');

// Account Settings: unified state, withdraw-all control at the end.
$allow=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNotice['uid'],'en','ui-runtime-allow-01');
$service->recordOwnStudentChoice('research_model_training','opt_in',$researchNotice['uid'],'en','ui-runtime-research-01');
$service->recordOwnStudentChoice('privacy_terms','accepted',$privacyNotice['uid'],'en','ui-runtime-privacy-01');
$account=$ui->student('account','en');
dzn_rda_ui_assert($account['recording']['decision']==='allow'&&!empty($account['recording']['decided_at']),'account must show the recording decision and date');
dzn_rda_ui_assert(!empty($account['consent_active']),'account must report unified consent active');
$accountHtml=dzn_rda_ui_render_student($account);
dzn_rda_ui_assert(str_contains($accountHtml,'رضایت شما ثبت شده'),'account must show unified active consent');
dzn_rda_ui_assert(str_contains($accountHtml,'پس گرفتن رضایت'),'opted-in student must expose withdraw-all');
$service->recordOwnStudentChoice('research_model_training','withdraw',$researchNotice['uid'],'en','ui-runtime-research-02');
$accountHtml=dzn_rda_ui_render_student($ui->student('account','en'));
dzn_rda_ui_assert(!str_contains($accountHtml,'پس گرفتن رضایت'),'withdrawn research must hide withdraw-all until re-consented');
dzn_rda_ui_assert(str_contains($accountHtml,'unified_agree'),'withdrawn student must be offered unified re-consent');

// Minor and unlinked accounts get a neutral unavailable state and no writable form.
wp_set_current_user($minorUser);$minorModel=$ui->student('enrolment','en');$minorHtml=dzn_rda_ui_render_student($minorModel);
dzn_rda_ui_assert($minorModel['available']===false&&$minorModel['capture_eligible']===false,'minor must be unavailable');
dzn_rda_ui_assert(str_contains($minorHtml,'انتخاب ضبط و پژوهش برای این حساب در دسترس نیست')&&!str_contains($minorHtml,'<form'),'minor must not receive a writable form');
dzn_rda_ui_assert(empty($minorModel['capacity_recovery_available']),'known minor must not be offered adult attestation');
wp_set_current_user($noCapacityUser);
$noCapacityModel=$ui->student('account','en');$noCapacityHtml=dzn_rda_ui_render_student($noCapacityModel);
dzn_rda_ui_assert(!empty($noCapacityModel['capacity_recovery_available']) && str_contains($noCapacityHtml,'dzn_student_adult_attestation'),'unclassified existing student must have authenticated attestation recovery');
dzn_rda_ui_assert(empty($noCapacityModel['available']) && !str_contains($noCapacityHtml,'name="unified_agree"'),'unclassified student must not bypass consent authority');
$capacityRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingStudentAccountRepository();
$capacityRepo->assertTransactionalStorage();
$beforeBad=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_capacity_classifications WHERE student_id=%d",$noCapacity));
$capacityRepo->begin();
try{
    $capacityRepo->recordAdultSelfAttestation(-1,$noCapacityUser,gmdate('Y-m-d H:i:s'));
    throw new RuntimeException('Invalid capacity write must fail');
}catch(\RuntimeException $e){$capacityRepo->rollback();}
dzn_rda_ui_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_capacity_classifications WHERE student_id=%d",$noCapacity))===$beforeBad,'failed capacity write must not change an existing Student');
$recovery=new \Delnavazan\Platform\Core\Application\StudentAdultAttestationRecoveryService();
$recovery->recover();
$recovered=$wpdb->get_row($wpdb->prepare("SELECT c.classification,c.verification_basis,c.evidence_reference FROM {$p}students s JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE s.id=%d",$noCapacity));
dzn_rda_ui_assert($recovered && $recovered->classification==='adult' && $recovered->verification_basis==='self_attestation' && str_contains($recovered->evidence_reference,'account_recovery'),'recovery must write new prospective capacity evidence');
dzn_rda_ui_assert($ui->student('account','en')['available']===true && !$ui->student('account','en')['consent_active'],'capacity recovery must not fabricate consent');
$recoveryRefused=false;try{$recovery->recover();}catch(\RuntimeException){$recoveryRefused=true;}
dzn_rda_ui_assert($recoveryRefused,'repeat recovery must not overwrite evidence');
wp_set_current_user($minorUser);
dzn_rda_ui_assert(!$recovery->eligible(),'minor must not be eligible for adult recovery');
$minorRefused=false;try{$recovery->recover();}catch(\RuntimeException){$minorRefused=true;}
dzn_rda_ui_assert($minorRefused,'known minor must not overwrite capacity');
// Booking account-creation branch must persist attested adult evidence before new consent.
$newBookingUser=dzn_rda_ui_user('new-booking-adult');
$newBookingAccount=get_userdata($newBookingUser);
$bookingAccountRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingStudentAccountRepository();
$bookingAccountService=new \Delnavazan\Platform\Core\Application\BookingStudentAccountService($bookingAccountRepo);
$bookingContact=array('full_name'=>'Synthetic New Booking Adult','email'=>$newBookingAccount->user_email,
    'mobile'=>'+61400000000','whatsapp_number'=>'+61400000000','country'=>'AU','city'=>'Brisbane',
    'timezone'=>'Australia/Brisbane','communication_language'=>'en','adult_self_attested'=>1);
$bookingAccountRepo->begin();
try{
    $newStudentId=(new ReflectionMethod($bookingAccountService,'resolveStudent'))->invoke($bookingAccountService,$newBookingUser,$bookingContact);
    $bookingAccountRepo->commit();
}catch(\Throwable $e){$bookingAccountRepo->rollback();throw $e;}
wp_set_current_user($newBookingUser);
$newAccountState=$ui->student('account','en');
dzn_rda_ui_assert($newAccountState['available']===true && !$newAccountState['consent_active'],'new booking adult must have valid capacity but no fabricated consent');
$bookingUnified=new \Delnavazan\Platform\Core\Application\UnifiedConsentService();
$bookingDecisions=$bookingUnified->acceptStudentUnified('ui-new-booking-student-v1','en');
dzn_rda_ui_assert(count($bookingDecisions)===3 && $ui->student('account','en')['consent_active']===true,'new booking account can record all explicit student consent purposes');

// Partial Student writes are purpose-specific and can be replayed safely.
$partialStudentUser=dzn_rda_ui_user('student-retry');$partialStudent=dzn_rda_ui_student('Retry Student','adult');dzn_rda_ui_student_link($partialStudent,$partialStudentUser);
wp_set_current_user($partialStudentUser);
$studentIntercept=function($type,$subjectId,$purpose)use($wpdb,$p,$partialStudent){
    if($type==='student' && (int)$subjectId===$partialStudent && $purpose==='lesson_recording'){
        $wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s AND language_code='en'",RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH));
    }
};
add_action('dzn_consent_decision_recorded',$studentIntercept,10,3);
$studentPartial=null;
try{$bookingUnified->acceptStudentUnified('ui-partial-student-v1','en');}catch(\Delnavazan\Platform\Core\Application\UnifiedConsentPartialFailure $e){$studentPartial=$e;}
remove_action('dzn_consent_decision_recorded',$studentIntercept,10);
dzn_rda_ui_assert($studentPartial!==null && $studentPartial->failedPurpose==='research_model_training'
    && $studentPartial->purposeOutcomes['lesson_recording']==='recorded'
    && $studentPartial->purposeOutcomes['privacy_terms']==='not_attempted','Student partial failure must report committed, failed and unattempted purposes');
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='published',published_at=%s WHERE audience_code=%s AND language_code='en'",gmdate('Y-m-d H:i:s'),RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH));
$studentRetry=$bookingUnified->acceptStudentUnified('ui-partial-student-v1','en');
dzn_rda_ui_assert($studentRetry['lesson_recording']['idempotent']===true && $ui->student('account','en')['consent_active']===true,'Student consent retry must finish without duplicating recorded decisions');
dzn_rda_ui_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type='student' AND subject_id=%d AND purpose_code='lesson_recording'",$partialStudent))===1,'Student retry must retain exactly one recording grant');
$newRenderRetry=$bookingUnified->acceptStudentUnified('ui-partial-student-new-render-v2','en');
dzn_rda_ui_assert($newRenderRetry['lesson_recording']['idempotent'] && $newRenderRetry['research_model_training']['idempotent'] && $newRenderRetry['privacy_terms']['idempotent'],'new Student form key must not duplicate current positive decisions');
$bookingUnified->withdrawStudentAll('ui-partial-student-withdraw','en');
dzn_rda_ui_assert(!$ui->student('account','en')['consent_active'],'Student withdrawal must revoke active bundled consent without deleting append-only history');
$staleReplayRefused=false;
try{$bookingUnified->acceptStudentUnified('ui-partial-student-v1','en');}
catch(\Delnavazan\Platform\Core\Application\UnifiedConsentPartialFailure){$staleReplayRefused=true;}
dzn_rda_ui_assert($staleReplayRefused && !$ui->student('account','en')['consent_active'],'stale replay after withdrawal must never claim consent restored');




wp_set_current_user($unlinkedUser);$unlinkedModel=$ui->student('enrolment','en');
dzn_rda_ui_assert($unlinkedModel['available']===false,'unlinked account must be unavailable');

// Teacher onboarding: unified mandatory consent, recorded date, and unavailable state.
wp_set_current_user($teacherUser);$teacherModel=$ui->teacher();
dzn_rda_ui_assert($teacherModel['available']===true&&$teacherModel['accepted']===false&&count($teacherModel['notices'])===2,'linked Teacher must see both published language versions');
dzn_rda_ui_assert(!empty($teacherModel['unified']['available']),'teacher unified consent must be available');
$teacherHtml=dzn_rda_ui_render_teacher($teacherModel);
foreach(array('Privacy Policy','Recording &amp; AI Policy','unified_agree','mandatory to work')as$text)dzn_rda_ui_assert(str_contains($teacherHtml,$text),'teacher component missing '.$text);
dzn_rda_ui_assert((bool)preg_match('/name="unified_agree"[^>]*checked/',$teacherHtml),'teacher unified checkbox must be checked by default');
$accepted=$service->acceptTeacherTerms($teacherEn['uid'],'en','ui-runtime-terms-01');
$teacherModel=$ui->teacher();dzn_rda_ui_assert($teacherModel['accepted']===true&&!empty($teacherModel['accepted_at']),'teacher acceptance must expose its recorded date');
$teacherHtml=dzn_rda_ui_render_teacher($teacherModel);
dzn_rda_ui_assert(str_contains($teacherHtml,'unified_agree'),'teacher component must still offer unified completion after terms only');
// Teacher mandatory consent must not silently record research or require a research publication.
$optionalUser=dzn_rda_ui_user('teacher-optional');$optionalTeacher=dzn_rda_ui_teacher('Optional Research Teacher');dzn_rda_ui_teacher_link($optionalTeacher,$optionalUser);
wp_set_current_user($optionalUser);
$bundle=new \Delnavazan\Platform\Core\Application\UnifiedConsentService();
$optionalResults=$bundle->acceptTeacherUnified('ui-optional-teacher-v1','en','portal_unified','teacher_unified',false);
dzn_rda_ui_assert(count($optionalResults)===3 && !isset($optionalResults['teacher_research_model_training']),'research must not be bundled without separate opt-in');
$optionalState=$ui->teacher('settings');
dzn_rda_ui_assert($optionalState['mandatory_accepted']===true && $optionalState['unified_accepted']===true && !$optionalState['research_opted_in'],'teacher can complete terms without research');
$optionalHtml=dzn_rda_ui_render_teacher($optionalState);
dzn_rda_ui_assert(str_contains($optionalHtml,'Opt in to research') && !str_contains($optionalHtml,'Withdraw research consent'),'research opt-in must be independent of teaching');
$optionalUpgrade=$bundle->acceptTeacherUnified('ui-optional-teacher-research-v2','en','portal_unified','teacher_unified',true);
dzn_rda_ui_assert(count($optionalUpgrade)===4 && $optionalUpgrade['teacher_terms']['idempotent']===true,'separate optional opt-in must not duplicate required teaching decisions');
$optionalState=$ui->teacher('settings');
dzn_rda_ui_assert($optionalState['research_opted_in']===true && $optionalState['mandatory_accepted']===true,'independent research acceptance must not alter teaching eligibility');
dzn_rda_ui_assert(str_contains(dzn_rda_ui_render_teacher($optionalState),'Withdraw research consent'),'opted-in teacher needs withdrawal action');
(new \Delnavazan\Platform\Core\Application\ConsentWithdrawalService())->withdrawTeacherResearch($teacherResEn['uid'],'en','ui-optional-research-withdraw');
$optionalState=$ui->teacher('settings');
dzn_rda_ui_assert(!$optionalState['research_opted_in'] && $optionalState['mandatory_accepted'],'research withdrawal must preserve teaching consent');
$afterOptout=$bundle->acceptTeacherUnified('ui-optional-teacher-no-research-v3','en','portal_unified','teacher_unified',false);
dzn_rda_ui_assert(count($afterOptout)===3 && !$ui->teacher('settings')['research_opted_in'],'re-submitting required terms must not re-enable research after withdrawal');


// Fail second purpose after first commit; retry must replay, not duplicate.
$partialUser=dzn_rda_ui_user('teacher-retry');$partialTeacher=dzn_rda_ui_teacher('Retry Teacher');dzn_rda_ui_teacher_link($partialTeacher,$partialUser);
wp_set_current_user($partialUser);
$intercept=function($type,$subjectId,$purpose)use($wpdb,$p,$partialTeacher){
    if($type==='teacher' && (int)$subjectId===$partialTeacher && $purpose==='teacher_terms'){
        $wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s AND language_code='en'",RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING));
    }
};
add_action('dzn_consent_decision_recorded',$intercept,10,3);
$partialError=null;
try{$bundle->acceptTeacherUnified('ui-partial-teacher-v1','en');}catch(\Delnavazan\Platform\Core\Application\UnifiedConsentPartialFailure $e){$partialError=$e;}
remove_action('dzn_consent_decision_recorded',$intercept,10);
dzn_rda_ui_assert($partialError!==null && $partialError->failedPurpose==='teacher_lesson_recording' && $partialError->purposeOutcomes['teacher_terms']==='recorded','failure must identify first committed and second failed purposes');
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='published',published_at=%s WHERE audience_code=%s AND language_code='en'",gmdate('Y-m-d H:i:s'),RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING));
$retried=$bundle->acceptTeacherUnified('ui-partial-teacher-v1','en');
dzn_rda_ui_assert($retried['teacher_terms']['idempotent']===true && $ui->teacher('settings')['unified_accepted']===true,'retry must replay first purpose and finish remaining purposes');
dzn_rda_ui_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type='teacher' AND subject_id=%d AND purpose_code='teacher_terms'",$partialTeacher))===1,'retry must not duplicate teacher terms evidence');
$reRendered=$bundle->acceptTeacherUnified('ui-partial-teacher-new-render-v2','en');
dzn_rda_ui_assert($reRendered['teacher_terms']['idempotent'] && $reRendered['teacher_lesson_recording']['idempotent'] && $reRendered['privacy_terms']['idempotent'],'new form idempotency key must not duplicate current positive Teacher decisions');
dzn_rda_ui_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_decisions WHERE subject_type='teacher' AND subject_id=%d",$partialTeacher))===3,'repeat unified Teacher consent must not append duplicate decisions');


wp_set_current_user($unlinkedUser);$missing=$ui->teacher();$missingHtml=dzn_rda_ui_render_teacher($missing);
dzn_rda_ui_assert($missing['available']===false&&str_contains($missingHtml,'available only to an active Teacher account')&&!str_contains($missingHtml,'name="unified_agree"'),'unlinked Teacher must get no acceptance form');

// No approved publication: acceptance and recording forms are unavailable, with no fabricated record.
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s",RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS));
wp_set_current_user($teacherUser);$noTerms=$ui->teacher();$noTermsHtml=dzn_rda_ui_render_teacher($noTerms);
dzn_rda_ui_assert($noTerms['available']===false&&str_contains($noTermsHtml,'ثبت رضایت در دسترس نیست')&&!str_contains($noTermsHtml,'name="unified_agree"'),'missing teacher publication must not render acceptance');
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='published',published_at=%s WHERE audience_code=%s",gmdate('Y-m-d H:i:s'),RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS));
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s",RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING));
wp_set_current_user($adultUser);$noRecording=$ui->student('enrolment','en');$noRecordingHtml=dzn_rda_ui_render_student($noRecording);
dzn_rda_ui_assert(!isset($noRecording['forms']['recording'])&&str_contains($noRecordingHtml,'ثبت رضایت در دسترس نیست'),'missing recording publication must not render unified consent');
dzn_rda_ui_assert(!str_contains($noRecordingHtml,'name="unified_agree"'),'missing recording publication must not write a decision');
// Published-but-unapproved notice cannot authorise a new consent decision.
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET approval_state='pending_review' WHERE audience_code=%s AND language_code='en'",RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH));
$unapproved=$ui->student('account','en');
dzn_rda_ui_assert(empty($unapproved['unified']['available']) && $service->publishedNotice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,'en')===null,'unapproved published Student notice must be unavailable');
$refusedUnapproved=false;
try{$service->recordOwnStudentChoice('research_model_training','opt_in',$researchNotice['uid'],'en','ui-unapproved-research-choice');}
catch(\RuntimeException $e){$refusedUnapproved=$e->getMessage()==='recording_notice_unavailable';}
dzn_rda_ui_assert($refusedUnapproved,'unapproved notice must fail closed on write');
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET approval_state='approved' WHERE audience_code=%s AND language_code='en'",RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH));

$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='published',published_at=%s WHERE audience_code=%s",gmdate('Y-m-d H:i:s'),RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING));

// The presentation bridge must supply the same Account Settings and onboarding models.
wp_set_current_user($adultUser);
$bridgeChoices=dzn_theme_platform_recording_choices('account');
dzn_rda_ui_assert($bridgeChoices['available']===true&&isset($bridgeChoices['forms']['recording']),'the Account Settings bridge must supply recording choices');
wp_set_current_user($teacherUser);
$bridgeTerms=dzn_theme_platform_teacher_recording_terms('onboarding');
dzn_rda_ui_assert($bridgeTerms['available']===true&&count($bridgeTerms['notices'])===2,'the Teacher onboarding bridge must supply both language versions');
dzn_rda_ui_assert($bridgeTerms['notices'][0]['return_to']==='onboarding','the Teacher onboarding form must return to onboarding');
$settingsTerms=dzn_theme_platform_teacher_recording_terms('settings');
dzn_rda_ui_assert($settingsTerms['notices'][0]['return_to']==='settings','the Teacher settings form must return to settings');

// Confirmation feedback is a one-shot server-side status, not a spoofable query parameter.
wp_set_current_user($adultUser);
set_transient('dzn_recording_status_student_'.$adultUser,'recording_saved',120);
$statusModel=$ui->student('account','en');
dzn_rda_ui_assert($statusModel['status']==='recording_saved','the server-side confirmation status must be visible once');
$statusModel=$ui->student('account','en');
dzn_rda_ui_assert($statusModel['status']==='','the confirmation status must be consumed after one read');

// The canonical information route resolves without an operator-created page and renders the bilingual body.
$_SERVER['REQUEST_URI']='/recording-and-research/';
dzn_rda_ui_assert(dzn_theme_route()==='recording-and-research','the canonical information URL must resolve as a public route');
ob_start();dzn_theme_render_recording_information_route();$infoHtml=(string)ob_get_clean();
foreach(array('پروژه پژوهشی','Research Project','An example: learning riz on setar','recording is active by default','lang="fa"','lang="en"','Privacy policy')as$text)dzn_rda_ui_assert(str_contains($infoHtml,$text),'public information route missing '.$text);
dzn_rda_ui_assert(substr_count($infoHtml,'<form')===1 && str_contains($infoHtml,'name="action" value="dzn_research_name"'),'Only the project name suggestion form belongs on this page');
dzn_rda_ui_assert(!str_contains($infoHtml,'dzn_unified_student_consent') && !str_contains($infoHtml,'dzn_unified_teacher_consent'),'Name suggestions must not collect recording or research decisions');
dzn_rda_ui_assert(str_contains($infoHtml,'<article id="project-fa"') && !preg_match('/<details[^>]*id="project-en"[^>]*\bopen\b/',$infoHtml),'Persian must be an article and English must start collapsed');
dzn_rda_ui_assert(strpos($infoHtml,'id="project-name"') < strpos($infoHtml,'id="project-fa"') && strpos($infoHtml,'id="project-fa"') < strpos($infoHtml,'id="project-en"'),'Form, Persian and English must appear in that order');

// Exercise the anonymous name intake against real WordPress storage and capabilities.
$inbox=\Delnavazan\Platform\Public\ResearchNameSuggestionController::class;
wp_set_current_user(0);
$input=array('_wpnonce'=>wp_create_nonce($inbox::ACTION),'website'=>'','project_name'=>'هم‌آوا — Research fixture');
$before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s",$inbox::POST_TYPE));
dzn_rda_ui_assert($inbox::receive($input,'192.0.2.50','POST')==='received','Anonymous suggestion must save');
dzn_rda_ui_assert($inbox::receive($input,'192.0.2.50','POST')==='received','Retry must acknowledge the saved suggestion');
$after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s",$inbox::POST_TYPE));
dzn_rda_ui_assert($after===$before+1,'Retry must not create another suggestion');
$suggestion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type=%s ORDER BY ID DESC LIMIT 1",$inbox::POST_TYPE));
dzn_rda_ui_assert($suggestion && $suggestion->post_status==='private' && $suggestion->post_title===$input['project_name'] && (int)$suggestion->post_author===0,'Stored suggestion must preserve Persian text and remain anonymous/private');
dzn_rda_ui_assert(!current_user_can('read_post',(int)$suggestion->ID),'Guests cannot read the private inbox');
wp_set_current_user($adultUser);
dzn_rda_ui_assert(!current_user_can('read_post',(int)$suggestion->ID),'Students cannot read the private inbox');
wp_set_current_user(1);
dzn_rda_ui_assert(current_user_can('read_post',(int)$suggestion->ID),'Administrator can review suggestions');
$type=get_post_type_object($inbox::POST_TYPE);
dzn_rda_ui_assert(!$type->public && !$type->publicly_queryable && !$type->show_in_rest,'Suggestions must not be exposed by public queries or REST');
$pageId=wp_insert_post(array('post_type'=>'page','post_name'=>'recording-and-research','post_status'=>'publish','post_title'=>'Recording and research information'));
dzn_rda_ui_assert(!is_wp_error($pageId)&&$pageId>0,'fixture information page failed');
dzn_rda_ui_assert(dzn_theme_route()==='','a published editor-managed page must take over the canonical slug');

// Administrator publication authority: a new version is immutable, becomes current and requires re-acceptance.
$publisher=new RecordingNoticePublicationService();
$published=$publisher->publish(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en','Teacher recording terms v2 (runtime publication)',1);
dzn_rda_ui_assert($published['version']===2,'publication must derive the next version server-side');
$stored=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions WHERE uid=%s",$published['uid']));
dzn_rda_ui_assert($stored!==null&&(string)$stored->publication_state==='published'&&hash_equals(hash('sha256',(string)$stored->body_text),(string)$stored->text_digest),'publication must store a published digest-backed version');
$current=$publisher->current(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en');
dzn_rda_ui_assert($current!==null&&(string)$current['uid']===$published['uid'],'the newest published version must be current');
wp_set_current_user($teacherUser);$renewed=$ui->teacher();
dzn_rda_ui_assert($renewed['available']===true&&$renewed['accepted']===false,'a newer published version must require re-acceptance');
dzn_rda_ui_assert(in_array(2,array_map(static fn(array $notice):int=>(int)$notice['notice_version'],$renewed['notices']),true),'the renewal form must cite the new version');
$withdrawn=$publisher->withdrawAll(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',1);
dzn_rda_ui_assert($withdrawn>=1&&$publisher->current(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en')===null,'withdrawal must leave no current English version');
$afterWithdraw=$ui->teacher();
dzn_rda_ui_assert(count($afterWithdraw['notices'])===1&&(string)$afterWithdraw['notices'][0]['language']==='fa','withdrawing English must leave the published Persian version only');
wp_set_current_user(1);
ob_start();\Delnavazan\Platform\Admin\Controller\RecordingNoticeAdminController::render();$adminHtml=(string)ob_get_clean();
dzn_rda_ui_assert(str_contains($adminHtml,'Recording notices')&&str_contains($adminHtml,'Publish new version')&&str_contains($adminHtml,'Withdraw all published versions'),'the administrator publication screen must render');
dzn_rda_ui_assert(str_contains($adminHtml,'Not published'),'a withdrawn audience language must be shown as not published');

echo "Recording choices UI runtime: PASS\n";
