<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateValidationException;
use Delnavazan\Platform\Portals\PortalNotificationInbox;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;

/**
 * Notifications & Messaging — one capability-checked, nonce-protected communications workspace.
 *
 * Sections: message templates, delivery rules, delivery monitoring, channel settings and website
 * notifications. Every mutation is a POST/redirect/GET with server-side validation; every read is
 * escaped. The page can never change a recipient, authorise a payment, modify a lesson or bypass the
 * business authority that produced a notification.
 */
final class NotificationManagementController {
    public const PAGE='dzn-notifications';
    public const CAP_EDIT='dzn_manage_notification_templates';
    public const CAP_VIEW=NotificationRule::READ_CAPABILITY;
    private const FLASH='dzn_notification_flash_';

    public static function register():void{
        add_action('admin_post_dzn_notification_copy_save_draft',array(__CLASS__,'saveDraft'));
        add_action('admin_post_dzn_notification_copy_publish',array(__CLASS__,'publish'));
        add_action('admin_post_dzn_notification_copy_discard',array(__CLASS__,'discardDraft'));
        add_action('admin_post_dzn_notification_copy_restore',array(__CLASS__,'restoreVersion'));
        add_action('admin_post_dzn_notification_copy_restore_default',array(__CLASS__,'restoreDefault'));
        add_action('admin_post_dzn_notification_provider_mapping',array(__CLASS__,'saveProviderMapping'));
        add_action('admin_post_dzn_notification_channel_policy',array(__CLASS__,'saveChannelPolicy'));
        add_action('admin_post_dzn_notification_inbox_prune',array(__CLASS__,'pruneInbox'));
        add_action('admin_post_dzn_notification_inbox_test',array(__CLASS__,'testInbox'));
        add_action('admin_post_dzn_notification_recover_leases',array(__CLASS__,'recoverLeases'));
    }

    public static function screen():void{
        self::authorizeView();
        $tab=isset($_GET['tab'])&&is_scalar($_GET['tab'])?sanitize_key(wp_unslash((string)$_GET['tab'])):'templates';
        if(!in_array($tab,array('templates','rules','monitoring','channels','website'),true))$tab='templates';
        echo '<div class="wrap dzn-notif-admin" dir="ltr"><style>.dzn-notif-admin{direction:ltr}.dzn-notif-admin .nav-tab-wrapper{margin-block-end:16px}.dzn-notif-lede{max-width:72ch;color:#50575e}.dzn-notif-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px}.dzn-notif-panel h2{margin-block-start:6px}.dzn-notif-meta{color:#646970;font-size:12px;line-height:1.9}.dzn-notif-note{color:#50575e;font-size:12px;line-height:1.9;background:#f6f7f7;border-inline-start:3px solid #0E8C8C;padding:8px 12px;border-radius:3px}.dzn-notif-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-block:14px}.dzn-notif-card{border:1px solid #dcdcde;border-radius:6px;padding:14px}.dzn-notif-card h3{margin-block-start:0}.dzn-notif-list{margin:0;padding-inline-start:18px;line-height:2}.dzn-notif-table td,.dzn-notif-table th{vertical-align:top}.dzn-notif-preview{background:#FBF7EE;border:1px solid #e6dfd2;border-radius:8px;padding:10px}.dzn-notif-preview pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;direction:rtl;margin:0;line-height:2}.dzn-notif-preview--text{background:#fff}.dzn-notif-editor textarea{font-family:Tahoma,Arial,sans-serif;line-height:2;direction:rtl}.dzn-notif-actions{display:flex;gap:8px;flex-wrap:wrap}.dzn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a}.dzn-badge.is-ok{background:#edfaef;color:#1e6b2f}.dzn-badge.is-warn{background:#fcf3e6;color:#8a4b00}.dzn-badge.is-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-inline-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.dzn-notif-admin code{direction:ltr;unicode-bidi:embed}.dzn-notif-admin details pre{max-height:220px;overflow:auto;white-space:pre-wrap}</style><h1>Notifications &amp; Messaging</h1>';
        echo '<p class="dzn-notif-lede">Manage email, WhatsApp, SMS and portal notification templates in one place. Editing copy never changes a recipient, authorises a payment, alters a lesson or bypasses the business authority that produced the notification.</p>';
        self::renderFlash();
        $tabs=array('templates'=>'Templates','rules'=>'Delivery Rules','monitoring'=>'Delivery Monitoring','channels'=>'Channels','website'=>'Portal Notifications');
        echo '<nav class="nav-tab-wrapper">';
        foreach($tabs as $slug=>$label)echo '<a class="nav-tab'.($tab===$slug?' nav-tab-active':'').'" href="'.esc_url(self::url(array('tab'=>$slug))).'">'.esc_html($label).'</a>';
        echo '</nav><div class="dzn-notif-panel">';
        match($tab){
            'rules'=>self::renderRules(),
            'monitoring'=>self::renderMonitoring(),
            'channels'=>self::renderChannels(),
            'website'=>self::renderWebsite(),
            default=>self::renderTemplates(),
        };
        echo '</div></div>';
    }

