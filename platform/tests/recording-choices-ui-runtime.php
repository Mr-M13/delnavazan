<?php
if(getenv('DZN_RECORDING_UI_TEST')!=='choices'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Recording choices UI runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
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

dzn_rda_ui_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='56','UI runtime requires Schema 56');
dzn_rda_ui_assert('Delnavazan Production Theme'===(string)wp_get_theme()->get('Name'),'Delnavazan theme must be active for component rendering');

$teacherUser=dzn_rda_ui_user('teacher');$teacher=dzn_rda_ui_teacher('UI Teacher');dzn_rda_ui_teacher_link($teacher,$teacherUser);
$adultUser=dzn_rda_ui_user('adult');$adult=dzn_rda_ui_student('UI Adult','adult');dzn_rda_ui_student_link($adult,$adultUser);
$minorUser=dzn_rda_ui_user('minor');$minor=dzn_rda_ui_student('UI Minor','minor');dzn_rda_ui_student_link($minor,$minorUser);
$unlinkedUser=dzn_rda_ui_user('unlinked');
$teacherEn=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'en',1,'Teacher recording terms v1');
$teacherFa=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,'fa',1,'شرایط ضبط درس برای مدرسان نسخه ۱');
$recordingNotice=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en',1,'Student recording notice v1');
$researchNotice=dzn_rda_ui_notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,'en',1,'Student research notice v1');

// Enrolment: two equally clear recording choices, no preselection, separate optional research.
wp_set_current_user($adultUser);
$enrolment=$ui->student('enrolment','en');
dzn_rda_ui_assert($enrolment['available']===true,'adult enrolment choices must be available');
dzn_rda_ui_assert($enrolment['default_recording_choice']===null&&$enrolment['default_research_choice']===null,'enrolment must not preselect any choice');
dzn_rda_ui_assert($enrolment['capture_eligible']===false,'unanswered recording must be ineligible for capture');
dzn_rda_ui_assert($enrolment['forms']['recording']['notice_uid']===$recordingNotice['uid'],'recording form must cite the published notice');
dzn_rda_ui_assert($enrolment['forms']['research']['notice_uid']===$researchNotice['uid'],'research form must cite the separate published notice');
$html=dzn_rda_ui_render_student($enrolment);
foreach(array('Recording and research choices','Allow lesson recording','Do not allow lesson recording','I want to participate in research','No, thanks')as$text)dzn_rda_ui_assert(str_contains($html,$text),'enrolment component missing '.$text);
dzn_rda_ui_assert(!preg_match('/name="choice" value="allow"[^>]*checked/',$html),'enrolment must not preselect allow');
dzn_rda_ui_assert(!preg_match('/name="choice" value="decline"[^>]*checked/',$html),'enrolment must not preselect decline');
dzn_rda_ui_assert(!str_contains($html,'guardian'),'no guardian form may be exposed');

// Account Settings: current state, dates, change controls and separate research withdrawal.
$allow=$service->recordOwnStudentChoice('lesson_recording','allow',$recordingNotice['uid'],'en','ui-runtime-allow-01');
$account=$ui->student('account','en');
dzn_rda_ui_assert($account['recording']['decision']==='allow'&&!empty($account['recording']['decided_at']),'account must show the recording decision and date');
$accountHtml=dzn_rda_ui_render_student($account);
dzn_rda_ui_assert(str_contains($accountHtml,'Lesson recording allowed'),'account must show the current recording decision');
dzn_rda_ui_assert((bool)preg_match('/name="choice" value="allow"[^>]*checked/',$accountHtml),'account must reflect the current allow choice');
dzn_rda_ui_assert(str_contains($accountHtml,'value="decline"'),'account must offer a decline change control');
$service->recordOwnStudentChoice('lesson_recording','decline',$recordingNotice['uid'],'en','ui-runtime-decline-01');
$declinedHtml=dzn_rda_ui_render_student($ui->student('account','en'));
dzn_rda_ui_assert((bool)preg_match('/name="choice" value="decline"[^>]*checked/',$declinedHtml),'account must reflect the changed decline choice');
dzn_rda_ui_assert(str_contains($declinedHtml,'value="allow"'),'account must still offer the allow change control');
$service->recordOwnStudentChoice('research_model_training','opt_in',$researchNotice['uid'],'en','ui-runtime-research-01');
$accountHtml=dzn_rda_ui_render_student($ui->student('account','en'));
dzn_rda_ui_assert(str_contains($accountHtml,'Withdraw participation'),'opted-in research must expose withdrawal');
$service->recordOwnStudentChoice('research_model_training','withdraw',$researchNotice['uid'],'en','ui-runtime-research-02');
$accountHtml=dzn_rda_ui_render_student($ui->student('account','en'));
dzn_rda_ui_assert(str_contains($accountHtml,'I want to participate in research')&&str_contains($accountHtml,'No, thanks'),'withdrawn research must expose the approved opt-in control again');
dzn_rda_ui_assert(!str_contains($accountHtml,'consent'),'the disabled default-on path must not be described as consent');

