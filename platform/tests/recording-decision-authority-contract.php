<?php
/** Static guard for the disabled Schema 55 recording/research decision authority. */
$root=dirname(__DIR__);
$m=(string)file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$s=(string)file_get_contents($root.'/src/Core/Application/RecordingDecisionAuthorityService.php');
$p=(string)file_get_contents($root.'/delnavazan-platform.php');
$fail=array();
foreach(array("'55'",'055_recording_decision_authority','recording_notice_versions','recording_decisions','recording_decision_commands','expires_at','withdrawn_at','verify_recording_decision_authority_schema')as$x)if(!str_contains($m.$p,$x))$fail[]='missing migration authority '.$x;
if(str_contains($m,'053_recording_consent_retention_deletion_authority'))$fail[]='Schema 053 must not be reused for recording decisions';
foreach(array('acceptTeacherTerms','recordOwnStudentChoice','recordGuardianChoiceForResolvedStudent','recordApprovedOptOutBasisForResolvedStudent','teacherTermsState','studentDecisionState','publishedNotice','capture_eligible','recording_default_on_policy_unavailable','recording_decision_replay_conflict','recording_notice_unavailable','recording_teacher_principal_required','recording_teacher_principal_ambiguous','recording_student_principal_required','recording_student_principal_ambiguous','recording_guardian_scope_unavailable','recording_student_capacity_unavailable','EVIDENCE_KEY_VERSION','research_model_training','lesson_recording','CAPTURE_ENABLED','RESEARCH_EXPORT_ENABLED')as$x)if(!str_contains($s,$x))$fail[]='missing decision safeguard '.$x;
foreach(array('CAPTURE_ENABLED=false','RESEARCH_EXPORT_ENABLED=false')as$x)if(!str_contains($s,$x))$fail[]='capture and research export must be disabled '.$x;
foreach(array('$_POST','$_REQUEST','$_GET','wp_remote_','ContractRecordingProvider','recording_provider','dataset_export','model_training_job')as$x)if(str_contains($s,$x))$fail[]='decision authority must not own capture, transport, or browser-supplied identity '.$x;
if(str_contains($s,'consent'))$fail[]='the disabled default-on path must not be described as consent';
if($fail)throw new RuntimeException("Recording decision authority contract failed:\n- ".implode("\n- ",$fail));
echo "Recording decision authority contract passed\n";
