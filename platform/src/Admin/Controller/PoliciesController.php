<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CommercialPolicyService;
use Delnavazan\Platform\Core\Application\ConsentLifecycleWorker;
use Delnavazan\Platform\Core\Application\ConsentNotificationService;
use Delnavazan\Platform\Core\Application\ConsentOperationsReadService;
use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\ConsentWithdrawalService;
use Delnavazan\Platform\Core\Application\MlDatasetEligibilityService;
use Delnavazan\Platform\Core\Application\RecordingNoticePublicationService;

/**
 * Settings → Policies: policy library and consent operations.
 *
 * This is the single administration surface for consent. It reads authoritative evidence, links to
 * the existing teacher/student profiles, sends only catalogue-backed reminders, retries only
 * authorised failed jobs and exports an access-controlled audit view. It has no write path that
 * creates or changes a person's consent decision.
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
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_'.self::ACTION_COMMERCIAL_POLICY,array(__CLASS__,'saveCommercialPolicy'));
        foreach(array(self::ACTION_REMINDER,self::ACTION_RETRY,self::ACTION_REEVALUATE,self::ACTION_WORKER,self::ACTION_EXPORT)as$action)add_action('admin_post_'.$action,array(__CLASS__,'command'));
    }

    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(Menu::ROOT_SLUG,'Policies','Policies',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
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
        if(!current_user_can(self::CAPABILITY))wp_die('Forbidden','Delnavazan',array('response'=>403));
        $service=new ConsentOperationsReadService();
        $counts=$service->counts();
        $status=sanitize_key((string)($_GET['dzn_consent']??''));
        echo '<div class="wrap dzn-settings-page dzn-policies-page" dir="ltr">';
        echo '<h1>Policies</h1>';
        echo '<p class="dzn-settings-lede">One-page operational policy reference. Editable values are written through their existing versioned authority; structural rules remain locked.</p>';
        self::flash($status);
        self::operationalPolicies();
        self::indicators($counts);
        self::policyLibrary($service);
        self::consentOperations($service,$counts);
        self::jobs($service);
        self::evidenceInspector();
        echo '</div>';
    }


    /** One-page, English-only catalogue. Never introduce a parallel policy store. */
    private static function operationalPolicies():void{
        $sections=array(
            'Booking and teacher allocation'=>array(
                array('Introductory booking horizon','INTRO_BOOKING_HORIZON','weeks','How far ahead an introductory lesson can be requested.','editable'),
                array('Teacher offer response window',null,'24 hours','Enforced: unanswered offers expire and are reassigned to the next eligible Teacher; the Teacher portal shows the remaining time.','locked'),
                array('Unassigned request escalation',null,'3 days','Agreed operational target; verify runtime enforcement before making configurable.','review'),
                array('Teacher allocation order',null,'Round-robin','Agreed allocation workflow; implementation authority must be verified.','review'),
            ),
            'Terms and lessons'=>array(
                array('Lessons per term',null,'12 sessions','Structural term commitment; not an editable commercial policy.','locked'),
                array('Payment instalment structure',null,'Sessions 1–6 and 7–12','Structural commercial instalment boundaries.','locked'),
            ),
            'Attendance and replacements'=>array(
                array('Replacement allowance',null,'2 per term','The term-recorded allowance is authoritative; changing it here would create conflicting state.','locked'),
                array('Replacement acceptance',null,'Teacher proposes; student accepts','Rescheduling must use the canonical schedule revision service.','review'),
            ),
            'Teachers and availability'=>array(
                array('Availability for new bookings',null,'Selected teaching hours only','Unselected hours are unavailable; existing bookings are preserved.','review'),
            ),
            'Finance, renewals and payments'=>array(
                array('Manual renewal slot guarantee','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','weeks','Versioned commercial renewal protection window.','editable'),
                array('Automatic renewal charge lead time','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','duration','Versioned lead time in seconds; leave unset unless approved.','editable'),
                array('Payment recovery policy','PAYMENT_RECOVERY_POLICY','policy_reference','Approved policy reference, not arbitrary free-form rules.','editable'),
                array('Instalment due date policy','INSTALMENT_DUE_DATE_POLICY','policy_reference','Approved policy reference; unset means no policy has been activated.','editable'),
            ),
            'Notifications and communications'=>array(
                array('Mandatory account and financial notices',null,'Always required','Manage templates and delivery in Settings → Notifications.','locked'),
            ),
        );
        echo '<section class="dzn-settings-card" id="operational-policy-centre"><h2>Operational policies and rules</h2>';
        echo '<p>Search the policy register or jump to a category. A rule marked Review is an operational requirement that has not been verified as a configurable runtime policy. Do not mistake a documented target for a deployed control.</p>';
        echo '<p><label for="dzn-policy-search">Search policies</label> <input type="search" id="dzn-policy-search" class="regular-text" placeholder="Search rules and descriptions"></p>';
        echo '<nav aria-label="Policy categories">';
        $index=0;
        foreach(array_keys($sections) as $title){echo '<a class="button button-small" style="margin:0 6px 6px 0" href="#dzn-policy-section-'.esc_attr((string)$index).'">'.esc_html($title).'</a>'; $index++;}
        echo ' <a class="button button-small" href="#dzn-consent-policy-library">Privacy and consent</a></nav>';
        $service=new CommercialPolicyService();
        $index=0;
        foreach($sections as $title=>$rows){
            echo '<div class="dzn-policy-category" id="dzn-policy-section-'.esc_attr((string)$index).'"><h3>'.esc_html($title).'</h3>';
            foreach($rows as $rule){
                [$label,$key,$unit,$description,$kind]=$rule;
                $state=null;
                if($key!==null){try{$state=$service->current($key);}catch(\Throwable $e){$state=array('set'=>false,'version'=>null,'value'=>null,'read_error'=>true);}}
                $value=$key!==null?(!empty($state['set'])?(string)$state['value'].' '.($unit==='weeks'?'weeks':($unit==='duration'?'seconds':'')):'Not configured'):$unit;
                echo '<article class="dzn-policy-rule" style="border-top:1px solid #dcdcde;padding:12px 0" data-policy-search="'.esc_attr(strtolower($label.' '.$description.' '.$title)).'">';
                echo '<strong>'.esc_html($label).'</strong> <span class="dzn-settings-meta">('.esc_html($kind==='editable'?'Versioned':($kind==='locked'?'Fixed':'Review required')).')</span>';
                echo '<p style="margin:4px 0">'.esc_html($description).'</p><p><strong>Current:</strong> '.esc_html($value);
                if($state!==null&&!empty($state['version']))echo ' <span class="dzn-settings-meta">Version '.esc_html((string)$state['version']).'</span>';
                echo '</p>';
                if($key!==null&&empty($state['read_error'])&&current_user_can('dzn_manage_commercial_policies')){
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_COMMERCIAL_POLICY).'"><input type="hidden" name="policy_key" value="'.esc_attr($key).'">';
                    wp_nonce_field(self::ACTION_COMMERCIAL_POLICY);
                    echo '<label>New value <input name="policy_value" type="'.($unit==='weeks'||$unit==='duration'?'number':'text').'" '.($unit==='weeks'?'min="1" max="104"':($unit==='duration'?'min="1"':'' )).' value="'.esc_attr(!empty($state['set'])?(string)$state['value']:'').'"></label> ';
                    echo '<button class="button button-secondary" type="submit">Record new version</button></form>';
                }
                echo '</article>';
            }
            echo '</div>';
            $index++;
        }
        if(isset($_GET['dzn_policy_status']))echo '<p role="status">'.esc_html(sanitize_text_field(wp_unslash((string)$_GET['dzn_policy_status']))).'</p>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var q=document.getElementById("dzn-policy-search");if(!q)return;q.addEventListener("input",function(){var term=q.value.toLowerCase().trim();document.querySelectorAll(".dzn-policy-rule").forEach(function(el){el.hidden=!!term&&!el.dataset.policySearch.includes(term)});document.querySelectorAll(".dzn-policy-category").forEach(function(el){el.hidden=!!term&&!el.querySelector(".dzn-policy-rule:not([hidden])")})})});</script>';
        echo '</section>';
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
        try{
            $now=gmdate('Y-m-d H:i:s');
            (new CommercialPolicyService())->set($key,array(
                'policy_value'=>$value,'value_type'=>$types[$key],
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>'settings-policies:'.get_current_user_id().':'.wp_generate_uuid4(),
                'evidence_at'=>$now,
            ),'settings-policies-'.$key.'-'.wp_generate_uuid4());
        }catch(\Throwable $error){$message='Policy not changed. Check the value and your policy permissions.';}
        wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG,'dzn_policy_status'=>$message),admin_url('admin.php')).'#operational-policy-centre');
        exit;
    }

    private static function indicators(array $counts):void{
        $cards=array(
            'Teachers awaiting consent'=>(int)$counts['teachers_awaiting'],
            'Students awaiting consent'=>(int)$counts['students_awaiting'],
            'Guardian decisions requiring attention'=>(int)$counts['guardian_attention'],
            'Recording-authorised lessons'=>(int)$counts['recording_authorised_lessons'],
            'ML-eligible recordings'=>(int)$counts['ml_eligible_recordings'],
            'Blocked or quarantined'=>(int)$counts['blocked_or_quarantined'],
            'Pending withdrawal actions'=>(int)$counts['pending_withdrawals'],
            'Failed consent jobs'=>(int)$counts['failed_jobs'],
        );
        echo '<section class="dzn-settings-card"><h2>Consent indicators</h2><div class="dzn-settings-cards">';
        foreach($cards as$label=>$value)echo '<div class="dzn-settings-card"><h3>'.esc_html($label).'</h3><p class="dzn-settings-meta" style="font-size:1.6em;margin:0"><strong>'.esc_html((string)$value).'</strong></p></div>';
        echo '</div><p class="dzn-settings-note">Counts are derived from append-only decisions, authorisation evidence and processing jobs. Capture and research export remain disabled in this build.</p></section>';
    }

    private static function policyLibrary(ConsentOperationsReadService $service):void{
        $library=$service->policyLibrary();
        echo '<section class="dzn-settings-card" id="dzn-consent-policy-library"><h2>Policy library</h2>';
        echo '<p>Recording, research, privacy and recorded-work terms are independent purposes. A new publication creates a new immutable version; withdrawing a publication closes every published version for that audience and language.</p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Purpose</th><th>Subject</th><th>Notice audience</th><th>Optional</th><th>Guardian</th><th>Recording</th><th>Dataset</th></tr></thead><tbody>';
        foreach((array)$library['purposes']as$purpose){
            echo '<tr><td><strong>'.esc_html((string)$purpose->title_en).'</strong><br><span class="dzn-settings-meta">'.esc_html((string)$purpose->title_fa).'</span></td><td>'.esc_html((string)$purpose->subject_scope).'</td><td><code>'.esc_html((string)$purpose->notice_audience_code).'</code></td><td>'.((int)$purpose->optional_purpose===1?'Yes':'No').'</td><td>'.((int)$purpose->guardian_allowed===1?'Allowed':'Not allowed').'</td><td>'.((int)$purpose->required_for_recording===1?'Required':'No').'</td><td>'.((int)$purpose->required_for_dataset===1?'Required':'No').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Published and historical versions</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Audience</th><th>Language</th><th>Version</th><th>State</th><th>Approval</th><th>Published</th><th>Withdrawn</th><th>Digest</th></tr></thead><tbody>';
        foreach((array)$library['versions']as$version){
            echo '<tr><td><code>'.esc_html((string)$version->audience_code).'</code></td><td>'.esc_html((string)$version->language_code).'</td><td>'.esc_html((string)$version->version_number).'</td><td>'.esc_html((string)$version->publication_state).'</td><td>'.esc_html((string)$version->approval_state).'</td><td>'.esc_html((string)($version->published_at??'—')).'</td><td>'.esc_html((string)($version->withdrawn_at??'—')).'</td><td><code>'.esc_html(substr((string)$version->text_digest,0,16)).'…</code></td></tr>';
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

    private static function consentOperations(ConsentOperationsReadService $service,array $counts):void{
        echo '<section class="dzn-settings-card" id="dzn-consent-operations"><h2>Consent operations</h2>';
        self::pendingTable('Teachers awaiting consent','teacher',$service->pendingTeachers());
        self::pendingTable('Students awaiting consent','student',$service->pendingStudents());
        echo '<h3>Guardian decisions requiring attention</h3>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Student</th><th>Active guardian grants</th><th>Action</th></tr></thead><tbody>';
        $guardians=$service->guardianAttention();
        if(!$guardians)echo '<tr><td colspan="3"><em>No minor student is awaiting a guardian decision.</em></td></tr>';
        foreach($guardians as$row){
            echo '<tr><td>'.esc_html((string)$row->display_name).' <span class="dzn-settings-meta">#'.esc_html((string)$row->id).'</span></td><td>'.esc_html((string)$row->active_grants).'</td><td>'.self::linkProfile('student',(int)$row->id).' '.self::reminderButton('guardian',(int)$row->id).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Recent withdrawals and opt-outs</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Decision</th><th>Subject</th><th>Purpose</th><th>Choice</th><th>Decided (UTC)</th><th>Profile</th></tr></thead><tbody>';
        $withdrawn=$service->withdrawn();
        if(!$withdrawn)echo '<tr><td colspan="6"><em>No withdrawal or opt-out has been recorded.</em></td></tr>';
        foreach($withdrawn as$row){
            echo '<tr><td><code>#'.esc_html((string)$row->id).'</code></td><td>'.esc_html((string)$row->subject_type).' #'.esc_html((string)$row->subject_id).'</td><td><code>'.esc_html((string)$row->purpose_code).'</code></td><td>'.esc_html((string)$row->decision_code).'</td><td>'.esc_html((string)$row->decided_at).'</td><td>'.self::linkProfile((string)$row->subject_type,(int)$row->subject_id).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Recording and dataset eligibility exceptions</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Kind</th><th>Source</th><th>Reason</th><th>Occurred (UTC)</th></tr></thead><tbody>';
        $exceptions=$service->exceptions();
        if(!$exceptions)echo '<tr><td colspan="4"><em>No eligibility exception is recorded.</em></td></tr>';
        foreach($exceptions as$row)echo '<tr><td>'.esc_html((string)$row->kind).'</td><td>#'.esc_html((string)$row->source_id).'</td><td><code>'.esc_html((string)$row->reason).'</code></td><td>'.esc_html((string)$row->occurred_at).'</td></tr>';
        echo '</tbody></table>';
        echo '<h3>Dataset operations</h3><p>Re-evaluation is queued through the durable consent worker. It never re-enables a withdrawn permission.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_REEVALUATE).'">'.wp_nonce_field(self::ACTION_REEVALUATE,'_wpnonce',true,false).'<label>Dataset key <input type="text" name="dataset_key" value="persian_music_research" class="regular-text"></label> <button class="button" type="submit">Queue re-evaluation</button></form>';
        echo '</section>';
    }

    private static function jobs(ConsentOperationsReadService $service):void{
        echo '<section class="dzn-settings-card" id="dzn-consent-jobs"><h2>Withdrawal processing</h2>';
        echo '<p>Jobs are leased, retryable and durable. A completed recording disposition reports truthfully when no captured asset exists.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-settings-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_WORKER).'">'.wp_nonce_field(self::ACTION_WORKER,'_wpnonce',true,false).'<button class="button" type="submit">Run pending jobs (20)</button></form>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Job</th><th>Type</th><th>Subject</th><th>Purpose</th><th>State</th><th>Attempts</th><th>Last error</th><th>Result</th><th>Action</th></tr></thead><tbody>';
        $jobs=$service->jobs();
        if(!$jobs)echo '<tr><td colspan="9"><em>No consent processing job exists.</em></td></tr>';
        foreach($jobs as$job){
            echo '<tr><td><code>#'.esc_html((string)$job->id).'</code></td><td><code>'.esc_html((string)$job->job_type).'</code></td><td>'.esc_html((string)$job->subject_type).' #'.esc_html((string)$job->subject_id).'</td><td><code>'.esc_html((string)$job->purpose_code).'</code></td><td>'.esc_html((string)$job->state).'</td><td>'.esc_html((string)$job->attempts).'/'.esc_html((string)$job->max_attempts).'</td><td>'.esc_html((string)($job->last_error_code??'—')).'</td><td>'.esc_html((string)($job->result_code??'—')).'</td><td>';
            if((string)$job->state==='failed')echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_RETRY).'"><input type="hidden" name="job_id" value="'.esc_attr((string)$job->id).'">'.wp_nonce_field(self::ACTION_RETRY,'_wpnonce',true,false).'<button class="button button-small" type="submit">Retry</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function evidenceInspector():void{
        echo '<section class="dzn-settings-card" id="dzn-consent-evidence"><h2>Consent evidence</h2>';
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

    private static function pendingTable(string $title,string $type,array $rows):void{
        echo '<h3>'.esc_html($title).'</h3><table class="widefat striped dzn-settings-table"><thead><tr><th>Name</th><th>Classification</th><th>Action</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="3"><em>No one is awaiting this consent.</em></td></tr>';
        foreach($rows as$row){
            echo '<tr><td>'.esc_html((string)$row->display_name).' <span class="dzn-settings-meta">#'.esc_html((string)$row->id).'</span></td><td>'.esc_html((string)($row->classification??'—')).'</td><td>'.self::linkProfile($type,(int)$row->id).' '.self::reminderButton($type,(int)$row->id).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function linkProfile(string $type,int $id):string{
        $url=$type==='teacher'?admin_url('admin.php?page=dzn-teachers-menu&id='.$id):admin_url('admin.php?page=dzn-student&id='.$id);
        return '<a class="button button-small" href="'.esc_url($url).'">Open profile</a>';
    }

    private static function reminderButton(string $type,int $id):string{
        return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="'.esc_attr(self::ACTION_REMINDER).'"><input type="hidden" name="subject_type" value="'.esc_attr($type).'"><input type="hidden" name="subject_id" value="'.esc_attr((string)$id).'">'.wp_nonce_field(self::ACTION_REMINDER,'_wpnonce',true,false).'<button class="button button-small" type="submit">Send reminder</button></form>';
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
