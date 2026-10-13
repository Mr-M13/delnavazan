<?php
$m=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$b=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array('037_student_account_claim_invitation_authority','student_account_claim_invitations','student_account_claim_generations','student_account_claim_events','UNIQUE KEY student_id(student_id)','UNIQUE KEY invitation_generation(invitation_id,generation_number)','UNIQUE KEY invitation_active(invitation_id,active_slot)','UNIQUE KEY token_digest(token_digest)','UNIQUE KEY command_digest(command_digest)','UNIQUE KEY invitation_sequence(invitation_id,event_sequence)','command_digest','expires_at','claimed_principal_id','revoked_at','superseded_at') as $needle)if(strpos($m,$needle)===false)throw new RuntimeException('Student claim storage contract missing '.$needle);
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '(\d+)'/",$b,$schema)|| (int)$schema[1]<37)throw new RuntimeException('Schema identity must be at least 37 for student claim storage');
if(strpos($m,'verify_student_account_claim_invitation_schema();')===false)throw new RuntimeException('Student claim storage must be verified on current schema');
if(strpos($m,"foreach(array('token','secret','raw_token','plaintext_token') as \$forbidden")===false||strpos($m,'SHOW COLUMNS FROM {$generation} LIKE \'{$forbidden}\'')===false)throw new RuntimeException('Student claim secret-storage verifier missing forbidden-column sweep');
if(strpos($m,"state='claimed' AND (claimed_at IS NULL OR claimed_principal_id IS NULL)")===false)throw new RuntimeException('Claim consumption invariant missing');
if(strpos($m,"active_slot=1 AND state<>'active'")===false)throw new RuntimeException('Active invitation lifecycle invariant missing');
echo "Student claim storage contract passed\n";
