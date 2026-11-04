<?php
/**
 * Disposable WordPress/MariaDB behavioural proof for the consent lifecycle.
 *
 * Proves independent purposes, guardian authority, fail-closed recording and dataset gates,
 * withdrawal propagation, policy supersession, idempotency and truthful worker outcomes.
 */
if(getenv('DZN_CONSENT_BEHAVIOR_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Consent behavior runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\ConsentLifecycleWorker;
use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\MlDatasetEligibilityService;
use Delnavazan\Platform\Core\Application\RecordingAuthorisationGate;
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Application\RecordingNoticePublicationService;
use Delnavazan\Platform\Core\Application\ConsentWithdrawalService;
use Delnavazan\Platform\Core\Support\Identifier;
global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
function dzn_cl_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_cl_user(string $label):int{$id=wp_insert_user(array('user_login'=>'dzn-cl-'.$label.'-'.substr(Identifier::uid(),0,8),'user_pass'=>wp_generate_password(24),'user_email'=>'dzn-cl-'.$label.'-'.substr(Identifier::uid(),0,8).'@example.invalid','role'=>'subscriber'));if(is_wp_error($id)||(int)$id<1)throw new RuntimeException('fixture user failed');return(int)$id;}
function dzn_cl_teacher(string $name):int{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'teachers',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('teacher fixture');return(int)$wpdb->insert_id;}
function dzn_cl_student(string $name,string $capacity):int{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>$name,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('student fixture');$id=(int)$wpdb->insert_id;if($wpdb->insert($p.'student_acceptance_capacity_classifications',array('uid'=>Identifier::uid(),'student_id'=>$id,'classification'=>$capacity,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'consent-lifecycle','evidence_at'=>$now,'classified_at'=>$now,'classified_by'=>1,'created_at'=>$now,'created_by'=>1))===false)throw new RuntimeException('capacity fixture');$cid=(int)$wpdb->insert_id;if($wpdb->update($p.'students',array('current_acceptance_capacity_classification_id'=>$cid),array('id'=>$id))===false)throw new RuntimeException('capacity projection');return$id;}
function dzn_cl_link_teacher(int $teacher,int $user):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$teacher,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1))===false)throw new RuntimeException('teacher link');}
function dzn_cl_link_student(int $student,int $user):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'student_principal_links',array('student_id'=>$student,'wordpress_user_id'=>$user,'status'=>'active','linked_at'=>$now,'linked_by'=>1,'link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'consent-lifecycle','evidence_at'=>$now,'updated_at'=>$now,'updated_by'=>1))===false)throw new RuntimeException('student link');}
function dzn_cl_guardian(int $student,int $user):int{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'student_acceptance_authority_grants',array('uid'=>Identifier::uid(),'student_id'=>$student,'acting_wordpress_user_id'=>$user,'authority_type'=>'guardian_representative','authority_scope'=>'service_acceptance','state'=>'active','active_slot'=>1,'effective_from'=>$now,'effective_until'=>null,'verification_basis'=>'synthetic_runtime_fixture','evidence_channel'=>'runtime_fixture','evidence_reference'=>'consent-lifecycle','evidence_at'=>$now,'version'=>1,'granted_at'=>$now,'granted_by'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('guardian fixture');return(int)$wpdb->insert_id;}
function dzn_cl_lesson(int $teacher,int $student):int{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');if($wpdb->insert($p.'lessons',array('uid'=>Identifier::uid(),'student_id'=>$student,'teacher_id'=>$teacher,'course_id'=>1,'lesson_type'=>'standard','status'=>'scheduled','record_model'=>'canonical_term_lesson_v1','lifecycle_state'=>'authorised','current_schedule_version_id'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))===false)throw new RuntimeException('lesson fixture '.$wpdb->last_error);return(int)$wpdb->insert_id;}
function dzn_cl_item(int $lesson,string $source,string $digest):array{return array('source_recording_id'=>$source,'lesson_id'=>$lesson,'content_digest'=>$digest);}
dzn_cl_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='57','behavior runtime requires the current package schema');
$noticeService=new RecordingNoticePublicationService();
$notices=array();
foreach(array(
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS=>'Teacher recorded-work terms runtime notice',
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING=>'Teacher lesson recording runtime notice',
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH=>'Teacher research runtime notice',
    RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING=>'Student lesson recording runtime notice v1',
    RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH=>'Student research runtime notice v1',
)as$audience=>$body)$notices[$audience]=$noticeService->publish($audience,'en',$body,1);
$authority=new RecordingDecisionAuthorityService();$gate=new RecordingAuthorisationGate();$datasetService=new MlDatasetEligibilityService();$withdrawalService=new ConsentWithdrawalService();

$teacherUser=dzn_cl_user('teacher');$teacher=dzn_cl_teacher('Consent Teacher');dzn_cl_link_teacher($teacher,$teacherUser);
wp_set_current_user($teacherUser);
$terms=$authority->acceptTeacherTerms($notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS]['uid'],'en','cl-teacher-terms-0001');
$recordingConsent=$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RECORDING,'allow',$notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING]['uid'],'en','cl-teacher-recording-0001');
$researchConsent=$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH]['uid'],'en','cl-teacher-research-0001');
$replay=$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RECORDING,'allow',$notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING]['uid'],'en','cl-teacher-recording-0001');
dzn_cl_assert($replay['idempotent']===true&&$replay['decision_id']===$recordingConsent['decision_id'],'duplicate teacher decision must replay, not append');
$teacherState=$authority->teacherConsentState();dzn_cl_assert($teacherState['terms']['positive']&&$teacherState['recording']['positive']&&$teacherState['research']['positive'],'teacher purposes must be independently positive');

