<?php
$m=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/StudentAccountClaimInvitationRepository.php');
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/StudentAccountClaimInvitationService.php');
foreach(array('dzn_issue_student_account_claims','command_digest','UNIQUE KEY command_digest(command_digest)') as $needle)if(strpos($m,$needle)===false)throw new RuntimeException('Claim issuance capability/storage contract missing '.$needle);
foreach(array('studentForUpdate','invitationForStudentForUpdate','generationForCommandForUpdate','supersedeActiveGenerations','nextEventSequence','insertEvent') as $needle)if(strpos($r,$needle)===false)throw new RuntimeException('Claim issuance repository contract missing '.$needle);
foreach(array("current_user_can('dzn_issue_student_account_claims')",'random_bytes(32)',"hash_hmac('sha256',$secret,wp_salt('auth'))",'command_digest','Student is not eligible for account claim',"'replacement_issued'","state'=>'active'","active_slot'=>1") as $needle)if(strpos($s,$needle)===false)throw new RuntimeException('Claim issuance authority contract missing '.$needle);
if(strpos($s,'student_principal_links')!==false||strpos($s,'establishPrincipal(')!==false)throw new RuntimeException('Issuance must not establish Student principal links');
if(strpos($s,"'secret'=>$secret")===false)throw new RuntimeException('Claim secret must be returned only at the issuance boundary');
if(strpos($r,'secret')!==false)throw new RuntimeException('Claim repository must not persist or expose raw secrets');
echo "Student claim invitation issuance contract passed\n";
