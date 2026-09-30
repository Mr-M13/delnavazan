<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Portals\{PortalCapabilityService,PortalDiagnosticsService,PortalRule};

final class PortalCapabilityController {
    public const CAPABILITY='dzn_view_portal_capabilities';
    public const MENU_SLUG='dzn-portal-capabilities';
    public static function register():void { add_action('admin_menu',array(__CLASS__,'menu')); add_action('admin_post_dzn_portal_capability_command',array(__CLASS__,'command')); }
    public static function menu():void { if(function_exists('add_submenu_page'))add_submenu_page('dzn-platform','Portal capabilities','Portal capabilities',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render')); }

    public static function command():void {
        if(!current_user_can('dzn_manage_portal_capabilities'))wp_die(esc_html__('Unauthorized','delnavazan-platform'),'',array('response'=>403));
        check_admin_referer('dzn_portal_capability_command'); $operation=sanitize_key((string)($_POST['operation']??'')); $key=sanitize_text_field((string)($_POST['command_key']??'')); $service=new PortalCapabilityService();
        $lesson=(int)($_POST['lesson_id']??0);$schedule=(int)($_POST['schedule_version_id']??0);$purpose=sanitize_key((string)($_POST['purpose']??''));$expires=(int)(strtotime((string)($_POST['expires_at']??''))?:0);$target=esc_url_raw((string)($_POST['join_target']??''));$student=!empty($_POST['student_id'])?(int)$_POST['student_id']:null;$reasonInput=sanitize_key((string)($_POST['reason']??''));
        $payload=$operation==='mint'?wp_json_encode(array($lesson,$schedule,$purpose,$expires,$purpose===PortalRule::ABSENCE?'https://meet.google.com/':$target,$student)):($operation==='rotate'?wp_json_encode(array($lesson,$schedule,$purpose,$expires,$purpose===PortalRule::ABSENCE?'https://meet.google.com/':$target,$reasonInput)):($operation==='revoke'?wp_json_encode(array((int)($_POST['capability_id']??0),$reasonInput,'revoked')):wp_json_encode(array('operation'=>$operation))));
        try {
            if($key==='')throw new \InvalidArgumentException('portal_confirmation_required');
            if($operation==='mint'){$result=$service->mint($lesson,$schedule,$purpose,$expires,$target,$student,true,$key);self::result(!empty($result['replayed'])?'Mint command replayed; the original plaintext link is not recoverable.':'Capability minted. The plaintext link is shown once below.',(string)($result['url']??''));return;}
            if($operation==='rotate'){$result=$service->rotate($lesson,$schedule,$purpose,$expires,$target,$reasonInput,$key);self::result(!empty($result['replayed'])?'Rotate command replayed; the original plaintext link is not recoverable.':'Capability rotated. The plaintext link is shown once below.',(string)($result['url']??''));return;}
            if($operation==='revoke'){$service->revoke((int)($_POST['capability_id']??0),$reasonInput,$key);self::result('Capability revoked.');return;}
            throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed');
        } catch(\Throwable $e) {
            /*
             * §15.6 — only a declared business refusal writes refusal evidence.  A persistence, corruption
             * or infrastructure failure (`portal_capability_persistence_failed`, an unexpected `\Throwable`)
             * has already rolled its own transaction back, so it is re-raised unchanged: a transient failed
             * insert or commit can never be followed by a durable refusal command record.
             */
            $reason=PortalRule::refusalReason($e); if($reason===null)throw $e;
            $recordLesson=$lesson;$recordPurpose=$purpose;
            if($operation==='revoke'&&(int)($_POST['capability_id']??0)>0){global $wpdb;$capability=$wpdb->get_row($wpdb->prepare("SELECT lesson_id,purpose FROM {$wpdb->prefix}dzn_portal_public_capabilities WHERE id=%d",(int)$_POST['capability_id']));if($capability){$recordLesson=(int)$capability->lesson_id;$recordPurpose=(string)$capability->purpose;}}
            try{$reason=$service->recordRefusal($operation===''?'unknown':$operation,$key,$payload,(string)$reason,$recordLesson,$recordPurpose);}catch(\InvalidArgumentException$replay){$reason=$replay->getMessage()==='command_replay_conflict'?'command_replay_conflict':'portal_upstream_aggregate_invalid';}
            self::result('Command refused: '.$reason);
        }
    }

    private static function result(string $message,string $url=''):void { echo '<div class="wrap"><h1>Portal capabilities</h1><p>'.esc_html($message).'</p>';if($url!=='')echo '<p><a href="'.esc_url($url).'">'.esc_html($url).'</a></p>';echo '<p><a href="'.esc_url(admin_url('admin.php?page='.self::MENU_SLUG)).'">Back</a></p></div>';exit; }
    private static function field(string $name,string $label,string $type='text',bool $required=false):void { $value=$name==='command_key'?wp_generate_uuid4():'';echo '<label>'.esc_html($label).' <input name="'.esc_attr($name).'" type="'.esc_attr($type).'"'.($value!==''?' value="'.esc_attr($value).'"':'').($required?' required':'').' /></label> '; }
    private static function form(string $operation,string $label):void {
        echo '<h2>'.esc_html($label).'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_portal_capability_command"><input type="hidden" name="operation" value="'.esc_attr($operation).'">';wp_nonce_field('dzn_portal_capability_command');
        if($operation==='revoke'){self::field('capability_id','Capability ID','number',true);echo '<label>Reason <select name="reason" required>';foreach(PortalRule::OPERATOR_REASON_CODES as $reason)echo '<option value="'.esc_attr($reason).'">'.esc_html($reason).'</option>';echo '</select></label> ';}
        else {self::field('lesson_id','Lesson ID','number',true);self::field('schedule_version_id','Schedule version ID','number',true);echo '<label>Purpose <select name="purpose" required><option value="lesson_join">lesson_join</option><option value="lesson_absence">lesson_absence</option></select></label> ';self::field('expires_at','Expires at','datetime-local',true);self::field('join_target','Safe join target','url',false);self::field('student_id','Student ID (join scope optional)','number',false);if($operation==='rotate'){echo '<label>Reason <select name="reason" required>';foreach(PortalRule::OPERATOR_REASON_CODES as $reason)echo '<option value="'.esc_attr($reason).'">'.esc_html($reason).'</option>';echo '</select></label> ';}}
        self::field('command_key','Stable command key','text',true);echo '<button class="button button-primary">'.esc_html($label).'</button></form>';
    }

    public static function render():void {
        if(!current_user_can(self::CAPABILITY))return; global $wpdb; $table=$wpdb->prefix.'dzn_portal_public_capabilities';
        $rows=$wpdb->get_results("SELECT c.id,c.lesson_id,c.purpose,c.generation,c.state,c.expires_at,c.revocation_reason_code,(SELECT COUNT(*) FROM {$wpdb->prefix}dzn_portal_public_action_events a WHERE a.capability_id=c.id) redemption_count,(SELECT a2.action_state FROM {$wpdb->prefix}dzn_portal_public_action_events a2 WHERE a2.capability_id=c.id ORDER BY a2.id DESC LIMIT 1) last_outcome FROM {$table} c ORDER BY c.id DESC LIMIT 100"); $diagnostics=(new PortalDiagnosticsService())->summary();
        echo '<div class="wrap"><h1>Portal capabilities</h1><p>Bounded capability state, redemption outcomes and durable refusals. Public actions remain disabled by default.</p>';
        if(current_user_can('dzn_manage_portal_capabilities')){self::form('mint','Mint');self::form('rotate','Rotate');self::form('revoke','Revoke');}
        echo '<table class="widefat"><thead><tr><th>ID</th><th>Lesson</th><th>Purpose</th><th>Generation</th><th>State</th><th>Expiry</th><th>Redemptions</th><th>Last outcome</th><th>Reason</th></tr></thead><tbody>';
        foreach((array)$rows as $row)echo '<tr><td>'.esc_html($row->id).'</td><td>'.esc_html($row->lesson_id).'</td><td>'.esc_html($row->purpose).'</td><td>'.esc_html($row->generation).'</td><td>'.esc_html($row->state).'</td><td>'.esc_html($row->expires_at).'</td><td>'.esc_html($row->redemption_count).'</td><td>'.esc_html((string)$row->last_outcome).'</td><td>'.esc_html((string)$row->revocation_reason_code).'</td></tr>';
        echo '</tbody></table><h2>Diagnostics</h2><pre>'.esc_html(wp_json_encode($diagnostics)).'</pre></div>';
    }
}
