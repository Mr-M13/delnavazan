<?php
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/StudentAccountClaimInvitationRepository.php');
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/StudentAccountClaimValidationService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Portals/StudentAccountClaimController.php');
foreach(array('claimContextForTokenForUpdate','activePrincipalForStudentForUpdate','activePrincipalForUserForUpdate','wordpressUserForUpdate') as $needle)if(strpos($r,$needle)===false)throw new RuntimeException('Claim validation repository contract missing '.$needle);
foreach(array('get_current_user_id()!==$wordpressUserId','is_user_logged_in()',"/^[a-f0-9]{64}$/D","hash_hmac('sha256',$secret,wp_salt('auth'))","expires_at<=$now","invitation_state!==\'active\'","activePrincipalForStudentForUpdate","activePrincipalForUserForUpdate") as $needle)if(strpos($s,$needle)===false)throw new RuntimeException('Claim validation contract missing '.$needle);
if(strpos($s,'insertPrincipal')!==false||strpos($s,'student_principal_links SET')!==false)throw new RuntimeException('Validation must not write canonical principal links');
if(strpos($c,'register()')!==false||strpos($c,'add_action(')!==false)throw new RuntimeException('Controller must remain unregistered before atomic authority handoff');
echo "Student claim validation contract passed\n";
