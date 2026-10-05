<?php
$a=file_get_contents(dirname(__DIR__).'/src/Core/Application/StudentAcceptanceAuthorityService.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/StudentAccountClaimInvitationRepository.php');
$c=file_get_contents(dirname(__DIR__).'/src/Portals/StudentAccountClaimController.php');
$p=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array('establishPrincipalFromAccountClaim','claimContextForTokenForUpdate','authorityLocks','insertPrincipal','consumeGeneration','verified_internal_record','dzn_student_account_claim_authority_locks_held') as $n)if(strpos($a,$n)===false)throw new RuntimeException('Claim authority handoff missing '.$n);
foreach(array("state='claimed'","claimed_principal_id","state='active' AND active_slot=1",'Claim invitation generation changed during acceptance') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Claim consumption invariant missing '.$n);
if(strpos($a,'StudentAccountClaimValidationService')!==false)throw new RuntimeException('Final authority must revalidate under its own transaction');
if(strpos($c,"add_action('admin_post_dzn_student_account_claim'")===false||strpos($p,'StudentAccountClaimController::register()')===false)throw new RuntimeException('Atomic claim controller must be registered');
echo "Student claim authority handoff contract passed\n";
