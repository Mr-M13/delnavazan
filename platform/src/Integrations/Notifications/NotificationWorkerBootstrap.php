<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Cron bootstrap for the durable notification worker. No schedule is installed unless the deployment
 * runtime is explicitly configured; every invocation rechecks readiness before touching queued work.
 */
final class NotificationWorkerBootstrap {
 public const HOOK='dzn_notification_dispatch';
 public static function register():void{
  add_filter('cron_schedules',array(__CLASS__,'schedules'));
  add_action(self::HOOK,array(__CLASS__,'run'));
  if(self::eligible()&&!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'five_minutes',self::HOOK);
 }
 public static function schedules(array $s):array{$s['five_minutes']??=array('interval'=>300,'display'=>'Every five minutes');return $s;}
 public static function run():void{
  if(!self::eligible())return;
  $worker=NotificationRuntimeFactory::worker();if($worker===null)return;
  $worker->runOnce();
 }
 private static function eligible():bool{
  if(!in_array(wp_get_environment_type(),array('staging','production'),true))return false;
  return NotificationRuntimeFactory::configured();
 }
}
