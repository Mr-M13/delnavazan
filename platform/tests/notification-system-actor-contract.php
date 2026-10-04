<?php
$p=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationSystemActor.php');
foreach(array("dzn_notification_dispatch_user_id","dzn_operate_notification_dispatch","user_can","wp_set_current_user","finally") as $needle)if(strpos($p,$needle)===false)throw new RuntimeException('System actor contract missing '.$needle);
if(strpos($p,"current_user_can")!==false)throw new RuntimeException('System actor must validate configured user explicitly');
echo "Notification system actor source contract passed\n";