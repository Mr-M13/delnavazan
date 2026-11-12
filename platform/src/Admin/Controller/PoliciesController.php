<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\BookingRequestValidationService;
use Delnavazan\Platform\Core\Application\CommercialPolicyService;
use Delnavazan\Platform\Core\Application\ConsentLifecycleWorker;
use Delnavazan\Platform\Core\Application\ConsentNotificationService;
use Delnavazan\Platform\Core\Application\ConsentOperationsReadService;
use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\ConsentWithdrawalService;
use Delnavazan\Platform\Core\Application\LegalPageProvisioner;
use Delnavazan\Platform\Core\Application\MlDatasetEligibilityService;

/**
 * Settings → Policy: one canonical policy and consent administration surface.
 *
 * Policy content, commercial rules, legal pages and consent evidence are separate authorities.
 * This controller is presentation and bounded commands only; it never invents a policy version,
 * changes a consent decision or publishes a legally consequential page.
 */
final class PoliciesController {
    public const MENU_SLUG='dzn-policies';
    public const CAPABILITY=ConsentOperationsReadService::CAPABILITY;
    public const VIEW_CAPABILITY=ConsentOperationsReadService::VIEW_CAPABILITY;
    public const ACTION_COMMERCIAL_POLICY='dzn_policies_commercial_update';
    public const ACTION_REMINDER='dzn_consent_reminder';
    public const ACTION_RETRY='dzn_consent_retry_job';
    public const ACTION_REEVALUATE='dzn_consent_reevaluate';
    public const ACTION_WORKER='dzn_consent_run_worker';
    public const ACTION_EXPORT='dzn_consent_export_evidence';

    public static function register():void{
        // The route is registered once by Menu::menu() with the composite policy access
        // capability, so read-only consent staff can open the page while write commands
        // remain gated by the manage capability.
        add_action('admin_post_'.self::ACTION_COMMERCIAL_POLICY,array(__CLASS__,'saveCommercialPolicy'));
        foreach(array(self::ACTION_REMINDER,self::ACTION_RETRY,self::ACTION_REEVALUATE,self::ACTION_WORKER,self::ACTION_EXPORT)as$action)add_action('admin_post_'.$action,array(__CLASS__,'command'));
    }

