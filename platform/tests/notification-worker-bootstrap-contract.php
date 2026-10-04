<?php
$p=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/NotificationWorkerBootstrap.php');
foreach(array("dzn_notification_dispatch","NotificationRuntimeFactory::configured()","NotificationRuntimeFactory::worker()","wp_get_environment_type()","staging","production","wp_next_scheduled","wp_schedule_event","runOnce") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Notification worker bootstrap missing '.$n);
$posConfigured=strpos($p,'NotificationRuntimeFactory::configured()');$posSchedule=strpos($p,'wp_schedule_event');if($posConfigured===false||$posConfigured>$posSchedule)throw new RuntimeException('Configuration gate must precede scheduling');
echo "Notification worker bootstrap source contract passed\n";