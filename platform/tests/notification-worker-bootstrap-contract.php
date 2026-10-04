<?php
$p=file_get_contents(dirname(__DIR__).'/src/Integrations/Notifications/NotificationWorkerBootstrap.php');
foreach(array("dzn_notification_dispatch","NotificationRuntimeFactory::configured()","NotificationRuntimeFactory::worker()","wp_get_environment_type()","staging","production","wp_next_scheduled","wp_schedule_event","runOnce") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Notification worker bootstrap missing '.$n);
$start=strpos($p,'public static function register():void');$end=strpos($p,'public static function schedules',$start);$register=substr($p,$start,$end-$start);
if(strpos($register,'self::eligible()')===false||strpos($register,'self::eligible()')>strpos($register,'wp_schedule_event'))throw new RuntimeException('Eligibility gate must precede scheduling in register()');
echo "Notification worker bootstrap source contract passed\n";