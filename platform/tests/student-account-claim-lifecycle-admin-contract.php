<?php
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/StudentAccountClaimInvitationRepository.php');
$c=file_get_contents(dirname(__DIR__).'/src/Admin/Controller/StudentIdentityAuthorityController.php');
foreach(array('lifecycleRows','student_account_claim_invitations','generation_state','replacement_reason_code') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Claim lifecycle read contract missing '.$n);
foreach(array('Student account claim invitations','Lifecycle facts only','lifecycleRows') as $n)if(strpos($c,$n)===false)throw new RuntimeException('Claim lifecycle admin presentation missing '.$n);
$projection=substr($r,strpos($r,'public function lifecycleRows'),strpos($r,'public function begin')-strpos($r,'public function lifecycleRows'));if(strpos($projection,'token_digest')!==false||strpos($projection,'command_digest')!==false)throw new RuntimeException('Admin lifecycle projection must not expose digests');
echo "Student claim lifecycle admin contract passed\n";