$adultUser=dzn_cl_user('adult');$adult=dzn_cl_student('Adult Consent Student','adult');dzn_cl_link_student($adult,$adultUser);
wp_set_current_user($adultUser);
$studentRecording=$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RECORDING,'allow',$notices[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING]['uid'],'en','cl-student-recording-0001');
$studentResearch=$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'],'en','cl-student-research-0001');
$state=$authority->studentDecisionState();dzn_cl_assert($state['authority']==='student'&&$state['capture_eligible']&&$state['research']['positive'],'adult decisions must be independent and capture eligible');

$lesson=dzn_cl_lesson($teacher,$adult);
$authorised=$gate->authoriseLesson($lesson);
dzn_cl_assert($authorised['authorised']===true&&$authorised['reason']==='authorised','complete participant consent must authorise recording');
$deniedProvider=false;try{$gate->assertProviderSupported(RecordingAuthorisationGate::PROVIDER_GOOGLE_MEET);}catch(RuntimeException $e){$deniedProvider=$e->getMessage()==='recording_provider_not_consent_enforced';}
dzn_cl_assert($deniedProvider,'Google Meet recording must be refused while it cannot enforce consent');

$source='recording-fixture-'.$lesson;$digest=hash('sha256','recording content '.$lesson);
$ingest=$datasetService->assertEligible(dzn_cl_item($lesson,$source,$digest),'persian_music_research','v1','storage://fixture/'.$lesson,1,'source_ingest');
dzn_cl_assert($ingest['eligible']===true,'recording and separate research opt-ins must make the item eligible');
wp_set_current_user(1);$manifest=$datasetService->exportManifest('persian_music_research');dzn_cl_assert(count($manifest)===1&&$manifest[0]['source_recording_id']===$source,'eligible manifest must export the item');wp_set_current_user($adultUser);
dzn_cl_assert(!isset($manifest[0]['student_id'])&&!isset($manifest[0]['teacher_id']),'manifest export must be pseudonymised');

// Withdrawing student research must stop future eligibility and quarantine the existing item.
$withdraw=$withdrawalService->withdrawStudentResearch($notices[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'],'en','cl-student-research-withdraw-01');
dzn_cl_assert($withdraw['state']==='withdraw','student research withdrawal must append a decision');
$item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_dataset_items WHERE source_recording_id=%s",$source));
dzn_cl_assert($item!==null&&in_array((string)$item->exclusion_state,array('quarantine_pending','quarantined'),true),'withdrawal must quarantine the affected dataset item');
$jobsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_processing_jobs WHERE job_type='dataset_exclusion'");
dzn_cl_assert($jobsBefore>=1,'withdrawal must create a durable exclusion job');
$workerResult=(new ConsentLifecycleWorker())->run(20);dzn_cl_assert($workerResult['completed']>=1,'worker must complete the exclusion job');
$item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_dataset_items WHERE id=%d",(int)$item->id));
dzn_cl_assert((string)$item->exclusion_state==='excluded','worker must exclude the dataset item');
wp_set_current_user(1);$manifest=$datasetService->exportManifest('persian_music_research');dzn_cl_assert($manifest===array(),'withdrawn material must not export');
$refused=false;try{$datasetService->assertEligible(dzn_cl_item($lesson,$source.'b',hash('sha256','second')),'persian_music_research','v1','storage://fixture/second',1);}catch(RuntimeException $e){$refused=str_starts_with($e->getMessage(),'ml_dataset_ingestion_refused:student_research_');}
dzn_cl_assert($refused,'dataset ingestion without current student research consent must be refused');

// A newer published recording notice supersedes the older decision until the student decides again.
$nextRecording=$noticeService->publish(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,'en','Student lesson recording runtime notice v2',1);
wp_set_current_user($adultUser);
$stale=$gate->authoriseLesson($lesson);dzn_cl_assert($stale['authorised']===false&&$stale['reason']==='student_policy_superseded','a newer policy must invalidate the older recording decision');
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RECORDING,'allow',$nextRecording['uid'],'en','cl-student-recording-v2-0001');
$current=$gate->authoriseLesson($lesson);dzn_cl_assert($current['authorised']===true,'a current explicit decision must restore authorisation');

// Teacher research withdrawal independently excludes material even when student consent remains.
wp_set_current_user($teacherUser);
$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH]['uid'],'en','cl-teacher-research-reoptin-01');
wp_set_current_user($adultUser);
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'],'en','cl-student-research-reoptin-01');
wp_set_current_user($adultUser);$reItem=$datasetService->assertEligible(dzn_cl_item($lesson,$source.'c',hash('sha256','third')),'persian_music_research','v1','storage://fixture/third',1);
dzn_cl_assert($reItem['eligible']===true,'fresh opt-ins must allow a fresh eligible item');
wp_set_current_user($teacherUser);
$withdrawalService->withdrawTeacherResearch($notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH]['uid'],'en','cl-teacher-research-withdraw-01');
$teacherItem=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_dataset_items WHERE source_recording_id=%s",$source.'c'));
dzn_cl_assert($teacherItem!==null&&in_array((string)$teacherItem->exclusion_state,array('quarantine_pending','quarantined'),true),'teacher research withdrawal must quarantine teacher-dependent material');
(new ConsentLifecycleWorker())->run(20);
$teacherItem=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_dataset_items WHERE id=%d",(int)$teacherItem->id));
dzn_cl_assert((string)$teacherItem->exclusion_state==='excluded','teacher research withdrawal must complete exclusion');

