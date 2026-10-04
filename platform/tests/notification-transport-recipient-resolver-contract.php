<?php
$p=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/NotificationTransportRecipientResolver.php');
foreach(array("notification_key_digest","byKeyDigest","recipient_digest","contact_expires_at","NotificationSupport::decryptEnvelope") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Transport recipient resolver missing '.$n);
$port=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php');
if(strpos($port,'contact_envelope')!==false)throw new RuntimeException('Strict transport port must remain contact-free');
echo "Notification transport recipient resolver contract passed\n";