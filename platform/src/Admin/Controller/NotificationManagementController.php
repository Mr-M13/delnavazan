<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationEventReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSmsFallbackReadService;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Integrations\Notifications\EmailAppearance;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateValidationException;
use Delnavazan\Platform\Portals\PortalNotificationInbox;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;

/**
 * Notifications — one capability-checked, nonce-protected communications workspace.
 *
 * The event catalogue is the working surface: each business notification shows its actual channel
 * availability, copy readiness and delivery evidence together. Diagnostics live only in Settings →
 * Diagnostics; the old tab URLs redirect there so bookmarks do not create a second source of truth.
 * Every mutation is a POST/redirect/GET with server-side validation and every read is escaped.
 * The page can never change a recipient, authorise a payment, modify a lesson or bypass the business
 * authority that produced a notification.
 */
final class NotificationManagementController {
    public const PAGE='dzn-notifications';
    public const CAP_EDIT='dzn_manage_notification_templates';
    public const CAP_VIEW=NotificationRule::READ_CAPABILITY;
    private const FLASH='dzn_notification_flash_';

    /** Old bookmarked tab names map to their new home instead of becoming dead URLs. */
    private const LEGACY_TABS=array('rules'=>'events','channels'=>'events','settings'=>'events','templates'=>'events');

    public static function register():void{
        add_action('admin_init',static function():void{NotificationTemplateLibrary::retireLegacyCategoryPolicy();});
        add_action('admin_post_dzn_notification_copy_save_draft',array(__CLASS__,'saveDraft'));
        add_action('admin_post_dzn_notification_copy_publish',array(__CLASS__,'publish'));
        add_action('admin_post_dzn_notification_copy_discard',array(__CLASS__,'discardDraft'));
        add_action('admin_post_dzn_notification_copy_restore',array(__CLASS__,'restoreVersion'));
        add_action('admin_post_dzn_notification_copy_restore_default',array(__CLASS__,'restoreDefault'));
        add_action('admin_post_dzn_notification_provider_mapping',array(__CLASS__,'saveProviderMapping'));
        add_action('admin_post_dzn_notification_event_toggle',array(__CLASS__,'toggleEvent'));
        add_action('admin_post_dzn_notification_event_channel_toggle',array(__CLASS__,'toggleEventChannel'));
        add_action('admin_post_dzn_notification_email_appearance',array(__CLASS__,'saveEmailAppearance'));
        add_action('admin_post_dzn_notification_inbox_prune',array(__CLASS__,'pruneInbox'));
        add_action('admin_post_dzn_notification_inbox_test',array(__CLASS__,'testInbox'));
        add_action('admin_post_dzn_notification_recover_leases',array(__CLASS__,'recoverLeases'));
    }

