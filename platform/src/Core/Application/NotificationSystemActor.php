<?php
namespace Delnavazan\Platform\Core\Application;
final class NotificationSystemActor {
 public const USER_OPTION='dzn_notification_dispatch_user_id';
 public const CAPABILITY='dzn_operate_notification_dispatch';
 public static function run(callable $work){
  $userId=(int)get_option(self::USER_OPTION,0);
  if($userId<1)throw new \RuntimeException('notification_system_actor_unconfigured');
  $user=get_user_by('id',$userId);
  if(!$user instanceof \WP_User||!user_can($user,self::CAPABILITY))throw new \RuntimeException('notification_system_actor_invalid');
  $previous=get_current_user_id();wp_set_current_user($userId);
  try{return $work($userId);}finally{wp_set_current_user($previous>0?$previous:0);}
 }
}
