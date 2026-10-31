<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSmsFallbackReadService;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateValidationException;
use Delnavazan\Platform\Portals\PortalNotificationInbox;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;

/**
 * Notifications — one capability-checked, nonce-protected communications workspace.
 *
 * Templates are the working surface. Channel settings (WhatsApp, Email, SMS) and the moved delivery
 * diagnostics live in two secondary views; the old tab URLs remain resolvable so bookmarks keep
 * working. Every mutation is a POST/redirect/GET with server-side validation and every read is escaped.
 * The page can never change a recipient, authorise a payment, modify a lesson or bypass the business
 * authority that produced a notification.
 */
final class NotificationManagementController {
    public const PAGE='dzn-notifications';
    public const CAP_EDIT='dzn_manage_notification_templates';
    public const CAP_VIEW=NotificationRule::READ_CAPABILITY;
    private const FLASH='dzn_notification_flash_';

    /** Old bookmarked tab names map to their new home instead of becoming dead URLs. */
    private const LEGACY_TABS=array('rules'=>'settings','channels'=>'settings','monitoring'=>'diagnostics','website'=>'diagnostics');

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
        $requested=isset($_GET['tab'])&&is_scalar($_GET['tab'])?sanitize_key(wp_unslash((string)$_GET['tab'])):'templates';
        $tab=self::LEGACY_TABS[$requested]??$requested;
        if(!in_array($tab,array('templates','settings','diagnostics'),true))$tab='templates';
        echo '<div class="wrap dzn-notif-admin" dir="ltr"><style>.dzn-notif-admin{direction:ltr}.dzn-notif-admin .nav-tab-wrapper{margin-block-end:16px}.dzn-notif-lede{max-width:78ch;color:#50575e}.dzn-notif-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px}.dzn-notif-panel h2{margin-block-start:6px}.dzn-notif-meta{color:#646970;font-size:12px;line-height:1.9}.dzn-notif-note{color:#50575e;font-size:12px;line-height:1.9;background:#f6f7f7;border-inline-start:3px solid #0E8C8C;padding:8px 12px;border-radius:3px}.dzn-notif-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-block:14px}.dzn-notif-card{border:1px solid #dcdcde;border-radius:6px;padding:14px}.dzn-notif-card h3{margin-block-start:0}.dzn-notif-list{margin:0;padding-inline-start:18px;line-height:2}.dzn-notif-table td,.dzn-notif-table th{vertical-align:top}.dzn-notif-table details{margin-block:4px}.dzn-notif-table details pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;line-height:1.9;margin:6px 0;max-height:180px;overflow:auto}.dzn-notif-preview{background:#FBF7EE;border:1px solid #e6dfd2;border-radius:8px;padding:10px}.dzn-notif-preview pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;direction:rtl;margin:0;line-height:2}.dzn-notif-preview--text{background:#fff}.dzn-notif-editor textarea{font-family:Tahoma,Arial,sans-serif;line-height:2;direction:rtl}.dzn-notif-actions{display:flex;gap:8px;flex-wrap:wrap}.dzn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a}.dzn-badge.is-ok{background:#edfaef;color:#1e6b2f}.dzn-badge.is-warn{background:#fcf3e6;color:#8a4b00}.dzn-badge.is-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-inline-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.dzn-notif-admin code{direction:ltr;unicode-bidi:embed}.dzn-notif-admin details pre{max-height:220px;overflow:auto;white-space:pre-wrap}.dzn-notif-record-status{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin-block:18px}.dzn-notif-record-status h2{margin-block-start:0}</style><h1>Notifications</h1>';
        echo '<p class="dzn-notif-lede">Templates are the working surface: review each notification’s copy and its applicable delivery choices together. Editing copy never changes a recipient, authorises a payment, alters a lesson or bypasses the business authority that produced the notification.</p>';
        self::renderFlash();
        if($requested!==$tab){
            $legacy=array('rules'=>'Delivery Rules','channels'=>'Channels','monitoring'=>'Delivery Monitoring','website'=>'Portal Notifications');
            echo '<div class="notice notice-info inline"><p>'.esc_html((string)($legacy[$requested]??'That view')).' has moved into '.esc_html($tab==='settings'?'Channel settings':'Diagnostics').'. The old URL still works.</p></div>';
        }
        $tabs=array('templates'=>'Templates','settings'=>'Channel settings','diagnostics'=>'Diagnostics');
        echo '<nav class="nav-tab-wrapper">';
        foreach($tabs as $slug=>$label)echo '<a class="nav-tab'.($tab===$slug?' nav-tab-active':'').'" href="'.esc_url(self::url(array('tab'=>$slug))).'">'.esc_html($label).'</a>';
        echo '</nav><div class="dzn-notif-panel">';
        match($tab){
            'settings'=>self::renderSettings(),
            'diagnostics'=>self::renderDiagnostics(),
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
        echo '<h2>Templates</h2><p>Each event is shown once with its copy and only the delivery choices that its category, event and provider state permit. Email and Portal are the defaults for events that support both.</p>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="templates">';
        echo '<label>Channel <select name="channel"><option value="">All</option>';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channel===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Language <select name="locale"><option value="fa-IR"'.($locale==='fa-IR'?' selected':'').'>Persian (fa-IR)</option><option value="en"'.($locale==='en'?' selected':'').'>English (en)</option></select></label><button class="button">Filter</button></form>';
        $rows=$library->catalogueRows($channel===''?null:$channel,$locale);
        $groups=array();
        foreach($rows as $row)$groups[(string)$row['event_key']][]=$row;
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' applicable templates across '.esc_html((string)count($groups)).' events. Default copy is always recoverable and published versions are immutable.</p>';
        if(!self::canEdit())echo '<p class="dzn-notif-note">You have read-only access. Editing and publishing require the <code>dzn_manage_notification_templates</code> capability.</p>';
        if($groups===array()){echo '<p>No template matches this channel and language filter.</p>';return;}
        echo '<table class="widefat striped dzn-notif-table"><thead><tr><th>Notification</th><th>Category</th><th>Copy</th><th>Delivery choices</th><th>Readiness</th><th>Actions</th></tr></thead><tbody>';
        foreach($groups as $eventKey=>$eventRows){
            $event=NotificationCatalogue::get($eventKey)??array();
            echo '<tr><td><strong>'.esc_html((string)($event['label']??$eventKey)).'</strong><br><code dir="ltr">'.esc_html($eventKey).'</code></td>';
            echo '<td>'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)($event['category']??'')]['label']??($event['category']??''))).'</td><td>';
            $issues=array();
            foreach($eventRows as $row){
                $working=$library->workingCopy($eventKey,(string)$row['channel'],(string)$row['locale']);
                $subject=trim((string)($working['editable']['subject']??''));
                $body=trim((string)($working['editable']['body']??''));
                echo '<details><summary><strong>'.esc_html(self::channelLabel((string)$row['channel'])).'</strong> · '.esc_html($subject!==''?$subject:self::excerpt($body,80)).'</summary>';
                if($subject!=='')echo '<p><strong>'.esc_html((string)$row['channel']==='website'?'Title':'Subject').':</strong> '.esc_html($subject).'</p>';
                echo '<pre dir="rtl" lang="fa">'.esc_html($body).'</pre></details>';
                foreach((array)$row['issues'] as $issue)$issues[]=(string)$issue;
            }
            echo '</td><td>';
            foreach($eventRows as $row){
                $channel=(string)$row['channel'];
                $default=in_array($channel,array('email','website'),true);
                $provider=self::providerReady($channel);
                $state=(string)$row['provider_state'];
                $label=self::channelLabel($channel).($default?' · default':'');
                if($provider!==null)$label.=' · '.($provider?'Configured (unverified)':'Provider not configured');
                elseif($state==='not_mapped')$label.=' · Not mapped';
                elseif($state==='approval_required')$label.=' · Re-approval required';
                elseif($state==='mapped')$label.=' · Mapped';
                elseif($state==='restricted_fallback')$label.=' · Fallback only';
                $class=$state==='not_mapped'||$state==='approval_required'||$provider===false?'is-warn':'is-ok';
                echo '<div><span class="dzn-badge '.esc_attr($class).'">'.esc_html($label).'</span></div>';
                if($channel==='whatsapp'&&$provider===false)$issues[]='The WhatsApp provider is not configured; business-initiated sending will not start.';
                if($channel==='sms'&&$provider===false)$issues[]='The SMS provider is not configured; SMS remains unavailable.';
            }
            echo '</td><td>';
            if($issues)echo '<span class="dzn-badge is-warn">'.esc_html((string)count(array_unique($issues))).' issue(s)</span><ul class="dzn-notif-list">';
            else echo '<span class="dzn-badge is-ok">Ready</span><ul class="dzn-notif-list">';
            foreach(array_unique($issues) as $issue)echo '<li>'.esc_html($issue).'</li>';
            echo '</ul></td><td>';
            foreach($eventRows as $row){
                echo '<div><a class="button button-small" href="'.esc_url(self::url(array('tab'=>'templates','template'=>$eventKey,'channel'=>(string)$row['channel'],'locale'=>(string)$row['locale']))).'">'.(self::canEdit()?'Edit':'View').' '.esc_html(self::channelLabel((string)$row['channel'])).'</a></div>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        self::renderDedicatedAuthorityLink();
    }

    private static function renderEditor(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale):void{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return;
        $status=$library->status($eventKey,$channel,$locale);
        $working=$library->workingCopy($eventKey,$channel,$locale);
        $permitted=$library->channelPermitted($eventKey,$channel);
        $isDedicated=(string)$event['authority']==='dedicated';
        $editable=self::canEdit()&&!$isDedicated&&$permitted;
        echo '<p><a href="'.esc_url(self::url(array('tab'=>'templates'))).'">‹ Back to templates</a></p>';
        echo '<h2>'.esc_html((string)$event['label']).'</h2><p class="dzn-notif-meta"><code dir="ltr">'.esc_html($eventKey).'</code> · '.esc_html(self::channelLabel($channel)).' · '.esc_html($locale).' · Category: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</p>';
        if(!$permitted)echo '<div class="notice notice-warning inline"><p>This channel is not offered for this event. The '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'category')).' policy disables it, so it cannot be edited, mapped or sent from here. Its reserved copy remains visible below.</p></div>';
        echo '<div class="dzn-notif-grid">';
        echo '<section class="dzn-notif-card"><h3>Variable Contract</h3><p>Allowed placeholders:</p><p>';
        foreach(NotificationCatalogue::variables($eventKey) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p>Required placeholders for this channel:</p><p>';
        foreach(NotificationTemplateSanitizer::requiredVariables($eventKey,$channel) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p class="dzn-notif-note">Copy is stored as plain text. The system supplies the HTML structure and brand presentation. Scripts, PHP, HTML tags and browser event handlers are rejected. Previews use synthetic values and never reveal a one-time secret.</p></section>';
        echo '<section class="dzn-notif-card"><h3>Status</h3><ul class="dzn-notif-list">';
        echo '<li>Delivery choice: '.esc_html($permitted?'Offered':'Not offered').'</li>';
        echo '<li>Active version: '.esc_html($status['active_version_number']!==null?('#'.$status['active_version_number']):'—').'</li>';
        echo '<li>Stored active copy: '.esc_html($status['published']?'Yes':'No').'</li>';
        echo '<li>Draft: '.esc_html($status['draft']!==null?'Present':'None').'</li>';
        echo '<li>Provider status: '.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</li></ul>';
        if($status['issues']){echo '<div class="notice notice-warning inline"><ul>';foreach($status['issues'] as $issue)echo '<li>'.esc_html((string)$issue).'</li>';echo '</ul></div>';}
        if($isDedicated&&self::canEditTeacherInvitation()){echo '<div class="notice notice-info inline"><p>This message belongs to a dedicated secure delivery authority. Edit its copy on the <a href="'.esc_url(admin_url('admin.php?page=dzn-communications-templates')).'">Teacher invitation template page</a> so the one-time code stays out of general notifications.</p></div>';}
        echo '</section></div>';

        if($isDedicated){
            self::renderHistory($library,$eventKey,$channel,$locale,$status);
            return;
        }

        echo '<h3>Preview</h3>';
        $preview=$library->preview($eventKey,$channel,(string)($working['editable']['subject']??''),(string)($working['editable']['body']??''),$locale);
        echo '<p class="dzn-notif-note">Preview uses synthetic data — no message is sent and no one-time secret is shown.</p>';
        if($channel==='email'){
            echo '<div class="dzn-notif-preview"><iframe title="Email preview" sandbox="" srcdoc="'.esc_attr((string)$preview['html']).'" style="width:100%;min-height:560px;border:1px solid #dccfc0;border-radius:8px;background:#fff;"></iframe></div>';
        }else{
            echo '<div class="dzn-notif-preview dzn-notif-preview--text" dir="rtl" lang="fa"><strong>'.esc_html((string)($preview['subject']??'')).'</strong><pre>'.esc_html((string)($preview['text']??'')).'</pre>';
            if(is_array($preview['segments']??null))echo '<p class="dzn-notif-meta">'.esc_html('Length: '.$preview['segments']['length'].' · Segments: '.$preview['segments']['segments'].' · Encoding: '.$preview['segments']['encoding']).'</p>';
            echo '</div>';
        }
        if($channel==='whatsapp')self::renderProviderMapping($library,$eventKey,$channel,$locale,$status);
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

    /** Provider mapping belongs with the WhatsApp copy it describes, not in a separate channels tab. */
    private static function renderProviderMapping(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale,array $status):void{
        if($channel!=='whatsapp')return;
        $mapping=$library->providerMapping((string)$status['template_key']);
        $ready=self::providerReady('whatsapp');
        echo '<h3>WhatsApp provider template</h3><p>Meta must approve the provider template separately. Recording a mapping never sends a message and never changes a published version.</p>';
        echo '<p>Mapping status: <strong>'.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</strong>';
        if($mapping['name']!=='')echo ' · <code dir="ltr">'.esc_html($mapping['name'].' · '.$mapping['language']).'</code>';
        echo '</p>';
        if(!self::canEdit())return;
        if(!$ready){
            echo '<div class="notice notice-warning inline"><p>The WhatsApp provider is not configured. <a href="'.esc_url(admin_url('admin.php?page=dzn-integration-whatsapp')).'">Configure Telnyx WhatsApp and Meta WABA settings</a> before recording a provider mapping. No send is enabled by this page.</p></div>';
            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_provider_mapping"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="whatsapp"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
        wp_nonce_field('dzn_notification_provider_mapping');
        echo '<input name="provider_name" placeholder="template_name" value="'.esc_attr($mapping['name']).'" maxlength="191" dir="ltr"><input name="provider_language" placeholder="fa" value="'.esc_attr($mapping['language']).'" maxlength="10" dir="ltr" size="6"><button class="button button-small">Record Mapping</button></form>';
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

    // ------------------------------------------------------------------ channel settings

    private static function renderSettings():void{
        $library=new NotificationTemplateLibrary();
        echo '<h2>Channel settings</h2><p>WhatsApp, Email and SMS settings are listed in that order. Readiness here is local configuration only; it is not provider verification and it never starts a send.</p>';
        echo '<div class="dzn-notif-grid">';
        self::renderChannelCard('whatsapp','WhatsApp',array(
            'Telnyx WhatsApp and Meta WABA configuration (existing saved values are preserved).',
            'Provider template mapping is recorded in the WhatsApp template editor.',
            'Provider readiness: '.self::providerReadyLabel('whatsapp'),
        ),'dzn-integration-whatsapp','Open WhatsApp settings',array('tab'=>'templates','channel'=>'whatsapp'),'Open WhatsApp templates');
        self::renderChannelCard('email','Email',array(
            'Sender, SMTP and host-mail settings for account, security and operational email.',
            'Provider readiness: '.self::providerReadyLabel('email'),
        ),'dzn-integration-email','Open Email settings');
        self::renderChannelCard('sms','SMS',array(
            'SMS is a constrained fallback only; it is never a primary channel.',
            'Automatic SMS requires a verified terminal WhatsApp delivery failure for the same notification, plus valid SMS consent, suppression and policy eligibility.',
            'Provider readiness: '.self::providerReadyLabel('sms'),
        ),'dzn-integration-sms','Open SMS settings');
        echo '</div>';
        echo '<section class="dzn-notif-card"><h3>Twilio WhatsApp</h3><div class="notice notice-warning inline"><p><strong>Twilio WhatsApp is not implemented in this platform.</strong> No Twilio credentials are stored or read here, and no Twilio WhatsApp send can be enabled from this screen. The existing Telnyx WhatsApp and Meta WABA settings are preserved.</p></div></section>';
        self::renderChannelRules($library);
    }

    private static function renderChannelCard(string $channel,string $label,array $lines,string $route,string $routeLabel,?array $templateArgs=null,string $templateLabel=''):void{
        echo '<section class="dzn-notif-card"><h3>'.esc_html($label).'</h3><ul class="dzn-notif-list">';
        foreach($lines as $line)echo '<li>'.esc_html($line).'</li>';
        echo '</ul><p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page='.$route)).'">'.esc_html($routeLabel).'</a>';
        if($templateArgs!==null&&$templateLabel!=='')echo ' <a class="button button-small" href="'.esc_url(self::url(array_merge(array('tab'=>'templates'),$templateArgs))).'">'.esc_html($templateLabel).'</a>';
        echo '</p></section>';
    }

    private static function renderChannelRules(NotificationTemplateLibrary $library):void{
        echo '<h2>Delivery rules</h2><p>Email and Portal are the default channels for events that offer both. WhatsApp and SMS remain off unless this category policy, the event, recipient consent and provider readiness all permit them. Security, account and financial messages stay mandatory and cannot be disabled.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_channel_policy">';
        wp_nonce_field('dzn_notification_channel_policy');
        echo '<table class="widefat striped"><thead><tr><th>Category</th><th>Email</th><th>Portal</th><th>WhatsApp</th><th>SMS</th><th>SMS fallback after WhatsApp failure</th><th>Mandatory</th></tr></thead><tbody>';
        foreach($library->channelPolicies() as $category=>$policy){
            $locked=!empty($policy['mandatory']);
            echo '<tr><td><input type="hidden" name="policy['.esc_attr($category).'][present]" value="1"><strong>'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['label']??$category)).'</strong><br><span class="dzn-notif-meta">'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['description']??'')).'</span></td>';
            foreach(array('email','website','whatsapp','sms','sms_fallback') as $flag){
                $mandatoryDisabled=$locked&&in_array($flag,array('email','website'),true);
                $securityDisabled=($category==='security')&&in_array($flag,array('whatsapp','sms','sms_fallback'),true);
                echo '<td><input type="checkbox" name="policy['.esc_attr($category).']['.esc_attr($flag).']" value="1"'.(!empty($policy[$flag])?' checked':'').(($mandatoryDisabled||$securityDisabled)?' disabled':'').'></td>';
            }
            echo '<td>'.($locked?'<span class="dzn-badge is-locked">Locked</span>':'<span class="dzn-badge">Optional</span>').'</td></tr>';
        }
        echo '</tbody></table>';
        if(self::canEdit())echo '<p><button class="button button-primary">Save Channel Rules</button></p>';
        echo '</form>';
        echo '<details><summary><strong>Event → applicable channel map</strong></summary><table class="widefat striped"><thead><tr><th>Event</th><th>Category</th><th>Applicable channels</th><th>Delivery Authority</th><th>Consent Basis</th></tr></thead><tbody>';
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            $channels=$library->permittedChannels($eventKey);
            echo '<tr><td>'.esc_html((string)$event['label']).'<br><code dir="ltr">'.esc_html($eventKey).'</code></td><td>'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</td><td>'.esc_html(implode(', ',array_map(array(__CLASS__,'channelLabel'),$channels))).'</td><td>'.esc_html((string)(NotificationCatalogue::AUTHORITIES[(string)$event['authority']]??$event['authority'])).'</td><td>'.esc_html(self::consentLabel((string)$event['consent'])).'</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    // ------------------------------------------------------------------ diagnostics

    private static function renderDiagnostics():void{
        echo '<h2>Diagnostics</h2><p class="dzn-notif-note">“Handed off to provider” only means the email or messaging service accepted the request. “Delivery confirmed” is shown only when a verified provider receipt exists.</p>';
        $states=array();
        $report=null;
        if(current_user_can(self::CAP_VIEW)||current_user_can('manage_options')){
            try{$report=(new NotificationDiagnosticsReadService())->report();$states=(array)($report['counts']??array());}catch(\Throwable $error){echo '<div class="notice notice-error inline"><p>Notification service diagnostics could not be read.</p></div>';}
        }else{echo '<div class="notice notice-warning inline"><p>Viewing notification service diagnostics requires the <code>dzn_view_notification_authority</code> capability.</p></div>';}
        $subject=isset($_GET['subject'])&&is_scalar($_GET['subject'])?sanitize_key(wp_unslash((string)$_GET['subject'])):'';
        $subjectId=isset($_GET['subject_id'])?absint($_GET['subject_id']):0;
        if($subject!==''&&$subjectId>0)self::renderSubjectDetails($subject,$subjectId);
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
        self::renderSmsFallbackMonitoring();
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
            $recent=$inbox->recent(50);
            echo '<h3>Portal Notifications</h3><table class="widefat striped"><thead><tr><th>Audience</th><th>Total</th><th>Unread</th></tr></thead><tbody>';
            foreach(array('student'=>'Student','teacher'=>'Teacher','admin'=>'Admin') as $audience=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($summary[$audience]['total']??0)).'</td><td>'.esc_html((string)(int)($summary[$audience]['unread']??0)).'</td></tr>';
            echo '</tbody></table>';
            echo '<h4>Recent portal notifications</h4>';
            if($recent===array())echo '<p>No portal notification has been recorded yet.</p>';
            else{
                echo '<table class="widefat striped"><thead><tr><th>Created</th><th>Audience</th><th>Event</th><th>Title</th><th>Status</th></tr></thead><tbody>';
                foreach($recent as $row)echo '<tr><td><code dir="ltr">'.esc_html((string)$row['created_at']).'</code></td><td>'.esc_html(self::audienceLabel((string)$row['audience'])).' #'.esc_html((string)$row['recipient_id']).'</td><td><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td><td>'.esc_html((string)$row['title']).'</td><td>'.esc_html($row['read']?'Read':'Unread').'</td></tr>';
                echo '</tbody></table>';
            }
            echo '<p class="dzn-notif-note">Portal notifications are removed after '.esc_html((string)PortalNotificationInbox::RETENTION_DAYS).' days. Cleanup deletes only portal-notification rows and never changes class, payment or education history.</p>';
            if(self::canEdit()){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_inbox_prune">'.wp_nonce_field('dzn_notification_inbox_prune','_wpnonce',true,false).'<button class="button">Prune Expired Notifications</button></form> ';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_inbox_test">'.wp_nonce_field('dzn_notification_inbox_test','_wpnonce',true,false).'<button class="button">Record Admin Test Notification</button></form>';
                echo '<p class="dzn-notif-meta">The test notification is recorded for the admin list only; no email, WhatsApp or SMS message is sent.</p>';
            }
        }catch(\Throwable $error){echo '<div class="notice notice-warning inline"><p>The portal notification table is not ready. Check the database migration.</p></div>';}
        $issues=0;foreach((new NotificationTemplateLibrary())->catalogueRows(null,NotificationCatalogue::PRIMARY_LOCALE) as $row){if($row['issues'])$issues++;}
        echo '<h3>Template Configuration</h3><p>'.esc_html($issues===0?'All applicable primary-language templates have copy and an active version.':($issues.' applicable templates have configuration warnings.')).' <a href="'.esc_url(self::url(array('tab'=>'templates'))).'">Review templates</a></p>';
        if(current_user_can(NotificationRule::READ_CAPABILITY)||current_user_can('manage_options'))echo '<p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=dzn-communications')).'">Open the legacy communications health view</a></p>';
        if(current_user_can('dzn_operate_notification_dispatch')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_recover_leases">';
            wp_nonce_field('dzn_notification_recover_leases');
            echo '<p><button class="button">Safely recover expired leases</button> <span class="dzn-notif-meta">Releases only expired leases under the existing retry policy and creates no new message.</span></p></form>';
        }
    }

    /**
     * The operator view of the WhatsApp-first policy.  Each row links the original WhatsApp attempt to
     * the one permitted SMS fallback (or records the safe blocking reason).  No contact value, message
     * body, provider identifier or credential is ever rendered.
     */
    private static function renderSmsFallbackMonitoring():void{
        $report=(new NotificationSmsFallbackReadService())->report(50);
        $rows=(array)($report['rows']??array());
        $counts=(array)($report['counts']??array());
        echo '<h3>WhatsApp → SMS fallback</h3>';
        echo '<p class="dzn-notif-note">Automatic SMS is sent only after a verified terminal WhatsApp delivery failure. WhatsApp delivery pending, unknown, unconfigured or ineligible never triggers SMS. A later delivery cancels a queued fallback.</p>';
        echo '<p>';
        foreach(array(NotificationSmsFallbackReadService::ELIGIBLE=>'SMS fallback eligible',NotificationSmsFallbackReadService::SENT=>'SMS fallback sent',NotificationSmsFallbackReadService::DELIVERED=>'SMS delivered',NotificationSmsFallbackReadService::BLOCKED=>'SMS fallback blocked') as $key=>$label){
            echo '<span class="dzn-badge '.($key===NotificationSmsFallbackReadService::DELIVERED?'is-ok':($key===NotificationSmsFallbackReadService::BLOCKED?'is-warn':'')).'">'.esc_html($label.': '.(int)($counts[$key]??0)).'</span> ';
        }
        echo '</p>';
        if($rows===array()){echo '<p>No WhatsApp terminal failure has required an SMS fallback decision yet.</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>WhatsApp notification</th><th>WhatsApp attempt</th><th>WhatsApp state</th><th>SMS fallback</th><th>SMS state</th><th>Recorded</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td><code dir="ltr">#'.esc_html((string)$row['source_notification_id']).'</code></td>';
            echo '<td>'.($row['source_attempt_id']>0?'<code dir="ltr">#'.esc_html((string)$row['source_attempt_id']).'</code>':'—').'</td>';
            echo '<td>'.esc_html((string)$row['whatsapp_label']).'</td>';
            echo '<td>'.($row['fallback_notification_id']!==null?'<code dir="ltr">#'.esc_html((string)$row['fallback_notification_id']).'</code>':'—').'</td>';
            echo '<td>'.esc_html((string)$row['sms_label']).'</td>';
            echo '<td><code dir="ltr">'.esc_html((string)$row['recorded_at']).'</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="dzn-notif-meta">Labels are administrative only. Customer-facing notification content remains Persian and is unchanged by this view.</p>';
    }

    /** Compact delivery status for a booking request or class record, with a protected details link. */
    public static function renderRecordStatus(string $subject,int $subjectId):void{
        $subject=in_array($subject,array('booking_request','lesson'),true)?$subject:'';
        if($subject===''||$subjectId<1)return;
        $service=new NotificationDeliveryStatusReadService();
        $status=$subject==='booking_request'?$service->forBookingRequest($subjectId):$service->forLesson($subjectId);
        echo '<section class="dzn-notif-record-status"><h2>Notifications</h2>';
        echo '<p class="dzn-notif-meta">Shows notification records linked to this booking or class. Queued means the message is waiting for a worker. Handed off means the provider accepted the request; it is not proof of delivery. Delivery confirmed requires a verified provider receipt. Available in portal means an inbox entry was recorded, not that it was read.</p>';
        if($status['rows']===array()){echo '<p>No linked notification status is available for this record.</p></section>';return;}
        echo '<p>';
        foreach(array(NotificationDeliveryStatusReadService::QUEUED=>'Queued',NotificationDeliveryStatusReadService::HANDED_OFF=>'Handed off',NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED=>'Delivery confirmed',NotificationDeliveryStatusReadService::PORTAL_RECORDED=>'Available in portal',NotificationDeliveryStatusReadService::FAILED=>'Failed',NotificationDeliveryStatusReadService::CLOSED=>'Closed') as $key=>$label){
            $count=(int)($status['summary'][$key]??0);
            if($count>0)echo '<span class="dzn-badge '.($key===NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED?'is-ok':($key===NotificationDeliveryStatusReadService::FAILED?'is-warn':'')).'">'.esc_html($label.': '.$count).'</span> ';
        }
        echo '</p>';
        if(current_user_can(self::CAP_VIEW)||current_user_can('manage_options')){
            echo '<p><a class="button button-small" href="'.esc_url(self::url(array('tab'=>'diagnostics','subject'=>$subject,'subject_id'=>$subjectId))).'">Inspect notification details</a></p>';
        }
        echo '</section>';
    }

    private static function renderSubjectDetails(string $subject,int $subjectId):void{
        if(!in_array($subject,array('booking_request','lesson'),true))return;
        if(!current_user_can(self::CAP_VIEW)&&!current_user_can('manage_options'))return;
        $service=new NotificationDeliveryStatusReadService();
        $status=$subject==='booking_request'?$service->forBookingRequest($subjectId):$service->forLesson($subjectId);
        echo '<h3>Delivery records for '.esc_html($subject==='booking_request'?'booking request':'class').' #'.esc_html((string)$subjectId).'</h3>';
        if($status['rows']===array()){echo '<p>No delivery record is available.</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Source</th><th>State</th><th>Operator meaning</th><th>Attempts</th><th>Handed off</th><th>Delivery confirmed</th><th>Last error</th><th>Updated</th></tr></thead><tbody>';
        foreach($status['rows'] as $row){
            echo '<tr><td><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td><td>'.esc_html((string)$row['source']).'</td><td><code dir="ltr">'.esc_html((string)$row['state']).'</code></td><td>'.esc_html(self::deliveryCategoryLabel((string)$row['category'])).'</td><td>'.esc_html((string)$row['attempt_count']).'</td><td>'.esc_html((string)($row['handed_off_at']??'—')).'</td><td>'.esc_html((string)($row['delivery_confirmed_at']??'—')).'</td><td>'.esc_html((string)($row['last_error_code']??'—')).'</td><td><code dir="ltr">'.esc_html((string)$row['updated_at']).'</code></td></tr>';
        }
        echo '</tbody></table>';
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
    },array('nonce'=>'dzn_notification_provider_mapping','tab'=>'templates'));}

    public static function saveChannelPolicy():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveChannelPolicy((array)($input['policy']??array()));
        return array('type'=>'success','message'=>'Channel rules saved.');
    },array('nonce'=>'dzn_notification_channel_policy','tab'=>'settings','event_required'=>false));}

    public static function pruneInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $deleted=(new PortalNotificationInbox())->prune();
        return array('type'=>'success','message'=>$deleted.' expired notifications pruned.');
    },array('nonce'=>'dzn_notification_inbox_prune','tab'=>'diagnostics','event_required'=>false));}

    public static function testInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $id=PortalNotificationRecorder::admin('system.inbox_check.admin','admin_test',(int)get_current_user_id(),array('when'=>'Test sample'),null);
        return $id===null?array('type'=>'error','message'=>'The test notification could not be recorded. Check the portal template configuration.'):array('type'=>'success','message'=>'The test notification was recorded in the admin list only. No email, WhatsApp or SMS message was sent.');
    },array('nonce'=>'dzn_notification_inbox_test','tab'=>'diagnostics','event_required'=>false));}

    public static function recoverLeases():never{
        if(!current_user_can('dzn_operate_notification_dispatch')&&!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('dzn_notification_recover_leases');
        try{
            $result=(new NotificationDispatchService())->recoverExpiredLeases(array(
                'evidence_channel'=>'staff_record','evidence_reference'=>'admin-recover:'.get_current_user_id().':'.time(),'evidence_at'=>NotificationSupport::now(),
            ),'admin-recover-leases:'.gmdate('YmdHi'));
            self::flash('success','Recovery complete: '.$result['recovered'].' re-armed, '.$result['closed'].' closed.');
        }catch(\Throwable $error){self::flash('error','Recovery failed: '.$error->getMessage());}
        self::redirectBack('diagnostics');
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
        if($event!==null&&$input['channel']!==''&&!(new NotificationTemplateLibrary())->channelPermitted($input['event_key'],$input['channel'])&&in_array($input['channel'],array('email','whatsapp','sms','website'),true)){
            self::flash('error','That delivery channel is disabled by the category policy.');
            self::redirectBack('templates',array('template'=>$input['event_key'],'channel'=>$input['channel'],'locale'=>$input['locale']));
        }
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
    private static function canEditTeacherInvitation():bool{return current_user_can('manage_options')||current_user_can('dzn_manage_provider_integrations');}
    private static function renderDedicatedAuthorityLink():void{
        if(!self::canEditTeacherInvitation())return;
        echo '<hr><h3>Dedicated secure authority</h3><p class="dzn-notif-note">Teacher invitations use a separate one-time-secret delivery authority. <a href="'.esc_url(admin_url('admin.php?page=dzn-communications-templates')).'">Open the Teacher invitation template</a>. The secret is never copied into the general template library or a preview.</p>';
    }

    /** Local provider configuration only; never a live send or provider verification. */
    private static function providerReady(string $channel):?bool{
        if(!in_array($channel,array('email','whatsapp','sms'),true))return null;
        try{return (new IntegrationSettingsService())->ready($channel);}catch(\Throwable){return false;}
    }
    private static function providerReadyLabel(string $channel):string{
        $ready=self::providerReady($channel);
        return $ready===null?'Not applicable':($ready?'Configured (unverified)':'Not configured');
    }

    private static function excerpt(string $text,int $length):string{
        $text=trim(preg_replace('/\s+/u',' ',$text));
        return mb_strlen($text)<=$length?$text:mb_substr($text,0,$length).'…';
    }
    private static function deliveryCategoryLabel(string $category):string{return array(
        NotificationDeliveryStatusReadService::QUEUED=>'Queued',
        NotificationDeliveryStatusReadService::HANDED_OFF=>'Handed off to provider — delivery not confirmed',
        NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED=>'Delivery confirmed by provider receipt',
        NotificationDeliveryStatusReadService::PORTAL_RECORDED=>'Available in portal — read state is separate',
        NotificationDeliveryStatusReadService::FAILED=>'Failed',
        NotificationDeliveryStatusReadService::CLOSED=>'Closed',
    )[$category]??$category;}

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
    private static function providerStateLabel(string $state):string{return array(
        'not_mapped'=>'Not mapped','approval_required'=>'Meta re-approval required','mapped'=>'Mapped',
        'restricted_fallback'=>'Restricted fallback','not_applicable'=>'Not applicable','unsupported'=>'Unsupported',
        'not_permitted'=>'Not offered by category policy',
    )[$state]??$state;}
    private static function stateLabel(string $state):string{return array('draft'=>'Draft','active'=>'Active','superseded'=>'Superseded','retired'=>'Retired')[$state]??$state;}
    private static function sourceLabel(string $source):string{return array('authored'=>'Authored','rollback'=>'Rollback','default'=>'Default copy','published'=>'Published')[$source]??($source!==''?$source:'—');}
    private static function consentLabel(string $consent):string{return array(
        'security'=>'Security requirement; no marketing consent','operational'=>'Operational message based on the teaching relationship',
        'financial'=>'Financial message; requires legal review','marketing'=>'Marketing consent only',
    )[$consent]??$consent;}
    private static function audienceLabel(string $audience):string{return array('student'=>'Student','teacher'=>'Teacher','admin'=>'Admin')[$audience]??$audience;}
}