    public static function screen():void{
        self::authorizeView();
        $requested=isset($_GET['tab'])&&is_scalar($_GET['tab'])?sanitize_key(wp_unslash((string)$_GET['tab'])):'events';
        if(in_array($requested,array('diagnostics','monitoring','website'),true)){
            wp_safe_redirect(admin_url('admin.php?page=dzn-system-health&component=notifications'));
            exit;
        }
        $tab=self::LEGACY_TABS[$requested]??$requested;
        if($tab!=='events')$tab='events';
        $templateKey=isset($_GET['template'])?self::eventKey(wp_unslash((string)$_GET['template'])):'';
        if($templateKey!==''){
            $channel=isset($_GET['channel'])?sanitize_key(wp_unslash((string)$_GET['channel'])):'email';
            $locale=isset($_GET['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_GET['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
            $library=new NotificationTemplateLibrary();
            echo '<div class="wrap dzn-notif-admin" dir="ltr">';
            self::renderFlash();
            if(NotificationCatalogue::get($templateKey)===null){echo '<div class="notice notice-error inline"><p>Notification event not found.</p></div></div>';return;}
            self::renderEditor($library,$templateKey,$channel,$locale);
            echo '</div>';
            return;
        }
        echo '<div class="wrap dzn-notif-admin" dir="ltr"><style>.dzn-notif-admin{direction:ltr}.dzn-notif-admin .nav-tab-wrapper{margin-block-end:16px}.dzn-notif-lede{max-width:78ch;color:#50575e}.dzn-notif-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px}.dzn-notif-panel h2{margin-block-start:6px}.dzn-notif-meta{color:#646970;font-size:12px;line-height:1.9}.dzn-notif-note{color:#50575e;font-size:12px;line-height:1.9;background:#f6f7f7;border-inline-start:3px solid #0E8C8C;padding:8px 12px;border-radius:3px}.dzn-notif-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-block:14px}.dzn-notif-card{border:1px solid #dcdcde;border-radius:6px;padding:14px}.dzn-notif-card h3{margin-block-start:0}.dzn-notif-list{margin:0;padding-inline-start:18px;line-height:2}.dzn-notif-table td,.dzn-notif-table th{vertical-align:top}.dzn-notif-table details{margin-block:4px}.dzn-notif-table details pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;line-height:1.9;margin:6px 0;max-height:180px;overflow:auto}.dzn-notif-preview{background:#FBF7EE;border:1px solid #e6dfd2;border-radius:8px;padding:10px}.dzn-notif-preview pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;direction:rtl;margin:0;line-height:2}.dzn-notif-preview--text{background:#fff}.dzn-notif-editor textarea{font-family:Tahoma,Arial,sans-serif;line-height:2;direction:rtl}.dzn-notif-actions{display:flex;gap:8px;flex-wrap:wrap}.dzn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a}.dzn-badge.is-ok{background:#edfaef;color:#1e6b2f}.dzn-badge.is-warn{background:#fcf3e6;color:#8a4b00}.dzn-badge.is-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-inline-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.dzn-notif-admin code{direction:ltr;unicode-bidi:embed}.dzn-notif-admin details pre{max-height:220px;overflow:auto;white-space:pre-wrap}.dzn-notif-record-status{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin-block:18px}.dzn-notif-record-status h2{margin-block-start:0}.dzn-channel-strip{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.dzn-channel-strip a{text-decoration:none}</style><h1>Notifications</h1>';
        echo '<p class="dzn-notif-lede">Choose which notifications are sent and edit their copy. Provider configuration is under Integrations; detailed delivery evidence is under Diagnostics. This workspace never changes a recipient, authorises a payment, alters a lesson or bypasses the business authority that produced the notification.</p>';
        self::renderFlash();
        if($requested!==$tab){
            $legacy=array('rules'=>'Delivery rules','channels'=>'Channel settings','monitoring'=>'Delivery monitoring','website'=>'Portal notifications','settings'=>'Channel settings');
            echo '<div class="notice notice-info inline"><p>'.esc_html((string)($legacy[$requested]??'That view')).' now lives in the Notifications event workspace. The old URL still works.</p></div>';
        }
        echo '<div class="dzn-notif-panel">';
        self::renderEvents();
        echo '</div></div>';
    }

    // ------------------------------------------------------------------ event workspace

    private static function renderEvents():void{
        $library=new NotificationTemplateLibrary();
        $latest=(new NotificationEventReadService())->latestByEvent();
        $eventFilter=isset($_GET['event'])&&is_scalar($_GET['event'])?sanitize_text_field(wp_unslash((string)$_GET['event'])):'';
        $channelFilter=isset($_GET['channel'])&&is_scalar($_GET['channel'])?sanitize_key(wp_unslash((string)$_GET['channel'])):'';
        $taskFilter=isset($_GET['task'])&&is_scalar($_GET['task'])?sanitize_key(wp_unslash((string)$_GET['task'])):'';
        $audienceFilter=isset($_GET['audience'])&&is_scalar($_GET['audience'])?sanitize_key(wp_unslash((string)$_GET['audience'])):'';
        $readinessFilter=isset($_GET['readiness'])&&is_scalar($_GET['readiness'])?sanitize_key(wp_unslash((string)$_GET['readiness'])):'';
        $problemFilter=isset($_GET['problem'])?sanitize_key(wp_unslash((string)$_GET['problem'])):'';
        echo '<h2>Notification catalogue</h2>';
        echo '<p>Every business notification is managed here. Each row shows the four delivery methods, their real delivery state and the copy that belongs to that exact event. A channel is never described as ready unless its local copy and provider requirements are satisfied.</p>';
        self::renderChannelHealth();
        self::renderEmailAppearance();
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="events">';
        echo '<label>Task <select name="task"><option value="">All tasks</option>';
        foreach(NotificationCatalogue::TASKS as $value=>$task)echo '<option value="'.esc_attr($value).'"'.($taskFilter===$value?' selected':'').'>'.esc_html((string)$task['label']).'</option>';
        echo '</select></label><label>Event <input name="event" value="'.esc_attr($eventFilter).'" placeholder="event key or purpose"></label>';
        echo '<label>Channel <select name="channel"><option value="">All</option>';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channelFilter===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Audience <select name="audience"><option value="">All</option>';
        foreach(array('student'=>'Student','teacher'=>'Teacher') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($audienceFilter===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Template readiness <select name="readiness"><option value="">All</option><option value="ready"'.($readinessFilter==='ready'?' selected':'').'>Ready</option><option value="draft"'.($readinessFilter==='draft'?' selected':'').'>Draft</option><option value="missing"'.($readinessFilter==='missing'?' selected':'').'>Copy missing</option><option value="provider"'.($readinessFilter==='provider'?' selected':'').'>Provider attention</option><option value="disabled"'.($readinessFilter==='disabled'?' selected':'').'>Channel disabled</option></select></label><label>Action needed <select name="problem"><option value="">All</option><option value="needs_action"'.($problemFilter==='needs_action'?' selected':'').'>Needs action</option><option value="failed"'.($problemFilter==='failed'?' selected':'').'>Failed delivery</option><option value="disabled"'.($problemFilter==='disabled'?' selected':'').'>Disabled</option><option value="ready"'.($problemFilter==='ready'?' selected':'').'>Ready</option></select></label><button class="button">Filter</button> <a class="button-link" href="'.esc_url(self::url(array('tab'=>'events'))).'">Reset</a></form>';

        $rows=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            if($eventFilter!==''&&stripos($eventKey.' '.(string)($event['label']??''),$eventFilter)===false)continue;
            $taskKey=NotificationCatalogue::taskKey($eventKey);
            if($taskFilter!==''&&$taskFilter!==$taskKey)continue;
            if($audienceFilter!==''&&(string)($event['audience']??'')!==$audienceFilter)continue;
            $offered=(array)($event['channels']??array());
            if($channelFilter!==''&&!in_array($channelFilter,$offered,true))continue;
            $enabled=$library->eventEnabled($eventKey);
            $mandatory=NotificationCatalogue::isMandatory($eventKey);
            $issues=array();$providerIssues=array();
            $readiness=self::readiness($library,$eventKey,$offered);
            foreach($offered as $channel){
                $status=$library->status($eventKey,$channel,NotificationCatalogue::PRIMARY_LOCALE);
                foreach((array)$status['issues'] as $issue)$issues[]=(string)$issue;
                $provider=self::providerReady($channel);
                if($provider===false)$providerIssues[]=self::channelLabel($channel).' provider is not configured.';
            }
            $issues=array_values(array_unique(array_merge($issues,$providerIssues)));
            $last=$latest[$eventKey]??null;
            $needs=!$enabled||$issues!==array()||($last&&($last['state']===NotificationDeliveryStatusReadService::FAILED));
            if($problemFilter==='needs_action'&&!$needs)continue;
            if($problemFilter==='failed'&&(!$last||$last['state']!==NotificationDeliveryStatusReadService::FAILED))continue;
            if($problemFilter==='disabled'&&$enabled)continue;
            if($problemFilter==='ready'&&$needs)continue;
            if($readinessFilter!==''&&$readiness!==$readinessFilter)continue;
            $rows[]=array('key'=>$eventKey,'event'=>$event,'task'=>$taskKey,'offered'=>$offered,'enabled'=>$enabled,'mandatory'=>$mandatory,'issues'=>$issues,'last'=>$last,'needs'=>$needs,'readiness'=>$readiness);
        }
        usort($rows,static fn(array $left,array $right):int=>array_search($left['task'],array_keys(NotificationCatalogue::TASKS),true)<=>array_search($right['task'],array_keys(NotificationCatalogue::TASKS),true) ?: strcmp($left['key'],$right['key']));
        if($rows===array()){echo '<p>No notification event matches these filters.</p>';return;}
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' event(s) match. Delivery controls apply to each event; consent and legally required delivery safeguards remain enforced.</p>';
        echo '<table class="widefat striped dzn-notif-table"><thead><tr><th>Business task</th><th>Purpose</th><th>Recipient</th><th>Delivery channels</th><th>Template readiness</th><th>Latest delivery</th><th>Action needed</th><th>Actions</th></tr></thead><tbody>';
        foreach($rows as $row){
            $eventKey=$row['key'];$event=$row['event'];$last=$row['last'];
            $task=NotificationCatalogue::taskForEvent($eventKey)??array('label'=>$row['task'],'description'=>'');
            echo '<tr><td><strong>'.esc_html((string)$task['label']).'</strong><br><span class="dzn-notif-meta">'.esc_html((string)$task['description']).'</span></td><td><strong>'.esc_html((string)($event['label']??$eventKey)).'</strong><br><code dir="ltr">'.esc_html($eventKey).'</code><br><span class="dzn-notif-meta">Delivery policy: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)($event['category']??'')]['label']??'')).'</span></td>';
            echo '<td>'.esc_html(self::audienceLabel((string)($event['audience']??''))).'</td><td>';
            foreach(array('website','email','whatsapp','sms') as $channel){
                if(!in_array($channel,$row['offered'],true)){echo '<div><span class="dzn-notif-meta">'.esc_html(self::channelLabel($channel)).': not supported</span></div>';continue;}
                $active=$library->eventChannelEnabled($eventKey,$channel);$locked=(NotificationCatalogue::isMandatory($eventKey)&&in_array($channel,array('email','website'),true))||($eventKey==='account.claim_issued.student'&&in_array($channel,array('whatsapp','sms'),true));
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_event_channel_toggle"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="enabled" value="'.($active?'0':'1').'">';wp_nonce_field('dzn_notification_event_channel_toggle');echo '<span class="dzn-badge '.($active?'is-ok':'is-locked').'">'.esc_html(self::channelLabel($channel)).'</span>';if(self::canEdit()&&!$locked)echo ' <button class="button button-small">'.($active?'Off':'On').'</button>';echo ' <a class="button button-small" href="'.esc_url(self::url(array('template'=>$eventKey,'channel'=>$channel,'locale'=>NotificationCatalogue::PRIMARY_LOCALE))).'">'.(self::canEdit()?'Edit':'View').'</a></form>';
            }
            echo '<div class="dzn-notif-meta">SMS is fallback-only after a verified terminal WhatsApp failure.</div></td><td>';
            if($row['readiness']==='ready')echo '<span class="dzn-badge is-ok">Ready</span>';
            elseif($row['readiness']==='draft')echo '<span class="dzn-badge is-warn">Draft awaiting publication</span>';
            elseif($row['readiness']==='disabled')echo '<span class="dzn-badge is-warn">A channel is disabled</span>';
            else{echo '<span class="dzn-badge is-warn">'.esc_html((string)count($row['issues'])).' issue(s)</span><ul class="dzn-notif-list">';foreach($row['issues'] as $issue)echo '<li>'.esc_html($issue).'</li>';echo '</ul>';}
            echo '</td><td>';
            if($last){
                $class=$last['state']===NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED?'is-ok':($last['state']===NotificationDeliveryStatusReadService::FAILED?'is-locked':'is-warn');
                echo '<span class="dzn-badge '.esc_attr($class).'">'.esc_html((string)$last['label']).'</span><br><span class="dzn-notif-meta">'.esc_html((string)$last['at']).'</span>';
            }else echo '<span class="dzn-notif-meta">No delivery record yet</span>';
            echo '</td><td>';
            if(!$row['enabled'])echo 'Event is disabled.';
            elseif($last&&$last['state']===NotificationDeliveryStatusReadService::FAILED)echo 'Open Diagnostics to review the delivery evidence and the owning record workflow.';
            elseif($row['issues']!==array())echo esc_html((string)$row['issues'][0]);
            else echo 'None.';
            echo '</td><td>';
            echo '<a class="button button-small" href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=notifications')).'">Open diagnostics</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** A small channel-readiness strip with links to the owning configuration/evidence page. */
    private static function renderChannelHealth():void{
        echo '<div class="dzn-channel-strip" aria-label="Channel health">';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS') as $channel=>$label){
            $ready=self::providerReady($channel);
            $state=$ready===true?'Healthy':($ready===false?'Attention needed':'Unavailable');
            $class=$ready===true?'is-ok':'is-warn';
            echo '<a href="'.esc_url(admin_url('admin.php?page=dzn-integrations')).'"><span class="dzn-badge '.esc_attr($class).'">'.esc_html($label).': '.esc_html($state).'</span></a> ';
        }
        echo '<a href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=portal')).'"><span class="dzn-badge is-ok">Portal: Healthy</span></a> ';
        echo '<span class="dzn-notif-meta">Local readiness only; provider working state is verified in Integrations.</span></div>';
    }

