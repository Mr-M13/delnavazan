<?php
$root=dirname(__DIR__,2);
$fixture=file_get_contents($root.'/acceptance/fixtures/student-account-claim.php');
$runner=file_get_contents($root.'/acceptance/bin/run-student-account-claim-acceptance.sh');
$make=file_get_contents($root.'/acceptance/Makefile');
foreach(array('StudentAccountClaimInvitationService','StudentAccountClaimValidationService','establishPrincipalFromAccountClaim','NotificationService','NotificationTemplateService','NotificationWorkflowService',"'variable_codes' => array( 'claim_code' )",'STUDENT_ACCOUNT_CLAIM_ISSUED','consent_absent','account_claim_expired','does not match account claim recipient','pre_wp_mail','$mailCalls === 0','$mailCalls === 1',"'transport-intercept@example.invalid'",'PrincipalInvitationService','TeacherOnboardingService','BookingRequestSubmissionService','FinalAcceptanceService','EnrolmentConversionService','TeacherAssignmentService','assigned_teacher_id') as $needle)if(!str_contains($fixture,$needle))throw new RuntimeException('Student claim acceptance fixture missing '.$needle);
foreach(array('run-student-account-claim-acceptance.sh','student-account-claim.php','trap cleanup EXIT','destroy.sh" YES') as $needle)if(!str_contains($runner.$make,$needle))throw new RuntimeException('Student claim acceptance runner missing '.$needle);
if(substr_count($fixture,'wp_mail(')!==1||str_contains($fixture,'NotificationDispatchWorker')||str_contains($fixture,'handOff('))throw new RuntimeException('Student claim acceptance fixture may only call the one synthetic, pre_wp_mail-intercepted transport probe');
echo "Student account claim acceptance harness contract passed\n";