// Minor and unlinked accounts get a neutral unavailable state and no writable form.
wp_set_current_user($minorUser);$minorModel=$ui->student('enrolment','en');$minorHtml=dzn_rda_ui_render_student($minorModel);
dzn_rda_ui_assert($minorModel['available']===false&&$minorModel['capture_eligible']===false,'minor must be unavailable');
dzn_rda_ui_assert(str_contains($minorHtml,'Self-service recording and research choices are not available')&&!str_contains($minorHtml,'<form'),'minor must not receive a writable form');
wp_set_current_user($unlinkedUser);$unlinkedModel=$ui->student('enrolment','en');
dzn_rda_ui_assert($unlinkedModel['available']===false,'unlinked account must be unavailable');

// Teacher onboarding: version, language, explicit acceptance, recorded date, and unavailable state.
wp_set_current_user($teacherUser);$teacherModel=$ui->teacher();
dzn_rda_ui_assert($teacherModel['available']===true&&$teacherModel['accepted']===false&&count($teacherModel['notices'])===2,'linked Teacher must see both published language versions');
$teacherHtml=dzn_rda_ui_render_teacher($teacherModel);
foreach(array('Teacher recording terms','Accept these teacher recording terms','English','فارسی','version 1')as$text)dzn_rda_ui_assert(str_contains($teacherHtml,$text),'teacher component missing '.$text);
$accepted=$service->acceptTeacherTerms($teacherEn['uid'],'en','ui-runtime-terms-01');
$teacherModel=$ui->teacher();dzn_rda_ui_assert($teacherModel['accepted']===true&&!empty($teacherModel['accepted_at']),'teacher acceptance must expose its recorded date');
$teacherHtml=dzn_rda_ui_render_teacher($teacherModel);
dzn_rda_ui_assert(str_contains($teacherHtml,'Recorded:')&&str_contains($teacherHtml,'version 1'),'teacher component must show recorded date and terms version');
wp_set_current_user($unlinkedUser);$missing=$ui->teacher();$missingHtml=dzn_rda_ui_render_teacher($missing);
dzn_rda_ui_assert($missing['available']===false&&str_contains($missingHtml,'available only to an active Teacher account')&&!str_contains($missingHtml,'Accept these teacher recording terms'),'unlinked Teacher must get no acceptance form');

// No approved publication: acceptance and recording forms are unavailable, with no fabricated record.
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s",RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS));
wp_set_current_user($teacherUser);$noTerms=$ui->teacher();$noTermsHtml=dzn_rda_ui_render_teacher($noTerms);
dzn_rda_ui_assert($noTerms['available']===false&&str_contains($noTermsHtml,'Acceptance is unavailable')&&!str_contains($noTermsHtml,'Accept these teacher recording terms'),'missing teacher publication must not render acceptance');
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='published',published_at=%s WHERE audience_code=%s",gmdate('Y-m-d H:i:s'),RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS));
$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET publication_state='draft',published_at=NULL WHERE audience_code=%s",RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING));
wp_set_current_user($adultUser);$noRecording=$ui->student('enrolment','en');$noRecordingHtml=dzn_rda_ui_render_student($noRecording);
dzn_rda_ui_assert(!isset($noRecording['forms']['recording'])&&str_contains($noRecordingHtml,'A current approved recording notice is not published'),'missing recording publication must not render a recording form');
dzn_rda_ui_assert(!str_contains($noRecordingHtml,'name="purpose" value="lesson_recording"'),'missing recording publication must not write a decision');
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
foreach(array('Recording and research information','Mbox local processing','currently disabled','link pending approval','lang="fa"','lang="en"')as$text)dzn_rda_ui_assert(str_contains($infoHtml,$text),'public information route missing '.$text);
$pageId=wp_insert_post(array('post_type'=>'page','post_name'=>'recording-and-research','post_status'=>'publish','post_title'=>'Recording and research information'));
dzn_rda_ui_assert(!is_wp_error($pageId)&&$pageId>0,'fixture information page failed');
dzn_rda_ui_assert(dzn_theme_route()==='','a published editor-managed page must take over the canonical slug');

echo "Recording choices UI runtime: PASS\n";
