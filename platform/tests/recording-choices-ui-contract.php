<?php
/** Static contract for the disabled recording/research self-service UI. */
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('src/Portals/RecordingDecisionController.php');
$readService=$read('src/Portals/RecordingDecisionUiReadService.php');
$plugin=$read('delnavazan-platform.php');
$migrator=$read('src/Core/Infrastructure/Migration/Migrator.php');
$authority=$read('src/Core/Application/RecordingDecisionAuthorityService.php');
$bridge=$read('../theme/inc/platform-bridge.php');
$student=$read('../theme/template-parts/portal/recording-choices.php');
$teacher=$read('../theme/template-parts/teacher-portal/recording-terms.php');
$info=$read('../theme/page-templates/recording-research-information.php');
$shell=$read('../theme/template-parts/portal/shell.php');
$postIntro=$read('../theme/template-parts/portal/post-intro.php');
$onboarding=$read('../theme/template-parts/teacher-portal/onboarding.php');
$account=$read('../theme/template-parts/teacher-portal/account.php');
$fail=array();
$need=static function(bool $ok,string $message)use(&$fail):void{if(!$ok)$fail[]=$message;};

$need(str_contains($plugin,'RecordingDecisionController::register()'),'the controller must be registered by the plugin');
$need(str_contains($controller,"STUDENT_ACTION='dzn_recording_student_choice'"),'the student action must be fixed');
$need(str_contains($controller,"TEACHER_ACTION='dzn_recording_teacher_terms'"),'the teacher action must be fixed');
$need(str_contains($controller,'check_admin_referer(self::STUDENT_ACTION)'),'student submissions must verify their nonce');
$need(str_contains($controller,'check_admin_referer(self::TEACHER_ACTION)'),'teacher submissions must verify their nonce');
$need(str_contains($controller,"current_user_can('dzn_accept_own_recording_terms')"),'teacher acceptance must require its own capability');
$need(str_contains($controller,'recordOwnStudentChoice('),'student choices must use the authority service');
$need(str_contains($controller,'acceptTeacherTerms('),'teacher terms must use the authority service');
foreach(array("_POST['student_id']","_POST['teacher_id']","_POST['guardian","_POST['subject","_POST['wordpress_user_id']")as$forbidden)$need(!str_contains($controller,$forbidden),'the controller must not accept browser-supplied identity '.$forbidden);
foreach(array('purpose','choice','notice_uid','language','idempotency_key','return_to')as$field)$need(str_contains($controller,$field),'the controller must read only the action field '.$field);

$need(str_contains($readService,'studentDecisionState()'),'the UI must read the Student authority state');
$need(str_contains($readService,'teacherTermsState()'),'the UI must read the Teacher authority state');
$need(str_contains($readService,'publishedNotice('),'the UI must cite a published notice version');
$need(str_contains($readService,"'default_recording_choice'=>null"),'enrolment must not preselect a recording choice');
$need(str_contains($readService,"'default_research_choice'=>null"),'research must not preselect a choice');
$need(str_contains($readService,"'information_url'"),'the UI must link the public information page');
$need(str_contains($readService,'wp_generate_uuid4()'),'each rendered action must carry a fresh idempotency key');

$need(str_contains($student,'Recording and research choices'),'the Account Settings section title is required');
$need(str_contains($student,'Allow lesson recording'),'the allow recording label is required');
$need(str_contains($student,'Do not allow lesson recording'),'the decline recording label is required');
$need(str_contains($student,'I want to participate in research'),'the research opt-in label is required');
$need(str_contains($student,'No, thanks'),'the research decline label is required');
$need(str_contains($student,'Withdraw participation'),'the research withdrawal control is required');
$need(str_contains($student,"'account' === \$context && 'allow' === \$current_recording ? ' checked' : ''"),'only Account Settings may reflect the current recording choice');
$need(str_contains($student,"'account' === \$context && 'decline' === \$current_recording ? ' checked' : ''"),'only Account Settings may reflect the current decline');
$need(!preg_match('/name="choice" value="allow"\s+checked/',$student),'enrolment must not preselect allow');
$need(!preg_match('/name="choice" value="decline"\s+checked/',$student),'enrolment must not preselect decline');
$need(!str_contains($student,'guardian'),'no guardian form may be exposed');
$need(str_contains($student,'not legally approved yet'),'provisional copy must be visibly marked as draft');
$need(str_contains($student,'No recording decision has been saved'),'unavailable accounts must show a neutral state');

$need(str_contains($teacher,'Acceptance is unavailable'),'teacher acceptance must show an explicit unavailable state');
$need(str_contains($teacher,'version '),'teacher acceptance must show the publication version');
$need(str_contains($teacher,'English')&&str_contains($teacher,'فارسی'),'teacher acceptance must show the publication language');
$need(str_contains($teacher,'Recorded:'),'teacher acceptance must show the recorded date after acceptance');
$need(str_contains($teacher,'dzn_recording_teacher_terms'),'teacher acceptance must post to the protected endpoint');
$need(str_contains($onboarding,'recording-terms'),'teacher onboarding must render the terms step');
$need(str_contains($account,'recording-terms'),'teacher settings must show the accepted terms state');

$need(str_contains($shell,"'recording-choices'"),'Account Settings must render the student choices');
$need(str_contains($shell,"'recording' => \$model['recording_choices']"),'online enrolment must pass the choices to the post-intro section');
$need(str_contains($postIntro,"dzn_theme_portal_component( 'recording-choices'"),'the enrolment point must render the notice');
$need(str_contains($bridge,'RecordingDecisionUiReadService'),'the theme must use the platform read projection');

foreach(array('What future lesson recording may involve','Why Delnavazan collects data','Separate optional research','How choices can be changed','Mbox local processing','currently disabled','Project information page')as$text)$need(str_contains($info,$text),'the public information page must explain '.$text);
$need(str_contains($info,'lang="fa"')&&str_contains($info,'lang="en"'),'the information page must be bilingual');
$need(str_contains($info,'link pending approval'),'the information page must include a project-link placeholder');
$need(!str_contains($info,'legal basis is'),'the information page must not hard-code an unapproved legal basis');
$need(!str_contains($info,'retention period of'),'the information page must not hard-code an unapproved retention period');
$need(!str_contains($info,'provider guarantee'),'the information page must not hard-code an unapproved provider guarantee');
$need(!str_contains($info,'consent'),'the disabled default-on path must not be described as consent');

$need(str_contains($migrator,"'dzn_accept_own_recording_terms'"),'the Teacher self-service capability must be installed');
$need(str_contains($authority,'CAPTURE_ENABLED=false')&&str_contains($authority,'RESEARCH_EXPORT_ENABLED=false'),'capture and research export must remain disabled');
$need(!str_contains($controller,'ContractRecordingProvider')&&!str_contains($controller,'recording_provider'),'no recording provider may be added');
$need(!str_contains($readService,'wp_remote_')&&!str_contains($readService,'curl_'),'the UI read service must not transport media');
echo $fail?'Recording choices UI contract failed:\n- '.implode("\n- ",$fail)."\n":'Recording choices UI contract passed'."\n";
if($fail)exit(1);
