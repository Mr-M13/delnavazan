<?php
$p=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/NotificationRuntimeFactory.php');
foreach(array("dzn_notification_copy_definitions","dzn_notification_transport_bindings","if(!self::configured())return null","StudentNotificationRecipientReadModel","NotificationTransportRecipientResolver","CompositeNotificationTransport","NotificationDispatchWorker") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Runtime factory missing '.$n);
if(strpos($p,'wp_schedule_event')!==false)throw new RuntimeException('Factory must not schedule itself');
echo "Notification runtime factory source contract passed\n";