    // Legacy list renderer retained only for backwards-compatible source contracts. The visible
    // interface is the catalogue above; all edit links land directly on the single-event editor.
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
        $taskFilter=isset($_GET['task'])&&is_scalar($_GET['task'])?sanitize_key(wp_unslash((string)$_GET['task'])):'';
        echo '<h2>Templates</h2><p>Each event is shown once under its business task, with recipient-specific copy and only the delivery choices that its policy, event and provider state permit. Email and Portal are the defaults for events that support both.</p>';
        self::renderEmailAppearance();
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="templates">';
        echo '<label>Task <select name="task"><option value="">All tasks</option>';
        foreach(NotificationCatalogue::TASKS as $value=>$task)echo '<option value="'.esc_attr($value).'"'.($taskFilter===$value?' selected':'').'>'.esc_html((string)$task['label']).'</option>';
        echo '</select></label><label>Channel <select name="channel"><option value="">All</option>';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channel===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Language <select name="locale"><option value="fa-IR"'.($locale==='fa-IR'?' selected':'').'>Persian (fa-IR)</option><option value="en"'.($locale==='en'?' selected':'').'>English (en)</option></select></label><button class="button">Filter</button></form>';
        $rows=$library->catalogueRows($channel===''?null:$channel,$locale);
        $groups=array();
        foreach($rows as $row){
            $eventKey=(string)$row['event_key'];
            if($taskFilter!==''&&NotificationCatalogue::taskKey($eventKey)!==$taskFilter)continue;
            $groups[$eventKey][]=$row;
        }
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' applicable templates across '.esc_html((string)count($groups)).' events. Default copy is always recoverable and published versions are immutable.</p>';
        if(!self::canEdit())echo '<p class="dzn-notif-note">You have read-only access. Editing and publishing require the <code>dzn_manage_notification_templates</code> capability.</p>';
        if($groups===array()){echo '<p>No template matches this channel and language filter.</p>';return;}
        echo '<table class="widefat striped dzn-notif-table"><thead><tr><th>Business task</th><th>Notification</th><th>Copy</th><th>Delivery choices</th><th>Readiness</th><th>Actions</th></tr></thead><tbody>';
        foreach($groups as $eventKey=>$eventRows){
            $event=NotificationCatalogue::get($eventKey)??array();
            $task=NotificationCatalogue::taskForEvent($eventKey)??array('label'=>'Support');
            echo '<tr><td><strong>'.esc_html((string)$task['label']).'</strong><br><span class="dzn-notif-meta">Delivery policy: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)($event['category']??'')]['label']??($event['category']??''))).'</span></td><td><strong>'.esc_html((string)($event['label']??$eventKey)).'</strong><br><code dir="ltr">'.esc_html($eventKey).'</code><br><span class="dzn-notif-meta">Recipient: '.esc_html(self::audienceLabel((string)($event['audience']??''))).'</span></td><td>';
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

    /** One global HTML-email wrapper. WhatsApp, SMS and portal copy stay text/provider templates. */
    private static function renderEmailAppearance():void{
        $values=EmailAppearance::values();
        echo '<details class="dzn-notif-card"><summary><strong>Global email appearance</strong></summary>';
        echo '<p>Logo, font and shared footer layout for every HTML email. This changes only the shared email wrapper; it never changes WhatsApp, SMS or portal text, and it never changes per-event wording.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_email_appearance">';
        wp_nonce_field('dzn_notification_email_appearance');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="dzn-email-logo">Logo URL</label></th><td><input id="dzn-email-logo" class="large-text" name="logo_url" value="'.esc_attr((string)$values['logo_url']).'" placeholder="https://…"><p class="description">Current approved Delnavazan logo. HTTPS only; the theme logo is the default.</p></td></tr>';
        echo '<tr><th><label for="dzn-email-font">Email font</label></th><td><select id="dzn-email-font" name="font">';
        foreach(array('tahoma'=>'Tahoma / Segoe UI / Arial','vazirmatn'=>'Vazirmatn with safe fallbacks','system'=>'System UI') as $key=>$label)echo '<option value="'.esc_attr($key).'"'.($values['font']===$key?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select><p class="description">The font is applied only inside the HTML email wrapper. WhatsApp and SMS are text/provider templates and are not affected.</p></td></tr>';
        echo '<tr><th><label for="dzn-email-footer">Shared footer note</label></th><td><input id="dzn-email-footer" class="large-text" maxlength="240" name="footer_note" value="'.esc_attr((string)$values['footer_note']).'"><p class="description">Optional one-line note added to every HTML email.</p></td></tr>';
        echo '</tbody></table>';
        if(self::canEdit())echo '<p><button class="button button-primary">Save email appearance</button></p>';
        echo '</form></details>';
    }

    private static function renderEditor(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale):void{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return;
        $status=$library->status($eventKey,$channel,$locale);
        $working=$library->workingCopy($eventKey,$channel,$locale);
        $offered=$library->channelOffered($eventKey,$channel);
        $enabled=$library->eventChannelEnabled($eventKey,$channel);
        $isDedicated=(string)$event['authority']==='dedicated';
        $editable=self::canEdit()&&!$isDedicated&&$offered;
        echo '<p><a href="'.esc_url(self::url(array('tab'=>'events'))).'">‹ Back to notification catalogue</a></p>';
        $task=NotificationCatalogue::taskForEvent($eventKey)??array('label'=>'Support');
        echo '<h2>'.esc_html((string)$event['label']).'</h2><p class="dzn-notif-meta"><code dir="ltr">'.esc_html($eventKey).'</code> · '.esc_html(self::channelLabel($channel)).' · '.esc_html($locale).' · Task: '.esc_html((string)$task['label']).' · Delivery policy: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</p>';
        echo '<p class="dzn-notif-actions">';
        foreach((array)$event['channels'] as $availableChannel)echo '<a class="button button-small'.($availableChannel===$channel?' button-primary':'').'" href="'.esc_url(self::url(array('template'=>$eventKey,'channel'=>$availableChannel,'locale'=>$locale))).'">'.esc_html(self::channelLabel($availableChannel)).'</a> ';
        foreach(NotificationCatalogue::SUPPORTED_LOCALES as $availableLocale)echo '<a class="button button-small'.($availableLocale===$locale?' button-primary':'').'" href="'.esc_url(self::url(array('template'=>$eventKey,'channel'=>$channel,'locale'=>$availableLocale))).'">'.esc_html($availableLocale).'</a> ';
        echo '</p>';
        if(!$offered)echo '<div class="notice notice-warning inline"><p>This channel is not supported by this event and cannot be edited or sent.</p></div>';
        elseif(!$enabled)echo '<div class="notice notice-info inline"><p>This channel is currently disabled for this notification. You may prepare and publish its copy, but it cannot send until it is enabled from the catalogue.</p></div>';
        echo '<div class="dzn-notif-grid">';
        echo '<section class="dzn-notif-card"><h3>Variable Contract</h3><p>Allowed placeholders:</p><p>';
        foreach(NotificationCatalogue::variables($eventKey) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p>Required placeholders for this channel:</p><p>';
        foreach(NotificationTemplateSanitizer::requiredVariables($eventKey,$channel) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p class="dzn-notif-note">Copy is stored as plain text. The system supplies the HTML structure and brand presentation. Scripts, PHP, HTML tags and browser event handlers are rejected. Previews use synthetic values and never reveal a one-time secret.</p></section>';
        echo '<section class="dzn-notif-card"><h3>Status</h3><ul class="dzn-notif-list">';
        echo '<li>Delivery choice: '.esc_html($offered?($enabled?'Enabled':'Disabled'):'Not supported').'</li>';
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
        if(($mapping['source']??'')==='discovery'){
            echo '<p class="dzn-notif-meta">This mapping comes from the verified Telnyx template discovery record. Refresh or correct provider discovery instead of maintaining a duplicate manual mapping here.</p>';
            return;
        }
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

    // ------------------------------------------------------------------ diagnostics

    private static function renderDiagnostics():void{
        echo '<h2>Delivery diagnostics have moved</h2>';
        echo '<p class="dzn-notif-note">Detailed delivery traces, queue counts, provider evidence and redacted support bundles now live in Settings → Diagnostics. This old bookmarked view remains reachable and no longer duplicates those tables.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=notifications')).'">Open Diagnostics → Notifications</a></p>';
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
            echo '<p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=notifications&subject='.$subject.'&subject_id='.$subjectId)).'">Inspect notification details</a></p>';
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
    },array('nonce'=>'dzn_notification_provider_mapping','tab'=>'events'));}

    public static function toggleEvent():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_event_toggle');
        $eventKey=isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'';
        $enabled=isset($_POST['enabled'])&&(string)$_POST['enabled']==='1';
        try{
            (new NotificationTemplateLibrary())->saveEventEnabled($eventKey,$enabled);
            self::flash('success',$enabled?'Optional notification event enabled.':'Optional notification event disabled.');
        }catch(\Throwable $error){self::flash('error',self::eventToggleError($error->getMessage()));}
        self::redirectBack('events');
    }

    public static function toggleEventChannel():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_event_channel_toggle');
        $eventKey=isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'';
        $channel=isset($_POST['channel'])?sanitize_key(wp_unslash((string)$_POST['channel'])):'';
        $enabled=isset($_POST['enabled'])&&(string)$_POST['enabled']==='1';
        try{(new NotificationTemplateLibrary())->saveEventChannel($eventKey,$channel,$enabled);self::flash('success',self::channelLabel($channel).' delivery '.($enabled?'enabled.':'disabled.'));}
        catch(\Throwable $error){self::flash('error','This delivery channel is required for the notification safeguard or is not available for this event.');}
        self::redirectBack('events');
    }

    public static function saveEmailAppearance():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_email_appearance');
        try{
            EmailAppearance::save(array(
                'logo_url'=>isset($_POST['logo_url'])?wp_unslash((string)$_POST['logo_url']):'',
                'font'=>isset($_POST['font'])?sanitize_key(wp_unslash((string)$_POST['font'])):EmailAppearance::DEFAULT_FONT,
                'footer_note'=>isset($_POST['footer_note'])?wp_unslash((string)$_POST['footer_note']):'',
            ));
            self::flash('success','Global email appearance saved. WhatsApp, SMS and portal text are unchanged.');
        }catch(\Throwable $error){self::flash('error','The email appearance was not saved. Check the logo URL and try again.');}
        self::redirectBack('events');
    }

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
        if($eventRequired&&$event===null){self::flash('error','Unknown notification event.');self::redirectBack('events');}
        if($event!==null&&(string)$event['authority']==='dedicated'&&in_array($input['channel'],array('email','whatsapp','sms'),true)){self::flash('error','This message belongs to a dedicated secure delivery authority and is not edited from this page.');self::redirectBack('events');}
        if($event!==null&&$input['channel']!==''&&!(new NotificationTemplateLibrary())->channelOffered($input['event_key'],$input['channel'])&&in_array($input['channel'],array('email','whatsapp','sms','website'),true)){
            self::flash('error','That delivery channel is not supported by this notification.');
            self::redirectBack('events',array('template'=>$input['event_key'],'channel'=>$input['channel'],'locale'=>$input['locale']));
        }
        try{
            $result=$operation(new NotificationTemplateLibrary(),$input);
            self::flash((string)($result['type']??'success'),(string)($result['message']??'Done.'));
        }catch(NotificationTemplateValidationException $error){
            self::flash('error',implode(' ',$error->reasons()));
        }catch(\Throwable $error){
            self::flash('error','Save failed: '.$error->getMessage());
        }
        $tab=(string)($options['tab']??'events');
        $args=array();
        if($tab==='events'&&$input['event_key']!==''){$args=array('template'=>$input['event_key'],'channel'=>$input['channel'],'locale'=>$input['locale']);}
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

    /** A single, filterable readiness state for the catalogue row. */
    private static function readiness(NotificationTemplateLibrary $library,string $eventKey,array $channels):string{
        $hasDraft=false;$hasDisabled=false;$hasMissing=false;$hasProvider=false;
        foreach($channels as $channel){
            $status=$library->status($eventKey,(string)$channel,NotificationCatalogue::PRIMARY_LOCALE);
            if(!$library->eventChannelEnabled($eventKey,(string)$channel))$hasDisabled=true;
            if($status['draft']!==null)$hasDraft=true;
            foreach((array)$status['issues'] as $issue){
                $issue=(string)$issue;
                if(str_contains($issue,'No default copy')||str_contains($issue,'No active version')||str_contains($issue,'body is unavailable'))$hasMissing=true;
                if(str_contains($issue,'Meta')||str_contains($issue,'approved')||str_contains($issue,'provider'))$hasProvider=true;
            }
            if(in_array((string)$channel,array('email','whatsapp','sms'),true)&&self::providerReady((string)$channel)===false)$hasProvider=true;
        }
        if($hasProvider)return 'provider';
        if($hasMissing)return 'missing';
        if($hasDraft)return 'draft';
        if($hasDisabled)return 'disabled';
        return 'ready';
    }

    private static function excerpt(string $text,int $length):string{
        $text=trim(preg_replace('/\s+/u',' ',$text));
        return mb_strlen($text)<=$length?$text:mb_substr($text,0,$length).'…';
    }
    private static function eventToggleError(string $code):string{
        return match($code){
            'notification_event_unknown'=>'That notification event is not in the catalogue.',
            'notification_event_mandatory'=>'Security, account and financial notices are mandatory and cannot be disabled.',
            default=>'The optional notification event was not changed.',
        };
    }
    private static function manualRetryError(string $code):string{
        return match($code){
            'notification_not_found'=>'That notification record was not found.',
            'notification_not_retryable'=>'Only a failed or expired notification can be retried.',
            'notification_retry_window_closed'=>'The permitted send window has closed; a retry cannot be authorised.',
            'notification_subject_not_retryable'=>'This notification is not linked to a supported booking, lesson, account or payment record.',
            'notification_dispatch_forbidden'=>'You are not authorised to operate notification dispatch.',
            'notification_idempotency_conflict'=>'That retry command was already used for a different notification.',
            'notification_command_required'=>'The retry command key is missing. Reload the page and try again.',
            default=>'The notification was not retried. Review its state and eligibility.',
        };
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
        'not_approved'=>'Provider template not approved','ambiguous'=>'Ambiguous provider mapping','contract_mismatch'=>'Provider contract mismatch',
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
