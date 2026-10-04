<?php
$p=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/ReadModel/StudentNotificationRecipientReadModel.php');
foreach(array("dzn_notification_student_route","dzn_notification_student_opted_in","dzn_notification_student_guardian_authority","hash_hmac('sha256'","NotificationSupport::salt()") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Student notification authority projection missing '.$n);
foreach(array("'opted_in'=>true","'guardian_authority_present'=>true","hash('sha256'","?'mobile':'email'") as$n)if(strpos($p,$n)!==false)throw new RuntimeException('Student notification authority must not default approval or unkeyed identity');
echo "Student notification recipient authority source contract passed\n";