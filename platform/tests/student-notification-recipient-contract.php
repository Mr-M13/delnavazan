<?php
$p=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/ReadModel/StudentNotificationRecipientReadModel.php');
$port=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationBoundRecipientReadPort.php');
if(strpos($port,'extends NotificationRecipientReadPort')===false)throw new RuntimeException('Bound recipient port must preserve the canonical recipient contract');
foreach(array('implements NotificationBoundRecipientReadPort',"dzn_students","NotificationSupport::encryptEnvelope","contact_digest","contact_expires_at","whatsapp_eligible","sms_eligible","email_eligible") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Student recipient projection missing '.$n);
if(strpos($p,'INSERT ')!==false||strpos($p,'UPDATE ')!==false||strpos($p,'DELETE ')!==false)throw new RuntimeException('Recipient projection must remain read-only');
echo "Student notification recipient projection contract passed\n";