<?php
$p=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/ConfiguredNotificationCopy.php');
foreach(array("NotificationTemplateRepository","subject_template_digest","body_template_digest","hash_hmac('sha256'","NotificationSupport::salt()","hash_equals") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Configured notification copy integrity missing '.$n);
echo "Configured notification copy integrity source contract passed\n";