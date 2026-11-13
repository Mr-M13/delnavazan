<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\{GodModeController,GodModeTeacherController};
use Delnavazan\Platform\Core\Application\GodMode\{GodModeRule,GodModeScenarioLibrary};

/** English-only presentation for Admin -> God Mode. */
final class GodModeView {
    private const TABS=array('overview'=>'Overview','book'=>'Book Test Class','scenarios'=>'Test Scenarios','history'=>'Test History','inspector'=>'Session Inspector','settings'=>'Settings');
    public function __construct(private object $read){}

    /** @param array<string,mixed> $context */
    public function render(string $tab,?array $flash,array $context):void{
        echo '<div class="wrap dzn-god-mode" dir="ltr">';
        $this->header($context);
        if($flash!==null)$this->notice((string)($flash['type']??'info'),(string)($flash['message']??''));
        $this->tabs($tab);
        echo '<div class="dzn-gm-panel">';
        match($tab){
            'book'=>$this->book($context),
            'scenarios'=>$this->scenarios($context),
            'history'=>$this->history($context),
            'inspector'=>$this->inspector($context),
            'settings'=>$this->settings($context),
            default=>$this->overview($context),
        };
        echo '</div></div>';
    }

    /** @param array<string,mixed> $context */
    private function header(array $context):void{
        $identity=(array)($context['identity']??array());
        $active=(string)($identity['auth_state']??'missing')==='active'&&!empty($identity['capability']);
        echo '<div class="dzn-gm-header"><div><h1>God Mode</h1><p>Permanent Super Tester environment for end-to-end validation. Every test session is explicitly classified and financially isolated.</p></div>';
        echo '<div class="dzn-gm-status '.($active?'is-active':'is-inactive').'"><span class="dzn-gm-dot"></span>'.($active?'Authorised':'Not authorised').'<small>User ID '.(int)($identity['user_id']??0).'</small></div></div>';
    }

    private function notice(string $type,string $message):void{
        $class=in_array($type,array('success','error','warning'),true)?$type:'info';
        echo '<div class="notice notice-'.$class.'"><p>'.esc_html($message).'</p></div>';
    }

