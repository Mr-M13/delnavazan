<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationDispatchWorker;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;
use Delnavazan\Platform\Core\Infrastructure\ReadModel\StudentNotificationRecipientReadModel;

/**
 * Deployment composition root. It is deliberately unavailable until copy and at least one transport
 * binding are explicitly supplied; an unconfigured install therefore cannot claim notification work.
 */
final class NotificationRuntimeFactory {
 public static function configured():bool{
  $copy=apply_filters('dzn_notification_copy_definitions',array());
  $bindings=apply_filters('dzn_notification_transport_bindings',array());
  return is_array($copy)&&$copy!==array()&&is_array($bindings)&&self::hasConfiguredBinding($bindings);
 }
 public static function worker():?NotificationDispatchWorker{
  if(!self::configured())return null;
  $copy=(array)apply_filters('dzn_notification_copy_definitions',array());
  $bindings=(array)apply_filters('dzn_notification_transport_bindings',array());
  $recipients=new StudentNotificationRecipientReadModel();
  $resolver=new NotificationTransportRecipientResolver($recipients);
  $transport=new CompositeNotificationTransport(new NotificationTransportRouter($bindings),new ConfiguredNotificationCopy($copy),$resolver);
  return new NotificationDispatchWorker(new NotificationDispatchService(transport:$transport,recipients:$recipients));
 }
 private static function hasConfiguredBinding(array $bindings):bool{
  foreach($bindings as $b)if(is_array($b)&&!empty($b['configured'])&&isset($b['port']))return true;
  return false;
 }
}