    public static function command():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        if(!current_user_can(self::CAPABILITY))wp_die('Forbidden','Delnavazan',array('response'=>403));
        $action=sanitize_key((string)wp_unslash($_POST['action']??''));
        check_admin_referer($action);
        $status='done';
        try{
            if($action===self::ACTION_REMINDER){
                $type=sanitize_key((string)wp_unslash($_POST['subject_type']??''));
                $id=absint($_POST['subject_id']??0);
                if($id<1||!in_array($type,array('teacher','student','guardian'),true))throw new \InvalidArgumentException('consent_subject_invalid');
                $notifications=new ConsentNotificationService();
                if($type==='teacher')$id=$notifications->teacherRequired($id);
                elseif($type==='guardian')$id=$notifications->guardianRequired($id);
                else $id=$notifications->studentRequired($id);
                $status=$id===null?'reminder_unavailable':'reminder_sent';
            }elseif($action===self::ACTION_RETRY){
                $jobId=absint($_POST['job_id']??0);
                $ok=(new ConsentLifecycleWorker())->retry($jobId,get_current_user_id());
                $status=$ok?'job_retried':'job_not_retryable';
            }elseif($action===self::ACTION_REEVALUATE){
                $key=sanitize_key((string)wp_unslash($_POST['dataset_key']??''));
                (new ConsentWithdrawalService())->reevaluateDataset($key!==''?$key:'persian_music_research',get_current_user_id());
                $status='reevaluation_queued';
            }elseif($action===self::ACTION_WORKER){
                $result=(new ConsentLifecycleWorker())->run(20);
                $status='worker_'.$result['completed'].'_completed_'.$result['failed'].'_failed';
            }elseif($action===self::ACTION_EXPORT){
                if(!current_user_can(MlDatasetEligibilityService::EXPORT_CAPABILITY)&&!current_user_can('manage_options'))throw new \RuntimeException('consent_export_capability_required');
                $subjectType=sanitize_key((string)wp_unslash($_POST['subject_type']??''));
                $subjectId=absint($_POST['subject_id']??0);
                $service=new ConsentOperationsReadService();
                $payload=array('schema'=>'delnavazan-consent-evidence-v1','generated_at'=>gmdate('Y-m-d H:i:s'),'subject_type'=>$subjectType,'subject_id'=>$subjectId,'decisions'=>$service->evidence($subjectType,$subjectId,500));
                nocache_headers();
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="delnavazan-consent-evidence-'.preg_replace('/[^a-z0-9_-]/','',$subjectType).'-'.$subjectId.'.json"');
                echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            }
        }catch(\Throwable $error){
            $reason=(string)$error->getMessage();
            $status=preg_match('/^[a-z0-9_]{3,80}$/D',$reason)===1?$reason:'consent_action_failed';
        }
        wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG,'dzn_consent'=>$status),admin_url('admin.php')));
        exit;
    }

    public static function render():void{
        if(!current_user_can(self::CAPABILITY)&&!current_user_can(self::VIEW_CAPABILITY))wp_die('Forbidden','Delnavazan',array('response'=>403));
        $service=new ConsentOperationsReadService();
        $counts=$service->counts();
        $status=sanitize_key((string)($_GET['dzn_consent']??''));
        echo '<div class="wrap dzn-settings-page dzn-policies-page" dir="ltr">';
        echo '<h1>Policy</h1>';
        echo '<p class="dzn-settings-lede">Policy cards for operational, legal, financial, attendance, cancellation and consent rules. Editable values are written through their existing versioned authority. Consent evidence is read-only here; this page cannot create or change a person’s decision.</p>';
        self::flash($status);
        if(!current_user_can(self::CAPABILITY))echo '<div class="notice notice-info inline"><p>Read-only policy and consent access. Reminders, retries, publication and policy version changes require the relevant management capability.</p></div>';
        self::policyCards();
        self::legalPages();
        self::consentManagement($service,$counts);
        self::policyLibrary($service);
        self::evidenceInspector();
        self::jobs($service);
        echo '</div>';
    }

    /**
     * Card-based policy management. Each card is scannable without expansion and carries its own
     * authority state, current version and management action. Cards are grouped by business kind,
     * not by an introductory jump navigation.
     */
    private static function policyCards():void{
        $service=new CommercialPolicyService();
        echo '<section class="dzn-settings-card" id="policy-cards"><h2>Policy register</h2>';
        echo '<p class="dzn-settings-note">A card marked <strong>Fixed</strong> is a structural rule; <strong>Review</strong> is an operational target not yet verified as configurable; <strong>Versioned</strong> is written through the existing versioned authority.</p>';
        $groups=array(
            'operational'=>array('label'=>'Operational','description'=>'Booking, allocation, availability and teacher response rules.','cards'=>array(
                array('Teacher offer response window','How long a Teacher has to accept an offer before it is reassigned.','Fixed','24 hours','—','Teachers and coordination','locked','Unanswered offers expire and are reassigned to the next eligible Teacher; the Teacher portal shows the remaining time.'),
                array('Unassigned request escalation','Operational target for escalating a request that has no matching Teacher.','Review','3 days','—','Coordination','review','Agreed operational target; verify runtime enforcement before making it configurable.'),
                array('Teacher allocation order','Allocation workflow applied when several eligible Teachers exist.','Review','Round-robin','—','Coordination','review','Agreed allocation workflow; implementation authority must be verified.'),
                array('Availability for new bookings','Which hours are offered to new bookings.','Review','Selected teaching hours only','—','Teachers','review','Unselected hours are unavailable; existing bookings are preserved.'),
            )),
            'scheduling'=>array('label'=>'Scheduling','description'=>'The introductory booking horizon enforced by public intake.','cards'=>array()),
            'terms'=>array('label'=>'Terms and lessons','description'=>'Structural term and instalment commitments.','cards'=>array(
                array('Lessons per term','Structural term commitment.','Fixed','12 sessions','—','Students and enrolment','locked','Not an editable commercial policy.'),
                array('Payment instalment structure','Structural commercial instalment boundaries.','Fixed','Sessions 1–6 and 7–12','—','Finance and enrolment','locked','Changing this here would create conflicting commercial state.'),
            )),
            'attendance'=>array('label'=>'Attendance and replacements','description'=>'Replacement allowance and acceptance rules.','cards'=>array(
                array('Replacement allowance','Maximum replacement lessons recorded against a term.','Fixed','2 per term','—','Students and Teachers','locked','The term-recorded allowance is authoritative; changing it here would create conflicting state.'),
                array('Replacement acceptance','How a replacement is accepted.','Review','Teacher proposes; student accepts','—','Students and Teachers','review','Rescheduling must use the canonical schedule revision service.'),
            )),
            'financial'=>array('label'=>'Financial','description'=>'Versioned commercial renewal, recovery and instalment policies.','cards'=>array(
                self::commercialCard('Manual renewal slot guarantee','How long a manual renewal slot is protected.','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','weeks','Finance and enrolment'),
                self::commercialCard('Automatic renewal charge lead time','Lead time before an automatic renewal charge.','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','duration','Finance'),
                self::commercialCard('Payment recovery policy','Approved policy reference for payment recovery.','PAYMENT_RECOVERY_POLICY','policy_reference','Finance'),
                self::commercialCard('Instalment due date policy','Approved policy reference for instalment due dates.','INSTALMENT_DUE_DATE_POLICY','policy_reference','Finance'),
            )),
            'cancellation'=>array('label'=>'Cancellation','description'=>'Cancellation and retraction rules.','cards'=>array(
                array('Cancellation authority','Cancellation must run through the canonical lesson delivery or booking cancellation service.','Fixed','Canonical service','—','Coordination and Classes','locked','Cancellation is never a free-text policy or a diagnostic retry.'),
            )),
            'consent'=>array('label'=>'Consent and recording','description'=>'Recording, research, privacy and consent evidence rules.','cards'=>array(
                array('Recording and research consent','Independent purposes with immutable notice versions.','Published versions','See Consent Management','—','Students, Teachers and guardians','consent','Manage evidence, versions and publication below. A new publication creates a new immutable version.'),
            )),
        );
        foreach($groups as$key=>$group){
            echo '<div class="dzn-policy-group dzn-policy-group--'.esc_attr($key).'"><div class="dzn-policy-group__head"><h3>'.esc_html((string)$group['label']).'</h3><p>'.esc_html((string)$group['description']).'</p></div><div class="dzn-policy-card-grid">';
            if($key==='scheduling'){
                self::bookingHorizonCard();
            }
            foreach((array)$group['cards']as$card){
                if(isset($card['commercial_key']))self::commercialCardRender($service,$card);
                else self::policyCardRender($card,$key);
            }
            echo '</div></div>';
        }
        echo '</section>';
    }

    private static function policyCardRender(array $card,string $kind):void{
        [$title,$description,$status,$value,$version,$audience,$tone,$note]=$card;
        echo '<article class="dzn-policy-card dzn-policy-card--'.esc_attr($kind).'">';
        echo '<header><h4>'.esc_html($title).'</h4><span class="dzn-status-badge '.esc_attr($tone==='locked'?'is-locked':($tone==='consent'?'is-ok':'is-warn')).'">'.esc_html($status).'</span></header>';
        echo '<p>'.esc_html($description).'</p>';
        echo '<dl><div><dt>Current</dt><dd>'.esc_html($value).'</dd></div><div><dt>Version</dt><dd>'.esc_html($version).'</dd></div><div><dt>Audience</dt><dd>'.esc_html($audience).'</dd></div></dl>';
        echo '<p class="dzn-settings-meta">'.esc_html($note).'</p></article>';
    }

    private static function commercialCard(string $title,string $description,string $key,string $unit,string $audience):array{
        return array('commercial_key'=>$key,'title'=>$title,'description'=>$description,'unit'=>$unit,'audience'=>$audience);
    }

    private static function commercialCardRender(CommercialPolicyService $service,array $card):void{
        $key=(string)$card['commercial_key'];
        $state=array('set'=>false,'version'=>null,'value'=>null);
        try{$state=$service->current($key);}catch(\Throwable){$state=array('set'=>false,'version'=>null,'value'=>null,'read_error'=>true);}
        $unit=(string)$card['unit'];
        $value=!empty($state['set'])?(string)$state['value'].($unit==='weeks'?' weeks':($unit==='duration'?' seconds':'')):'Not configured';
        echo '<article class="dzn-policy-card dzn-policy-card--financial"><header><h4>'.esc_html((string)$card['title']).'</h4><span class="dzn-status-badge '.(!empty($state['set'])?'is-ok':'is-warn').'">'.(!empty($state['set'])?'Versioned':'Not configured').'</span></header>';
        echo '<p>'.esc_html((string)$card['description']).'</p>';
        echo '<dl><div><dt>Current</dt><dd>'.esc_html($value).'</dd></div><div><dt>Version</dt><dd>'.esc_html(!empty($state['version'])?'#'.(string)$state['version']:'—').'</dd></div><div><dt>Audience</dt><dd>'.esc_html((string)$card['audience']).'</dd></div></dl>';
        if(!empty($state['read_error']))echo '<div class="notice notice-warning inline"><p>The recorded policy could not be read. No value is assumed.</p></div>';
        if(current_user_can('dzn_manage_commercial_policies')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_COMMERCIAL_POLICY).'"><input type="hidden" name="policy_key" value="'.esc_attr($key).'">';
            wp_nonce_field(self::ACTION_COMMERCIAL_POLICY);
            echo '<label>New value <input name="policy_value" type="'.($unit==='weeks'||$unit==='duration'?'number':'text').'" '.($unit==='weeks'?'min="1" max="104"':($unit==='duration'?'min="1"':'')).' value="'.esc_attr(!empty($state['set'])?(string)$state['value']:'').'" required></label> ';
            echo '<button class="button button-secondary" type="submit">Record new version</button></form>';
        }else{
            echo '<p class="dzn-settings-meta">Recording a new version requires the commercial-policy capability.</p>';
        }
        echo '</article>';
    }

    private static function bookingHorizonCard():void{
        $configuration=BookingRequestValidationService::horizonConfiguration();
        $recorded=$configuration['source']==='policy';
        $days=(int)$configuration['days'];
        $summary=$recorded
            ? (string)$configuration['weeks'].' week'.((int)$configuration['weeks']===1?'':'s').' ('.$days.' days)'
            : $days.' days';
        $history=array();
        if(current_user_can('dzn_view_commercial_authority')){
            try{$history=(array)((new CommercialPolicyService())->history('INTRO_BOOKING_HORIZON')['versions']??array());}catch(\Throwable){}
        }
        echo '<article class="dzn-policy-card dzn-policy-card--scheduling" id="booking-horizon"><header><h4>Introductory booking horizon</h4><span class="dzn-status-badge is-ok">Active</span></header>';
        echo '<p>This academy scheduling rule controls how far ahead an introductory lesson can be requested. Public intake always applies the effective value below.</p>';
        echo '<dl><div><dt>Current</dt><dd>'.esc_html($summary).'</dd></div><div><dt>Version</dt><dd>'.esc_html($recorded?'#'.(string)$configuration['version']:'Built-in fallback').'</dd></div><div><dt>Audience</dt><dd>Public booking intake</dd></div></dl>';
        if($recorded)echo '<p class="dzn-settings-meta">Recorded version in force.</p>';
        elseif(!empty($configuration['recorded']))echo '<p class="dzn-settings-meta">The recorded value ('.esc_html((string)($configuration['recorded_value']??'')).' '.esc_html((string)($configuration['recorded_value_type']??'')).') is not a valid whole-week value, so the built-in '.esc_html((string)$days).'-day fallback is in force.</p>';
        else echo '<p class="dzn-settings-meta">No version recorded — the built-in '.esc_html((string)$days).'-day fallback is in force.</p>';
        if(!empty($configuration['read_error']))echo '<div class="notice notice-warning inline"><p>The recorded policy could not be read, so the built-in fallback is in force.</p></div>';
        if(current_user_can('dzn_manage_commercial_policies')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION_COMMERCIAL_POLICY).'"><input type="hidden" name="policy_key" value="INTRO_BOOKING_HORIZON"><input type="hidden" name="return_anchor" value="booking-horizon">';
            wp_nonce_field(self::ACTION_COMMERCIAL_POLICY);
            echo '<label for="dzn-policy-booking-horizon"><strong>Whole weeks</strong></label> <input id="dzn-policy-booking-horizon" name="policy_value" type="number" min="1" max="104" required placeholder="e.g. 4" value="'.esc_attr($recorded?(string)$configuration['weeks']:'').'"> <button class="button button-primary" type="submit">Record new horizon version</button>';
            echo '<p class="dzn-settings-meta">Recording a new version never rewrites a completed booking or an existing policy version.</p></form>';
        }else{
            echo '<p class="dzn-settings-meta">Recording a new horizon requires the commercial-policy capability.</p>';
        }
        if($history!==array()){
            echo '<details><summary><strong>Version history</strong></summary><table class="widefat striped dzn-settings-table"><thead><tr><th>Version</th><th>Value</th><th>Effective from</th><th>Recorded</th></tr></thead><tbody>';
            foreach(array_slice(array_reverse($history),0,10)as$row){
                $historyType=(string)($row['value_type']??'');
                $historyValue=($row['value']??null)===null?'Unset':(string)$row['value'].($historyType==='weeks'?' weeks':($historyType==='duration'?' seconds':''));
                echo '<tr><td>#'.esc_html((string)($row['policy_version']??'')).'</td><td>'.esc_html($historyValue).'</td><td>'.esc_html((string)($row['effective_from']??'—')).'</td><td><code dir="ltr">'.esc_html((string)($row['recorded_at']??'')).'</code></td></tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '</article>';
    }

    /** Canonical legal-page management, moved here from General. Draft-only provisioning; publication stays in WordPress. */
    private static function legalPages():void{
        if(!class_exists(LegalPageProvisioner::class))return;
        $result=isset($_GET['dzn_legal_result'])?sanitize_key(wp_unslash((string)$_GET['dzn_legal_result'])):'';
        echo '<section class="dzn-settings-card" id="legal-policy"><h2>Legal and policy pages</h2>';
        echo '<p>These pages are created or refreshed as <strong>unpublished drafts</strong>. Nothing here publishes a legally consequential document, and opening or saving Policy never changes a published page. Confirm the operator identity, consumer terms, recording disclosures and jurisdiction-specific requirements before publishing in WordPress.</p>';
        if($result!==''){
            echo '<div class="notice notice-info inline"><p>'.esc_html(sprintf('Legal draft provisioning completed: %d created, %d updated, %d unchanged.',(int)($_GET['dzn_legal_created']??0),(int)($_GET['dzn_legal_updated']??0),(int)($_GET['dzn_legal_skipped']??0))).'</p></div>';
        }
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Document</th><th>Canonical slug</th><th>Version</th><th>State</th><th>Review</th><th>Actions</th></tr></thead><tbody>';
        foreach(LegalPageProvisioner::status()as$row){
            $status=(string)$row['status'];$published=!empty($row['published']);
            echo '<tr><td><strong>'.esc_html((string)$row['title']).'</strong></td><td><code>'.esc_html((string)$row['slug']).'</code></td><td>'.esc_html((string)$row['version']).'</td>';
            echo '<td><span class="dzn-status-badge '.($published?'is-ok':'is-warn').'">'.esc_html($status==='missing'?'Not created':($published?'Published':'Draft')).'</span></td>';
            $reviewStatus=(string)($row['review_status']??'');
            echo '<td>'.esc_html($reviewStatus===''?'—':'Owner and legal review required').'</td><td>';
            if(!empty($row['edit_url']))echo '<a class="button button-small" href="'.esc_url((string)$row['edit_url']).'">'.($published?'Edit page':'Open draft').'</a> ';
            if($published&&!empty($row['page_id'])&&function_exists('get_permalink')){$url=get_permalink((int)$row['page_id']);if(is_string($url)&&$url!=='')echo '<a class="button button-small" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">View</a> ';}
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if(current_user_can('manage_options')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field(LegalPagesController::ACTION,'_wpnonce',true,false);
            echo '<input type="hidden" name="action" value="'.esc_attr(LegalPagesController::ACTION).'"><p><button class="button button-primary" type="submit">Create / refresh legal drafts</button> <span class="description">Does not publish any page and does not overwrite a published policy.</span></p></form>';
            echo '<p><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=dzn-recording-notices')).'">Recording and research notices</a> <span class="dzn-settings-meta">Publication history and immutable consent notices remain on the protected publication route.</span></p>';
        }
        echo '</section>';
    }

    /** Unified consent administration: summary first, then one searchable and filterable register. */
    private static function consentManagement(ConsentOperationsReadService $service,array $counts):void{
        $filterType=sanitize_key((string)($_GET['consent_subject_type']??''));
        if(!in_array($filterType,array('teacher','student'),true))$filterType='';
        $filterStatus=sanitize_key((string)($_GET['consent_status']??'awaiting'));
        if(!in_array($filterStatus,array('awaiting','withdrawn','exceptions','all'),true))$filterStatus='awaiting';
        echo '<section class="dzn-settings-card" id="consent-management"><h2>Consent Management</h2>';
        echo '<p>Summary, register and audit evidence in one place. Counts and records are derived from append-only decisions and authorisation evidence. Mandatory consent checks are not weakened by this screen.</p>';
        echo '<div class="dzn-settings-cards">';
        foreach(array(
            'Teachers awaiting consent'=>(int)$counts['teachers_awaiting'],
            'Students awaiting consent'=>(int)$counts['students_awaiting'],
            'Guardian decisions needing attention'=>(int)$counts['guardian_attention'],
            'Recording-authorised lessons'=>(int)$counts['recording_authorised_lessons'],
            'AI research eligible'=>(int)$counts['ml_eligible_recordings'],
            'Blocked or quarantined'=>(int)$counts['blocked_or_quarantined'],
            'Pending withdrawal actions'=>(int)$counts['pending_withdrawals'],
            'Failed consent jobs'=>(int)$counts['failed_jobs'],
        )as$label=>$value)echo '<div class="dzn-settings-card"><h3>'.esc_html($label).'</h3><p class="dzn-settings-meta" style="font-size:1.6em;margin:0"><strong>'.esc_html((string)$value).'</strong></p></div>';
        echo '</div>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-settings-filter"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'">';
        echo '<label>Subject <select name="consent_subject_type"><option value="">All</option><option value="teacher"'.selected($filterType,'teacher',false).'>Teacher</option><option value="student"'.selected($filterType,'student',false).'>Student</option></select></label> ';
        echo '<label>Status <select name="consent_status">';
        foreach(array('awaiting'=>'Missing or awaiting','withdrawn'=>'Withdrawn or declined','exceptions'=>'Blocked or quarantined','all'=>'All records')as$key=>$label)echo '<option value="'.esc_attr($key).'"'.selected($filterStatus,$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <button class="button">Filter register</button> <a class="button-link" href="'.esc_url(admin_url('admin.php?page='.self::MENU_SLUG)).'#consent-management">Reset</a></form>';
        if($filterStatus==='awaiting'||$filterStatus==='all')self::pendingRegister($service,$filterType);
        if($filterStatus==='withdrawn'||$filterStatus==='all')self::withdrawalRegister($service,$filterType);
        if($filterStatus==='exceptions'||$filterStatus==='all')self::exceptionRegister($service);
        self::guardianRegister($service,$filterType);
        echo '<h3>Recording and AI research consent</h3><p>Recording and dataset eligibility are independent purposes. A lesson recording may be authorised while research eligibility is refused. Evidence below is aggregate only.</p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Evidence</th><th>Count</th><th>Meaning</th></tr></thead><tbody>';
        echo '<tr><td>Recording-authorised lessons</td><td>'.(int)$counts['recording_authorised_lessons'].'</td><td>Latest authorisation outcome is authorised.</td></tr>';
        echo '<tr><td>AI research eligible recordings</td><td>'.(int)$counts['ml_eligible_recordings'].'</td><td>Eligibility is a separate dataset decision; recording consent alone never grants it.</td></tr>';
        echo '<tr><td>Blocked or quarantined</td><td>'.(int)$counts['blocked_or_quarantined'].'</td><td>Latest authorisation is not authorised or the dataset item is quarantined.</td></tr>';
        echo '</tbody></table>';
        echo '<p class="dzn-settings-meta">Capture and research export remain disabled in this build. See the policy library below for immutable notice versions.</p></section>';
    }

    private static function pendingRegister(ConsentOperationsReadService $service,string $type):void{
        echo '<h3>Consent register — missing or awaiting</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Subject</th><th>Type</th><th>Classification</th><th>Action</th></tr></thead><tbody>';
        $rows=array();
        if($type===''||$type==='teacher')foreach($service->pendingTeachers()as$row)$rows[]=array('type'=>'teacher','row'=>$row);
        if($type===''||$type==='student')foreach($service->pendingStudents()as$row)$rows[]=array('type'=>'student','row'=>$row);
        if($rows===array())echo '<tr><td colspan="4"><em>No one matches this consent filter.</em></td></tr>';
        foreach($rows as$entry){$row=$entry['row'];$subjectType=$entry['type'];
            echo '<tr><td>'.esc_html((string)$row->display_name).' <span class="dzn-settings-meta">#'.esc_html((string)$row->id).'</span></td><td>'.esc_html(ucfirst($subjectType)).'</td><td>'.esc_html((string)($row->classification??'—')).'</td><td>'.self::linkProfile($subjectType,(int)$row->id).' '.self::reminderButton($subjectType,(int)$row->id).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function withdrawalRegister(ConsentOperationsReadService $service,string $type):void{
        echo '<h3>Consent history — withdrawals and opt-outs</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Decision</th><th>Subject</th><th>Purpose</th><th>Choice</th><th>Decided (UTC)</th><th>Profile</th></tr></thead><tbody>';
        $rows=$service->withdrawn();
        if($type!=='')$rows=array_values(array_filter($rows,static fn($row)=>(string)$row->subject_type===$type));
        if($rows===array())echo '<tr><td colspan="6"><em>No withdrawal or opt-out matches this filter.</em></td></tr>';
        foreach($rows as$row)echo '<tr><td><code>#'.esc_html((string)$row->id).'</code></td><td>'.esc_html((string)$row->subject_type).' #'.esc_html((string)$row->subject_id).'</td><td><code>'.esc_html((string)$row->purpose_code).'</code></td><td>'.esc_html((string)$row->decision_code).'</td><td>'.esc_html((string)$row->decided_at).'</td><td>'.self::linkProfile((string)$row->subject_type,(int)$row->subject_id).'</td></tr>';
        echo '</tbody></table>';
    }

    private static function exceptionRegister(ConsentOperationsReadService $service):void{
        echo '<h3>Missing, invalid or blocked consent</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Kind</th><th>Source</th><th>Reason</th><th>Occurred (UTC)</th></tr></thead><tbody>';
        $rows=$service->exceptions();
        if($rows===array())echo '<tr><td colspan="4"><em>No eligibility exception is recorded.</em></td></tr>';
        foreach($rows as$row)echo '<tr><td>'.esc_html((string)$row->kind).'</td><td>#'.esc_html((string)$row->source_id).'</td><td><code>'.esc_html((string)$row->reason).'</code></td><td>'.esc_html((string)$row->occurred_at).'</td></tr>';
        echo '</tbody></table>';
    }

    private static function guardianRegister(ConsentOperationsReadService $service,string $type):void{
        if($type==='teacher')return;
        echo '<h3>Guardian decisions requiring attention</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Student</th><th>Active guardian grants</th><th>Action</th></tr></thead><tbody>';
        $rows=$service->guardianAttention();
        if($rows===array())echo '<tr><td colspan="3"><em>No minor student is awaiting a guardian decision.</em></td></tr>';
        foreach($rows as$row)echo '<tr><td>'.esc_html((string)$row->display_name).' <span class="dzn-settings-meta">#'.esc_html((string)$row->id).'</span></td><td>'.esc_html((string)$row->active_grants).'</td><td>'.self::linkProfile('student',(int)$row->id).' '.self::reminderButton('guardian',(int)$row->id).'</td></tr>';
        echo '</tbody></table>';
    }

    private static function policyLibrary(ConsentOperationsReadService $service):void{
        $library=$service->policyLibrary();
        echo '<section class="dzn-settings-card" id="dzn-consent-policy-library"><h2>Policy library and notice versions</h2>';
        echo '<p>Recording, research, privacy and recorded-work terms are independent purposes. A new publication creates a new immutable version; withdrawing a publication closes every published version for that audience and language.</p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Purpose</th><th>Subject</th><th>Notice audience</th><th>Optional</th><th>Guardian</th><th>Recording</th><th>Dataset</th></tr></thead><tbody>';
        foreach((array)$library['purposes']as$purpose){
            echo '<tr><td><strong>'.esc_html((string)$purpose->title_en).'</strong><br><span class="dzn-settings-meta">'.esc_html((string)$purpose->title_fa).'</span></td><td>'.esc_html((string)$purpose->subject_scope).'</td><td><code>'.esc_html((string)$purpose->notice_audience_code).'</code></td><td>'.((int)$purpose->optional_purpose===1?'Yes':'No').'</td><td>'.((int)$purpose->guardian_allowed===1?'Allowed':'Not allowed').'</td><td>'.((int)$purpose->required_for_recording===1?'Required':'No').'</td><td>'.((int)$purpose->required_for_dataset===1?'Required':'No').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Published and historical versions</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Audience</th><th>Language</th><th>Version</th><th>State</th><th>Approval</th><th>Published</th><th>Withdrawn</th><th>Digest</th></tr></thead><tbody>';
        foreach((array)$library['versions']as$version){
            echo '<tr><td><code>'.esc_html((string)$version->audience_code).'</code></td><td>'.esc_html((string)$version->language_code).'</td><td>'.esc_html((string)$version->version_number).'</td><td>'.esc_html((string)$version->publication_state).'</td><td>'.esc_html((string)($version->approval_state??'—')).'</td><td>'.esc_html((string)($version->published_at??'—')).'</td><td>'.esc_html((string)($version->withdrawn_at??'—')).'</td><td><code>'.esc_html(substr((string)$version->text_digest,0,16)).'…</code></td></tr>';
        }
        echo '</tbody></table>';
        if(current_user_can('manage_options')){
            echo '<h3>Publish a reviewed version</h3><p>Publishing is delegated to the existing append-only publication authority. Paste only the final owner- and legal-approved text.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form">';
            echo '<input type="hidden" name="action" value="dzn_recording_notice_command"><input type="hidden" name="operation" value="publish">';
            wp_nonce_field('dzn_recording_notice_command');
            echo '<p><label>Audience <select name="audience" required>';
            foreach(ConsentPurposeRegistry::all()as$purpose)echo '<option value="'.esc_attr((string)$purpose['notice_audience']).'">'.esc_html((string)$purpose['label_en']).'</option>';
            echo '</select></label> <label>Language <select name="language"><option value="fa">Persian (فارسی)</option><option value="en">English</option></select></label></p>';
            echo '<p><textarea name="body_text" rows="8" class="large-text code" required placeholder="Exact approved notice text"></textarea></p>';
            echo '<p><button type="submit" class="button button-primary">Publish immutable version</button> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-recording-notices')).'">Open publication history</a></p></form>';
        }else{
            echo '<p class="dzn-settings-note">Publishing a legally consequential policy version requires the WordPress administrator capability. Review is available here; publication remains delegated to the protected route.</p>';
        }
        echo '</section>';
    }

    private static function evidenceInspector():void{
        echo '<section class="dzn-settings-card" id="dzn-consent-evidence"><h2>Consent evidence inspector</h2>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-settings-filter"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>Subject <select name="consent_subject_type"><option value="teacher">Teacher</option><option value="student">Student</option></select></label> <label>ID <input type="number" name="consent_subject_id" min="1" required></label> <button class="button" type="submit">Inspect evidence</button></form>';
        $type=sanitize_key((string)($_GET['consent_subject_type']??''));
        $id=absint($_GET['consent_subject_id']??0);
        if($id>0&&in_array($type,array('teacher','student'),true)){
            $service=new ConsentOperationsReadService();
            $rows=$service->evidence($type,$id,200);
            echo '<h3>'.esc_html(ucfirst($type)).' #'.esc_html((string)$id).'</h3>';
            echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>ID</th><th>Purpose</th><th>Decision</th><th>Notice</th><th>Language</th><th>Version</th><th>Channel</th><th>Scope</th><th>Decided (UTC)</th><th>Actor</th><th>Guardian grant</th><th>Supersedes</th><th>Withdrawal of</th></tr></thead><tbody>';
            if(!$rows)echo '<tr><td colspan="13"><em>No consent evidence exists for this subject.</em></td></tr>';
            foreach($rows as$row){
                echo '<tr><td><code>#'.esc_html((string)$row->id).'</code><br><span class="dzn-settings-meta">'.esc_html(substr((string)$row->uid,0,8)).'…</span></td><td><code>'.esc_html((string)$row->purpose_code).'</code></td><td>'.esc_html((string)$row->decision_code).'</td><td><code>'.esc_html((string)$row->notice_uid).'</code><br><span class="dzn-settings-meta">'.esc_html((string)$row->notice_audience_code).'</span></td><td>'.esc_html((string)$row->notice_language_code).'</td><td>'.esc_html((string)$row->notice_version_number).'</td><td>'.esc_html((string)$row->collection_channel).'</td><td>'.esc_html((string)$row->scope_code).'</td><td>'.esc_html((string)$row->decided_at).'</td><td>#'.esc_html((string)$row->decided_by_user_id).'</td><td>'.esc_html($row->guardian_authority_grant_id!==null?'#'.$row->guardian_authority_grant_id:'—').'</td><td>'.esc_html($row->supersedes_decision_id!==null?'#'.$row->supersedes_decision_id:'—').'</td><td>'.esc_html($row->withdrawal_of_decision_id!==null?'#'.$row->withdrawal_of_decision_id:'—').'</td></tr>';
            }
            echo '</tbody></table>';
            if(current_user_can(MlDatasetEligibilityService::EXPORT_CAPABILITY)||current_user_can('manage_options')){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_EXPORT).'"><input type="hidden" name="subject_type" value="'.esc_attr($type).'"><input type="hidden" name="subject_id" value="'.esc_attr((string)$id).'">'.wp_nonce_field(self::ACTION_EXPORT,'_wpnonce',true,false).'<button class="button" type="submit">Export audit evidence (JSON)</button></form>';
            }
        }
        echo '</section>';
    }

    private static function jobs(ConsentOperationsReadService $service):void{
        echo '<section class="dzn-settings-card" id="dzn-consent-jobs"><h2>Withdrawal processing</h2>';
        echo '<p>Jobs are leased, retryable and durable. A completed recording disposition reports truthfully when no captured asset exists.</p>';
        if(current_user_can(self::CAPABILITY))echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_WORKER).'">'.wp_nonce_field(self::ACTION_WORKER,'_wpnonce',true,false).'<button class="button" type="submit">Run pending jobs (20)</button></form>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Job</th><th>Type</th><th>Subject</th><th>Purpose</th><th>State</th><th>Attempts</th><th>Last error</th><th>Result</th><th>Action</th></tr></thead><tbody>';
        $jobs=$service->jobs();
        if(!$jobs)echo '<tr><td colspan="9"><em>No consent processing job exists.</em></td></tr>';
        foreach($jobs as$job){
            echo '<tr><td><code>#'.esc_html((string)$job->id).'</code></td><td><code>'.esc_html((string)$job->job_type).'</code></td><td>'.esc_html((string)$job->subject_type).' #'.esc_html((string)$job->subject_id).'</td><td><code>'.esc_html((string)$job->purpose_code).'</code></td><td>'.esc_html((string)$job->state).'</td><td>'.esc_html((string)$job->attempts).'/'.esc_html((string)$job->max_attempts).'</td><td>'.esc_html((string)($job->last_error_code??'—')).'</td><td>'.esc_html((string)($job->result_code??'—')).'</td><td>';
            if((string)$job->state==='failed'&&current_user_can(self::CAPABILITY))echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_RETRY).'"><input type="hidden" name="job_id" value="'.esc_attr((string)$job->id).'">'.wp_nonce_field(self::ACTION_RETRY,'_wpnonce',true,false).'<button class="button button-small" type="submit">Retry</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function linkProfile(string $type,int $id):string{
        $url=$type==='teacher'?admin_url('admin.php?page=dzn-teachers-menu&id='.$id):admin_url('admin.php?page=dzn-student&id='.$id);
        return '<a class="button button-small" href="'.esc_url($url).'">Open profile</a>';
    }

    private static function reminderButton(string $type,int $id):string{
        if(!current_user_can(self::CAPABILITY))return '';
        return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_REMINDER).'"><input type="hidden" name="subject_type" value="'.esc_attr($type).'"><input type="hidden" name="subject_id" value="'.esc_attr((string)$id).'">'.wp_nonce_field(self::ACTION_REMINDER,'_wpnonce',true,false).'<button class="button button-small" type="submit">Send reminder</button></form>';
    }

    public static function saveCommercialPolicy():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed','Delnavazan',array('response'=>405));
        if(!current_user_can('dzn_manage_commercial_policies'))wp_die('Forbidden','Delnavazan',array('response'=>403));
        check_admin_referer(self::ACTION_COMMERCIAL_POLICY);
        $types=array('INTRO_BOOKING_HORIZON'=>'weeks','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS'=>'weeks','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME'=>'duration','PAYMENT_RECOVERY_POLICY'=>'policy_reference','INSTALMENT_DUE_DATE_POLICY'=>'policy_reference');
        $key=sanitize_text_field(wp_unslash((string)($_POST['policy_key']??'')));
        if(!isset($types[$key]))wp_die('Unknown policy','Delnavazan',array('response'=>400));
        $value=sanitize_text_field(wp_unslash((string)($_POST['policy_value']??'')));
        $message='Policy version recorded';
        $recorded=true;
        try{
            $now=gmdate('Y-m-d H:i:s');
            (new CommercialPolicyService())->set($key,array(
                'policy_value'=>$value,'value_type'=>$types[$key],'reason_code'=>'settings_policy_update',
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>'settings-policies:'.get_current_user_id().':'.wp_generate_uuid4(),
                'evidence_at'=>$now,
            ),'settings-policies-'.$key.'-'.wp_generate_uuid4());
        }catch(\Throwable $error){$message='Policy not changed. Check the value and your policy permissions.';$recorded=false;}
        $anchor=sanitize_key((string)wp_unslash($_POST['return_anchor']??''));
        if(!in_array($anchor,array('policy-cards','booking-horizon'),true))$anchor='policy-cards';
        $redirect=add_query_arg(array('page'=>self::MENU_SLUG,'dzn_policy_status'=>$message),admin_url('admin.php'));
        wp_safe_redirect($recorded?$redirect.'#'.$anchor:$redirect);
        exit;
    }

    private static function flash(string $status):void{
        if($status==='')return;
        $messages=array(
            'reminder_sent'=>'Reminder recorded through the canonical notification service.',
            'reminder_unavailable'=>'The reminder could not be resolved. No direct message was sent.',
            'job_retried'=>'The failed job was re-queued.',
            'job_not_retryable'=>'Only a failed job can be retried.',
            'reevaluation_queued'=>'Dataset re-evaluation jobs were queued.',
        );
        $message=$messages[$status]??('Consent action: '.$status);
        echo '<div class="notice notice-info is-dismissible"><p>'.esc_html($message).'</p></div>';
    }
}