    // ------------------------------------------------------------------ templates

    private static function renderTemplates():void{
        $library=new NotificationTemplateLibrary();
        $eventKey=isset($_GET['template'])?self::eventKey(wp_unslash((string)$_GET['template'])):'';
        if($eventKey!==''){
            $channel=isset($_GET['channel'])?sanitize_key(wp_unslash((string)$_GET['channel'])):'email';
            $locale=isset($_GET['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_GET['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
            if(NotificationCatalogue::get($eventKey)===null){echo '<p>Notification event not found.</p>';return;}
            self::renderEditor($library,$eventKey,$channel,$locale);
            return;
        }
        $channel=isset($_GET['channel'])&&is_scalar($_GET['channel'])?sanitize_key(wp_unslash((string)$_GET['channel'])):'';
        $locale=isset($_GET['locale'])&&is_scalar($_GET['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_GET['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="templates">';
        echo '<label>Channel <select name="channel"><option value="">All</option>';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channel===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Language <select name="locale"><option value="fa-IR"'.($locale==='fa-IR'?' selected':'').'>Persian (fa-IR)</option><option value="en"'.($locale==='en'?' selected':'').'>English (en)</option></select></label><button class="button">Filter</button></form>';
        $rows=$library->catalogueRows($channel===''?null:$channel,$locale);
        $groups=array();
        foreach($rows as $row)$groups[(string)$row['event_key']][]=$row;
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' templates across '.esc_html((string)count($groups)).' events. Default copy is always recoverable and published versions are immutable.</p>';
        echo '<table class="widefat striped dzn-notif-table"><thead><tr><th>Event</th><th>Category</th><th>Channel</th><th>Version Status</th><th>Provider</th><th>Warnings</th><th></th></tr></thead><tbody>';
        foreach($groups as $eventRows){
            $first=true;
            foreach($eventRows as $row){
                echo '<tr>';
                if($first){echo '<td rowspan="'.esc_attr((string)count($eventRows)).'"><strong>'.esc_html((string)$row['label']).'</strong><br><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td>';echo '<td rowspan="'.esc_attr((string)count($eventRows)).'">'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$row['category']]['label']??$row['category'])).'</td>';$first=false;}
                echo '<td>'.esc_html(self::channelLabel((string)$row['channel'])).' · '.esc_html((string)$row['locale']).'</td>';
                echo '<td>'.esc_html(self::versionStateLabel((string)$row['active_state'],(bool)$row['published'],$row['draft'])).'</td>';
                echo '<td>'.esc_html(self::providerStateLabel((string)$row['provider_state'])).'</td>';
                echo '<td>'.($row['issues']?'<span class="dzn-badge is-warn">'.esc_html((string)count($row['issues'])).' issue(s)</span>':'<span class="dzn-badge is-ok">Ready</span>').'</td>';
                echo '<td><a class="button button-small" href="'.esc_url(self::url(array('tab'=>'templates','template'=>(string)$row['event_key'],'channel'=>(string)$row['channel'],'locale'=>(string)$row['locale']))).'">'.(self::canEdit()?'Edit':'View').'</a></td></tr>';
            }
        }
        echo '</tbody></table>';
        if(!self::canEdit())echo '<p class="dzn-notif-note">You have read-only access. Editing and publishing require the <code>dzn_manage_notification_templates</code> capability.</p>';
    }

    private static function renderEditor(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale):void{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return;
        $status=$library->status($eventKey,$channel,$locale);
        $working=$library->workingCopy($eventKey,$channel,$locale);
        $editable=self::canEdit()&&(string)$event['authority']!=='dedicated';
        $isDedicated=(string)$event['authority']==='dedicated';
        echo '<p><a href="'.esc_url(self::url(array('tab'=>'templates'))).'">‹ Back to templates</a></p>';
        echo '<h2>'.esc_html((string)$event['label']).'</h2><p class="dzn-notif-meta"><code dir="ltr">'.esc_html($eventKey).'</code> · '.esc_html(self::channelLabel($channel)).' · '.esc_html($locale).' · Category: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</p>';
        echo '<div class="dzn-notif-grid">';
        echo '<section class="dzn-notif-card"><h3>Variable Contract</h3><p>Allowed placeholders:</p><p>';
        foreach(NotificationCatalogue::variables($eventKey) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p>Required placeholders for this channel:</p><p>';
        foreach(NotificationTemplateSanitizer::requiredVariables($eventKey,$channel) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p class="dzn-notif-note">Copy is stored as plain text. The system supplies the HTML structure and brand presentation. Scripts, PHP, HTML tags and browser event handlers are rejected.</p></section>';
        echo '<section class="dzn-notif-card"><h3>Status</h3><ul class="dzn-notif-list">';
        echo '<li>Active version: '.esc_html($status['active_version_number']!==null?('#'.$status['active_version_number']):'—').'</li>';
        echo '<li>Stored active copy: '.esc_html($status['published']?'Yes':'No').'</li>';
        echo '<li>Draft: '.esc_html($status['draft']!==null?'Present':'None').'</li>';
        echo '<li>Provider status: '.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</li></ul>';
        if($status['issues']){echo '<div class="notice notice-warning inline"><ul>';foreach($status['issues'] as $issue)echo '<li>'.esc_html((string)$issue).'</li>';echo '</ul></div>';}
        if($isDedicated){echo '<div class="notice notice-info inline"><p>This message belongs to a dedicated secure delivery authority. Edit its copy on the <a href="'.esc_url(admin_url('admin.php?page=dzn-communications-templates')).'">Teacher invitation template page</a> so the one-time code stays out of general notifications.</p></div>';}
        echo '</section></div>';

        if($isDedicated){
            self::renderHistory($library,$eventKey,$channel,$locale,$status);
            return;
        }

        echo '<h3>Preview</h3>';
        $preview=$library->preview($eventKey,$channel,(string)($working['editable']['subject']??''),(string)($working['editable']['body']??''),$locale);
        echo '<p class="dzn-notif-note">Preview uses synthetic data — no message is sent.</p>';
        if($channel==='email'){
            echo '<div class="dzn-notif-preview"><iframe title="Email preview" sandbox="" srcdoc="'.esc_attr((string)$preview['html']).'" style="width:100%;min-height:560px;border:1px solid #dccfc0;border-radius:8px;background:#fff;"></iframe></div>';
        }else{
            echo '<div class="dzn-notif-preview dzn-notif-preview--text" dir="rtl" lang="fa"><strong>'.esc_html((string)($preview['subject']??'')).'</strong><pre>'.esc_html((string)($preview['text']??'')).'</pre>';
            if(is_array($preview['segments']??null))echo '<p class="dzn-notif-meta">'.esc_html('Length: '.$preview['segments']['length'].' · Segments: '.$preview['segments']['segments'].' · Encoding: '.$preview['segments']['encoding']).'</p>';
            echo '</div>';
        }
        echo '<h3>Edit Message</h3>';
        $subject=(string)($working['editable']['subject']??'');
        $body=(string)($working['editable']['body']??'');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-editor">';
        // Pressing Enter in a field must never publish: the hidden default saves a draft, and a clicked
        // button's own action value is submitted after it and therefore wins.
        echo '<input type="hidden" name="action" value="dzn_notification_copy_save_draft"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
        wp_nonce_field('dzn_notification_copy_save_draft','dzn_draft_nonce');
        wp_nonce_field('dzn_notification_copy_publish','dzn_publish_nonce');
        wp_nonce_field('dzn_notification_copy_discard','dzn_discard_nonce');
        wp_nonce_field('dzn_notification_copy_restore_default','dzn_restore_default_nonce');
        if($channel!=='sms')echo '<p><label for="dzn-notif-subject"><strong>'.($channel==='website'?'Notification title':'Subject').'</strong></label><br><input id="dzn-notif-subject" class="large-text" dir="rtl" lang="fa" maxlength="180" name="subject" value="'.esc_attr($subject).'"'.($editable?'':' readonly').'></p>';
        else echo '<input type="hidden" name="subject" value="'.esc_attr($subject).'">';
        echo '<p><label for="dzn-notif-body"><strong>Message body</strong></label><br><textarea id="dzn-notif-body" class="large-text" dir="rtl" lang="fa" rows="14" maxlength="5000" name="body"'.($editable?'':' readonly').'>'.esc_textarea($body).'</textarea></p>';
        if($editable){
            echo '<p class="dzn-notif-actions">';
            echo '<button class="button" name="action" value="dzn_notification_copy_save_draft">Save Draft</button> ';
            echo '<button class="button button-primary" name="action" value="dzn_notification_copy_publish">Publish New Version</button> ';
            if($status['draft']!==null)echo '<button class="button" name="action" value="dzn_notification_copy_discard">Discard Draft</button> ';
            echo '<button class="button" name="action" value="dzn_notification_copy_restore_default" onclick="return confirm(\'Publish the default copy as a new version?\')">Restore Default</button>';
            echo '</p>';
        }
        echo '</form>';
        self::renderHistory($library,$eventKey,$channel,$locale,$status);
    }

    private static function renderHistory(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale,array $status):void{
        $history=$library->history($eventKey,$channel,$locale);
        echo '<h3>Version History</h3>';
        if($history===array()){echo '<p>No version has been published yet. Default copy is available.</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Version</th><th>Status</th><th>Created</th><th>Source</th><th>Content</th><th></th></tr></thead><tbody>';
        foreach($history as $version){
            echo '<tr><td>#'.esc_html((string)$version['version_number']).'</td><td>'.esc_html(self::stateLabel((string)$version['state'])).'</td><td><code dir="ltr">'.esc_html((string)$version['created_at']).'</code></td><td>'.esc_html(self::sourceLabel((string)($version['source']??''))).'</td>';
            echo '<td>'.($version['body_present']?'<details><summary>Show</summary><strong dir="rtl" lang="fa">'.esc_html((string)$version['subject']).'</strong><pre dir="rtl" lang="fa">'.esc_html((string)$version['body']).'</pre></details>':'<span class="dzn-badge is-warn">Body not stored</span>').'</td><td>';
            if(self::canEdit()&&$version['body_present']&&(int)$status['active_version_id']!==(int)$version['template_version_id']){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_copy_restore"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'"><input type="hidden" name="template_version_id" value="'.esc_attr((string)$version['template_version_id']).'">';
                wp_nonce_field('dzn_notification_copy_restore');
                echo '<button class="button button-small">Republish this copy</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="dzn-notif-note">Restoring never modifies the old version; the same copy is published as a new immutable version. Notifications that already froze a version keep it.</p>';
    }

    // ------------------------------------------------------------------ rules

    private static function renderRules():void{
        $library=new NotificationTemplateLibrary();
        echo '<h2>Delivery Rules</h2><p>Each category has a channel policy. Security, account and financial messages cannot be disabled, and marketing consent never substitutes for operational-message authority.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_channel_policy">';
        wp_nonce_field('dzn_notification_channel_policy');
        echo '<table class="widefat striped"><thead><tr><th>Category</th><th>Email</th><th>Portal</th><th>WhatsApp</th><th>SMS</th><th>SMS instead of WhatsApp</th><th>Mandatory</th></tr></thead><tbody>';
        foreach($library->channelPolicies() as $category=>$policy){
            $locked=!empty($policy['mandatory']);
            echo '<tr><td><input type="hidden" name="policy['.esc_attr($category).'][present]" value="1"><strong>'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['label']??$category)).'</strong><br><span class="dzn-notif-meta">'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['description']??'')).'</span></td>';
            foreach(array('email','website','whatsapp','sms','sms_fallback') as $flag){
                echo '<td><input type="checkbox" name="policy['.esc_attr($category).']['.esc_attr($flag).']" value="1"'.(!empty($policy[$flag])?' checked':'').(($locked&&in_array($flag,array('email','website'),true))?' disabled':'').'>';
            }
            echo '<td>'.($locked?'<span class="dzn-badge is-locked">Locked</span>':'<span class="dzn-badge">Optional</span>').'</td></tr>';
        }
        echo '</tbody></table>';
        if(self::canEdit())echo '<p><button class="button button-primary">Save Channel Rules</button></p>';
        echo '</form>';
        echo '<h3>Event → Channel Map</h3><table class="widefat striped"><thead><tr><th>Event</th><th>Category</th><th>Channels</th><th>Delivery Authority</th><th>Consent Basis</th></tr></thead><tbody>';
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            echo '<tr><td>'.esc_html((string)$event['label']).'<br><code dir="ltr">'.esc_html($eventKey).'</code></td><td>'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</td><td>'.esc_html(implode(', ',array_map(array(__CLASS__,'channelLabel'),(array)$event['channels']))).'</td><td>'.esc_html((string)(NotificationCatalogue::AUTHORITIES[(string)$event['authority']]??$event['authority'])).'</td><td>'.esc_html(self::consentLabel((string)$event['consent'])).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------ monitoring

    private static function renderMonitoring():void{
        echo '<h2>Delivery Monitoring</h2><p class="dzn-notif-note">“Handed off to provider” only means the email or messaging service accepted the request. “Delivery confirmed” is shown only when a verified provider receipt exists.</p>';
        $states=array();
        $report=null;
        if(current_user_can(self::CAP_VIEW)||current_user_can('manage_options')){
            try{$report=(new NotificationDiagnosticsReadService())->report();$states=(array)($report['counts']??array());}catch(\Throwable $error){echo '<div class="notice notice-error inline"><p>Notification service diagnostics could not be read.</p></div>';}
        }else{echo '<div class="notice notice-warning inline"><p>Viewing notification service diagnostics requires the <code>dzn_view_notification_authority</code> capability.</p></div>';}
        $labels=array(
            'pending'=>'Pending schedule','scheduled'=>'Scheduled','queued'=>'Queued','dispatching'=>'Processing',
            'dispatched'=>'Handed off to provider','delivered'=>'Delivery confirmed','closed'=>'Closed',
            'suppressed'=>'Suppressed','cancelled'=>'Cancelled','expired'=>'Expired','failed'=>'Failed',
        );
        echo '<table class="widefat striped"><thead><tr><th>Status</th><th>Count</th><th>Meaning</th></tr></thead><tbody>';
        foreach($labels as $state=>$label){
            $count=(int)($states[$state]??0);
            $meaning=match($state){
                'dispatched'=>'The provider accepted the request; final delivery is not confirmed.',
                'delivered'=>'A verified delivery receipt was recorded.',
                'queued'=>'Ready for the dispatch worker.','dispatching'=>'A dispatch lease is active.',
                'failed'=>'Closed with an error; automatic retry stopped.','expired'=>'The permitted send window has ended.',
                'suppressed'=>'Stopped by recipient request or consent policy.','cancelled'=>'Cancelled by an explicit command.',
                default=>'—',
            };
            echo '<tr><td>'.esc_html($label).'<br><code dir="ltr">'.esc_html($state).'</code></td><td>'.esc_html((string)$count).'</td><td>'.esc_html($meaning).'</td></tr>';
        }
        echo '</tbody></table>';
        if(is_array($report)){
            echo '<h3>Operational Diagnostics</h3><table class="widefat striped"><tbody>';
            $diagnostics=array(
                'unregistered_intent'=>'Unregistered intent in queue','unroutable_intent'=>'Intent with no active route','orchestration_backlog'=>'Scheduling backlog',
                'stuck_lease'=>'Stuck lease','expired_lease'=>'Expired lease','orphan_outbox_row'=>'Orphan outbox row',
                'retry_exhausted'=>'Retries exhausted','retry_window_exhausted'=>'Retry window exhausted',
            );
            foreach($diagnostics as $key=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($report[$key]??0)).'</td></tr>';
            echo '</tbody></table>';
            if(!empty($report['unroutable_intents']))echo '<p class="dzn-notif-note">Unroutable events: <code dir="ltr">'.esc_html(implode(', ',(array)$report['unroutable_intents'])).'</code></p>';
        }
        try{
            $inbox=new PortalNotificationInbox();
            $summary=$inbox->adminSummary();
            echo '<h3>Portal Notifications</h3><table class="widefat striped"><thead><tr><th>Audience</th><th>Total</th><th>Unread</th></tr></thead><tbody>';
            foreach(array('student'=>'Student','teacher'=>'Teacher','admin'=>'Admin') as $audience=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($summary[$audience]['total']??0)).'</td><td>'.esc_html((string)(int)($summary[$audience]['unread']??0)).'</td></tr>';
            echo '</tbody></table>';
        }catch(\Throwable $error){echo '<div class="notice notice-warning inline"><p>The portal notification table is not ready. Check the database migration.</p></div>';}
        $issues=0;foreach((new NotificationTemplateLibrary())->catalogueRows(null,NotificationCatalogue::PRIMARY_LOCALE) as $row){if($row['issues'])$issues++;}
        echo '<h3>Template Configuration</h3><p>'.esc_html($issues===0?'All primary-language templates have copy and an active version.':($issues.' templates have configuration warnings.')).' <a href="'.esc_url(self::url(array('tab'=>'templates'))).'">Review templates</a></p>';
        if(current_user_can('dzn_operate_notification_dispatch')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_recover_leases">';
            wp_nonce_field('dzn_notification_recover_leases');
            echo '<p><button class="button">Safely recover expired leases</button> <span class="dzn-notif-meta">Releases only expired leases under the existing retry policy and creates no new message.</span></p></form>';
        }
    }

    // ------------------------------------------------------------------ channels

    private static function renderChannels():void{
        echo '<h2>Channels</h2><p>This page shows readiness and mapping only; provider keys and secrets are never displayed.</p>';
        try{
            $report=(new IntegrationControlPlaneReadService())->report();
        }catch(\Throwable $error){$report=null;}
        echo '<div class="dzn-notif-grid">';
        $channels=array(
            'email'=>array('Email','Email settings','dzn-integration-email'),
            'whatsapp'=>array('WhatsApp (Meta Cloud API)','WhatsApp settings','dzn-integration-whatsapp'),
            'sms'=>array('SMS (Telnyx)','SMS settings','dzn-integration-sms'),
        );
        foreach($channels as $channel=>[$label,$link,$slug]){
            $state=(string)($report['notifications']['channels'][$channel]??'not_configured');
            echo '<section class="dzn-notif-card"><h3>'.esc_html($label).'</h3><p>Connection status: <strong>'.esc_html($state==='configured'?'Configured (unverified)':'Not configured').'</strong></p><p class="dzn-notif-note">Configured does not mean delivery verified.</p><p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($link).'</a></p></section>';
        }
        echo '</div>';
        echo '<h3>WhatsApp Template Mapping</h3><p>For business-initiated messages, each event must map to a Meta-approved template and language code. Changing local copy requires provider re-approval, and sending never starts without a valid mapping.</p>';
        $library=new NotificationTemplateLibrary();
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Mapping Status</th><th>Template ID &amp; Language</th><th></th></tr></thead><tbody>';
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            if(!in_array('whatsapp',(array)$event['channels'],true))continue;
            $status=$library->status($eventKey,'whatsapp',NotificationCatalogue::PRIMARY_LOCALE);
            $mapping=$library->providerMapping((string)$status['template_key']);
            echo '<tr><td>'.esc_html((string)$event['label']).'<br><code dir="ltr">'.esc_html($eventKey).'</code></td><td>'.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</td><td><code dir="ltr">'.esc_html($mapping['name']!==''?$mapping['name'].' · '.$mapping['language']:'—').'</code></td><td>';
            if(self::canEdit()){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_provider_mapping"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="whatsapp"><input type="hidden" name="locale" value="fa-IR">';
                wp_nonce_field('dzn_notification_provider_mapping');
                echo '<input name="provider_name" placeholder="template_name" value="'.esc_attr($mapping['name']).'" maxlength="191" dir="ltr"><input name="provider_language" placeholder="fa" value="'.esc_attr($mapping['language']).'" maxlength="10" dir="ltr" size="6"><button class="button button-small">Record Mapping</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>SMS Length</h3><p>Persian text is measured as UCS-2: 70 characters for one part and 67 for each additional part. Each SMS template preview shows its length and segment count. SMS is used only as a constrained fallback when delivery policy permits it.</p>';
    }

    // ------------------------------------------------------------------ website inbox

    private static function renderWebsite():void{
        $inbox=new PortalNotificationInbox();
        try{$summary=$inbox->adminSummary();$recent=$inbox->recent(50);}catch(\Throwable $error){echo '<div class="notice notice-error inline"><p>The portal notification table could not be read. Check the database migration.</p></div>';return;}
        echo '<h2>Portal Notifications</h2><p>Student and teacher notifications appear in their personal portal with a link to the same business event. Admin notifications are kept separate, and clicking a notification never approves a business action.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Audience</th><th>Total</th><th>Unread</th><th>Retention Policy</th></tr></thead><tbody>';
        foreach(array('student'=>'Student','teacher'=>'Teacher','admin'=>'Admin') as $audience=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($summary[$audience]['total']??0)).'</td><td>'.esc_html((string)(int)($summary[$audience]['unread']??0)).'</td><td>'.esc_html($audience==='admin'?'Admin console only':(PortalNotificationInbox::RETENTION_DAYS.' days')).'</td></tr>';
        echo '</tbody></table>';
        echo '<h3>Recent Notifications</h3>';
        if($recent===array()){echo '<p>No notification has been recorded yet.</p>';}
        else{
            echo '<table class="widefat striped"><thead><tr><th>Created</th><th>Audience</th><th>Event</th><th>Title</th><th>Status</th></tr></thead><tbody>';
            foreach($recent as $row)echo '<tr><td><code dir="ltr">'.esc_html((string)$row['created_at']).'</code></td><td>'.esc_html(self::audienceLabel((string)$row['audience'])).' #'.esc_html((string)$row['recipient_id']).'</td><td><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td><td>'.esc_html((string)$row['title']).'</td><td>'.esc_html($row['read']?'Read':'Unread').'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<div class="dzn-notif-grid"><section class="dzn-notif-card"><h3>Retention</h3><p>Portal notifications are removed after '.esc_html((string)PortalNotificationInbox::RETENTION_DAYS).' days. This cleanup deletes only portal-notification rows and never changes class, payment or education history.</p>';
        if(self::canEdit())echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_inbox_prune">'.wp_nonce_field('dzn_notification_inbox_prune', '_wpnonce', true, false).'<button class="button">Prune Expired Notifications</button></form>';
        echo '</section><section class="dzn-notif-card"><h3>Safe Check</h3><p>A test notification is recorded for the admin list only; no email, WhatsApp or SMS message is sent.</p>';
        if(self::canEdit())echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_inbox_test">'.wp_nonce_field('dzn_notification_inbox_test', '_wpnonce', true, false).'<button class="button">Record Admin Test Notification</button></form>';
        echo '</section></div>';
    }

    // ------------------------------------------------------------------ handlers

    public static function saveDraft():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveDraft($input['event_key'],$input['channel'],$input['locale'],(string)($input['subject']??''),(string)($input['body']??''));
        return array('type'=>'success','message'=>'Draft saved. The delivered message does not change until you publish.');
    },array('nonce'=>'dzn_notification_copy_save_draft','nonce_field'=>'dzn_draft_nonce'));}

    public static function publish():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $result=$library->publish($input['event_key'],$input['channel'],$input['locale'],(string)($input['subject']??''),(string)($input['body']??''));
        return array('type'=>'success','message'=>'Version '.$result['version_number'].' published and active. Notifications that already froze a version keep it.');
    },array('nonce'=>'dzn_notification_copy_publish','nonce_field'=>'dzn_publish_nonce'));}

    public static function discardDraft():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->discardDraft($input['event_key'],$input['channel'],$input['locale']);
        return array('type'=>'success','message'=>'Draft discarded.');
    },array('nonce'=>'dzn_notification_copy_discard','nonce_field'=>'dzn_discard_nonce'));}

    public static function restoreVersion():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $versionId=(int)($input['template_version_id']??0);
        if($versionId<1)throw new \InvalidArgumentException('The selected version is not valid.');
        $result=$library->restoreVersion($input['event_key'],$input['channel'],$input['locale'],$versionId);
        return array('type'=>'success','message'=>'The previous copy was published as new version '.$result['version_number'].'.');
    },array('nonce'=>'dzn_notification_copy_restore'));}

    public static function restoreDefault():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $result=$library->restoreDefault($input['event_key'],$input['channel'],$input['locale']);
        return array('type'=>'success','message'=>'Default copy published as new version '.$result['version_number'].'.');
    },array('nonce'=>'dzn_notification_copy_restore_default','nonce_field'=>'dzn_restore_default_nonce'));}

    public static function saveProviderMapping():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveProviderMapping($input['event_key'],$input['channel'],$input['locale'],(string)($input['provider_name']??''),(string)($input['provider_language']??''));
        return array('type'=>'success','message'=>'Mapping saved. Meta template approval is still required separately.');
    },array('nonce'=>'dzn_notification_provider_mapping','tab'=>'channels'));}

    public static function saveChannelPolicy():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveChannelPolicy((array)($input['policy']??array()));
        return array('type'=>'success','message'=>'Channel rules saved.');
    },array('nonce'=>'dzn_notification_channel_policy','tab'=>'rules','event_required'=>false));}

    public static function pruneInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $deleted=(new PortalNotificationInbox())->prune();
        return array('type'=>'success','message'=>$deleted.' expired notifications pruned.');
    },array('nonce'=>'dzn_notification_inbox_prune','tab'=>'website','event_required'=>false));}

    public static function testInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $id=PortalNotificationRecorder::admin('system.inbox_check.admin','admin_test',(int)get_current_user_id(),array('when'=>'Test sample'),null);
        return $id===null?array('type'=>'error','message'=>'The test notification could not be recorded. Check the portal template configuration.'):array('type'=>'success','message'=>'The test notification was recorded in the admin list only. No email, WhatsApp or SMS message was sent.');
    },array('nonce'=>'dzn_notification_inbox_test','tab'=>'website','event_required'=>false));}

    public static function recoverLeases():never{
        if(!current_user_can('dzn_operate_notification_dispatch')&&!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('dzn_notification_recover_leases');
        try{
            $result=(new NotificationDispatchService())->recoverExpiredLeases(array(
                'evidence_channel'=>'staff_record','evidence_reference'=>'admin-recover:'.get_current_user_id().':'.time(),'evidence_at'=>NotificationSupport::now(),
            ),'admin-recover-leases:'.gmdate('YmdHi'));
            self::flash('success','Recovery complete: '.$result['recovered'].' re-armed, '.$result['closed'].' closed.');
        }catch(\Throwable $error){self::flash('error','Recovery failed: '.$error->getMessage());}
        self::redirectBack('monitoring');
    }

    /** @param callable(NotificationTemplateLibrary,array):array $operation */
    private static function handle(callable $operation,array $options=array()):never{
        self::authorizeEdit();
        $nonce=(string)($options['nonce']??'dzn_notification_copy_publish');
        $nonceField=(string)($options['nonce_field']??'_wpnonce');
        check_admin_referer($nonce,$nonceField);
        $input=array(
            'event_key'=>isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'',
            'channel'=>isset($_POST['channel'])?sanitize_key(wp_unslash((string)$_POST['channel'])):'',
            'locale'=>isset($_POST['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_POST['locale']))):NotificationCatalogue::PRIMARY_LOCALE,
            'subject'=>isset($_POST['subject'])?wp_unslash((string)$_POST['subject']):'',
            'body'=>isset($_POST['body'])?wp_unslash((string)$_POST['body']):'',
            'template_version_id'=>isset($_POST['template_version_id'])?(int)$_POST['template_version_id']:0,
            'provider_name'=>isset($_POST['provider_name'])?sanitize_text_field(wp_unslash((string)$_POST['provider_name'])):'',
            'provider_language'=>isset($_POST['provider_language'])?sanitize_text_field(wp_unslash((string)$_POST['provider_language'])):'',
            'policy'=>isset($_POST['policy'])&&is_array($_POST['policy'])?(array)wp_unslash($_POST['policy']):array(),
        );
        $eventRequired=($options['event_required']??true)!==false;
        $event=$eventRequired?NotificationCatalogue::get($input['event_key']):null;
        if($eventRequired&&$event===null){self::flash('error','Unknown notification event.');self::redirectBack('templates');}
        if($event!==null&&(string)$event['authority']==='dedicated'&&in_array($input['channel'],array('email','whatsapp','sms'),true)){self::flash('error','This message belongs to a dedicated secure delivery authority and is not edited from this page.');self::redirectBack('templates');}
        try{
            $result=$operation(new NotificationTemplateLibrary(),$input);
            self::flash((string)($result['type']??'success'),(string)($result['message']??'Done.'));
        }catch(NotificationTemplateValidationException $error){
            self::flash('error',implode(' ',$error->reasons()));
        }catch(\Throwable $error){
            self::flash('error','Save failed: '.$error->getMessage());
        }
        $tab=(string)($options['tab']??'templates');
        $args=array();
        if($tab==='templates'&&$input['event_key']!==''){$args=array('template'=>$input['event_key'],'channel'=>$input['channel'],'locale'=>$input['locale']);}
        self::redirectBack($tab,$args);
    }

    // ------------------------------------------------------------------ helpers

    private static function authorizeView():void{
        if(!current_user_can('manage_options')&&!current_user_can(self::CAP_VIEW)&&!current_user_can(self::CAP_EDIT))wp_die('Forbidden',403);
    }
    private static function authorizeEdit():void{
        if(!current_user_can('manage_options')&&!current_user_can(self::CAP_EDIT))wp_die('Forbidden',403);
    }
    private static function canEdit():bool{return current_user_can('manage_options')||current_user_can(self::CAP_EDIT);}

    /** @param array<string,string|int> $args */
    private static function url(array $args=array()):string{return admin_url('admin.php?'.http_build_query(array_merge(array('page'=>self::PAGE),$args)));}
    private static function redirectBack(string $tab,array $extra=array()):never{wp_safe_redirect(self::url(array_merge(array('tab'=>$tab),$extra)));exit;}
    private static function flash(string $type,string $message):void{
        if(function_exists('set_transient'))set_transient(self::FLASH.get_current_user_id(),array('type'=>$type,'message'=>$message),120);
    }
    private static function renderFlash():void{
        if(!function_exists('get_transient'))return;
        $flash=get_transient(self::FLASH.get_current_user_id());
        if(!is_array($flash)||empty($flash['message']))return;
        delete_transient(self::FLASH.get_current_user_id());
        $class=($flash['type']??'')==='error'?'notice-error':'notice-success';
        echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html((string)$flash['message']).'</p></div>';
    }

    /** Event keys contain dots; sanitize_key would strip them, so validate the closed shape explicitly. */
    private static function eventKey(string $value):string{
        $value=trim($value);
        return preg_match('/^[a-z0-9._-]{1,64}$/',$value)===1?$value:'';
    }

    public static function channelLabel(string $channel):string{return array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal')[$channel]??$channel;}
    private static function versionStateLabel(string $state,bool $published,?array $draft):string{
        if($draft!==null)return 'Draft saved';
        if($published)return 'Published active version';
        if($state==='unregistered')return 'Default copy';
        return 'No active version';
    }
    private static function providerStateLabel(string $state):string{return array(
        'not_mapped'=>'Not mapped','approval_required'=>'Meta re-approval required','mapped'=>'Mapped',
        'restricted_fallback'=>'Restricted fallback','not_applicable'=>'Not applicable','unsupported'=>'Unsupported',
    )[$state]??$state;}
    private static function stateLabel(string $state):string{return array('draft'=>'Draft','active'=>'Active','superseded'=>'Superseded','retired'=>'Retired')[$state]??$state;}
    private static function sourceLabel(string $source):string{return array('authored'=>'Authored','rollback'=>'Rollback','default'=>'Default copy','published'=>'Published')[$source]??($source!==''?$source:'—');}
    private static function consentLabel(string $consent):string{return array(
        'security'=>'Security requirement; no marketing consent','operational'=>'Operational message based on the teaching relationship',
        'financial'=>'Financial message; requires legal review','marketing'=>'Marketing consent only',
    )[$consent]??$consent;}
    private static function audienceLabel(string $audience):string{return array('student'=>'Student','teacher'=>'Teacher','admin'=>'Admin')[$audience]??$audience;}
}
