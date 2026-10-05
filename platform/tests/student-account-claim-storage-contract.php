<?php
$m=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$b=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array('037_student_account_claim_invitation_authority','student_account_claim_invitations','student_account_claim_generations','student_account_claim_events','UNIQUE KEY student_id(student_id)','UNIQUE KEY invitation_generation(invitation_id,generation_number)','UNIQUE KEY invitation_active(invitation_id,active_slot)','UNIQUE KEY token_digest(token_digest)','UNIQUE KEY invitation_sequence(invitation_id,event_sequence)','expires_at','claimed_principal_id','revoked_at','superseded_at') as $needle)if(strpos($m,$needle)===false)throw new RuntimeException('Student claim storage contract missing '.$needle);
if(strpos($b,"DZN_PLATFORM_SCHEMA_VERSION', '37'")===false)throw new RuntimeException('Schema identity not advanced for student claim storage');
if(strpos($m,'verify_student_account_claim_invitation_schema();')===false)throw new RuntimeException('Student claim storage must be verified on current schema');
foreach(array('SHOW COLUMNS FROM {$generation} LIKE \'token\'','SHOW COLUMNS FROM {$generation} LIKE \'secret\'','SHOW COLUMNS FROM {$generation} LIKE \'raw_token\'','SHOW COLUMNS FROM {$generation} LIKE \'plaintext_token\'') as $needle)if(strpos($m,$needle)===false)throw new RuntimeException('Student claim secret-storage verifier missing '.$needle);
if(strpos($m,"state='claimed' AND (claimed_at IS NULL OR claimed_principal_id IS NULL)")===false)throw new RuntimeException('Claim consumption invariant missing');
if(strpos($m,"active_slot=1 AND state<>'active'")===false)throw new RuntimeException('Active invitation lifecycle invariant missing');
echo "Student claim storage contract passed\n";