    private function tabs(string $tab):void{
        echo '<nav class="dzn-gm-tabs" aria-label="God Mode sections">';
        foreach(self::TABS as $key=>$label)echo '<a class="'.($tab===$key?'is-current':'').'" href="'.esc_url(add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>$key),admin_url('admin.php'))).'">'.esc_html($label).'</a>';
        echo '</nav>';
    }

    /** @param array<string,mixed> $context */
    private function overview(array $context):void{
        $m=(array)$context['metrics'];
        echo '<div class="dzn-gm-cards">';
        foreach(array(
            'total_sessions'=>array('Total test sessions','Session'),'active_sessions'=>array('Active test sessions','Scheduled or in progress'),
            'completed_tests'=>array('Completed tests','Completed test activity'),'passed_scenarios'=>array('Passed scenarios','Evidence-backed'),
            'failed_scenarios'=>array('Failed scenarios','Needs investigation'),'attention_scenarios'=>array('Scenarios requiring attention','Warning or manual verification'),
            'meet_failures'=>array('Meet provisioning failures','Provider failures'),'recording_failures'=>array('Recording verification failures','Unavailable evidence'),
        ) as $key=>$meta)echo '<div class="dzn-gm-card"><span>'.esc_html($meta[0]).'</span><strong>'.esc_html((string)($m[$key]??0)).'</strong><small>'.esc_html($meta[1]).'</small></div>';
        echo '</div>';
        echo '<div class="dzn-gm-grid"><section class="dzn-gm-box"><h2>Recent testing activity</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Test ID</th><th>Stage</th><th>State</th><th>Result</th></tr></thead><tbody>';
        if(empty($context['recent']))echo '<tr><td colspan="5">No test activity yet.</td></tr>';
        foreach((array)$context['recent'] as $row)echo '<tr><td>'.esc_html((string)$row->occurred_at).'</td><td><a href="'.esc_url($this->sessionUrl((int)$row->session_id)).'">'.esc_html((string)$row->reference_code).'</a></td><td>'.esc_html($this->read->stageLabel((string)$row->stage)).'</td><td>'.esc_html(GodModeRule::timelineStateLabel((string)$row->state)).'</td><td>'.esc_html(ucfirst(str_replace('_',' ',(string)($row->session_status??'')))).'</td></tr>';
        echo '</tbody></table></section>';
        echo '<aside class="dzn-gm-box"><h2>Principles</h2><ul class="dzn-gm-list"><li><strong>Freedom:</strong> any teacher, instrument, time, timezone and supported duration.</li><li><strong>Realism:</strong> genuine Calendar, Meet, co-host and notification pathways where safe.</li><li><strong>Isolation:</strong> no teacher earnings, invoices, charges, term balances or financial KPIs.</li><li><strong>Observability:</strong> every stage records requested, attempted, confirmed or unknown evidence.</li><li><strong>Maintainability:</strong> one secure capability, one dashboard and one explicit test aggregate.</li></ul>';
        echo '<p><a class="button" href="'.esc_url(add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>'book'),admin_url('admin.php'))).'">Book a test class</a> <a class="button button-secondary" href="'.esc_url(add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>'scenarios'),admin_url('admin.php'))).'">Run a scenario</a></p></aside></div>';
    }

    /** @param array<string,mixed> $context */
    private function book(array $context):void{
        $options=(array)$context['options'];$settings=(array)$context['settings'];
        $defaultDate=gmdate('Y-m-d',time()+86400);
        echo '<h2>Book Test Class</h2><p class="description">All normal availability, golden-hour, capacity, eligibility, payment and enrolment restrictions are bypassed for this test aggregate only. A conflict with a genuine class requires explicit confirmation and is never resolved by changing the genuine class.</p>';
        echo '<form id="dzn-gm-book-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-form">';
        $this->actionFields('book_test');
        echo '<div class="dzn-gm-form-grid">';
        $this->field('Teacher','<select name="teacher_id" required>'.$this->teacherOptions($options,(int)($_GET['teacher_id']??0)).'</select>');
        $this->field('Instrument','<select name="instrument_id">'.$this->instrumentOptions($options,0).'</select>');
        $this->field('Test type','<select name="scenario_code">'.implode('',array_map(static fn(array $s):string=>'<option value="'.esc_attr((string)$s['code']).'">'.esc_html((string)$s['title']).'</option>',array_values((array)$context['scenarios']))).'</select>');
        $this->field('Mode','<select name="mode"><option value="direct"'.selected((string)$settings['default_mode'],'direct',false).'>Direct Test Mode</option><option value="workflow"'.selected((string)$settings['default_mode'],'workflow',false).'>Workflow Test Mode</option></select>');
        $this->field('Date','<input type="date" name="local_date" value="'.esc_attr($defaultDate).'" required>');
        $this->field('Start time','<input type="time" name="local_time" value="10:00" required>');
        $this->field('Timezone','<select name="timezone">'.$this->timezoneOptions((string)$settings['default_timezone']).'</select>');
        $this->field('Duration (minutes)','<input type="number" name="duration_minutes" min="5" max="1440" value="'.esc_attr((string)$settings['default_duration_minutes']).'">');
        $this->field('Notification plan','<select name="notification_behavior"><option value="none">None</option><option value="portal"'.selected((string)$settings['default_notification_behavior'],'portal',false).'>Portal only</option><option value="email"'.selected((string)$settings['default_notification_behavior'],'email',false).'>Portal + email</option><option value="all"'.selected((string)$settings['default_notification_behavior'],'all',false).'>All approved channels</option></select>');
        $this->field('Title','<input type="text" name="title" value="God Mode test class" maxlength="191">');
        echo '</div>';
        echo '<p><label><input type="checkbox" name="recording" value="1" '.checked((bool)$settings['recording_default'],true,false).'> Request automatic recording (configuration and actual availability are verified separately)</label></p>';
        echo '<p><label class="dzn-gm-danger"><input type="checkbox" name="conflict_acknowledged" value="1"> I acknowledge any displayed conflict with a genuine class. The genuine class will not be cancelled, moved or modified.</label></p>';
        echo '<p><label>Description (optional)<br><textarea name="description" rows="3" maxlength="1000"></textarea></label></p>';
        echo '<p class="dzn-gm-actions"><button class="button button-primary" name="operation" value="book_test" type="submit">Book Test Class</button> <button class="button" name="operation" value="check_conflicts" type="submit">Check Conflicts</button> <button class="button" name="operation" value="preview_test" type="submit">Preview Test</button> <a class="button button-link" href="'.esc_url(add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>'overview'),admin_url('admin.php'))).'">Cancel</a></p>';
        echo '</form>';
    }

    /** @param array<string,mixed> $context */
    private function scenarios(array $context):void{
        echo '<h2>Test Scenario Library</h2><p class="description">Reusable scenarios report only evidence that was actually verified. Human participation and external recording availability are never fabricated.</p>';
        echo '<form id="dzn-gm-scenario-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-form">';
        $this->actionFields('run_scenario');
        echo '<div class="dzn-gm-form-grid">';
        $options=(array)$context['options'];$settings=(array)$context['settings'];
        $this->field('Teacher','<select name="teacher_id">'.$this->teacherOptions($options,0).'</select>');
        $this->field('Instrument','<select name="instrument_id">'.$this->instrumentOptions($options,0).'</select>');
        $this->field('Date','<input type="date" name="local_date" value="'.esc_attr(gmdate('Y-m-d',time()+86400)).'">');
        $this->field('Time','<input type="time" name="local_time" value="10:00">');
        $this->field('Timezone','<select name="timezone">'.$this->timezoneOptions((string)$settings['default_timezone']).'</select>');
        $this->field('Duration','<input type="number" name="duration_minutes" min="5" max="1440" value="'.esc_attr((string)$settings['default_duration_minutes']).'">');
        echo '</div><p><label><input type="checkbox" name="conflict_acknowledged" value="1"> Acknowledge conflicts for this run.</label></p></form>';
        $grouped=array();foreach((array)$context['scenario_library'] as $scenario)$grouped[(string)$scenario['category']][]=$scenario;
        foreach($grouped as $category=>$items){
            echo '<h3>'.esc_html($category).'</h3><table class="widefat striped dzn-gm-scenarios"><thead><tr><th>Scenario</th><th>Prerequisites</th><th>Mode</th><th>Verification</th><th>Action</th></tr></thead><tbody>';
            foreach($items as $scenario){
                $manual=!empty($scenario['manual_verification']);
                echo '<tr><td><strong>'.esc_html((string)$scenario['title']).'</strong><br><small>'.esc_html((string)$scenario['description']).'</small><br><code>'.esc_html((string)$scenario['code']).'</code></td>';
                echo '<td><small>'.esc_html(implode(' · ',(array)$scenario['prerequisites'])).'</small></td>';
                echo '<td>'.esc_html(ucfirst((string)$scenario['mode'])).'</td>';
                echo '<td>'.($manual?'<span class="dzn-gm-badge is-warning">Human verification</span>':'<span class="dzn-gm-badge is-auto">Automated evidence</span>').'</td>';
                echo '<td><button class="button button-small" form="dzn-gm-scenario-form" name="scenario_code" value="'.esc_attr((string)$scenario['code']).'" type="submit">Run</button></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3>Recent scenario runs</h3><table class="widefat striped"><thead><tr><th>Started</th><th>Scenario</th><th>State</th><th>Summary</th><th>Test session</th></tr></thead><tbody>';
        if(empty($context['scenario_runs']))echo '<tr><td colspan="5">No scenario runs yet.</td></tr>';
        foreach((array)$context['scenario_runs'] as $run)echo '<tr><td>'.esc_html((string)$run->started_at).'</td><td>'.esc_html((string)$run->scenario_code).'</td><td>'.esc_html(GodModeRule::resultLabel((string)$run->state)).'</td><td>'.esc_html((string)($run->result_summary??'')).'</td><td>'.(!empty($run->session_id)?'<a href="'.esc_url($this->sessionUrl((int)$run->session_id)).'">Open</a>':'—').'</td></tr>';
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $context */
    private function history(array $context):void{
        $filters=(array)$context['filters'];
        echo '<h2>Test History</h2><p class="description">Only explicitly classified test activity appears here. Filters and bulk actions never touch genuine student or teacher records.</p>';
        echo '<form method="get" class="dzn-gm-filters"><input type="hidden" name="page" value="'.esc_attr(GodModeController::MENU_SLUG).'"><input type="hidden" name="tab" value="history">';
        echo '<label>Teacher <select name="teacher_id"><option value="0">All</option>';
        foreach((array)$context['teachers'] as $teacher)echo '<option value="'.esc_attr((string)$teacher->id).'"'.selected((int)($filters['teacher_id']??0),(int)$teacher->id,false).'>'.esc_html((string)$teacher->name).'</option>';
        echo '</select></label> <label>Status <select name="status"><option value="">All</option>'.implode('',array_map(static fn(string $s):string=>'<option value="'.esc_attr($s).'"'.selected((string)($filters['status']??''),$s,false).'>'.esc_html(ucfirst(str_replace('_',' ',$s))).'</option>',GodModeRule::STATUSES)).'</select></label>';
        echo ' <label>Scenario <input type="text" name="scenario" value="'.esc_attr((string)($filters['scenario_code']??'')).'" placeholder="scenario code"></label>';
        echo ' <label>Mode <select name="mode"><option value="">All</option><option value="direct"'.selected((string)($filters['mode']??''),'direct',false).'>Direct</option><option value="workflow"'.selected((string)($filters['mode']??''),'workflow',false).'>Workflow</option></select></label>';
        echo ' <label>Result <select name="result"><option value="">All</option>'.implode('',array_map(static fn(string $s):string=>'<option value="'.esc_attr($s).'"'.selected((string)($filters['result']??''),$s,false).'>'.esc_html(GodModeRule::resultLabel($s)).'</option>',GodModeRule::RESULTS)).'</select></label>';
        echo ' <label>From <input type="date" name="from" value="'.esc_attr(substr((string)($filters['from_utc']??''),0,10)).'"></label> <label>To <input type="date" name="to" value="'.esc_attr(substr((string)($filters['to_utc']??''),0,10)).'"></label>';
        echo ' <label>Search <input type="search" name="q" value="'.esc_attr((string)($filters['search']??'')).'" placeholder="Test ID or title"></label>';
        echo ' <label>Sort <select name="sort"><option value="newest"'.selected((string)($filters['sort']??'newest'),'newest',false).'>Newest first</option><option value="oldest"'.selected((string)($filters['sort']??''),'oldest',false).'>Oldest first</option><option value="teacher"'.selected((string)($filters['sort']??''),'teacher',false).'>Teacher</option><option value="result"'.selected((string)($filters['sort']??''),'result',false).'>Result</option></select></label>';
        echo ' <button class="button">Filter</button> <a class="button button-link" href="'.esc_url(add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>'history'),admin_url('admin.php'))).'">Reset</a></form>';
        echo '<form id="dzn-gm-bulk-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->actionFields('bulk_action');echo '</form>';
        echo '<div class="dzn-gm-bulk"><label>Bulk action <select name="bulk" form="dzn-gm-bulk-form"><option value="">Select</option><option value="archive">Archive selected</option><option value="delete">Permanently remove eligible selected</option></select></label> <button class="button" type="submit" form="dzn-gm-bulk-form" onclick="return confirm(\'Run this bulk action on the selected test records only?\')">Apply</button> <span>'.esc_html((string)$context['history_count']).' matching test records</span></div>';
        echo '<table class="widefat striped dzn-gm-history"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.dzn-gm-select\').forEach(function(c){c.checked=this.checked;}.bind(this))"></th><th>Test ID</th><th>Scenario</th><th>Teacher</th><th>Instrument</th><th>Mode</th><th>Date and time</th><th>Duration</th><th>Meet</th><th>Recording</th><th>Result</th><th>Created by</th><th>Actions</th></tr></thead><tbody>';
        if(empty($context['history']))echo '<tr><td colspan="13">No test records match these filters.</td></tr>';
        foreach((array)$context['history'] as $row){
            $id=(int)$row->id;
            echo '<tr><td><input class="dzn-gm-select" form="dzn-gm-bulk-form" type="checkbox" name="selected[]" value="'.esc_attr((string)$id).'"></td>';
            echo '<td><a href="'.esc_url($this->sessionUrl($id)).'"><code>'.esc_html((string)$row->reference_code).'</code></a></td>';
            echo '<td>'.esc_html((string)$row->scenario_code).'</td><td>'.esc_html((string)($row->teacher_name??$row->teacher_name_fa??'—')).'</td><td>'.esc_html((string)($row->instrument_name_fa??$row->instrument_name_en??'—')).'</td><td>'.esc_html(ucfirst((string)$row->mode)).'</td>';
            echo '<td>'.esc_html((string)$row->starts_at_utc).' <small>'.esc_html((string)$row->schedule_timezone).'</small></td><td>'.esc_html((string)$row->duration_minutes).' min</td>';
            echo '<td>'.esc_html(ucfirst(str_replace('_',' ',(string)$row->meet_state))).'</td><td>'.esc_html(ucfirst(str_replace('_',' ',(string)$row->recording_state))).'</td><td>'.esc_html(GodModeRule::resultLabel((string)$row->result)).'</td><td>'.esc_html((string)$row->created_by).'</td>';
            echo '<td class="dzn-gm-row-actions"><a class="button button-small" href="'.esc_url($this->sessionUrl($id)).'">Inspect</a>';
            if((string)$row->meet_state==='ready')$this->joinForm($id,'Join');
            $this->simpleForm('duplicate_test',$id,'Duplicate');
            if(in_array((string)$row->meet_state,array('failed','pending'),true))$this->simpleForm('retry_stage',$id,'Retry',array('stage'=>GodModeRule::STAGE_MEET_PROVISIONED));
            if(in_array((string)$row->status,array('scheduled','in_progress'),true))$this->simpleForm('cancel_test',$id,'Cancel',array(),true);
            if(in_array((string)$row->status,array('completed','cancelled','failed'),true))$this->simpleForm('archive_test',$id,'Archive');
            if((string)$row->status==='archived'&&in_array((string)$row->recording_state,array('not_requested','unknown','failed'),true))$this->simpleForm('delete_test',$id,'Remove',array(),true);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $context */
    private function inspector(array $context):void{
        $inspector=$context['inspector'];
        if(!is_array($inspector)){
            echo '<h2>Session Inspector</h2><p class="description">Select a test session to inspect its chronological evidence.</p><table class="widefat striped"><thead><tr><th>Test ID</th><th>Scenario</th><th>Teacher</th><th>Start</th><th>Status</th><th>Result</th></tr></thead><tbody>';
            if(empty($context['history']))echo '<tr><td colspan="6">No test sessions yet.</td></tr>';
            foreach((array)$context['history'] as $row)echo '<tr><td><a href="'.esc_url($this->sessionUrl((int)$row->id)).'"><code>'.esc_html((string)$row->reference_code).'</code></a></td><td>'.esc_html((string)$row->scenario_code).'</td><td>'.esc_html((string)($row->teacher_name??$row->teacher_name_fa??'—')).'</td><td>'.esc_html((string)$row->starts_at_utc).'</td><td>'.esc_html((string)$row->status).'</td><td>'.esc_html(GodModeRule::resultLabel((string)$row->result)).'</td></tr>';
            echo '</tbody></table>';return;
        }
        $s=(array)$inspector['session'];$id=(int)$s['id'];
        echo '<h2>Session Inspector <code>'.esc_html((string)$s['reference_code']).'</code></h2>';
        echo '<div class="dzn-gm-inspector-head"><div class="dzn-gm-cards dzn-gm-cards--compact">';
        foreach(array('Status'=>(string)$s['status'],'Result'=>GodModeRule::resultLabel((string)$s['result']),'Mode'=>ucfirst((string)$s['mode']),'Activity type'=>(string)$s['activity_type'],'Meet'=>(string)$s['meet_state'],'Recording'=>(string)$s['recording_state'],'Billing impact'=>'0') as $label=>$value)echo '<div class="dzn-gm-card"><span>'.esc_html($label).'</span><strong>'.esc_html($value).'</strong></div>';
        echo '</div><div class="dzn-gm-actions">';
        if((string)$s['meet_state']==='ready')$this->joinForm($id,'Join Class');
        $this->simpleForm('duplicate_test',$id,'Duplicate');
        if(in_array((string)$s['status'],array('scheduled','in_progress'),true))$this->simpleForm('cancel_booking',$id,'Cancel booking',array(),true);
        if((string)$s['meet_state']==='failed')$this->simpleForm('retry_stage',$id,'Retry Meet',array('stage'=>GodModeRule::STAGE_MEET_PROVISIONED));
        if(!empty($s['recording_requested']))$this->simpleForm('verify_recording',$id,'Verify recording');
        $this->simpleForm('verify_attendance_evidence',$id,'Detect participant evidence');
        if(in_array((string)$s['status'],array('completed','cancelled','failed'),true))$this->simpleForm('archive_test',$id,'Archive');
        echo '<a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>GodModeController::EXPORT_ACTION,'session'=>$id),admin_url('admin-post.php')),'dzn_god_mode_export_'.$id)).'">Export diagnostics</a>';
        echo '</div></div>';
        echo '<div class="dzn-gm-grid"><section class="dzn-gm-box"><h3>Session facts</h3><table class="widefat"><tbody>';
        foreach(array('Test ID'=>(string)$s['reference_code'],'Scenario'=>(string)$s['scenario_code'],'Teacher ID'=>(string)$s['teacher_id'],'Instrument ID'=>(string)($s['instrument_id']??''),'Test student'=>(string)$s['student_id'],'Starts (UTC)'=>(string)$s['starts_at_utc'],'Ends (UTC)'=>(string)$s['ends_at_utc'],'Timezone'=>(string)$s['schedule_timezone'],'Duration'=>(string)$s['duration_minutes'].' minutes','Conflict summary'=>(string)($s['conflict_summary']??'None'),'Failure stage'=>(string)($s['failure_stage']??'None'),'Last error'=>(string)($s['last_error_code']??'None'),'Purge eligible'=>(string)($s['purge_eligible_at']??'Not eligible')) as $label=>$value)echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        echo '</tbody></table></section>';
        echo '<aside class="dzn-gm-box"><h3>Controlled actions</h3>';
        echo '<h4>Manual attendance</h4><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->actionFields('confirm_attendance',array('session_id'=>$id));
        echo '<p><label><input type="checkbox" name="attended" value="1" checked> Attended</label></p><p><input type="text" name="note" placeholder="Safe note" maxlength="200"></p><button class="button" type="submit">Confirm attendance and complete</button></form>';
        echo '<h4>Reschedule test class</h4><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-form">';$this->actionFields('reschedule_test',array('session_id'=>$id,'teacher_id'=>(string)$s['teacher_id'],'instrument_id'=>(string)($s['instrument_id']??''),'course_id'=>(string)($s['course_id']??'')));
        echo '<div class="dzn-gm-form-grid"><p class="dzn-gm-field"><label><span>Date</span><input type="date" name="local_date" value="'.esc_attr(substr((string)$s['starts_at_utc'],0,10)).'" required></label></p><p class="dzn-gm-field"><label><span>Time (UTC)</span><input type="time" name="local_time" value="'.esc_attr(substr((string)$s['starts_at_utc'],11,5)).'" required></label></p><p class="dzn-gm-field"><label><span>Timezone</span><select name="timezone">'.$this->timezoneOptions((string)$s['schedule_timezone']).'</select></label></p><p class="dzn-gm-field"><label><span>Duration</span><input type="number" name="duration_minutes" min="5" max="1440" value="'.esc_attr((string)$s['duration_minutes']).'"></label></p></div>';
        echo '<p><label class="dzn-gm-danger"><input type="checkbox" name="conflict_acknowledged" value="1"> Acknowledge any genuine conflict before moving this test class.</label></p><button class="button" type="submit">Reschedule test class</button></form>';
        echo '<h4>Notification test</h4><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->actionFields('notification_test',array('session_id'=>$id));
        echo '<p><select name="channel"><option value="portal">Portal</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select> <select name="recipient_kind"><option value="owner">Approved tester</option><option value="teacher">Participating teacher</option></select> <button class="button" type="submit">Send test</button></p></form>';
        echo '<p class="description">Transport hand-off is recorded. Recipient delivery requires separate verification.</p></aside></div>';
        echo '<h3>Chronological timeline</h3><table class="widefat striped dzn-gm-timeline"><thead><tr><th>Stage</th><th>State</th><th>Timestamp</th><th>Correlation ID</th><th>Evidence</th><th>Provider</th><th>Retry</th></tr></thead><tbody>';
        if(empty($inspector['timeline']))echo '<tr><td colspan="7">No timeline evidence recorded.</td></tr>';
        foreach((array)$inspector['timeline'] as $event){
            echo '<tr><td><strong>'.esc_html((string)$event['stage_label']).'</strong></td><td><span class="dzn-gm-state is-'.esc_attr((string)$event['state']).'">'.esc_html((string)$event['state_label']).'</span></td><td>'.esc_html((string)$event['occurred_at']).'</td><td><code>'.esc_html((string)$event['correlation_id']).'</code></td><td><pre>'.esc_html(wp_json_encode((array)$event['evidence'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
            if((string)$event['error_code']!=='')echo '<small class="dzn-gm-error">'.esc_html((string)$event['error_code']).' · '.esc_html((string)$event['error_detail']).'</small>';
            echo '</td><td>'.($event['provider_status']!==null?esc_html((string)$event['provider_status']):'—').'</td><td>'.($event['retryable']?'<button class="button button-small" form="dzn-gm-retry-'.(int)$event['id'].'" type="submit">Retry</button>':'').'</td></tr>';
            if($event['retryable']){echo '<form id="dzn-gm-retry-'.(int)$event['id'].'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->actionFields('retry_stage',array('session_id'=>$id,'stage'=>(string)$event['retry_stage']));echo '</form>';}
        }
        echo '</tbody></table>';
        echo '<h3>Participant identity mapping</h3>';
        $attendance=(array)($inspector['attendance_evidence']??array());
        $participants=(array)($attendance['participants']??array());
        if(empty($participants)){
            echo '<p class="description">No participant evidence is stored yet. Run “Detect participant evidence” after the class ends.</p>';
        }else{
            echo '<p>Verified teacher present: <strong>'.(!empty($attendance['verified_teacher_present'])?'Yes':'No').'</strong> · Verified student present: <strong>'.(!empty($attendance['verified_student_present'])?'Yes':'No').'</strong> · Attendance verified: <strong>'.(!empty($attendance['verified_attendance'])?'Yes':'No').'</strong></p>';
            echo '<table class="widefat striped"><thead><tr><th>Participant evidence</th><th>Type</th><th>Sessions</th><th>Mapped role</th><th>Action</th></tr></thead><tbody>';
            foreach($participants as $participant){
                $digest=(string)($participant['digest']??'');
                if(!preg_match('/^[a-f0-9]{64}$/D',$digest))continue;
                echo '<tr><td><code>'.esc_html(substr($digest,0,16)).'…</code></td><td>'.esc_html((string)($participant['type']??'')).'</td><td>'.esc_html((string)($participant['session_count']??0)).'</td><td>'.esc_html((string)($participant['mapped_role']??'Unmapped')).'</td><td>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline">';$this->actionFields('map_participant',array('session_id'=>$id,'participant_digest'=>$digest,'participant_type'=>(string)($participant['type']??'unknown')));
                echo '<select name="role"><option value="teacher">Teacher</option><option value="student">Student</option></select> <button class="button button-small" type="submit">Map identity</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3>Notification attempts</h3><table class="widefat striped"><thead><tr><th>Channel</th><th>Recipient</th><th>State</th><th>Provider</th><th>Outcome</th><th>Fallback</th><th>Attempted</th></tr></thead><tbody>';
        if(empty($inspector['notification_tests']))echo '<tr><td colspan="7">No notification attempts.</td></tr>';
        foreach((array)$inspector['notification_tests'] as $n)echo '<tr><td>'.esc_html((string)$n->channel).'</td><td>'.esc_html((string)$n->recipient_kind).'</td><td>'.esc_html((string)$n->state).'</td><td>'.esc_html((string)($n->provider_key??'')).'</td><td>'.esc_html((string)($n->outcome_code??'')).'</td><td>'.($n->fallback_used?'used'.($n->fallback_condition_verified?' · condition verified':''):'not used').'</td><td>'.esc_html((string)$n->attempted_at).'</td></tr>';
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $context */
    private function settings(array $context):void{
        $settings=(array)$context['settings'];$identity=(array)$context['identity'];
        echo '<h2>Settings</h2>';
        echo '<div class="dzn-gm-grid"><section class="dzn-gm-box"><h3>Designated Super Tester</h3><table class="widefat"><tbody>';
        $user=get_userdata((int)($identity['user_id']??0));
        foreach(array('WordPress user ID'=>(string)($identity['user_id']??0),'Account'=>($user&&is_object($user)?(string)$user->display_name:'Not resolved'),'Email'=>($user&&is_object($user)?(string)$user->user_email:'Not resolved'),'Capability'=>!empty($identity['capability'])?'Granted':'Not granted','Authority state'=>ucfirst((string)($identity['auth_state']??'missing')),'Administrator access'=>!empty($identity['manage_options'])?'Preserved':'Missing') as $label=>$value)echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        echo '</tbody></table><p class="description">The designation is fixed server-side by verified WordPress user ID. It is not a public role and cannot be assigned from this page.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Revoke God Mode for this account? Historical test records will remain classified.\')">';$this->actionFields('revoke_authority');echo '<button class="button button-link-delete" type="submit">Revoke God Mode authority</button></form></section>';
        echo '<section class="dzn-gm-box"><h3>Testing preferences</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->actionFields('save_settings');
        echo '<p><label>Default timezone<br><select name="default_timezone">'.$this->timezoneOptions((string)$settings['default_timezone']).'</select></label></p>';
        echo '<p><label>Default duration (minutes)<br><input type="number" name="default_duration_minutes" min="5" max="1440" value="'.esc_attr((string)$settings['default_duration_minutes']).'"></label></p>';
        echo '<p><label>Default booking mode<br><select name="default_mode"><option value="direct"'.selected((string)$settings['default_mode'],'direct',false).'>Direct</option><option value="workflow"'.selected((string)$settings['default_mode'],'workflow',false).'>Workflow</option></select></label></p>';
        echo '<p><label>Default notification behaviour<br><select name="default_notification_behavior"><option value="none"'.selected((string)$settings['default_notification_behavior'],'none',false).'>None</option><option value="portal"'.selected((string)$settings['default_notification_behavior'],'portal',false).'>Portal</option><option value="email"'.selected((string)$settings['default_notification_behavior'],'email',false).'>Portal + email</option><option value="all"'.selected((string)$settings['default_notification_behavior'],'all',false).'>All approved channels</option></select></label></p>';
        echo '<p><label>Approved testing recipients (one email or E.164 phone per line)<br><textarea name="approved_recipients" rows="4">'.esc_textarea(implode("\n",(array)$settings['approved_recipients'])).'</textarea></label></p>';
        echo '<p><label>Test-data retention period (days)<br><input type="number" name="retention_days" min="1" max="365" value="'.esc_attr((string)$settings['retention_days']).'"></label></p>';
        echo '<p><label><input type="checkbox" name="recording_default" value="1" '.checked(!empty($settings['recording_default']),true,false).'> Request recording by default</label><br><label><input type="checkbox" name="automatic_cleanup_eligibility" value="1" '.checked(!empty($settings['automatic_cleanup_eligibility']),true,false).'> Mark archived test data eligible after the retention period</label><br><label><input type="checkbox" name="conflict_warning_enabled" value="1" '.checked(!empty($settings['conflict_warning_enabled']),true,false).'> Require conflict acknowledgement</label><br><label><input type="checkbox" name="diagnostic_logging" value="1" '.checked(!empty($settings['diagnostic_logging']),true,false).'> Record diagnostic evidence</label></p>';
        echo '<p><button class="button button-primary" type="submit">Save settings</button></p></form></section></div>';
        echo '<section class="dzn-gm-box"><h3>Legacy student-data inventory</h3><p class="description">Read-only dependency inventory for the designated account. This never deletes a record. Financial and consent evidence are marked immutable.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline">';$this->actionFields('legacy_inventory');echo '<button class="button" type="submit">Run dry-run inventory</button></form> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline">';$this->actionFields('legacy_archive');echo '<button class="button button-secondary" type="submit">Write recoverable snapshot</button></form>';
        echo '<p class="description">Age-based lifecycle: archive completed or cancelled test sessions older than the retention period. This never permanently deletes data.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline">';$this->actionFields('cleanup_by_age');echo '<button class="button" type="submit">Archive test data older than retention period</button></form>';
        echo '<p class="description">Permanent cleanup removes only archived test records that pass classification, pending-provider and retained-recording checks. Audit records are retained.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline" onsubmit="return confirm(\'Permanently remove every eligible archived test record? Genuine records and audit evidence are never touched.\')">';$this->actionFields('cleanup_all_eligible');echo '<button class="button button-link-delete" type="submit">Remove all eligible archived test data</button></form>';
        if(is_array($context['inventory'])){
            $inventory=$context['inventory'];
            echo '<p><strong>Dry-run summary:</strong> '.(int)$inventory['totals']['records'].' related records · '.(int)$inventory['totals']['financial_evidence'].' financial evidence · '.(int)$inventory['totals']['retained_or_legal'].' retained/legal. No deletion was executed.</p>';
            echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Records</th><th>Classification</th><th>Retention</th><th>Sample IDs</th></tr></thead><tbody>';
            foreach((array)$inventory['items'] as $item)echo '<tr><td>'.esc_html((string)$item['label']).'<br><small>'.esc_html(str_replace('_',' ',(string)$item['table'])).'</small></td><td>'.esc_html((string)$item['count']).'</td><td>'.esc_html(str_replace('_',' ',(string)$item['classification'])).'</td><td>'.esc_html(str_replace('_',' ',(string)$item['retention'])).'</td><td><small>'.esc_html(implode(', ',array_slice((array)$item['sample_ids'],0,20))).'</small></td></tr>';
            echo '</tbody></table><p class="description">Destructive cleanup is not available in this release without separate approval. The snapshot is a recoverable manifest of record identifiers and counts, not a copy of financial or consent evidence.</p>';
        }
        echo '</section>';
    }

    private function actionFields(string $operation,array $extra=array()):void{
        echo '<input type="hidden" name="action" value="'.esc_attr(GodModeController::ACTION).'">';
        echo '<input type="hidden" name="operation" value="'.esc_attr($operation).'">';
        foreach($extra as $key=>$value)echo '<input type="hidden" name="'.esc_attr((string)$key).'" value="'.esc_attr((string)$value).'">';
        wp_nonce_field(GodModeController::ACTION);
    }
    private function simpleForm(string $operation,int $sessionId,string $label,array $extra=array(),bool $confirm=false):void{
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline"'.($confirm?' onsubmit="return confirm(\'This action affects this test session only. Continue?\')"':'').'>';
        $this->actionFields($operation,array_merge(array('session_id'=>$sessionId),$extra));
        echo '<button class="button button-small" type="submit">'.esc_html($label).'</button></form>';
    }
    private function joinForm(int $sessionId,string $label):void{
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-gm-inline"><input type="hidden" name="action" value="'.esc_attr(GodModeController::JOIN_ACTION).'"><input type="hidden" name="session_id" value="'.esc_attr((string)$sessionId).'">';
        wp_nonce_field(GodModeController::JOIN_ACTION.'_'.$sessionId);
        echo '<button class="button button-primary button-small" type="submit">'.esc_html($label).'</button></form>';
    }
    private function field(string $label,string $control):void{
        echo '<p class="dzn-gm-field"><label><span>'.esc_html($label).'</span>'.$control.'</label></p>';
    }
    private function teacherOptions(array $options,int $selected):string{
        $out='';foreach((array)($options['teachers']??array()) as $teacher){$name=trim((string)($teacher->display_name??$teacher->persian_name??$teacher->english_name??'Teacher'));$out.='<option value="'.esc_attr((string)$teacher->id).'"'.selected($selected,(int)$teacher->id,false).'>'.esc_html($name).'</option>';}
        return $out;
    }
    private function instrumentOptions(array $options,int $selected):string{
        $out='';foreach((array)($options['instruments']??array()) as $instrument){$name=trim((string)($instrument->name_fa??$instrument->name_en??'Instrument'));$out.='<option value="'.esc_attr((string)$instrument->id).'"'.selected($selected,(int)$instrument->id,false).'>'.esc_html($name).'</option>';}
        return $out;
    }
    private function timezoneOptions(string $selected):string{
        $out='';foreach(timezone_identifiers_list() as $zone)$out.='<option value="'.esc_attr($zone).'"'.selected($selected,$zone,false).'>'.esc_html($zone).'</option>';
        return $out;
    }
    private function sessionUrl(int $id):string{return add_query_arg(array('page'=>GodModeController::MENU_SLUG,'tab'=>'inspector','session'=>$id),admin_url('admin.php'));}
}
