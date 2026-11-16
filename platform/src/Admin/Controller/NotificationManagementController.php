<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationEventReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationManualSendService;
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
        add_action('admin_post_dzn_notification_prepare_drafts',array(__CLASS__,'prepareCatalogueDrafts'));
        add_action('admin_post_dzn_notification_approve_operational',array(__CLASS__,'approveOperationalDrafts'));
        add_action('admin_post_dzn_notification_publish_drafts',array(__CLASS__,'publishReviewedDrafts'));
        add_action('admin_post_dzn_notification_approve_draft',array(__CLASS__,'approveDraft'));
        add_action('admin_post_dzn_notification_revoke_approval',array(__CLASS__,'revokeDraftApproval'));
        add_action('admin_post_dzn_notification_copy_publish',array(__CLASS__,'publish'));
        add_action('admin_post_dzn_notification_copy_discard',array(__CLASS__,'discardDraft'));
        add_action('admin_post_dzn_notification_copy_restore',array(__CLASS__,'restoreVersion'));
        add_action('admin_post_dzn_notification_copy_restore_default',array(__CLASS__,'restoreDefault'));
        add_action('admin_post_dzn_notification_provider_mapping',array(__CLASS__,'saveProviderMapping'));
        add_action('admin_post_dzn_notification_event_toggle',array(__CLASS__,'toggleEvent'));
        add_action('admin_post_dzn_notification_event_channel_toggle',array(__CLASS__,'toggleEventChannel'));
        add_action('admin_post_dzn_notification_inbox_prune',array(__CLASS__,'pruneInbox'));
        add_action('admin_post_dzn_notification_inbox_test',array(__CLASS__,'testInbox'));
        add_action('admin_post_dzn_notification_recover_leases',array(__CLASS__,'recoverLeases'));
        add_action('admin_post_dzn_notification_manual_send',array(__CLASS__,'manualSend'));
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
        echo '<div class="wrap dzn-notif-admin" dir="ltr"><style>.dzn-notif-admin{direction:ltr;max-width:100%;box-sizing:border-box}.dzn-notif-admin .nav-tab-wrapper{margin-block-end:16px}.dzn-notif-lede{max-width:78ch;color:#50575e}.dzn-notif-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px;max-width:100%;box-sizing:border-box}.dzn-notif-panel h2{margin-block-start:6px}.dzn-notif-meta{color:#646970;font-size:12px;line-height:1.9;overflow-wrap:anywhere}.dzn-notif-note{color:#50575e;font-size:12px;line-height:1.9;background:#f6f7f7;border-inline-start:3px solid #0E8C8C;padding:8px 12px;border-radius:3px}.dzn-notif-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-block:14px}.dzn-notif-card{border:1px solid #dcdcde;border-radius:6px;padding:14px}.dzn-notif-card h3{margin-block-start:0}.dzn-notif-list{margin:0;padding-inline-start:18px;line-height:2}.dzn-notif-table td,.dzn-notif-table th{vertical-align:top}.dzn-notif-table details{margin-block:4px}.dzn-notif-table details pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;line-height:1.9;margin:6px 0;max-height:180px;overflow:auto}.dzn-notif-preview{background:#FBF7EE;border:1px solid #e6dfd2;border-radius:8px;padding:10px}.dzn-notif-preview pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;direction:rtl;margin:0;line-height:2}.dzn-notif-preview--text{background:#fff}.dzn-notif-editor textarea{font-family:Tahoma,Arial,sans-serif;line-height:2;direction:rtl}.dzn-notif-actions{display:flex;gap:8px;flex-wrap:wrap}.dzn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a}.dzn-badge.is-ok{background:#edfaef;color:#1e6b2f}.dzn-badge.is-warn{background:#fcf3e6;color:#8a4b00}.dzn-badge.is-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-inline-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.dzn-notif-admin code{direction:ltr;unicode-bidi:embed;overflow-wrap:anywhere;word-break:break-word}.dzn-notif-admin details pre{max-height:220px;overflow:auto;white-space:pre-wrap}.dzn-notif-record-status{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin-block:18px}.dzn-notif-record-status h2{margin-block-start:0}.dzn-channel-strip{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.dzn-channel-strip a{text-decoration:none}.dzn-notif-dialog{width:min(1080px,94vw);max-width:1080px;max-height:90vh;padding:0;border:0;border-radius:10px;box-shadow:0 24px 60px rgba(15,23,42,.35);direction:ltr;background:#fff}.dzn-notif-dialog::backdrop{background:rgba(15,23,42,.55)}.dzn-notif-dialog-bar{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:#fff;border-block-end:1px solid #dcdcde}.dzn-notif-dialog-bar h2{margin:0;font-size:16px}.dzn-notif-dialog-body{max-height:calc(90vh - 56px);overflow:auto;padding:4px 20px 20px}.dzn-notif-lifecycle{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;margin:8px 0 0;font-size:11px;line-height:1.7}.dzn-notif-lifecycle dt{color:#646970;white-space:nowrap}.dzn-notif-lifecycle dd{margin:0}.dzn-notif-toolbar{position:sticky;top:32px;z-index:30;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin-block:12px}.dzn-notif-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;border-top:1px solid #f0f0f1;padding-top:10px}.dzn-notif-chip{display:inline-block;padding:3px 12px;border:1px solid #dcdcde;border-radius:999px;font-size:12px;text-decoration:none;color:#1d2327;background:#fff}.dzn-notif-chip.is-active{background:#0E8C8C;border-color:#0E8C8C;color:#fff}.dzn-notif-task{margin-block:22px;max-width:100%;box-sizing:border-box}.dzn-notif-task h3{margin:0 0 10px}.dzn-notif-grid-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,320px),1fr));gap:12px;width:100%;max-width:100%;box-sizing:border-box}.dzn-notif-cardrow{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;display:flex;flex-direction:column;gap:8px;scroll-margin-top:150px;min-width:0;max-width:100%;box-sizing:border-box;overflow-wrap:anywhere}.dzn-notif-cardrow:hover{border-color:#0E8C8C;box-shadow:0 2px 8px rgba(15,23,42,.08)}.dzn-notif-cardhead{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:6px 8px}.dzn-notif-cardhead>div{min-width:0;flex:1 1 60%}.dzn-notif-cardhead>div>*{overflow-wrap:anywhere}.dzn-notif-cardhead .dzn-badge{flex:0 0 auto}.dzn-notif-cardhead strong{font-size:14px}.dzn-notif-cardrow-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px}.dzn-notif-channel-chips{display:flex;gap:6px;flex-wrap:wrap}.dzn-notif-channel-chip{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}.chip-ok{background:#edfaef;color:#1e6b2f}.chip-warn{background:#fcf3e6;color:#8a4b00}.chip-off{background:#f0f0f1;color:#646970}.chip-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-drawer-backdrop{position:fixed;top:0;right:0;bottom:0;left:0;background:rgba(15,23,42,.45);z-index:9990}.dzn-notif-drawer{position:fixed;top:32px;right:0;bottom:0;width:min(680px,96vw);background:#fff;border-inline-start:1px solid #dcdcde;box-shadow:-24px 0 60px rgba(15,23,42,.28);z-index:9991;overflow-y:auto;padding:20px}.dzn-notif-drawer-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.dzn-notif-drawer-bar h2{margin:0}@media(max-width:782px){.dzn-notif-drawer{top:46px;width:100vw}}</style><h1>Notifications</h1>';
        echo '<p class="dzn-notif-lede">Choose which notifications are sent and edit their copy. Provider configuration is under Integrations; detailed delivery evidence is under Diagnostics. This workspace never changes a recipient, authorises a payment, alters a lesson or bypasses the business authority that produced the notification.</p>';
        self::renderFlash();
        if($requested!==$tab){
            $legacy=array('rules'=>'Delivery rules','channels'=>'Channel settings','monitoring'=>'Delivery monitoring','website'=>'Portal notifications','settings'=>'Channel settings');
            echo '<div class="notice notice-info inline"><p>'.esc_html((string)($legacy[$requested]??'That view')).' now lives in the Notifications event workspace. The old URL still works.</p></div>';
        }
        echo '<div class="dzn-notif-panel">';
        self::renderEvents();
        if($templateKey!==''){
            $channel=isset($_GET['template_channel'])?sanitize_key(wp_unslash((string)$_GET['template_channel'])):'email';
            $locale=isset($_GET['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_GET['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
            if(NotificationCatalogue::get($templateKey)===null)echo '<div class="notice notice-error inline"><p>Notification event not found.</p></div>';
            else{
                // The editor is a right-hand drawer over the catalogue. The page still carries the
                // template in its URL so refresh, back/forward and post-save redirects all reopen it,
                // and the list underneath keeps its scroll position.
                echo '<div class="dzn-notif-drawer-backdrop" aria-hidden="true"></div>';
                echo '<aside class="dzn-notif-drawer" id="dzn-notif-drawer" aria-label="Notification editor"><div class="dzn-notif-drawer-bar"><h2 id="dzn-notif-editor-title">Edit notification</h2><button type="button" class="button button-small dzn-notif-drawer-close">Close</button></div><section>';
                self::renderEditor(new NotificationTemplateLibrary(),$templateKey,$channel,$locale);
                echo '</section></aside>';
                echo '<script>(function(){function close(){var b=document.querySelector(".dzn-notif-drawer-backdrop"),d=document.getElementById("dzn-notif-drawer");if(b)b.remove();if(d)d.remove();try{var u=new URL(window.location.href);["template","template_channel","locale"].forEach(function(k){u.searchParams.delete(k);});window.history.replaceState({},"",u.toString());}catch(e){}}var c=document.querySelector(".dzn-notif-drawer-close");if(c)c.addEventListener("click",close);var b=document.querySelector(".dzn-notif-drawer-backdrop");if(b)b.addEventListener("click",close);document.addEventListener("keydown",function(e){if(e.key==="Escape")close();});})();</script>';
            }
        }
        echo '</div></div>';
    }

    // ------------------------------------------------------------------ event workspace

    private static function renderEvents():void{
        $library=new NotificationTemplateLibrary();
        $filters=self::tableContext();
        $search=(string)($filters['search']??'');$categoryFilter=(string)($filters['category']??'');$recipientFilter=(string)($filters['recipient']??'');$channelFilter=(string)($filters['channel']??'');$statusFilter=(string)($filters['status']??'');
        echo '<h2>Notification catalogue</h2>';
        echo '<p>Every business notification is managed here, grouped by task. Open one to toggle its channels and edit its copy in a panel that keeps your place. A channel is never described as ready unless its copy and provider are both satisfied; switching delivery off never changes accounts, payments, lessons or consent records.</p>';
        self::renderChannelHealth();
        self::renderCatalogueActions($library);
        self::renderManualSend();
        self::renderToolbar($search,$categoryFilter,$recipientFilter,$channelFilter,$statusFilter);

        $rows=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            $searchText=$eventKey.' '.(string)($event['label']??'').' '.(string)($event['purpose']??'');
            foreach((array)($event['channels']??array()) as $searchChannel){$copy=$library->activeCopy($eventKey,(string)$searchChannel,NotificationCatalogue::PRIMARY_LOCALE);$searchText.=' '.(string)($copy['subject']??'').' '.(string)($copy['body']??'');}
            $offered=(array)($event['channels']??array());
            $states=array();foreach(array('website','email','whatsapp','sms') as $channel)$states[$channel]=self::effectiveChannelState($library,$eventKey,$channel);
            $candidate=array('key'=>$eventKey,'event'=>$event,'offered'=>$offered,'states'=>$states,'readiness'=>self::readiness($library,$eventKey,$offered),'search_text'=>$searchText);
            if(!self::tableFilterMatches($candidate,$filters))continue;
            $rows[]=array('key'=>$eventKey,'event'=>$event,'offered'=>$offered,'states'=>$states,'readiness'=>$candidate['readiness']);
        }
        usort($rows,static fn(array $left,array $right):int=>strcmp($left['key'],$right['key']));
        if($rows===array()){echo '<p>No notification event matches these filters.</p>';return;}
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' notification(s) match.</p>';

        // Group by business task, in the catalogue's declared task order.
        $byTask=array();
        foreach($rows as $row)$byTask[NotificationCatalogue::taskKey($row['key'])][]=$row;
        foreach(array_keys(NotificationCatalogue::TASKS) as $taskKey){
            if(!isset($byTask[$taskKey]))continue;
            $task=NotificationCatalogue::TASKS[$taskKey];
            echo '<section class="dzn-notif-task" aria-label="'.esc_attr((string)$task['label']).'"><h3>'.esc_html((string)$task['label']).' <span class="dzn-notif-meta">'.esc_html((string)count($byTask[$taskKey])).'</span></h3>';
            echo '<div class="dzn-notif-grid-cards">';
            foreach($byTask[$taskKey] as $row)self::renderEventCard($row,$library);
            echo '</div></section>';
        }
    }

    /** The sticky search/filter bar plus one-click shortcuts to the states that need attention. */
    private static function renderToolbar(string $search,string $categoryFilter,string $recipientFilter,string $channelFilter,string $statusFilter):void{
        echo '<div class="dzn-notif-toolbar">';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="events">';
        echo '<label>Search <input name="search" value="'.esc_attr($search).'" placeholder="label, purpose, event key, subject or body" aria-label="Search notifications"></label>';
        echo '<label>Category <select name="category"><option value="">All</option>';
        foreach(NotificationCatalogue::CATEGORIES as $value=>$category)echo '<option value="'.esc_attr($value).'"'.($categoryFilter===$value?' selected':'').'>'.esc_html((string)$category['label']).'</option>';
        echo '</select></label><label>Recipient <select name="recipient"><option value="">All</option>';
        foreach(array('student'=>'Student','teacher'=>'Teacher','guardian'=>'Guardian','admin'=>'Admin') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($recipientFilter===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Channel <select name="channel"><option value="">All</option>';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS','website'=>'Portal') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channelFilter===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>Status <select name="status"><option value="">All</option><option value="enabled"'.($statusFilter==='enabled'?' selected':'').'>Enabled</option><option value="partial"'.($statusFilter==='partial'?' selected':'').'>Partially available</option><option value="disabled"'.($statusFilter==='disabled'?' selected':'').'>Disabled</option><option value="policy_disabled"'.($statusFilter==='policy_disabled'?' selected':'').'>Disabled by SMS policy</option><option value="missing_template"'.($statusFilter==='missing_template'?' selected':'').'>Missing template</option><option value="awaiting_approval"'.($statusFilter==='awaiting_approval'?' selected':'').'>Awaiting approval</option><option value="missing_provider"'.($statusFilter==='missing_provider'?' selected':'').'>Missing provider</option><option value="unsupported"'.($statusFilter==='unsupported'?' selected':'').'>Unsupported</option><option value="draft"'.($statusFilter==='draft'?' selected':'').'>Draft awaiting review</option><option value="ready"'.($statusFilter==='ready'?' selected':'').'>Ready</option><option value="no_producer"'.($statusFilter==='no_producer'?' selected':'').'>No producer yet</option></select></label><button class="button">Filter</button> <a class="button-link" href="'.esc_url(self::url(array('tab'=>'events'))).'">Reset</a></form>';
        echo '<div class="dzn-notif-chips">';
        echo '<a class="dzn-notif-chip'.($statusFilter==='awaiting_approval'?' is-active':'').'" href="'.esc_url(self::url(array('tab'=>'events','status'=>'awaiting_approval'))).'">Held for approval</a>';
        echo '<a class="dzn-notif-chip'.($statusFilter==='no_producer'?' is-active':'').'" href="'.esc_url(self::url(array('tab'=>'events','status'=>'no_producer'))).'">No producer</a>';
        echo '<a class="dzn-notif-chip'.($statusFilter==='missing_template'?' is-active':'').'" href="'.esc_url(self::url(array('tab'=>'events','status'=>'missing_template'))).'">Needs publication</a>';
        echo '</div></div>';
    }

    private static function eventFamily(string $eventKey):string{
        if(str_starts_with($eventKey,'reschedule.change.')||str_contains($eventKey,'booking.rescheduled'))return 'Lesson changes';
        if(str_starts_with($eventKey,'reschedule.replacement.')||str_contains($eventKey,'replacement'))return 'Replacement sessions';
        return '';
    }
    /** Filter the rows that are actually displayed, including the editor's prefixed context. */
    private static function tableFilterMatches(array $row,array $filters):bool{
        $event=(array)($row['event']??array());
        $search=(string)($filters['search']??'');
        if($search!==''&&stripos((string)($row['search_text']??''),$search)===false)return false;
        if(($filters['category']??'')!==''&&(string)($event['category']??'')!==(string)$filters['category'])return false;
        if(($filters['recipient']??'')!==''&&(string)($event['audience']??'')!==(string)$filters['recipient'])return false;
        if(($filters['channel']??'')!==''&&!in_array((string)$filters['channel'],(array)($row['offered']??array()),true))return false;
        if(($filters['status']??'')!==''&&!in_array((string)$filters['status'],array_column((array)($row['states']??array()),'state'),true)&&(string)($row['readiness']??'')!==(string)$filters['status'])return false;
        return true;
    }

    private static function renderEventCard(array $row,NotificationTemplateLibrary $library):void{
        $eventKey=$row['key'];$event=$row['event'];
        $category=NotificationCatalogue::CATEGORIES[(string)($event['category']??'')]??array('label'=>(string)($event['category']??''));
        $eventEnabled=$library->eventEnabled($eventKey);
        $attention=self::attentionLabel((string)$row['readiness']);
        $primaryChannel=(string)((array)($event['channels']??array())[0]??'email');

        echo '<article class="dzn-notif-cardrow" id="event-'.esc_attr($eventKey).'">';
        echo '<div class="dzn-notif-cardhead"><div><strong>'.esc_html((string)($event['label']??$eventKey)).'</strong>';
        echo '<div class="dzn-notif-meta">'.esc_html(self::audienceLabel((string)($event['audience']??''))).' · '.esc_html((string)$category['label']).' · <code dir="ltr">'.esc_html($eventKey).'</code></div>';
        echo '<div class="dzn-notif-meta">'.esc_html((string)($event['purpose']??'')).'</div></div>';
        echo '<span class="dzn-badge '.esc_attr($attention['class']).'">'.esc_html($attention['label']).'</span></div>';

        echo '<div class="dzn-notif-channel-chips">';
        foreach(array('website','email','whatsapp','sms') as $channel){
            if(!in_array($channel,(array)$row['offered'],true))continue;
            $state=$row['states'][$channel];
            $tip=$state['label'].($state['reason']!==''?': '.$state['reason']:'');
            echo '<span class="dzn-notif-channel-chip '.esc_attr(self::chipClass((string)$state['state'])).'" title="'.esc_attr($tip).'">'.esc_html(self::channelLabel($channel)).': '.esc_html((string)$state['label']).'</span>';
        }
        echo '</div>';

        echo '<div class="dzn-notif-cardrow-actions">';
        if(self::canEdit()){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_event_toggle"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="enabled" value="'.($eventEnabled?'0':'1').'">';
            foreach(self::tableContext() as $contextKey=>$contextValue)echo '<input type="hidden" name="filter_'.esc_attr($contextKey).'" value="'.esc_attr((string)$contextValue).'">';
            wp_nonce_field('dzn_notification_event_toggle');
            echo '<button class="button button-small" type="submit">'.($eventEnabled?'Disable notification':'Enable notification').'</button></form>';
        }else{
            echo '<span class="dzn-badge '.($eventEnabled?'is-ok':'is-warn').'">'.($eventEnabled?'Enabled':'Disabled').'</span>';
        }
        echo '<a class="button button-small dzn-notif-open" href="'.esc_url(self::editorUrl($eventKey,$primaryChannel,NotificationCatalogue::PRIMARY_LOCALE)).'#event-'.esc_attr($eventKey).'">Open</a></div>';
        if(NotificationCatalogue::isMandatory($eventKey))echo '<div class="dzn-notif-meta">Sensitive notification — disabling delivery may prevent important account, security or payment messages. Core rules are unchanged.</div>';
        echo '</article>';
    }

    /** The one badge that summarises a card, ordered so the most urgent fact wins. */
    private static function attentionLabel(string $readiness):array{
        return match($readiness){
            'no_producer'=>array('label'=>'No producer','class'=>'is-warn'),
            'awaiting_approval'=>array('label'=>'Awaiting approval','class'=>'is-warn'),
            'missing'=>array('label'=>'Missing template','class'=>'is-warn'),
            'provider'=>array('label'=>'Provider needed','class'=>'is-warn'),
            'draft'=>array('label'=>'Draft awaiting review','class'=>'is-warn'),
            'disabled'=>array('label'=>'Disabled','class'=>''),
            'ready'=>array('label'=>'Ready','class'=>'is-ok'),
            default=>array('label'=>ucfirst((string)$readiness),'class'=>''),
        };
    }

    /** Channel-chip colour: good, warn, off, or blocked. */
    private static function chipClass(string $state):string{
        return match($state){
            'enabled'=>'chip-ok',
            'unsupported','disabled','partial'=>'chip-off',
            'missing_template','missing_provider','awaiting_approval','policy_disabled'=>'chip-locked',
            default=>'chip-warn',
        };
    }

    /**
     * The master switch and the per-channel delivery toggles, restored into the editor so an
     * administrator can enable or disable every offered channel in one place. A genuinely blocked
     * route (missing copy, unapproved provider, secure-only) cannot be switched on.
     */
    private static function renderDeliveryControls(NotificationTemplateLibrary $library,string $eventKey):void{
        $eventEnabled=$library->eventEnabled($eventKey);
        echo '<section class="dzn-notif-card"><h3>Delivery</h3>';
        if(self::canEdit()){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_event_toggle"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="enabled" value="'.($eventEnabled?'0':'1').'">';
            foreach(self::tableContext() as $contextKey=>$contextValue)echo '<input type="hidden" name="filter_'.esc_attr($contextKey).'" value="'.esc_attr((string)$contextValue).'">';
            wp_nonce_field('dzn_notification_event_toggle');
            echo '<strong>Notification:</strong> <span class="dzn-badge '.($eventEnabled?'is-ok':'is-warn').'">'.($eventEnabled?'Enabled':'Disabled').'</span> ';
            echo '<button class="button button-small" type="submit">'.($eventEnabled?'Disable notification':'Enable notification').'</button></form>';
        }else{
            echo '<strong>Notification:</strong> <span class="dzn-badge '.($eventEnabled?'is-ok':'is-warn').'">'.($eventEnabled?'Enabled':'Disabled').'</span>';
        }
        if(NotificationCatalogue::isMandatory($eventKey))echo '<p class="dzn-notif-meta">Sensitive notification — disabling delivery may prevent important account, security or payment messages. Core rules are unchanged.</p>';
        echo '<ul class="dzn-notif-list">';
        foreach(array('website','email','whatsapp','sms') as $channel){
            if(!$library->channelOffered($eventKey,$channel))continue;
            $active=$library->eventChannelEnabled($eventKey,$channel);
            $state=self::effectiveChannelState($library,$eventKey,$channel);
            $locked=in_array($state['state'],array('missing_template','awaiting_approval','missing_provider','policy_disabled'),true);
            $blocked=!empty($state['enable_blocked']);
            echo '<li><strong>'.esc_html(self::channelLabel($channel)).':</strong> <span class="dzn-badge '.($state['state']==='enabled'?'is-ok':'is-warn').'">'.esc_html((string)$state['label']).'</span> ';
            if(self::canEdit()){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_event_channel_toggle"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="enabled" value="'.($active?'0':'1').'">';
                foreach(self::tableContext() as $contextKey=>$contextValue)echo '<input type="hidden" name="filter_'.esc_attr($contextKey).'" value="'.esc_attr((string)$contextValue).'">';
                wp_nonce_field('dzn_notification_event_channel_toggle');
                if($active)echo '<button class="button button-small" type="submit">Turn off</button>';
                elseif(!$locked&&!$blocked)echo '<button class="button button-small" type="submit">Turn on</button>';
                else echo '<button class="button button-small" disabled title="'.esc_attr((string)$state['reason']).'">Turn on</button>';
                echo '</form>';
            }
            if($state['reason']!=='')echo '<span class="dzn-notif-meta">'.esc_html((string)$state['reason']).'</span>';
            echo '</li>';
        }
        echo '</ul></section>';
    }

    // ------------------------------------------------------------------ manual send

    /**
     * The administrator-invoked operational send. It is a composer plus a read-only preview; the
     * actual send is a separate, confirmed POST. Teacher recipients default to SMS and students to
     * WhatsApp; either channel may be overridden. WhatsApp is template-only.
     */
    private static function renderManualSend():void{
        if(!NotificationManualSendService::canSend())return;
        $service=new NotificationManualSendService();
        $segments=$service->segments();

        $audience=isset($_GET['ms_audience'])?sanitize_key(wp_unslash((string)$_GET['ms_audience'])):'';
        $channel=isset($_GET['ms_channel'])?sanitize_key(wp_unslash((string)$_GET['ms_channel'])):'';
        $numbers=isset($_GET['ms_numbers'])&&is_scalar($_GET['ms_numbers'])?sanitize_text_field(wp_unslash((string)$_GET['ms_numbers'])):'';
        $message=isset($_GET['ms_message'])&&is_scalar($_GET['ms_message'])?wp_unslash((string)$_GET['ms_message']):'';
        $waName=isset($_GET['ms_name'])&&is_scalar($_GET['ms_name'])?sanitize_text_field(wp_unslash((string)$_GET['ms_name'])):'';
        $waMessage=isset($_GET['ms_wa_message'])&&is_scalar($_GET['ms_wa_message'])?wp_unslash((string)$_GET['ms_wa_message']):'';
        $selectedSegments=isset($_GET['ms_segments'])&&is_array($_GET['ms_segments'])?array_map('sanitize_key',wp_unslash($_GET['ms_segments'])):array();

        $preview=null;
        if(($audience!==''||$numbers!==''||$selectedSegments!==array())){
            $preview=$service->preview(array(
                'audience'=>$audience,'channel'=>$channel==='auto'?'':$channel,'segments'=>$selectedSegments,'numbers'=>$numbers,
                'message'=>$message,'parameters'=>array('name'=>$waName,'message'=>$waMessage),
            ));
        }

        echo '<details class="dzn-notif-card dzn-notif-manual" style="margin-block:14px" '.($preview!==null?'open':'').'><summary><strong>Send a notification</strong> <span class="dzn-notif-meta">— compose an operational message to an audience or to entered numbers</span></summary>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="events">';
        echo '<p><strong>Audience</strong> <span class="dzn-notif-meta">(a segment, typed numbers, or both)</span></p>';
        echo '<div class="dzn-notif-actions">';
        foreach($segments as $segmentKey=>$segment){
            echo '<label class="dzn-notif-chip'.(in_array($segmentKey,$selectedSegments,true)?' is-active':'').'"><input type="checkbox" name="ms_segments[]" value="'.esc_attr($segmentKey).'"'.(in_array($segmentKey,$selectedSegments,true)?' checked':'').'> '.esc_html($segment['label']).' ('.esc_html((string)$segment['count']).')</label>';
        }
        echo '</div>';
        echo '<p><label>Or enter numbers (one per line or comma-separated)<br><textarea name="ms_numbers" rows="3" class="large-text" dir="ltr">'.esc_textarea($numbers).'</textarea></label></p>';
        echo '<p><label>Channel <select name="ms_channel"><option value="auto"'.($channel===''||$channel==='auto'?' selected':'').'>Automatic (teacher = SMS, student = WhatsApp)</option><option value="sms"'.($channel==='sms'?' selected':'').'>SMS</option><option value="whatsapp"'.($channel==='whatsapp'?' selected':'').'>WhatsApp</option></select></label></p>';
        echo '<p><label>SMS message<br><textarea name="ms_message" rows="3" class="large-text" maxlength="1000">'.esc_textarea($message).'</textarea></label></p>';
        echo '<p><label>WhatsApp template fields ('.esc_html(NotificationManualSendService::WHATSAPP_TEMPLATE).') — name <input name="ms_name" value="'.esc_attr($waName).'" class="regular-text"> · message <input name="ms_wa_message" value="'.esc_attr($waMessage).'" class="regular-text"></label> <span class="dzn-notif-meta">WhatsApp requires a Meta-approved template; until it is approved, WhatsApp sends fail closed.</span></p>';
        echo '<button class="button">Preview recipients</button></form>';

        if($preview!==null){
            echo '<div class="dzn-notif-manual-preview">';
            if($preview['errors']!==array()){
                echo '<div class="notice notice-error inline"><ul class="dzn-notif-list">';foreach($preview['errors'] as $error)echo '<li>'.esc_html((string)$error).'</li>';echo '</ul></div>';
            }else{
                echo '<p><strong>'.esc_html((string)$preview['reached']).'</strong> recipient(s) will be reached by <strong>'.esc_html($preview['channel']).'</strong>. ';
                if($preview['skipped']!==array()){
                    $skipped=array();foreach($preview['skipped'] as $reason=>$count)$skipped[]=esc_html((string)$reason).' '.esc_html((string)$count);
                    echo 'Skipped (STOP / opt-out): '.implode(', ',$skipped).'.';
                }
                echo '</p>';
                if($preview['channel']==='whatsapp')echo '<div class="notice notice-warning inline"><p>This uses the announcement template <code dir="ltr">'.esc_html(NotificationManualSendService::WHATSAPP_TEMPLATE).'</code>. Delivery only succeeds after Meta approves that template.</p></div>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_manual_send"><input type="hidden" name="ms_segments_json" value="'.esc_attr(wp_json_encode($selectedSegments)).'"><input type="hidden" name="ms_numbers" value="'.esc_attr($numbers).'"><input type="hidden" name="ms_channel" value="'.esc_attr($channel==='auto'?'':$channel).'"><input type="hidden" name="ms_message" value="'.esc_attr($message).'"><input type="hidden" name="ms_name" value="'.esc_attr($waName).'"><input type="hidden" name="ms_wa_message" value="'.esc_attr($waMessage).'">';
                wp_nonce_field('dzn_notification_manual_send');
                echo '<p><label><strong>Type CONFIRM to send to '.esc_html((string)$preview['reached']).' recipient(s):</strong> <input name="confirm" type="text" class="regular-text" autocomplete="off"></label> ';
                echo '<button class="button button-primary" type="submit" onclick="return this.form.confirm.value===\'CONFIRM\'||confirm(\'Type CONFIRM to send.\')">Send now</button></form>';
                echo '<p class="dzn-notif-meta">No message is sent to a recipient who has opted out. Every send is capped at '.esc_html((string)NotificationManualSendService::MAX_RECIPIENTS).' recipients, recorded as a batch and written to the audit log.</p></div>';
            }
        }
        echo '</details>';
    }

    public static function manualSend():never{
        if(!NotificationManualSendService::canSend())wp_die('Forbidden',403);
        check_admin_referer('dzn_notification_manual_send');
        if((string)(wp_unslash($_POST['confirm']??''))!=='CONFIRM'){self::flash('error','Type CONFIRM to send. Nothing was sent.');self::redirectBack('events',self::postTableContext());}
        $segments=isset($_POST['ms_segments_json'])&&is_scalar($_POST['ms_segments_json'])?json_decode((string)wp_unslash($_POST['ms_segments_json']),true):array();
        $input=array(
            'audience'=>isset($_POST['ms_audience'])?sanitize_key(wp_unslash((string)$_POST['ms_audience'])):'',
            'channel'=>isset($_POST['ms_channel'])?sanitize_key(wp_unslash((string)$_POST['ms_channel'])):'',
            'segments'=>is_array($segments)?array_map('sanitize_key',$segments):array(),
            'numbers'=>isset($_POST['ms_numbers'])?sanitize_text_field(wp_unslash((string)$_POST['ms_numbers'])):'',
            'message'=>isset($_POST['ms_message'])?wp_unslash((string)$_POST['ms_message']):'',
            'parameters'=>array(
                'name'=>isset($_POST['ms_name'])?sanitize_text_field(wp_unslash((string)$_POST['ms_name'])):'',
                'message'=>isset($_POST['ms_wa_message'])?wp_unslash((string)$_POST['ms_wa_message']):'',
            ),
        );
        try{
            // The command key is derived from the send intent — audience, channel, message and the
            // acting administrator — not from a clock. Re-submitting an identical send converges on the
            // same batch, so a partially completed batch is never resent to recipients who already
            // received it; a changed message or audience is a new batch.
            $commandKey='manual-send:'.get_current_user_id().':'.hash('sha256',wp_json_encode($input));
            $result=(new NotificationManualSendService())->send($input,$commandKey);
            self::flash('success',sprintf('Manual send complete: %d sent, %d failed, %d skipped, %d uncertain. Uncertain deliveries are held for reconciliation and are never resent automatically. Batch %s.',(int)$result['sent'],(int)$result['failed'],(int)$result['skipped'],(int)$result['uncertain'],(string)$result['batch_id']));
        }catch(\Throwable $error){
            self::flash('error','Manual send did not run: '.$error->getMessage());
        }
        self::redirectBack('events',self::postTableContext());
    }

    /**
     * The three-step copy workflow. The steps are separate, named actions in a fixed order so an
     * administrator can always see what has been done, what is next and what is deliberately held back
     * for an external approval. Nothing here sends a message or switches a channel on.
     */
    private static function renderCatalogueActions(NotificationTemplateLibrary $library):void{
        $coverage=NotificationCatalogue::coverage();
        $workflow=$library->workflowReport();
        $last=self::lastWorkflowResult();

        echo '<div class="dzn-notif-card dzn-notif-workflow" style="margin-block:12px"><h3>Copy and publication workflow</h3>';
        echo '<p class="dzn-notif-meta">'.esc_html((string)$coverage['present']).' of '.esc_html((string)$coverage['required']).' required templates carry their own Persian and English copy across '.esc_html((string)$coverage['events']).' notifications. Copy being complete is not the same as a notification being published or ready to send.</p>';

        echo '<ol class="dzn-notif-steps">';
        echo '<li><strong>Prepare drafts</strong> — <span class="dzn-badge '.($workflow['drafts']>0?'is-ok':'').'">'.esc_html((string)$workflow['drafts']).' draft(s) stored</span><div class="dzn-notif-meta">Creates a reviewable draft for every template that has no version and no draft. Never overwrites a draft or a published version.</div></li>';
        echo '<li><strong>Approve operational drafts</strong> — <span class="dzn-badge '.($workflow['awaiting']>0?'is-warn':'is-ok').'">'.esc_html((string)$workflow['awaiting']).' awaiting approval</span> <span class="dzn-badge '.($workflow['approved']>0?'is-ok':'').'">'.esc_html((string)$workflow['approved']).' approved</span><div class="dzn-notif-meta">Records an approval for each exact draft whose channel approvals are complete. Finance, legal, security, dedicated-authority and unmapped-provider copy is skipped and listed below.</div></li>';
        echo '<li><strong>Publish approved drafts</strong> — <span class="dzn-badge '.($workflow['publishable']>0?'is-ok':'').'">'.esc_html((string)$workflow['publishable']).' ready to publish</span><div class="dzn-notif-meta">Creates a new immutable version for each approved draft. It never enables a channel and never changes a notification that already froze a version.</div></li>';
        echo '</ol>';

        if(!self::canEdit()){
            echo '<p class="dzn-notif-note">You have read-only access. Editing, approving and publishing require the <code>dzn_manage_notification_templates</code> capability.</p>';
        }else{
            echo '<div class="dzn-notif-actions">';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_prepare_drafts">';
            wp_nonce_field('dzn_notification_prepare_drafts');
            echo '<button class="button" type="submit">1. Prepare drafts</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_approve_operational">';
            wp_nonce_field('dzn_notification_approve_operational');
            echo '<button class="button" type="submit">2. Approve operational drafts</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_publish_drafts">';
            wp_nonce_field('dzn_notification_publish_drafts');
            echo '<button class="button button-primary" type="submit" onclick="return confirm(\'Publish every approved draft as a new immutable version?\')">3. Publish approved drafts</button></form>';
            echo '</div>';
        }

        if($workflow['restricted']>0){
            echo '<div class="notice notice-warning inline"><p><strong>'.esc_html((string)$workflow['restricted']).' draft(s) are held for an external approval and are never bulk-published.</strong></p><ul class="dzn-notif-list">';
            foreach($workflow['restricted_by_reason'] as $reason=>$count)echo '<li>'.esc_html($reason).': '.esc_html((string)$count).'</li>';
            echo '</ul><p class="dzn-notif-meta">Publish these individually from the editor after the named owner has approved them. This is the deliberate reason a sensitive notification can show "Awaiting approval" rather than "Ready".</p></div>';
        }

        if($last!==null){
            echo '<p class="dzn-notif-note"><strong>Last run</strong> <code dir="ltr">'.esc_html((string)$last['at']).' UTC</code> — '.esc_html((string)$last['message']).'</p>';
        }elseif($workflow['drafts']===0){
            echo '<p class="dzn-notif-meta">No drafts are stored yet. Run step 1 to create them.</p>';
        }
        echo '</div>';
    }

    private const WORKFLOW_RESULT='dzn_notification_workflow_result';

    /** The persisted outcome of the last bulk action, so the page never looks like it did nothing. */
    private static function lastWorkflowResult():?array{
        if(!function_exists('get_option'))return null;
        $value=get_option(self::WORKFLOW_RESULT,null);
        return is_array($value)&&isset($value['message'],$value['at'])?$value:null;
    }
    private static function rememberWorkflowResult(string $message):void{
        if(function_exists('update_option'))update_option(self::WORKFLOW_RESULT,array('at'=>gmdate('Y-m-d H:i:s'),'message'=>$message,'by'=>get_current_user_id()),false);
    }

    /** A small channel-readiness strip with links to the owning configuration/evidence page. */
    private static function renderChannelHealth():void{
        echo '<div class="dzn-channel-strip" aria-label="Channel health">';
        foreach(array('email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS') as $channel=>$label){
            if($channel==='sms'){
                foreach(self::smsRouteStatus() as $route){
                    $routeState=$route['disabled']?'Disabled':(($route['ready']??false)?'Healthy':'Unavailable');
                    $routeClass=$routeState==='Healthy'?'is-ok':'is-warn';
                    echo '<span class="dzn-badge '.esc_attr($routeClass).'">SMS · '.esc_html((string)$route['label']).': '.esc_html($routeState).'</span> ';
                }
                continue;
            }
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
        echo '<h2>Templates</h2><p>Review Persian and English drafts before publishing. Preparing drafts never sends a message, enables a channel or approves a WhatsApp provider template.</p>';
        self::renderCatalogueActions($library);
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
                elseif($state==='restricted_fallback')$label.=' · Provider not configured';
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
        $offered=$library->channelOffered($eventKey,$channel);
        $enabled=$library->eventChannelEnabled($eventKey,$channel);
        $isDedicated=(string)$event['authority']==='dedicated';
        $editable=self::canEdit()&&!$isDedicated&&$offered;
        // A previously rejected submission wins over the stored draft so the administrator never has
        // to retype what the validator refused.
        $rejected=self::rejectedSubmission($eventKey,$channel,$locale);
        $editorSubject=(string)($rejected['subject']??($working['editable']['subject']??''));
        $editorBody=(string)($rejected['body']??($working['editable']['body']??''));
        echo '<p><a href="'.esc_url(self::url(array_merge(array('tab'=>'events'),self::tableContext()))).'">‹ Back to notification catalogue</a></p>';
        $task=NotificationCatalogue::taskForEvent($eventKey)??array('label'=>'Support');
        echo '<h2>'.esc_html((string)$event['label']).'</h2><p class="dzn-notif-meta"><code dir="ltr">'.esc_html($eventKey).'</code> · '.esc_html(self::channelLabel($channel)).' · '.esc_html($locale).' · Task: '.esc_html((string)$task['label']).' · Delivery policy: '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</p>';
        echo '<p class="dzn-notif-actions">';
        foreach((array)$event['channels'] as $availableChannel)echo '<a class="button button-small'.($availableChannel===$channel?' button-primary':'').'" href="'.esc_url(self::editorUrl($eventKey,$availableChannel,$locale)).'">'.esc_html(self::channelLabel($availableChannel)).'</a> ';
        foreach(NotificationCatalogue::SUPPORTED_LOCALES as $availableLocale)echo '<a class="button button-small'.($availableLocale===$locale?' button-primary':'').'" href="'.esc_url(self::editorUrl($eventKey,$channel,$availableLocale)).'">'.esc_html($availableLocale).'</a> ';
        echo '</p>';
        if(!$offered)echo '<div class="notice notice-warning inline"><p>This channel is not supported by this event and cannot be edited or sent.</p></div>';
        elseif(!$enabled)echo '<div class="notice notice-info inline"><p>This channel is currently disabled for this notification. You may prepare and publish its copy, but it cannot send until it is enabled.</p></div>';
        echo '<div class="dzn-notif-grid">';
        self::renderDeliveryControls($library,$eventKey);
        echo '<section class="dzn-notif-card"><h3>Variable Contract</h3><p>Allowed placeholders:</p><p>';
        foreach(NotificationCatalogue::variables($eventKey) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p>Required placeholders for this channel:</p><p>';
        foreach(NotificationTemplateSanitizer::requiredVariables($eventKey,$channel) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        $actionLinks=NotificationTemplateSanitizer::requiredActionLinks($eventKey,$channel);
        if($actionLinks!==array()){
            echo '</p><p>Action link (keep at least one):</p><p>';
            foreach($actionLinks as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        }
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
        $preview=$library->preview($eventKey,$channel,$editorSubject,$editorBody,$locale);
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
        if($rejected!==null){
            echo '<div class="notice notice-error inline"><p><strong>Your last change was not saved.</strong> The message below is exactly what you submitted, so you can correct it and submit again.</p><ul class="dzn-notif-list">';
            foreach((array)($rejected['reasons']??array()) as $reason)echo '<li>'.esc_html((string)$reason).'</li>';
            echo '</ul></div>';
        }
        $subject=$editorSubject;
        $body=$editorBody;
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-editor">';
        // Pressing Enter in a field must never publish: the hidden default saves a draft, and a clicked
        // button's own action value is submitted after it and therefore wins.
        echo '<input type="hidden" name="action" value="dzn_notification_copy_save_draft"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
        foreach(self::tableContext() as $contextKey=>$contextValue)echo '<input type="hidden" name="filter_'.esc_attr($contextKey).'" value="'.esc_attr((string)$contextValue).'">';
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
        self::renderDraftApproval($library,$eventKey,$channel,$locale,$status);
        self::renderHistory($library,$eventKey,$channel,$locale,$status);
    }

    /**
     * Editorial approval for the prepared draft. Bulk publication only accepts a draft whose exact
     * copy was approved here; editing, discarding or publishing the draft revokes the approval.
     */
    private static function renderDraftApproval(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale,array $status):void{
        echo '<h3>Draft approval</h3>';
        if($status['draft']===null){
            echo '<p class="dzn-notif-meta">There is no prepared draft. Save a draft first; preparing catalogue drafts creates one for every supported template.</p>';
            return;
        }
        $approval=$library->draftApproval($eventKey,$channel,$locale);
        $policy=$library->draftReviewPolicy($eventKey,$channel,$locale);
        echo '<p>Bulk publication: <span class="dzn-badge '.($policy['allowed']?'is-ok':'is-warn').'">'.esc_html($policy['allowed']?'Approved and publishable':'Not publishable in bulk').'</span></p>';
        if($policy['reason']!=='')echo '<p class="dzn-notif-meta">'.esc_html((string)$policy['reason']).'</p>';
        if($approval!==null){
            echo '<p class="dzn-notif-meta">Approved for this exact copy on <code dir="ltr">'.esc_html((string)$approval['approved_at']).' UTC</code> by user <code dir="ltr">'.esc_html((string)$approval['approved_by']).'</code>.</p>';
            if(self::canEdit()){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_revoke_approval"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
                wp_nonce_field('dzn_notification_revoke_approval');
                echo '<button class="button button-small">Withdraw approval</button></form>';
            }
            return;
        }
        echo '<p class="dzn-notif-meta">This draft has not been approved. Approving it allows the bulk action to publish it; it does not publish anything now, does not enable the channel and does not replace Meta approval for WhatsApp.</p>';
        if(self::canEdit()){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_approve_draft"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
            wp_nonce_field('dzn_notification_approve_draft');
            echo '<button class="button button-small">Approve draft for publication</button></form>';
        }
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

    public static function prepareCatalogueDrafts():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_prepare_drafts');
        try{
            $result=(new NotificationTemplateLibrary())->prepareMissingCatalogueDrafts();
            $message=sprintf('Step 1: prepared %d new draft(s); %d already existed. %d lack exact-language copy; %d failed validation. Next: approve operational drafts.',(int)$result['created'],(int)$result['existing'],count($result['missing']),count($result['invalid']));
            self::rememberWorkflowResult($message);
            self::flash('success',$message);
            if($result['missing']!==array()||$result['invalid']!==array()){
                update_option('dzn_notification_draft_gap_report',array('missing'=>$result['missing'],'invalid'=>$result['invalid'],'created_at'=>gmdate('c')),false);
            }
        }catch(\Throwable $error){
            self::rememberWorkflowResult('Step 1 failed: '.$error->getMessage());
            self::flash('error','Could not prepare catalogue drafts: '.$error->getMessage());
        }
        self::redirectBack('events',array());
    }

    public static function approveOperationalDrafts():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_approve_operational');
        try{
            $result=(new NotificationTemplateLibrary())->approveOperationalDrafts();
            $restricted=count($result['skipped']);
            $message=sprintf('Step 2: approved %d draft(s); %d held for an external approval or an unmapped provider. Next: publish approved drafts.',(int)$result['approved'],$restricted);
            self::rememberWorkflowResult($message);
            self::flash($result['approved']>0?'success':'warning',$message);
            if($result['skipped']!==array())update_option('dzn_notification_approval_gap_report',array('skipped'=>$result['skipped'],'created_at'=>gmdate('c')),false);
        }catch(\Throwable $error){
            self::rememberWorkflowResult('Step 2 failed: '.$error->getMessage());
            self::flash('error','Could not approve the drafts: '.$error->getMessage());
        }
        self::redirectBack('events',array());
    }

    public static function approveDraft():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_approve_draft');
        try{
            $library=new NotificationTemplateLibrary();
            $library->approveDraft(
                isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'',
                isset($_POST['channel'])?sanitize_key(wp_unslash((string)$_POST['channel'])):'',
                isset($_POST['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_POST['locale']))):NotificationCatalogue::PRIMARY_LOCALE
            );
            self::flash('success','Draft approved for bulk publication. Editing the copy, discarding the draft or publishing it revokes this approval.');
        }catch(\Throwable $error){self::flash('error','The draft could not be approved: '.$error->getMessage());}
        self::redirectBack('events',self::postTableContext() + self::postEditorContext());
    }

    public static function revokeDraftApproval():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_revoke_approval');
        try{
            (new NotificationTemplateLibrary())->revokeDraftApproval(
                isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'',
                isset($_POST['channel'])?sanitize_key(wp_unslash((string)$_POST['channel'])):'',
                isset($_POST['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_POST['locale']))):NotificationCatalogue::PRIMARY_LOCALE
            );
            self::flash('success','Draft approval withdrawn. The draft itself is unchanged.');
        }catch(\Throwable $error){self::flash('error','The approval could not be withdrawn: '.$error->getMessage());}
        self::redirectBack('events',self::postTableContext() + self::postEditorContext());
    }

    public static function publishReviewedDrafts():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_publish_drafts');
        try{
            $result=(new NotificationTemplateLibrary())->publishReviewedDrafts();
            $blocked=count($result['blocked']);
            $message=sprintf('Step 3: published %d draft(s) as new immutable versions; %d stayed review-only. No delivery channel was enabled and no message was sent.',count($result['published']),$blocked);
            self::rememberWorkflowResult($message);
            self::flash($blocked>0?'warning':'success',$message);
            if($result['blocked']!==array())update_option('dzn_notification_draft_review_report',array('blocked'=>$result['blocked'],'created_at'=>gmdate('c')),false);
        }catch(\Throwable $error){
            self::rememberWorkflowResult('Step 3 failed: '.$error->getMessage());
            self::flash('error','Could not publish the reviewed drafts: '.$error->getMessage());
        }
        self::redirectBack('events',array());
    }

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
            self::flash('success',$enabled?'Notification enabled; previous channel preferences retained.':'Notification disabled across all channels; preferences retained.');
        }catch(\Throwable $error){self::flash('error',self::eventToggleError($error->getMessage()));}
        self::redirectBack('events',self::postTableContext(),$eventKey);
    }

    public static function toggleEventChannel():never{
        self::authorizeEdit();
        check_admin_referer('dzn_notification_event_channel_toggle');
        $eventKey=isset($_POST['event_key'])?self::eventKey(wp_unslash((string)$_POST['event_key'])):'';
        $channel=isset($_POST['channel'])?sanitize_key(wp_unslash((string)$_POST['channel'])):'';
        $enabled=isset($_POST['enabled'])&&(string)$_POST['enabled']==='1';
        try{
            $library=new NotificationTemplateLibrary();
            if($enabled){
                $blocker=self::channelBlocker($library,$eventKey,$channel);
                if($blocker!==null)throw new \InvalidArgumentException('notification_channel_unavailable');
            }
            $library->saveEventChannel($eventKey,$channel,$enabled);
            self::flash('success',self::channelLabel($channel).' delivery '.($enabled?'enabled.':'disabled.'));
        }catch(\Throwable $error){self::flash('error',$error->getMessage()==='notification_channel_unavailable'?'This channel cannot be enabled until its template, provider approval and selected route are ready.':'This delivery channel is not available for this event.');}
        self::redirectBack('events',array('template'=>$eventKey,'template_channel'=>$channel)+self::postTableContext(),$eventKey);
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
            'filter_context'=>array(),
        );
        $input['filter_context']=self::postTableContext();
        $filterArgs=array();foreach($input['filter_context'] as $key=>$value)$filterArgs['filter_'.$key]=$value;
        $eventRequired=($options['event_required']??true)!==false;
        $event=$eventRequired?NotificationCatalogue::get($input['event_key']):null;
        if($eventRequired&&$event===null){self::flash('error','Unknown notification event.');self::redirectBack('events',$filterArgs);}
        if($event!==null&&(string)$event['authority']==='dedicated'&&in_array($input['channel'],array('email','whatsapp','sms'),true)){self::flash('error','This message belongs to a dedicated secure delivery authority and is not edited from this page.');self::redirectBack('events',$filterArgs);}
        if($event!==null&&$input['channel']!==''&&!(new NotificationTemplateLibrary())->channelOffered($input['event_key'],$input['channel'])&&in_array($input['channel'],array('email','whatsapp','sms','website'),true)){
            self::flash('error','That delivery channel is not supported by this notification.');
            self::redirectBack('events',array_merge(array('template'=>$input['event_key'],'template_channel'=>$input['channel'],'locale'=>$input['locale']),$filterArgs));
        }
        try{
            $result=$operation(new NotificationTemplateLibrary(),$input);
            self::clearRejectedSubmission();
            self::flash((string)($result['type']??'success'),(string)($result['message']??'Done.'));
        }catch(NotificationTemplateValidationException $error){
            // A rejected edit is never thrown away: the submitted copy and the exact reasons are kept
            // so the editor reopens with the administrator's own text and an inline explanation.
            self::rememberRejectedSubmission($input,$error->reasons());
            self::flash('error',implode(' ',$error->reasons()));
        }catch(\Throwable $error){
            self::flash('error','Save failed: '.$error->getMessage());
        }
        $tab=(string)($options['tab']??'events');
        $args=array();
        if($tab==='events'&&$input['event_key']!==''){$args=array('template'=>$input['event_key'],'template_channel'=>$input['channel'],'locale'=>$input['locale']);$args=array_merge($args,$filterArgs);}
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
        if(!in_array($channel,array('email','whatsapp','sms','sms_ir'),true))return null;
        try{$settings=new IntegrationSettingsService();return $channel==='sms_ir'?$settings->verified($channel):$settings->ready($channel);}catch(\Throwable){return false;}
    }
    private static function smsRouteStatus():array{
        $routing=SmsProviderRoutingPolicy::settings();$status=array();
        foreach(array('iran'=>'Iran','international'=>'other-number') as $region=>$label){
            $provider=(string)$routing[$region];
            if($provider==='off')$status[$region]=array('provider'=>$provider,'ready'=>false,'disabled'=>true,'label'=>$label.' route is Off');
            else $status[$region]=array('provider'=>$provider,'ready'=>self::providerReady($provider==='sms_ir'?'sms_ir':'sms'),'disabled'=>false,'label'=>$label.' uses '.($provider==='sms_ir'?'SMS.ir':'Telnyx'));
        }
        return $status;
    }
    private static function smsRouteReason(array $routes):string{
        $reasons=array();foreach($routes as $route){if(!empty($route['disabled']))$reasons[]=(string)$route['label'].' is Off';elseif(($route['ready']??false)!==true)$reasons[]=(string)$route['label'].' is unavailable';}return implode('; ',$reasons).($reasons===array()?'':'.');
    }
    private static function providerReadyLabel(string $channel):string{
        $ready=self::providerReady($channel);
        return $ready===null?'Not applicable':($ready?'Configured (unverified)':'Not configured');
    }

    /** Effective eligibility is deliberately fail-closed: a stored On value never masks a blocker. */
    private static function channelBlocker(NotificationTemplateLibrary $library,string $eventKey,string $channel):?array{
        $status=$library->status($eventKey,$channel,NotificationCatalogue::PRIMARY_LOCALE);
        $event=NotificationCatalogue::get($eventKey)??array();
        // A canonical event renders strictly from a published version. "No version yet" is only a
        // missing-template fault when nobody has to approve the copy first; when a finance, legal or
        // security sign-off is outstanding the honest state is "awaiting approval", not "missing".
        if((string)($event['authority']??'')==='canonical'&&($status['published']??false)!==true){
            $review=NotificationCatalogue::reviewReasons($eventKey);
            if($review!==array())return array('state'=>'awaiting_approval','label'=>'Awaiting approval','reason'=>implode('; ',$review).'. Publish it from the editor after that approval.');
            return array('state'=>'missing_template','label'=>'Needs publication','reason'=>'The catalogue copy exists but no version has been published for this canonical event.');
        }
        foreach((array)($status['issues']??array()) as $issue){
            $text=(string)$issue;
            if(str_contains($text,'No default copy')||str_contains($text,'No active version')||str_contains($text,'body is unavailable')||str_contains($text,'cannot be dispatched without a published version'))return array('state'=>'missing_template','label'=>'Missing template','reason'=>'Missing or invalid copy.');
            if(str_contains($text,'approved')||str_contains($text,'provider')||str_contains($text,'Meta'))return array('state'=>'missing_provider','label'=>'Missing or unapproved template','reason'=>'Provider approval or setup is missing.');
        }
        if(in_array((string)($status['provider_state']??''),array('not_mapped','approval_required','not_approved','contract_mismatch','restricted_fallback'),true))return array('state'=>'missing_provider','label'=>'Missing or unapproved template','reason'=>'Provider approval or setup is missing.');
        if($channel==='sms'){
            $routes=self::smsRouteStatus();$ready=0;foreach($routes as $route)if(($route['ready']??false)===true)$ready++;
            if($ready===0)return array('state'=>'missing_provider','label'=>'Missing provider','reason'=>self::smsRouteReason($routes)?:'No selected SMS route is available.');
        }elseif(in_array($channel,array('email','whatsapp'),true)&&self::providerReady($channel)===false)return array('state'=>'missing_provider','label'=>'Missing provider','reason'=>'Configure and test the provider in Integrations.');
        return null;
    }
    private static function effectiveChannelState(NotificationTemplateLibrary $library,string $eventKey,string $channel):array{
        if(!$library->channelOffered($eventKey,$channel))return array('state'=>'unsupported','label'=>'Not offered','reason'=>'This notification does not use this delivery method.');
        if(!$library->eventEnabled($eventKey))return array('state'=>'disabled','label'=>'Notification disabled','reason'=>'All channels are switched off for this notification.');
        $locked=($eventKey==='account.claim_issued.student'&&in_array($channel,array('whatsapp','sms'),true));
        $blocker=self::channelBlocker($library,$eventKey,$channel);
        if(!$locked&&!$library->eventChannelEnabled($eventKey,$channel)){
            return array('state'=>'disabled','label'=>'Disabled by administrator','reason'=>'Off.'.($blocker===null?'':' '.$blocker['reason']),'enable_blocked'=>$blocker!==null);
        }
        if($blocker!==null)return $blocker;
        if($channel==='sms'){
            $routes=self::smsRouteStatus();$readyCount=0;$offCount=0;foreach($routes as $route){if(($route['ready']??false)===true)$readyCount++;if(!empty($route['disabled']))$offCount++;}
            $reason=self::smsRouteReason($routes);
            if($readyCount===1)return array('state'=>'partial','label'=>'Partially available','reason'=>$reason);
            if($readyCount===0&&$offCount===2)return array('state'=>'policy_disabled','label'=>'Disabled by SMS policy','reason'=>$reason);
            if($readyCount===0)return array('state'=>'missing_provider','label'=>'Missing provider','reason'=>$reason);
        }elseif(in_array($channel,array('email','whatsapp'),true)&&self::providerReady($channel)===false)return array('state'=>'missing_provider','label'=>'Missing provider','reason'=>'Configure and test the provider in Integrations.');
        if($locked)return array('state'=>'policy_disabled','label'=>'Secure route unavailable','reason'=>'This route cannot safely be enabled without approved account-claim delivery.');
        return array('state'=>'enabled','label'=>'Enabled','reason'=>'');
    }

    /**
     * Six separate facts, deliberately never merged into one badge: catalogue completeness, prepared
     * drafts, published versions, outstanding approvals, delivery readiness and whether a producer
     * even exists. "Complete copy" is not "ready to send", and an event with no producer can never
     * fire however complete its copy is.
     *
     * @param string[] $channels
     * @return array<string,array{text:string,class:string}>
     */
    private static function lifecycleSummary(NotificationTemplateLibrary $library,string $eventKey,array $channels):array{
        $out=array();
        $event=NotificationCatalogue::get($eventKey)??array();

        // 1. Catalogue completeness: is there copy in both languages for every offered channel?
        $copyMissing=0;
        foreach($channels as $channel)foreach(NotificationCatalogue::SUPPORTED_LOCALES as $locale){
            $copy=NotificationCatalogue::exactCopy($eventKey,(string)$channel,$locale);
            if(!is_array($copy)||trim((string)($copy['body']??''))==='')$copyMissing++;
        }
        $out['Catalogue copy']=$copyMissing===0
            ?array('text'=>'Complete in both languages','class'=>'is-ok')
            :array('text'=>'Missing '.$copyMissing.' template(s)','class'=>'is-warn');

        // 2. Drafts prepared, and how many of them carry a valid editorial approval.
        $drafted=0;$approved=0;$draftBlocked=array();
        foreach($channels as $channel)foreach(NotificationCatalogue::SUPPORTED_LOCALES as $locale){
            $status=$library->status($eventKey,(string)$channel,$locale);
            if($status['draft']!==null){
                $drafted++;
                if($library->draftApproval($eventKey,(string)$channel,$locale)!==null)$approved++;
            }
        }
        $out['Drafts']=$drafted===0
            ?array('text'=>'None prepared','class'=>'')
            :array('text'=>$drafted.' prepared, '.$approved.' approved for publication','class'=>$approved===$drafted?'is-ok':'is-warn');

        // 3. Publication: how many offered channels have an active immutable version.
        $published=0;
        foreach($channels as $channel)$published+=$library->status($eventKey,(string)$channel,NotificationCatalogue::PRIMARY_LOCALE)['published']?1:0;
        $canonical=(string)($event['authority']??'')==='canonical';
        $out['Publication']=$published>0
            ?array('text'=>$published.' of '.count($channels).' channel(s) published','class'=>$published===count($channels)?'is-ok':'is-warn')
            :array('text'=>$canonical?'Required before this canonical event can dispatch':'No version published','class'=>$canonical?'is-warn':'');

        // 4. Approvals: editorial review flags and provider approval for the offered routes.
        $review=NotificationCatalogue::reviewReasons($eventKey);
        $providerPending=array();
        if(in_array('whatsapp',$channels,true))$providerPending[]='WhatsApp';
        if(in_array('sms',$channels,true)&&self::smsRouteReason(self::smsRouteStatus())!=='')$providerPending[]='SMS';
        if(in_array('email',$channels,true)&&self::providerReady('email')===false)$providerPending[]='Email';
        $approvalParts=array();
        if($review!==array())$approvalParts[]=implode(', ',$review);
        if($providerPending!==array())$approvalParts[]='provider approval for '.implode(' and ',$providerPending);
        $out['Approvals']=$approvalParts===array()
            ?array('text'=>'None outstanding','class'=>'is-ok')
            :array('text'=>'Awaiting '.implode('; ',$approvalParts),'class'=>'is-warn');

        // 5. Delivery: what an administrator has switched on, and what is actually ready to send.
        $enabled=0;$ready=0;
        foreach($channels as $channel){
            if($library->eventChannelEnabled($eventKey,(string)$channel)){$enabled++;$ready++;}
        }
        $out['Delivery']=array('text'=>$enabled.' of '.count($channels).' channel(s) enabled','class'=>$enabled>0?'is-ok':'');

        // 6. Producer: without one the notification can never be raised.
        $registered=!empty($event['producer_registered']);
        $out['Producer']=$registered
            ?array('text'=>'Registered','class'=>'is-ok')
            :array('text'=>'None registered — cannot fire yet','class'=>'is-warn');
        return $out;
    }

    /** A single, filterable readiness state for the catalogue row. */
    private static function readiness(NotificationTemplateLibrary $library,string $eventKey,array $channels):string{
        $event=NotificationCatalogue::get($eventKey)??array();
        // An event with no registered producer can never be raised, whatever its copy and provider
        // state. It is a distinct state so it stays visible as outstanding implementation work.
        if(empty($event['producer_registered']))return 'no_producer';
        $hasDraft=false;$hasDisabled=false;$hasMissing=false;$hasProvider=false;$awaiting=false;
        $canonical=(string)($event['authority']??'')==='canonical';
        $reviewed=NotificationCatalogue::reviewReasons($eventKey)!==array();
        foreach($channels as $channel){
            $status=$library->status($eventKey,(string)$channel,NotificationCatalogue::PRIMARY_LOCALE);
            if(!$library->eventChannelEnabled($eventKey,(string)$channel))$hasDisabled=true;
            if($status['draft']!==null)$hasDraft=true;
            // A sensitive canonical event with no published version is waiting for its owner, not
            // missing copy. Reuse the status already fetched rather than resolving the blocker again.
            if($canonical&&$reviewed&&($status['published']??false)!==true)$awaiting=true;
            foreach((array)$status['issues'] as $issue){
                $issue=(string)$issue;
                if(str_contains($issue,'No default copy')||str_contains($issue,'No active version')||str_contains($issue,'body is unavailable'))$hasMissing=true;
                if(str_contains($issue,'Meta')||str_contains($issue,'approved')||str_contains($issue,'provider'))$hasProvider=true;
            }
            if((string)$channel==='sms'&&self::smsRouteReason(self::smsRouteStatus())!=='')$hasProvider=true;
            elseif(in_array((string)$channel,array('email','whatsapp'),true)&&self::providerReady((string)$channel)===false)$hasProvider=true;
        }
        if($awaiting)return 'awaiting_approval';
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
            default=>'The notification event was not changed.',
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

    /** The rejected submission, keyed per user so two administrators cannot see each other's text. */
    private const REJECTED='dzn_notification_rejected_';
    private static function rememberRejectedSubmission(array $input,array $reasons):void{
        if(!function_exists('set_transient'))return;
        set_transient(self::REJECTED.get_current_user_id(),array(
            'event_key'=>(string)($input['event_key']??''),'channel'=>(string)($input['channel']??''),'locale'=>(string)($input['locale']??''),
            'subject'=>(string)($input['subject']??''),'body'=>(string)($input['body']??''),'reasons'=>array_values($reasons),'at'=>gmdate('Y-m-d H:i:s'),
        ),1800);
    }
    private static function rejectedSubmission(string $eventKey,string $channel,string $locale):?array{
        if(!function_exists('get_transient'))return null;
        $value=get_transient(self::REJECTED.get_current_user_id());
        if(!is_array($value))return null;
        if((string)($value['event_key']??'')!==$eventKey||(string)($value['channel']??'')!==$channel)return null;
        if(NotificationCatalogue::normaliseLocale((string)($value['locale']??''))!==$locale)return null;
        return $value;
    }
    private static function clearRejectedSubmission():void{
        if(function_exists('delete_transient'))delete_transient(self::REJECTED.get_current_user_id());
    }

    /** @param array<string,string|int> $args */
    private static function url(array $args=array()):string{return admin_url('admin.php?'.http_build_query(array_merge(array('page'=>self::PAGE),$args)));}
    private static function tableContext():array{
        $out=array();
        foreach(array('search','category','recipient','channel','status') as $key){
            $source='filter_'.$key;
            if(!isset($_GET[$source])||!is_scalar($_GET[$source]))$source=$key;
            if(isset($_GET[$source])&&is_scalar($_GET[$source]))$out[$key]=sanitize_text_field(wp_unslash((string)$_GET[$source]));
        }
        return $out;
    }
    private static function postTableContext():array{
        $out=array();
        foreach(array('search','category','recipient','channel','status') as $key)if(isset($_POST['filter_'.$key])&&is_scalar($_POST['filter_'.$key]))$out[$key]=sanitize_text_field(wp_unslash((string)$_POST['filter_'.$key]));
        return $out;
    }
    /** Reopen the same editor after an approval action. */
    private static function postEditorContext():array{
        $out=array();
        if(isset($_POST['event_key'])&&is_scalar($_POST['event_key'])){$eventKey=self::eventKey(wp_unslash((string)$_POST['event_key']));if($eventKey!=='')$out['template']=$eventKey;}
        if(isset($_POST['channel'])&&is_scalar($_POST['channel']))$out['template_channel']=sanitize_key(wp_unslash((string)$_POST['channel']));
        $locale=isset($_POST['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_POST['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
        if($out!==array())$out['locale']=$locale;
        return $out;
    }
    private static function editorUrl(string $eventKey,string $channel,string $locale):string{
        $args=array('template'=>$eventKey,'template_channel'=>$channel,'locale'=>$locale);
        foreach(self::tableContext() as $key=>$value)$args['filter_'.$key]=$value;
        return self::url($args);
    }
    private static function redirectBack(string $tab,array $extra=array(),string $anchor=''):never{
        if($anchor===''&&isset($extra['template'])&&$extra['template']!=='')$anchor=(string)$extra['template'];
        $url=self::url(array_merge(array('tab'=>$tab),$extra));
        if($anchor!=='')$url.='#event-'.$anchor;
        wp_safe_redirect($url);exit;
    }
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
        'restricted_fallback'=>'Provider not configured','not_applicable'=>'Not applicable','unsupported'=>'Unsupported',
        'not_permitted'=>'Not offered by category policy',
    )[$state]??$state;}
    private static function stateLabel(string $state):string{return array('draft'=>'Draft','active'=>'Active','superseded'=>'Superseded','retired'=>'Retired')[$state]??$state;}
    private static function sourceLabel(string $source):string{return array('authored'=>'Authored','rollback'=>'Rollback','default'=>'Default copy','published'=>'Published')[$source]??($source!==''?$source:'—');}
    private static function consentLabel(string $consent):string{return array(
        'security'=>'Security requirement; no marketing consent','operational'=>'Operational message based on the teaching relationship',
        'financial'=>'Financial message','marketing'=>'Marketing consent only',
    )[$consent]??$consent;}
    private static function audienceLabel(string $audience):string{return array('student'=>'Student','teacher'=>'Teacher','guardian'=>'Guardian','admin'=>'Admin')[$audience]??$audience;}
}