// Minor students act only through an active guardian grant.
$guardianUser=dzn_cl_user('guardian');$minor=dzn_cl_student('Minor Consent Student','minor');$grant=dzn_cl_guardian($minor,$guardianUser);
wp_set_current_user($guardianUser);
$guardianState=$authority->studentDecisionState();dzn_cl_assert($guardianState['authority']==='guardian'&&(int)$guardianState['student_id']===$minor,'guardian must resolve the minor server-side');
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RECORDING,'allow',$nextRecording['uid'],'en','cl-guardian-recording-0001');
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'],'en','cl-guardian-research-0001');
$minorLesson=dzn_cl_lesson($teacher,$minor);
// Teacher research was withdrawn above; re-opt-in so the minor path can be tested independently.
wp_set_current_user($teacherUser);$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RESEARCH,'opt_in',$notices[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH]['uid'],'en','cl-teacher-research-final-01');
$minorGate=$gate->authoriseLesson($minorLesson);dzn_cl_assert($minorGate['authorised']===true&&$minorGate['guardian_decision_id']>0,'guardian recording consent must authorise a minor lesson');
$minorItem=$datasetService->assertEligible(dzn_cl_item($minorLesson,'recording-fixture-minor',hash('sha256','minor content')),'persian_music_research','v1','storage://fixture/minor',1);
dzn_cl_assert($minorItem['eligible']===true,'guardian research consent must make a minor item eligible');
// Revoking guardian authority must make the minor ineligible for new processing.
$wpdb->update($p.'student_acceptance_authority_grants',array('state'=>'revoked','active_slot'=>null,'revoked_at'=>$now,'revoked_by'=>1,'reason_code'=>'synthetic_revocation','updated_at'=>$now,'updated_by'=>1),array('id'=>$grant));
$minorStale=$gate->authoriseLesson($minorLesson);dzn_cl_assert($minorStale['authorised']===false&&in_array($minorStale['reason'],array('guardian_authority_missing','guardian_recording_missing'),true),'revoked guardian authority must deny the minor gate');

// Administrative counts and evidence remain authoritative.
wp_set_current_user(1);
$counts=(new Delnavazan\Platform\Core\Application\ConsentOperationsReadService())->counts();
dzn_cl_assert((int)$counts['recording_authorised_lessons']>=1,'authorised lesson count must reflect evidence');
dzn_cl_assert((int)$counts['pending_withdrawals']>=0&&(int)$counts['failed_jobs']>=0,'processing counts must be readable');
$evidence=(new Delnavazan\Platform\Core\Application\ConsentOperationsReadService())->evidence('student',$adult,50);
dzn_cl_assert(count($evidence)>=2,'append-only evidence must remain readable for the adult student');
$_GET=array();ob_start();Delnavazan\Platform\Admin\Controller\PoliciesController::render();$adminHtml=(string)ob_get_clean();
foreach(array('Consent indicators','Policy library','Consent operations','Withdrawal processing','Consent evidence','Recording-authorised lessons','ML-eligible recordings')as$text)dzn_cl_assert(str_contains($adminHtml,$text),'Settings Policies missing '.$text);
dzn_cl_assert(!str_contains($adminHtml,'name="decision_code"'),'Policies must not expose a form that writes a decision');
echo "Consent lifecycle behavior runtime: PASS\n";
