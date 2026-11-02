<?php
namespace Delnavazan\Platform\Admin\Controller;
use Delnavazan\Platform\Core\Application\NotificationRule;

/**
 * Protected legacy communications-health route.
 *
 * Detailed queue, provider and delivery evidence now lives in Settings → Diagnostics. The old
 * bookmark stays resolvable, but this page no longer duplicates the diagnostics tables.
 */
final class NotificationDiagnosticsController {
 public static function screen():void{
  if(!current_user_can(NotificationRule::READ_CAPABILITY))wp_die('Forbidden',403);
  echo '<div class="wrap dzn-settings-page"><h1>Communications diagnostics</h1>';
  echo '<p>Delivery traces, queue counts and provider evidence have moved to Settings → Diagnostics. This protected legacy route remains available for bookmarks.</p>';
  echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=notifications')).'">Open Diagnostics → Notifications</a> ';
  echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-notifications')).'">Open Notifications events</a></p></div>';
 }
}
