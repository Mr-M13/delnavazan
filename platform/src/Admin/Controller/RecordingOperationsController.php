<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Recording\RecordingControlPlane;
use Delnavazan\Platform\Core\Application\Recording\RecordingLifecycle;
use Delnavazan\Platform\Core\Application\Recording\RecordingOperationsReadService;

/**
 * Recording operations extends the consolidated Delnavazan administration.
 *
 * It offers only bounded retry, reconcile and legal-hold commands. There is deliberately no consent
 * override, no provider credential field, no deletion-path field and no browser-supplied lifecycle.
 */
final class RecordingOperationsController {
    public const MENU_SLUG='dzn-recordings';
    public const ACTION='dzn_recording_operation';

    public static function register():void{
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'command'));
    }

    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(Menu::ROOT_SLUG,'Recordings','Recordings',RecordingOperationsReadService::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }

    public static function command():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        if(!current_user_can(RecordingOperationsReadService::CAPABILITY))wp_die('Forbidden','Delnavazan',array('response'=>403));
        check_admin_referer(self::ACTION);
        $operation=sanitize_key((string)wp_unslash($_POST['operation']??''));
        $recordingId=absint($_POST['recording_id']??0);
        $commandId=absint($_POST['command_id']??0);
        $plain=(string)wp_unslash($_POST['reason']??'');
        $reason=preg_match('/^[a-z0-9_]{3,64}$/D',$plain)===1?$plain:'administrator_action';
        try{
            if(!current_user_can(RecordingOperationsReadService::RECONCILE_CAPABILITY)&&!current_user_can('manage_options'))throw new \RuntimeException('recording_reconcile_capability_required');
            $control=new RecordingControlPlane();
            if($operation==='reconcile'){$control->reconcile($recordingId,get_current_user_id());$status='reconcile_queued';}
            elseif($operation==='retry'){
                if(!current_user_can(RecordingOperationsReadService::RETRY_CAPABILITY)&&!current_user_can('manage_options'))throw new \RuntimeException('recording_retry_capability_required');
                $control->retryCommand($commandId,get_current_user_id());$status='command_requeued';
            }elseif($operation==='legal_hold'){
                if(!current_user_can(RecordingOperationsReadService::LEGAL_HOLD_CAPABILITY)&&!current_user_can('manage_options'))throw new \RuntimeException('recording_legal_hold_capability_required');
                $active=(string)wp_unslash($_POST['legal_hold']??'')==='active';
                $control->setLegalHold($recordingId,$active,get_current_user_id(),$reason);$status=$active?'legal_hold_applied':'legal_hold_released';
            }else{$status='operation_not_supported';}
        }catch(\Throwable $error){
            $message=(string)$error->getMessage();
            $status=preg_match('/^[a-z0-9_]{3,80}$/D',$message)===1?$message:'recording_operation_failed';
        }
        wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG,'dzn_recording'=>$status),admin_url('admin.php')));
        exit;
    }

    public static function render():void{
        if(!current_user_can(RecordingOperationsReadService::CAPABILITY))wp_die('Forbidden','Delnavazan',array('response'=>403));
        $read=new RecordingOperationsReadService();$counts=$read->counts();$health=$read->health();
        $state=isset($_GET['state'])&&is_scalar($_GET['state'])?sanitize_key(wp_unslash((string)$_GET['state'])):'';
        $recordings=$read->recordings(100,$state);$commands=$read->commands(100);
        $flash=sanitize_key((string)($_GET['dzn_recording']??''));
        $labels=array(
            RecordingLifecycle::AUTHORISED=>'Authorised',RecordingLifecycle::RECORDING_REQUESTED=>'Recording requested',
            RecordingLifecycle::RECORDING_ACTIVE=>'Currently recording',RecordingLifecycle::RECORDING_FINALISING=>'Finalising',
            RecordingLifecycle::RECORDING_VERIFIED=>'Recording verified',RecordingLifecycle::AWAITING_ARCHIVE=>'Awaiting transfer',
            RecordingLifecycle::TRANSFERRING=>'Transferring',RecordingLifecycle::TRANSFER_RETRY_PENDING=>'Retry pending',
            RecordingLifecycle::ARCHIVE_VERIFICATION=>'Archive verification',RecordingLifecycle::ARCHIVED=>'Archived',
            RecordingLifecycle::VPS_DELETION_PENDING=>'VPS deletion pending',RecordingLifecycle::ARCHIVED_VPS_DELETED=>'Archived · VPS deleted',
            RecordingLifecycle::FAILED=>'Unavailable',RecordingLifecycle::QUARANTINED=>'Quarantined',RecordingLifecycle::DELETION_REQUIRED=>'Deletion required',
        );
        echo '<div class="wrap dzn-recordings-page" dir="ltr">';
        echo '<h1>Recordings</h1>';
        echo '<p class="description">Canonical lesson capture, archive reconciliation and the durable transfer/deletion outbox. This page cannot create consent, override a refusal or provide a filesystem path.</p>';
        if($flash!=='')echo '<div class="notice notice-info"><p>'.esc_html(str_replace('_',' ',$flash)).'</p></div>';
        self::health($health);self::indicators($counts,$labels);
        echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>State <select name="state"><option value="">All</option>';
        foreach($labels as $key=>$label)echo '<option value="'.esc_attr($key).'"'.selected($state,$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label> ';submit_button('Filter','secondary','',false);echo '</form>';
        self::recordingsTable($recordings,$labels);self::commandsTable($commands);
        echo '</div>';
    }

    private static function health(array $health):void{
        $tone=$health['fresh']&&$health['ready']&&$health['tailscale_connected']&&$health['archive_available']?'notice-success':'notice-warning';
        echo '<div class="notice '.esc_attr($tone).'"><p><strong>Recorder/archive health.</strong> ';
        echo 'fresh '.esc_html($health['fresh']?'yes':'no').' · ready '.esc_html($health['ready']?'yes':'no');
        echo ' · Tailscale '.esc_html($health['tailscale_connected']?'connected':'unavailable');
        echo ' · M-Box '.esc_html($health['archive_available']?'available':'unavailable');
        echo ' · mount '.esc_html($health['archive_mount_identity']!==''?$health['archive_mount_identity']:'unverified');
        echo ' · VPS free '.esc_html(self::bytes($health['storage_free_bytes'])).' ('.esc_html(number_format($health['storage_free_percent'],1)).'%)';
        echo ' · M-Box free '.esc_html(self::bytes($health['archive_free_bytes']));
        echo ' · oldest queue '.esc_html(self::duration($health['oldest_queue_age_seconds']));
        echo ' · Egress CPU '.esc_html(number_format($health['egress_cpu_percent'],1)).'% · RAM '.esc_html(self::bytes($health['egress_memory_bytes'])).'.</p></div>';
    }

    private static function indicators(array $counts,array $labels):void{
        echo '<div class="dzn-recording-indicators" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:1em 0">';
        $display=array(
            RecordingLifecycle::RECORDING_ACTIVE=>'Currently recording',RecordingLifecycle::RECORDING_FINALISING=>'Finalising',
            RecordingLifecycle::AWAITING_ARCHIVE=>'Awaiting transfer',RecordingLifecycle::TRANSFERRING=>'Transferring',
            RecordingLifecycle::TRANSFER_RETRY_PENDING=>'Retry pending',RecordingLifecycle::ARCHIVE_VERIFICATION=>'Archive verification',
            RecordingLifecycle::ARCHIVED=>'Archived',RecordingLifecycle::VPS_DELETION_PENDING=>'VPS deletion pending',
            'unavailable'=>'Unavailable',RecordingLifecycle::QUARANTINED=>'Quarantined','consent_blocked'=>'Consent block (24h)',
            'storage_warning'=>'Storage warning',
        );
        foreach($display as $key=>$label)echo '<div class="card" style="padding:12px;border:1px solid #ccd0d4;background:#fff"><div style="font-size:24px;font-weight:600">'.esc_html((string)($counts[$key]??0)).'</div><div>'.esc_html($label).'</div></div>';
        echo '</div>';
    }

    private static function recordingsTable(array $recordings,array $labels):void{
        echo '<h2>Recordings</h2><table class="widefat striped"><thead><tr><th>Recording</th><th>Lesson</th><th>Participants</th><th>State</th><th>Version</th><th>Media</th><th>Location</th><th>Legal hold</th><th>Actions</th></tr></thead><tbody>';
        if(!$recordings)echo '<tr><td colspan="9">No recording records.</td></tr>';
        foreach($recordings as $row){
            $state=(string)$row['lifecycle_state'];
            echo '<tr><td><code>'.esc_html((string)$row['uid']).'</code><br><small>'.esc_html((string)$row['created_at']).'</small></td>';
            echo '<td>'.esc_html((string)($row['lesson_reference']??$row['lesson_uid']??'')).'</td>';
            echo '<td>'.esc_html(trim((string)($row['teacher_name']??'').' / '.(string)($row['student_name']??''),' /')).'</td>';
            echo '<td><strong>'.esc_html($labels[$state]??$state).'</strong></td>';
            echo '<td>'.esc_html((string)$row['content_version']).'</td>';
            echo '<td>'.esc_html(trim((string)($row['media_format']??'').' '.(string)($row['video_codec']??'').'/'.(string)($row['audio_codec']??''))).'<br><small>'.esc_html(self::bytes((int)($row['source_bytes']??0))).'</small></td>';
            echo '<td>'.esc_html((string)$row['authoritative_location']).'</td>';
            echo '<td>'.esc_html((string)$row['legal_hold_state']).'</td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="recording_id" value="'.esc_attr((string)$row['id']).'"><input type="hidden" name="operation" value="reconcile">';
            wp_nonce_field(self::ACTION);submit_button('Reconcile','secondary small','',false);echo '</form> ';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="recording_id" value="'.esc_attr((string)$row['id']).'"><input type="hidden" name="operation" value="legal_hold"><input type="hidden" name="legal_hold" value="'.((string)$row['legal_hold_state']==='active'?'none':'active').'"><input type="hidden" name="reason" value="administrator_review">';
            wp_nonce_field(self::ACTION);submit_button((string)$row['legal_hold_state']==='active'?'Release hold':'Apply hold','secondary small','',false);echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function commandsTable(array $commands):void{
        echo '<h2>Durable outbox</h2><table class="widefat striped"><thead><tr><th>Recording</th><th>Command</th><th>State</th><th>Attempts</th><th>Next attempt</th><th>Lease expiry</th><th>Last error</th><th>Actions</th></tr></thead><tbody>';
        if(!$commands)echo '<tr><td colspan="8">No commands.</td></tr>';
        foreach($commands as $row){
            echo '<tr><td><code>'.esc_html((string)$row['recording_uid']).'</code></td><td>'.esc_html((string)$row['command_type']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)$row['attempts'].'/'.(string)$row['max_attempts']).'</td><td>'.esc_html((string)$row['available_at']).'</td><td>'.esc_html((string)($row['lease_expires_at']??'')).'</td><td>'.esc_html((string)($row['last_error_code']??'')).'</td><td>';
            if(in_array((string)$row['state'],array('failed','expired','dead'),true)){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="command_id" value="'.esc_attr((string)$row['id']).'"><input type="hidden" name="operation" value="retry">';wp_nonce_field(self::ACTION);submit_button('Retry','secondary small','',false);echo '</form>';
            }else{echo '—';}
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function bytes(int $bytes):string{
        if($bytes>=1073741824)return number_format($bytes/1073741824,2).' GiB';
        if($bytes>=1048576)return number_format($bytes/1048576,1).' MiB';
        if($bytes>=1024)return number_format($bytes/1024,1).' KiB';
        return $bytes.' B';
    }

    private static function duration(int $seconds):string{
        if($seconds>=3600)return intdiv($seconds,3600).'h '.intdiv($seconds%3600,60).'m';
        if($seconds>=60)return intdiv($seconds,60).'m '.($seconds%60).'s';
        return $seconds.'s';
    }
}
