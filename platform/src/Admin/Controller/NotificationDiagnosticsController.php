<?php
namespace Delnavazan\Platform\Admin\Controller;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
final class NotificationDiagnosticsController {
 public static function screen():void{
  if(!current_user_can('dzn_view_notification_authority'))wp_die('Forbidden',403);
  $r=(new NotificationDiagnosticsReadService())->report();
  echo '<div class="wrap"><h1>Communications diagnostics</h1><p>Read-only canonical notification health.</p><table class="widefat striped"><tbody>';
  foreach(array('orchestration_backlog'=>'Due backlog','stuck_lease'=>'Stuck leases','expired_lease'=>'Expired leases','orphan_outbox_row'=>'Orphan rows','retry_exhausted'=>'Retry exhausted','retry_window_exhausted'=>'Retry window exhausted','unregistered_intent'=>'Unregistered intents','unroutable_intent'=>'Intents without active workflow') as$k=>$label)echo '<tr><th>'.esc_html($label).'</th><td>'.(int)($r[$k]??0).'</td></tr>';
  echo '<tr><th>Generated UTC</th><td>'.esc_html((string)($r['generated_at']??'')).'</td></tr></tbody></table><h2>Notification states</h2><table class="widefat striped"><tbody>';
  foreach((array)($r['counts']??array()) as$state=>$count)echo '<tr><td>'.esc_html((string)$state).'</td><td>'.(int)$count.'</td></tr>';
  echo '</tbody></table><h2>Intents missing active workflow</h2>';
  $i=(array)($r['unroutable_intents']??array());echo $i?'<ul><li>'.implode('</li><li>',array_map('esc_html',$i)).'</li></ul>':'<p>None.</p>';echo '</div>';
 }
}
