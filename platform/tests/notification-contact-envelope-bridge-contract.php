<?php
$d=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDispatchService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/CompositeNotificationTransport.php');
foreach(array("'recipient'=>\$recipient","contact_envelope","contact_cipher_version","contact_expires_at") as$n)if(strpos($d,$n)===false)throw new RuntimeException('Dispatch envelope bridge missing '.$n);
foreach(array("NotificationSupport::decryptEnvelope","contact_expires_at","contact_unusable") as$n)if(strpos($c,$n)===false)throw new RuntimeException('Composite envelope bridge missing '.$n);
if(strpos($c,"NotificationSupport::decryptEnvelope")>strpos($c,"$this->router->route"))throw new RuntimeException('Contact must decrypt before routing');
echo "Notification contact envelope bridge contract passed\n";