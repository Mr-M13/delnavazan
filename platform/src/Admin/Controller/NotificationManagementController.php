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
 * «اعلان‌ها و پیام‌رسانی» — one capability-checked, nonce-protected communications workspace.
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
        echo '<div class="wrap dzn-notif-admin" dir="rtl"><style>.dzn-notif-admin{direction:rtl}.dzn-notif-admin .nav-tab-wrapper{margin-block-end:16px}.dzn-notif-lede{max-width:72ch;color:#50575e}.dzn-notif-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px}.dzn-notif-panel h2{margin-block-start:6px}.dzn-notif-meta{color:#646970;font-size:12px;line-height:1.9}.dzn-notif-note{color:#50575e;font-size:12px;line-height:1.9;background:#f6f7f7;border-inline-start:3px solid #0E8C8C;padding:8px 12px;border-radius:3px}.dzn-notif-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-block:14px}.dzn-notif-card{border:1px solid #dcdcde;border-radius:6px;padding:14px}.dzn-notif-card h3{margin-block-start:0}.dzn-notif-list{margin:0;padding-inline-start:18px;line-height:2}.dzn-notif-table td,.dzn-notif-table th{vertical-align:top}.dzn-notif-preview{background:#FBF7EE;border:1px solid #e6dfd2;border-radius:8px;padding:10px}.dzn-notif-preview pre{white-space:pre-wrap;font-family:Tahoma,Arial,sans-serif;direction:rtl;margin:0;line-height:2}.dzn-notif-preview--text{background:#fff}.dzn-notif-editor textarea{font-family:Tahoma,Arial,sans-serif;line-height:2;direction:rtl}.dzn-notif-actions{display:flex;gap:8px;flex-wrap:wrap}.dzn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a}.dzn-badge.is-ok{background:#edfaef;color:#1e6b2f}.dzn-badge.is-warn{background:#fcf3e6;color:#8a4b00}.dzn-badge.is-locked{background:#fbeaea;color:#8a1f1f}.dzn-notif-inline-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.dzn-notif-admin code{direction:ltr;unicode-bidi:embed}.dzn-notif-admin details pre{max-height:220px;overflow:auto;white-space:pre-wrap}</style><h1>اعلان‌ها و پیام‌رسانی</h1>';
        echo '<p class="dzn-notif-lede">مدیریت یکپارچهٔ قالب‌های ایمیل، واتس‌اپ، پیامک و اعلان‌های پرتال. ویرایش قالب، گیرنده یا مجوز یک اقدام کاری را تغییر نمی‌دهد.</p>';
        self::renderFlash();
        $tabs=array('templates'=>'قالب پیام‌ها','rules'=>'قوانین ارسال','monitoring'=>'وضعیت ارسال','channels'=>'تنظیمات کانال‌ها','website'=>'اعلان‌های پرتال');
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
            if(NotificationCatalogue::get($eventKey)===null){echo '<p>رویداد انتخابی یافت نشد.</p>';return;}
            self::renderEditor($library,$eventKey,$channel,$locale);
            return;
        }
        $channel=isset($_GET['channel'])&&is_scalar($_GET['channel'])?sanitize_key(wp_unslash((string)$_GET['channel'])):'';
        $locale=isset($_GET['locale'])&&is_scalar($_GET['locale'])?NotificationCatalogue::normaliseLocale(sanitize_text_field(wp_unslash((string)$_GET['locale']))):NotificationCatalogue::PRIMARY_LOCALE;
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-notif-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="templates">';
        echo '<label>کانال <select name="channel"><option value="">همه</option>';
        foreach(array('email'=>'ایمیل','whatsapp'=>'واتس‌اپ','sms'=>'پیامک','website'=>'پرتال') as $value=>$label)echo '<option value="'.esc_attr($value).'"'.($channel===$value?' selected':'').'>'.esc_html($label).'</option>';
        echo '</select></label><label>زبان <select name="locale"><option value="fa-IR"'.($locale==='fa-IR'?' selected':'').'>فارسی</option><option value="en"'.($locale==='en'?' selected':'').'>English</option></select></label><button class="button">نمایش</button></form>';
        $rows=$library->catalogueRows($channel===''?null:$channel,$locale);
        $groups=array();
        foreach($rows as $row)$groups[(string)$row['event_key']][]=$row;
        echo '<p class="dzn-notif-meta">'.esc_html((string)count($rows)).' قالب در '.esc_html((string)count($groups)).' رویداد. متن پیش‌فرض همیشه قابل بازگشت است و نسخه‌های منتشرشده تغییرناپذیر می‌مانند.</p>';
        echo '<table class="widefat striped dzn-notif-table"><thead><tr><th>رویداد</th><th>دسته</th><th>کانال</th><th>وضعیت نسخه</th><th>ارائه‌دهنده</th><th>هشدار</th><th></th></tr></thead><tbody>';
        foreach($groups as $eventRows){
            $first=true;
            foreach($eventRows as $row){
                echo '<tr>';
                if($first){echo '<td rowspan="'.esc_attr((string)count($eventRows)).'"><strong>'.esc_html((string)$row['label']).'</strong><br><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td>';echo '<td rowspan="'.esc_attr((string)count($eventRows)).'">'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$row['category']]['label']??$row['category'])).'</td>';$first=false;}
                echo '<td>'.esc_html(self::channelLabel((string)$row['channel'])).' · '.esc_html((string)$row['locale']).'</td>';
                echo '<td>'.esc_html(self::versionStateLabel((string)$row['active_state'],(bool)$row['published'],$row['draft'])).'</td>';
                echo '<td>'.esc_html(self::providerStateLabel((string)$row['provider_state'])).'</td>';
                echo '<td>'.($row['issues']?'<span class="dzn-badge is-warn">'.esc_html((string)count($row['issues'])).' مورد</span>':'<span class="dzn-badge is-ok">آماده</span>').'</td>';
                echo '<td><a class="button button-small" href="'.esc_url(self::url(array('tab'=>'templates','template'=>(string)$row['event_key'],'channel'=>(string)$row['channel'],'locale'=>(string)$row['locale']))).'">'.(self::canEdit()?'ویرایش':'مشاهده').'</a></td></tr>';
            }
        }
        echo '</tbody></table>';
        if(!self::canEdit())echo '<p class="dzn-notif-note">دسترسی شما فقط خواندنی است. برای ویرایش و انتشار به مجوز <code>dzn_manage_notification_templates</code> نیاز دارید.</p>';
    }

    private static function renderEditor(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale):void{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return;
        $status=$library->status($eventKey,$channel,$locale);
        $working=$library->workingCopy($eventKey,$channel,$locale);
        $editable=self::canEdit()&&(string)$event['authority']!=='dedicated';
        $isDedicated=(string)$event['authority']==='dedicated';
        echo '<p><a href="'.esc_url(self::url(array('tab'=>'templates'))).'">‹ بازگشت به فهرست قالب‌ها</a></p>';
        echo '<h2>'.esc_html((string)$event['label']).'</h2><p class="dzn-notif-meta"><code dir="ltr">'.esc_html($eventKey).'</code> · '.esc_html(self::channelLabel($channel)).' · '.esc_html($locale).' · دستهٔ '.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</p>';
        echo '<div class="dzn-notif-grid">';
        echo '<section class="dzn-notif-card"><h3>قرارداد متغیرها</h3><p>جای‌نگهدارهای مجاز:</p><p>';
        foreach(NotificationCatalogue::variables($eventKey) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p>جای‌نگهدارهای الزامی برای این کانال:</p><p>';
        foreach(NotificationTemplateSanitizer::requiredVariables($eventKey,$channel) as $variable)echo '<code>{{'.esc_html($variable).'}}</code> ';
        echo '</p><p class="dzn-notif-note">متن به‌صورت متن ساده ذخیره می‌شود؛ ساختار HTML و نشان‌گذاری برند را خودِ سامانه می‌سازد. اسکریپت، PHP، تگ HTML و رویداد مرورگر پذیرفته نمی‌شود.</p></section>';
        echo '<section class="dzn-notif-card"><h3>وضعیت</h3><ul class="dzn-notif-list">';
        echo '<li>نسخهٔ فعال: '.esc_html($status['active_version_number']!==null?('#'.$status['active_version_number']):'—').'</li>';
        echo '<li>متن فعال ذخیره‌شده: '.esc_html($status['published']?'بله':'خیر').'</li>';
        echo '<li>پیش‌نویس: '.esc_html($status['draft']!==null?'دارد':'ندارد').'</li>';
        echo '<li>وضعیت ارائه‌دهنده: '.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</li></ul>';
        if($status['issues']){echo '<div class="notice notice-warning inline"><ul>';foreach($status['issues'] as $issue)echo '<li>'.esc_html((string)$issue).'</li>';echo '</ul></div>';}
        if($isDedicated){echo '<div class="notice notice-info inline"><p>این پیام به یک مرجع تحویل امن اختصاصی وابسته است. متن آن از <a href="'.esc_url(admin_url('admin.php?page=dzn-communications-templates')).'">صفحهٔ قالب دعوت مدرس</a> ویرایش می‌شود تا کد یک‌بارمصرف از مسیر عمومی اعلان‌ها دور بماند.</p></div>';}
        echo '</section></div>';

        if($isDedicated){
            self::renderHistory($library,$eventKey,$channel,$locale,$status);
            return;
        }

        echo '<h3>پیش‌نمایش با دادهٔ ساختگی</h3>';
        $preview=$library->preview($eventKey,$channel,(string)($working['editable']['subject']??''),(string)($working['editable']['body']??''),$locale);
        echo '<p class="dzn-notif-note">'.esc_html(NotificationCatalogue::previewNotice()).'</p>';
        if($channel==='email'){
            echo '<div class="dzn-notif-preview"><iframe title="پیش‌نمایش ایمیل" sandbox="" srcdoc="'.esc_attr((string)$preview['html']).'" style="width:100%;min-height:560px;border:1px solid #dccfc0;border-radius:8px;background:#fff;"></iframe></div>';
        }else{
            echo '<div class="dzn-notif-preview dzn-notif-preview--text"><strong>'.esc_html((string)($preview['subject']??'')).'</strong><pre>'.esc_html((string)($preview['text']??'')).'</pre>';
            if(is_array($preview['segments']??null))echo '<p class="dzn-notif-meta">'.esc_html('طول: '.$preview['segments']['length'].' · بخش‌ها: '.$preview['segments']['segments'].' · رمزگذاری: '.$preview['segments']['encoding']).'</p>';
            echo '</div>';
        }
        echo '<h3>ویرایش متن</h3>';
        $subject=(string)($working['editable']['subject']??'');
        $body=(string)($working['editable']['body']??'');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-editor">';
        echo '<input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'">';
        wp_nonce_field('dzn_notification_copy_save_draft','dzn_draft_nonce');
        wp_nonce_field('dzn_notification_copy_publish','dzn_publish_nonce');
        wp_nonce_field('dzn_notification_copy_discard','dzn_discard_nonce');
        wp_nonce_field('dzn_notification_copy_restore_default','dzn_restore_default_nonce');
        if($channel!=='sms')echo '<p><label for="dzn-notif-subject"><strong>'.($channel==='website'?'عنوان اعلان':'عنوان').'</strong></label><br><input id="dzn-notif-subject" class="large-text" maxlength="180" name="subject" value="'.esc_attr($subject).'"'.($editable?'':' readonly').'></p>';
        else echo '<input type="hidden" name="subject" value="'.esc_attr($subject).'">';
        echo '<p><label for="dzn-notif-body"><strong>متن</strong></label><br><textarea id="dzn-notif-body" class="large-text" rows="14" maxlength="5000" name="body"'.($editable?'':' readonly').'>'.esc_textarea($body).'</textarea></p>';
        if($editable){
            echo '<p class="dzn-notif-actions">';
            echo '<button class="button" name="action" value="dzn_notification_copy_save_draft">ذخیرهٔ پیش‌نویس</button> ';
            echo '<button class="button button-primary" name="action" value="dzn_notification_copy_publish">انتشار نسخهٔ تازه</button> ';
            if($status['draft']!==null)echo '<button class="button" name="action" value="dzn_notification_copy_discard">کنار گذاشتن پیش‌نویس</button> ';
            echo '<button class="button" name="action" value="dzn_notification_copy_restore_default" onclick="return confirm(\'متن پیش‌فرض به‌صورت یک نسخهٔ تازه منتشر شود؟\')">بازگشت به متن پیش‌فرض</button>';
            echo '</p>';
        }
        echo '</form>';
        self::renderHistory($library,$eventKey,$channel,$locale,$status);
    }

    private static function renderHistory(NotificationTemplateLibrary $library,string $eventKey,string $channel,string $locale,array $status):void{
        $history=$library->history($eventKey,$channel,$locale);
        echo '<h3>تاریخ نسخه‌ها</h3>';
        if($history===array()){echo '<p>هنوز نسخه‌ای منتشر نشده است. متن پیش‌فرض در دسترس است.</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>نسخه</th><th>وضعیت</th><th>زمان</th><th>مبدأ</th><th>متن</th><th></th></tr></thead><tbody>';
        foreach($history as $version){
            echo '<tr><td>#'.esc_html((string)$version['version_number']).'</td><td>'.esc_html(self::stateLabel((string)$version['state'])).'</td><td><code dir="ltr">'.esc_html((string)$version['created_at']).'</code></td><td>'.esc_html(self::sourceLabel((string)($version['source']??''))).'</td>';
            echo '<td>'.($version['body_present']?'<details><summary>نمایش</summary><strong>'.esc_html((string)$version['subject']).'</strong><pre>'.esc_html((string)$version['body']).'</pre></details>':'<span class="dzn-badge is-warn">متن ذخیره نشده</span>').'</td><td>';
            if(self::canEdit()&&$version['body_present']&&(int)$status['active_version_id']!==(int)$version['template_version_id']){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_copy_restore"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="'.esc_attr($channel).'"><input type="hidden" name="locale" value="'.esc_attr($locale).'"><input type="hidden" name="template_version_id" value="'.esc_attr((string)$version['template_version_id']).'">';
                wp_nonce_field('dzn_notification_copy_restore');
                echo '<button class="button button-small">انتشار دوبارهٔ این متن</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="dzn-notif-note">بازگردانی، نسخهٔ قدیمی را تغییر نمی‌دهد؛ همان متن به‌صورت یک نسخهٔ تازه و تغییرناپذیر منتشر می‌شود. پیام‌هایی که نسخه‌شان تثبیت شده، همان نسخه را حفظ می‌کنند.</p>';
    }

    // ------------------------------------------------------------------ rules

    private static function renderRules():void{
        $library=new NotificationTemplateLibrary();
        echo '<h2>قوانین ارسال</h2><p>هر دسته یک سیاست کانال دارد. پیام‌های امنیتی، حساب و مالی قابل خاموش‌شدن نیستند و رضایت بازاریابی جایگزین اختیار پیام عملیاتی نمی‌شود.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_channel_policy">';
        wp_nonce_field('dzn_notification_channel_policy');
        echo '<table class="widefat striped"><thead><tr><th>دسته</th><th>ایمیل</th><th>پرتال</th><th>واتس‌اپ</th><th>پیامک</th><th>پیامک به‌جای واتس‌اپ</th><th>اجباری</th></tr></thead><tbody>';
        foreach($library->channelPolicies() as $category=>$policy){
            $locked=!empty($policy['mandatory']);
            echo '<tr><td><input type="hidden" name="policy['.esc_attr($category).'][present]" value="1"><strong>'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['label']??$category)).'</strong><br><span class="dzn-notif-meta">'.esc_html((string)(NotificationCatalogue::CATEGORIES[$category]['description']??'')).'</span></td>';
            foreach(array('email','website','whatsapp','sms','sms_fallback') as $flag){
                echo '<td><input type="checkbox" name="policy['.esc_attr($category).']['.esc_attr($flag).']" value="1"'.(!empty($policy[$flag])?' checked':'').(($locked&&in_array($flag,array('email','website'),true))?' disabled':'').'>';
            }
            echo '<td>'.($locked?'<span class="dzn-badge is-locked">قفل</span>':'<span class="dzn-badge">اختیاری</span>').'</td></tr>';
        }
        echo '</tbody></table>';
        if(self::canEdit())echo '<p><button class="button button-primary">ذخیرهٔ قوانین کانال</button></p>';
        echo '</form>';
        echo '<h3>نگاشت رویداد به کانال</h3><table class="widefat striped"><thead><tr><th>رویداد</th><th>دسته</th><th>کانال‌ها</th><th>اختیار تحویل</th><th>مبنای رضایت</th></tr></thead><tbody>';
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            echo '<tr><td>'.esc_html((string)$event['label']).'<br><code dir="ltr">'.esc_html($eventKey).'</code></td><td>'.esc_html((string)(NotificationCatalogue::CATEGORIES[(string)$event['category']]['label']??'')).'</td><td>'.esc_html(implode('، ',array_map(array(__CLASS__,'channelLabel'),(array)$event['channels']))).'</td><td>'.esc_html((string)(NotificationCatalogue::AUTHORITIES[(string)$event['authority']]??$event['authority'])).'</td><td>'.esc_html(self::consentLabel((string)$event['consent'])).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------ monitoring

    private static function renderMonitoring():void{
        echo '<h2>وضعیت ارسال</h2><p class="dzn-notif-note">«تحویل به ارائه‌دهنده» فقط یعنی سرویس ایمیل/پیام‌رسان درخواست را پذیرفت. «تأیید تحویل» تنها با رسید معتبر ارائه‌دهنده نمایش داده می‌شود.</p>';
        $states=array();
        $report=null;
        if(current_user_can(self::CAP_VIEW)||current_user_can('manage_options')){
            try{$report=(new NotificationDiagnosticsReadService())->report();$states=(array)($report['counts']??array());}catch(\Throwable $error){echo '<div class="notice notice-error inline"><p>خواندن تشخیص‌های سرویس اعلان ممکن نشد.</p></div>';}
        }else{echo '<div class="notice notice-warning inline"><p>برای دیدن تشخیص‌های سرویس اعلان به مجوز <code>dzn_view_notification_authority</code> نیاز دارید.</p></div>';}
        $labels=array(
            'pending'=>'در انتظار زمان‌بندی','scheduled'=>'زمان‌بندی‌شده','queued'=>'در صف','dispatching'=>'در حال پردازش',
            'dispatched'=>'تحویل‌شده به ارائه‌دهنده','delivered'=>'تأیید تحویل','closed'=>'بسته‌شده',
            'suppressed'=>'مسدودشده','cancelled'=>'لغوشده','expired'=>'منقضی','failed'=>'ناموفق',
        );
        echo '<table class="widefat striped"><thead><tr><th>وضعیت</th><th>تعداد</th><th>معنا</th></tr></thead><tbody>';
        foreach($labels as $state=>$label){
            $count=(int)($states[$state]??0);
            $meaning=match($state){
                'dispatched'=>'ارائه‌دهنده درخواست را پذیرفته؛ تحویل نهایی تأیید نشده است.',
                'delivered'=>'رسید تحویل معتبر ثبت شده است.',
                'queued'=>'آمادهٔ برداشت توسط کارگر ارسال.','dispatching'=>'اجارهٔ ارسال فعال است.',
                'failed'=>'بسته‌شده با خطا؛ تلاش خودکار متوقف است.','expired'=>'پنجرهٔ مجاز ارسال تمام شده است.',
                'suppressed'=>'با درخواست گیرنده یا سیاست رضایت متوقف شده است.','cancelled'=>'با فرمان صریح لغو شده است.',
                default=>'—',
            };
            echo '<tr><td>'.esc_html($label).'<br><code dir="ltr">'.esc_html($state).'</code></td><td>'.esc_html((string)$count).'</td><td>'.esc_html($meaning).'</td></tr>';
        }
        echo '</tbody></table>';
        if(is_array($report)){
            echo '<h3>تشخیص‌های عملیاتی</h3><table class="widefat striped"><tbody>';
            $diagnostics=array(
                'unregistered_intent'=>'رویداد ثبت‌نشده در صف','unroutable_intent'=>'رویداد بدون مسیر فعال','orchestration_backlog'=>'انباشت زمان‌بندی',
                'stuck_lease'=>'اجارهٔ گیرکرده','expired_lease'=>'اجارهٔ منقضی','orphan_outbox_row'=>'ردیف صف بی‌صاحب',
                'retry_exhausted'=>'تلاش دوبارهٔ تمام‌شده','retry_window_exhausted'=>'پنجرهٔ تلاش دوبارهٔ تمام‌شده',
            );
            foreach($diagnostics as $key=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($report[$key]??0)).'</td></tr>';
            echo '</tbody></table>';
            if(!empty($report['unroutable_intents']))echo '<p class="dzn-notif-note">رویدادهای بدون مسیر: <code dir="ltr">'.esc_html(implode(', ',(array)$report['unroutable_intents'])).'</code></p>';
        }
        try{
            $inbox=new PortalNotificationInbox();
            $summary=$inbox->adminSummary();
            echo '<h3>اعلان‌های پرتال</h3><table class="widefat striped"><thead><tr><th>مخاطب</th><th>کل</th><th>خوانده‌نشده</th></tr></thead><tbody>';
            foreach(array('student'=>'هنرجو','teacher'=>'مدرس','admin'=>'مدیر') as $audience=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($summary[$audience]['total']??0)).'</td><td>'.esc_html((string)(int)($summary[$audience]['unread']??0)).'</td></tr>';
            echo '</tbody></table>';
        }catch(\Throwable $error){echo '<div class="notice notice-warning inline"><p>جدول اعلان‌های پرتال هنوز آماده نیست. مهاجرت پایگاه داده را بررسی کنید.</p></div>';}
        $issues=0;foreach((new NotificationTemplateLibrary())->catalogueRows(null,NotificationCatalogue::PRIMARY_LOCALE) as $row){if($row['issues'])$issues++;}
        echo '<h3>پیکربندی قالب‌ها</h3><p>'.esc_html($issues===0?'همهٔ قالب‌های زبان اصلی متن و نسخهٔ فعال دارند.':($issues.' قالب دارای هشدار پیکربندی است.')).' <a href="'.esc_url(self::url(array('tab'=>'templates'))).'">بررسی قالب‌ها</a></p>';
        if(current_user_can('dzn_operate_notification_dispatch')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_recover_leases">';
            wp_nonce_field('dzn_notification_recover_leases');
            echo '<p><button class="button">بازیابی ایمن اجاره‌های منقضی</button> <span class="dzn-notif-meta">فقط اجاره‌های منقضی را با همان سیاست تلاش دوباره آزاد می‌کند و هیچ پیام تازه‌ای نمی‌سازد.</span></p></form>';
        }
    }

    // ------------------------------------------------------------------ channels

    private static function renderChannels():void{
        echo '<h2>تنظیمات کانال‌ها</h2><p>این صفحه فقط آمادگی و نگاشت را نشان می‌دهد؛ کلیدها و رمزهای ارائه‌دهنده هرگز نمایش داده نمی‌شوند.</p>';
        try{
            $report=(new IntegrationControlPlaneReadService())->report();
        }catch(\Throwable $error){$report=null;}
        echo '<div class="dzn-notif-grid">';
        $channels=array(
            'email'=>array('ایمیل','تنظیمات ایمیل','dzn-integration-email'),
            'whatsapp'=>array('واتس‌اپ (Meta Cloud API)','تنظیمات واتس‌اپ','dzn-integration-whatsapp'),
            'sms'=>array('پیامک (Telnyx)','تنظیمات پیامک','dzn-integration-sms'),
        );
        foreach($channels as $channel=>[$label,$link,$slug]){
            $state=(string)($report['notifications']['channels'][$channel]??'not_configured');
            echo '<section class="dzn-notif-card"><h3>'.esc_html($label).'</h3><p>وضعیت اتصال: <strong>'.esc_html($state==='configured'?'پیکربندی‌شده (تأییدنشده)':'پیکربندی‌نشده').'</strong></p><p class="dzn-notif-note">پیکربندی به‌معنای تأیید تحویل نیست.</p><p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($link).'</a></p></section>';
        }
        echo '</div>';
        echo '<h3>نگاشت قالب واتس‌اپ</h3><p>برای ارسال تجاری، هر رویداد باید به یک قالب تأییدشدهٔ Meta با کد زبان نگاشت شود. تغییر متن محلی، تأیید دوبارهٔ ارائه‌دهنده را لازم می‌کند و ارسال بدون نگاشت معتبر آغاز نمی‌شود.</p>';
        $library=new NotificationTemplateLibrary();
        echo '<table class="widefat striped"><thead><tr><th>رویداد</th><th>وضعیت نگاشت</th><th>شناسه و زبان</th><th></th></tr></thead><tbody>';
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            if(!in_array('whatsapp',(array)$event['channels'],true))continue;
            $status=$library->status($eventKey,'whatsapp',NotificationCatalogue::PRIMARY_LOCALE);
            $mapping=$library->providerMapping((string)$status['template_key']);
            echo '<tr><td>'.esc_html((string)$event['label']).'<br><code dir="ltr">'.esc_html($eventKey).'</code></td><td>'.esc_html(self::providerStateLabel((string)$status['provider_state'])).'</td><td><code dir="ltr">'.esc_html($mapping['name']!==''?$mapping['name'].' · '.$mapping['language']:'—').'</code></td><td>';
            if(self::canEdit()){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_notification_provider_mapping"><input type="hidden" name="event_key" value="'.esc_attr($eventKey).'"><input type="hidden" name="channel" value="whatsapp"><input type="hidden" name="locale" value="fa-IR">';
                wp_nonce_field('dzn_notification_provider_mapping');
                echo '<input name="provider_name" placeholder="template_name" value="'.esc_attr($mapping['name']).'" maxlength="191" dir="ltr"><input name="provider_language" placeholder="fa" value="'.esc_attr($mapping['language']).'" maxlength="10" dir="ltr" size="6"><button class="button button-small">ثبت نگاشت</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>طول پیامک</h3><p>پیام فارسی در قالب UCS-2 سنجیده می‌شود: یک بخش ۷۰ نویسه و هر بخش بعدی ۶۷ نویسه. پیش‌نمایش هر قالب پیامک، طول و تعداد بخش‌ها را نشان می‌دهد. پیامک فقط به‌عنوان جایگزین محدود و در صورت مجازبودن سیاست ارسال استفاده می‌شود.</p>';
    }

    // ------------------------------------------------------------------ website inbox

    private static function renderWebsite():void{
        $inbox=new PortalNotificationInbox();
        try{$summary=$inbox->adminSummary();$recent=$inbox->recent(50);}catch(\Throwable $error){echo '<div class="notice notice-error inline"><p>جدول اعلان‌های پرتال خوانده نشد. مهاجرت پایگاه داده را بررسی کنید.</p></div>';return;}
        echo '<h2>اعلان‌های پرتال</h2><p>اعلان‌های هنرجو و مدرس در پرتال شخصی و با پیوند به همان رویداد کاری نمایش داده می‌شوند. اعلان مدیر جدا نگه داشته می‌شود و کلیک روی اعلان هیچ اقدام کاری را تأیید نمی‌کند.</p>';
        echo '<table class="widefat striped"><thead><tr><th>مخاطب</th><th>کل</th><th>خوانده‌نشده</th><th>سیاست نگهداری</th></tr></thead><tbody>';
        foreach(array('student'=>'هنرجو','teacher'=>'مدرس','admin'=>'مدیر') as $audience=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)(int)($summary[$audience]['total']??0)).'</td><td>'.esc_html((string)(int)($summary[$audience]['unread']??0)).'</td><td>'.esc_html($audience==='admin'?'فقط پیشخوان مدیر':(PortalNotificationInbox::RETENTION_DAYS.' روز')).'</td></tr>';
        echo '</tbody></table>';
        echo '<h3>آخرین اعلان‌ها</h3>';
        if($recent===array()){echo '<p>هنوز اعلانی ثبت نشده است.</p>';}
        else{
            echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>مخاطب</th><th>رویداد</th><th>عنوان</th><th>وضعیت</th></tr></thead><tbody>';
            foreach($recent as $row)echo '<tr><td><code dir="ltr">'.esc_html((string)$row['created_at']).'</code></td><td>'.esc_html(self::audienceLabel((string)$row['audience'])).' #'.esc_html((string)$row['recipient_id']).'</td><td><code dir="ltr">'.esc_html((string)$row['event_key']).'</code></td><td>'.esc_html((string)$row['title']).'</td><td>'.esc_html($row['read']?'خوانده‌شده':'خوانده‌نشده').'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<div class="dzn-notif-grid"><section class="dzn-notif-card"><h3>نگهداری</h3><p>اعلان‌های پرتال پس از '.esc_html((string)PortalNotificationInbox::RETENTION_DAYS).' روز پاک می‌شوند. این پاک‌سازی فقط ردیف اعلان پرتال را حذف می‌کند و سابقهٔ کاری، مالی یا آموزشی را تغییر نمی‌دهد.</p>';
        if(self::canEdit())echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_inbox_prune">'.wp_nonce_field('dzn_notification_inbox_prune', '_wpnonce', true, false).'<button class="button">پاک‌سازی اعلان‌های منقضی</button></form>';
        echo '</section><section class="dzn-notif-card"><h3>بررسی امن</h3><p>یک اعلان آزمایشی فقط برای حساب مدیر جاری ثبت می‌شود؛ هیچ ایمیل، واتس‌اپ یا پیامکی ارسال نمی‌شود.</p>';
        if(self::canEdit())echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_notification_inbox_test">'.wp_nonce_field('dzn_notification_inbox_test', '_wpnonce', true, false).'<button class="button">ثبت اعلان آزمایشی مدیر</button></form>';
        echo '</section></div>';
    }

    // ------------------------------------------------------------------ handlers

    public static function saveDraft():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveDraft($input['event_key'],$input['channel'],$input['locale'],(string)($input['subject']??''),(string)($input['body']??''));
        return array('type'=>'success','message'=>'پیش‌نویس ذخیره شد. تا انتشار، پیام ارسالی تغییر نمی‌کند.');
    },array('nonce'=>'dzn_notification_copy_save_draft','nonce_field'=>'dzn_draft_nonce'));}

    public static function publish():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $result=$library->publish($input['event_key'],$input['channel'],$input['locale'],(string)($input['subject']??''),(string)($input['body']??''));
        return array('type'=>'success','message'=>'نسخهٔ '.$result['version_number'].' منتشر و فعال شد. پیام‌هایی که نسخه‌شان پیش‌تر تثبیت شده، همان نسخه را حفظ می‌کنند.');
    },array('nonce'=>'dzn_notification_copy_publish','nonce_field'=>'dzn_publish_nonce'));}

    public static function discardDraft():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->discardDraft($input['event_key'],$input['channel'],$input['locale']);
        return array('type'=>'success','message'=>'پیش‌نویس کنار گذاشته شد.');
    },array('nonce'=>'dzn_notification_copy_discard','nonce_field'=>'dzn_discard_nonce'));}

    public static function restoreVersion():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $versionId=(int)($input['template_version_id']??0);
        if($versionId<1)throw new \InvalidArgumentException('نسخهٔ انتخابی معتبر نیست.');
        $result=$library->restoreVersion($input['event_key'],$input['channel'],$input['locale'],$versionId);
        return array('type'=>'success','message'=>'متن نسخهٔ قبلی به‌صورت نسخهٔ تازهٔ '.$result['version_number'].' منتشر شد.');
    },array('nonce'=>'dzn_notification_copy_restore'));}

    public static function restoreDefault():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $result=$library->restoreDefault($input['event_key'],$input['channel'],$input['locale']);
        return array('type'=>'success','message'=>'متن پیش‌فرض به‌صورت نسخهٔ تازهٔ '.$result['version_number'].' منتشر شد.');
    },array('nonce'=>'dzn_notification_copy_restore_default','nonce_field'=>'dzn_restore_default_nonce'));}

    public static function saveProviderMapping():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveProviderMapping($input['event_key'],$input['channel'],$input['locale'],(string)($input['provider_name']??''),(string)($input['provider_language']??''));
        return array('type'=>'success','message'=>'نگاشت ثبت شد. تأیید قالب توسط Meta همچنان جداگانه لازم است.');
    },array('nonce'=>'dzn_notification_provider_mapping','tab'=>'channels'));}

    public static function saveChannelPolicy():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $library->saveChannelPolicy((array)($input['policy']??array()));
        return array('type'=>'success','message'=>'قوانین کانال ذخیره شد.');
    },array('nonce'=>'dzn_notification_channel_policy','tab'=>'rules','event_required'=>false));}

    public static function pruneInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $deleted=(new PortalNotificationInbox())->prune();
        return array('type'=>'success','message'=>$deleted.' اعلان منقضی پاک شد.');
    },array('nonce'=>'dzn_notification_inbox_prune','tab'=>'website','event_required'=>false));}

    public static function testInbox():never{self::handle(function(NotificationTemplateLibrary $library,array $input):array{
        $id=PortalNotificationRecorder::admin('system.inbox_check.admin','admin_test',(int)get_current_user_id(),array('when'=>'نمونهٔ آزمایشی'),null);
        return $id===null?array('type'=>'error','message'=>'ثبت اعلان آزمایشی ممکن نشد؛ پیکربندی قالب پرتال را بررسی کنید.'):array('type'=>'success','message'=>'اعلان آزمایشی فقط در فهرست مدیران ثبت شد. هیچ ایمیل، واتس‌اپ یا پیامکی ارسال نشد.');
    },array('nonce'=>'dzn_notification_inbox_test','tab'=>'website','event_required'=>false));}

    public static function recoverLeases():never{
        if(!current_user_can('dzn_operate_notification_dispatch')&&!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('dzn_notification_recover_leases');
        try{
            $result=(new NotificationDispatchService())->recoverExpiredLeases(array(
                'evidence_channel'=>'staff_record','evidence_reference'=>'admin-recover:'.get_current_user_id().':'.time(),'evidence_at'=>NotificationSupport::now(),
            ),'admin-recover-leases:'.gmdate('YmdHi'));
            self::flash('success','بازیابی انجام شد: '.$result['recovered'].' بازآرایی و '.$result['closed'].' بسته‌سازی.');
        }catch(\Throwable $error){self::flash('error','بازیابی انجام نشد: '.$error->getMessage());}
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
        if($eventRequired&&$event===null){self::flash('error','رویداد اعلان شناخته نشد.');self::redirectBack('templates');}
        if($event!==null&&(string)$event['authority']==='dedicated'&&in_array($input['channel'],array('email','whatsapp','sms'),true)){self::flash('error','این پیام به مرجع تحویل امن اختصاصی وابسته است و از این صفحه ویرایش نمی‌شود.');self::redirectBack('templates');}
        try{
            $result=$operation(new NotificationTemplateLibrary(),$input);
            self::flash((string)($result['type']??'success'),(string)($result['message']??'انجام شد.'));
        }catch(NotificationTemplateValidationException $error){
            self::flash('error',implode(' ',$error->reasons()));
        }catch(\Throwable $error){
            self::flash('error','ذخیره انجام نشد: '.$error->getMessage());
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

    public static function channelLabel(string $channel):string{return array('email'=>'ایمیل','whatsapp'=>'واتس‌اپ','sms'=>'پیامک','website'=>'پرتال')[$channel]??$channel;}
    private static function versionStateLabel(string $state,bool $published,?array $draft):string{
        if($draft!==null)return 'پیش‌نویس ذخیره‌شده';
        if($published)return 'نسخهٔ فعال منتشرشده';
        if($state==='unregistered')return 'متن پیش‌فرض';
        return 'بدون نسخهٔ فعال';
    }
    private static function providerStateLabel(string $state):string{return array(
        'not_mapped'=>'نگاشت‌نشده','approval_required'=>'نیازمند تأیید دوبارهٔ Meta','mapped'=>'نگاشت‌شده',
        'restricted_fallback'=>'جایگزین محدود','not_applicable'=>'کاربرد ندارد','unsupported'=>'پشتیبانی نمی‌شود',
    )[$state]??$state;}
    private static function stateLabel(string $state):string{return array('draft'=>'پیش‌نویس','active'=>'فعال','superseded'=>'جایگزین‌شده','retired'=>'بازنشسته')[$state]??$state;}
    private static function sourceLabel(string $source):string{return array('authored'=>'نگارش مدیر','rollback'=>'بازگردانی نسخه','default'=>'متن پیش‌فرض','published'=>'انتشار')[$source]??($source!==''?$source:'—');}
    private static function consentLabel(string $consent):string{return array(
        'security'=>'الزام امنیتی؛ بدون رضایت بازاریابی','operational'=>'پیام عملیاتی مبتنی بر رابطهٔ آموزشی',
        'financial'=>'پیام مالی؛ نیازمند بازبینی حقوقی','marketing'=>'فقط با رضایت بازاریابی',
    )[$consent]??$consent;}
    private static function audienceLabel(string $audience):string{return array('student'=>'هنرجو','teacher'=>'مدرس','admin'=>'مدیر')[$audience]??$audience;}
}
