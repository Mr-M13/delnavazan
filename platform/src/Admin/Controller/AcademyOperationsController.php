<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AcademyJourneyReadService;
use Delnavazan\Platform\Core\Application\CoreReadService;

/**
 * Read-only academy operations pipeline. It joins canonical/authoritative projections only and
 * deep-links to their existing command owners; this screen never becomes a second writer.
 */
final class AcademyOperationsController {
    private const CAP='dzn_view_booking_requests';
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        $query=isset($_GET['q'])&&is_scalar($_GET['q'])?sanitize_text_field(wp_unslash($_GET['q'])):'';
        $stage=isset($_GET['stage'])&&is_scalar($_GET['stage'])?sanitize_key(wp_unslash($_GET['stage'])):'needs_action';
        $view=isset($_GET['view'])&&is_scalar($_GET['view'])?sanitize_key(wp_unslash($_GET['view'])):'overview';
        if(!in_array($view,array('overview','upcoming','attention'),true))$view='overview';
        $page=isset($_GET['paged'])&&is_scalar($_GET['paged'])?max(1,absint($_GET['paged'])):1;
        $result=(new AcademyJourneyReadService())->search($query,$stage,$page);$rows=$result['rows'];
        echo '<div class="wrap"><h1>Academy</h1><p>Today’s work is collected here. Review the next action for each learner journey, then open the existing operational screen to complete it. The authorised path is booking → coordination and reviewed identity → accepted Service Arrangement → Enrolment → Teacher Assignment → Term and Lessons.</p>';
        self::pendingActions();
        echo '<form method="get"><input type="hidden" name="page" value="dzn-academy-dashboard"><label>Search name, email, booking reference, instrument or course <input type="search" name="q" value="'.esc_attr($query).'"></label> <label>Needs action <select name="stage"><option value="needs_action"'.selected($stage,'needs_action',false).'>Action queue</option><option value="">All records</option>';
        foreach(array('needs_coordination'=>'Coordination','needs_identity'=>'Student identity review','needs_acceptance'=>'Acceptance','needs_enrolment'=>'Enrolment conversion','needs_assignment'=>'Teacher assignment','complete'=>'Assignment complete')as$value=>$label)echo '<option value="'.esc_attr($value).'"'.selected($stage,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <button class="button">Search</button></form><p><strong>'.(int)$result['total'].'</strong> matching learner journeys · showing up to '.(int)$result['per_page'].' per page.</p>';
        self::classOperationalState();
        if (in_array($view, array('upcoming', 'attention'), true)) {
            $target = $view === 'upcoming' ? 'dzn-academy-today-upcoming' : 'dzn-academy-attention';
            echo '<script>document.getElementById(' . wp_json_encode($target) . ')?.scrollIntoView();</script>';
        }
        echo '<h2>Needs attention</h2>';
        $detailId=isset($_GET['detail_request_id'])&&is_scalar($_GET['detail_request_id'])?absint($_GET['detail_request_id']):0;
        if($detailId>0)foreach($rows as$r)if((int)$r->request_id===$detailId){self::journeyDetail($r);break;}
        echo '<table class="widefat striped"><thead><tr><th>Booking / person</th><th>Instrument / course</th><th>Journey stage</th><th>Current blocker / next action</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="4">No booking journeys match this search.</td></tr>';
        foreach($rows as$r){$detailUrl=add_query_arg(array('page'=>'dzn-academy-dashboard','q'=>$query,'stage'=>$stage,'paged'=>$page,'detail_request_id'=>(int)$r->request_id),admin_url('admin.php'));echo '<tr><td><a href="'.esc_url($detailUrl).'">'.esc_html((string)$r->request_ref).'</a><br>'.esc_html((string)($r->student_name?:$r->contact_name?:'Identity not resolved')).($r->contact_email?'<br>'.esc_html((string)$r->contact_email):'').'</td><td>'.esc_html((string)($r->instrument_fa?:$r->instrument_en?:'')).($r->course_fa||$r->course_en?' · '.esc_html((string)($r->course_fa?:$r->course_en)):'').'</td>';
            $journey=($r->case_id?'Coordinated':'Coordination needed').' · '.($r->student_id?'Identity reviewed':'Identity unresolved').' · '.($r->arrangement_id?'Accepted':'Not accepted').' · '.($r->enrolment_id?'Enrolled':'No Enrolment').' · '.($r->assignment_id?'Teacher assigned':'No Teacher Assignment').' · '.self::claimStatus($r);
            echo '<td>'.esc_html($journey).'</td><td>'.self::attention($r).'<br>'.self::next($r).'</td></tr>';}
        if($result['pages']>1){echo '</tbody></table><nav class="tablenav"><div class="tablenav-pages">';for($n=1;$n<=$result['pages'];$n++){if(abs($n-$page)>2&&$n!==1&&$n!==$result['pages'])continue;$url=add_query_arg(array('page'=>'dzn-academy-dashboard','q'=>$query,'stage'=>$stage,'paged'=>$n),admin_url('admin.php'));echo '<a class="button'.($n===$page?' button-primary':'').'" href="'.esc_url($url).'">'.(int)$n.'</a> ';}echo '</div></nav>';}else echo '</tbody></table>';
        echo '</div>';
    }
    /** Prominent queues are individually capability-gated; this page never broadens authority. */
    private static function pendingActions():void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$items=[];
        if(current_user_can('dzn_manage_onboarding')){
            $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_onboarding_states WHERE state='pending_review'");
            $items[]=['Teacher onboarding submissions',$count,'dzn-teachers-menu','Review and decide',['view'=>'onboarding']];
        }
        if(current_user_can('dzn_manage_booking_request_coordination')){
            $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries d INNER JOIN {$p}booking_requests r ON r.id=d.booking_request_id WHERE d.state='pending_request' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL");
            $items[]=['Teacher booking requests pending response',$count,'dzn-booking-requests','Review request delivery',[]];
        }
        if(!$items)return;
        echo '<section class="notice notice-warning dzn-academy-pending-actions"><h2>Pending actions</h2><ul>';
        foreach($items as[$label,$count,$page,$action,$args])echo '<li><strong>'.(int)$count.'</strong> '.esc_html($label).' · <a class="button" href="'.esc_url(add_query_arg(array_merge(['page'=>$page],$args),admin_url('admin.php'))).'">'.esc_html($action).'</a></li>';
        echo '</ul></section>';
    }
    private static function journeyDetail(object $r):void{
        echo '<section class="notice notice-info"><h2>Journey detail: '.esc_html((string)$r->request_ref).'</h2><p>'.esc_html((string)($r->student_name?:$r->contact_name?:'Identity unresolved')).($r->contact_email?' · '.esc_html((string)$r->contact_email):'').' · '.esc_html((string)($r->instrument_fa?:$r->instrument_en?:'Instrument unavailable')).($r->course_fa||$r->course_en?' · '.esc_html((string)($r->course_fa?:$r->course_en)):'').'</p><ol>';
        foreach(array('Booking submitted'=>true,'Coordination opened'=>(bool)$r->case_id,'Student identity resolved'=>(bool)$r->student_id,'Service Arrangement accepted'=>(bool)$r->arrangement_id,'Enrolment converted'=>(bool)$r->enrolment_id,'Teacher Assignment current'=>(bool)$r->assignment_id,'Student account connected'=>$r->claim_state==='claimed','Term created'=>(bool)$r->term_id)as$label=>$done)echo '<li>'.esc_html($label).': <strong>'.($done?'Complete':'Pending').'</strong></li>';
        echo '</ol><p><strong>Current blocker and next authorised action:</strong> '.self::next($r).'</p><p>Unavailable actions are identified by their required capability or missing predecessor stage above. Authority screens revalidate each command.</p></section>';
    }
    /**
     * Reuses the Classes workflow's capability-gated projection. The Academy page
     * intentionally does not query lesson tables or expose class data to an actor
     * who cannot open the existing Classes authority screen.
     */
    private static function classOperationalState(): void {
        if (!current_user_can('dzn_manage_lessons')) return;
        $rows = (new CoreReadService())->classOperatorRows();
        $attention = array_values(array_filter($rows, static fn(object $row): bool => in_array((string) $row->class_status, array('Integrity review needed', 'Schedule needs review', 'Ready to schedule'), true)));
        $todayRows = array_values(array_filter($rows, static fn(object $row): bool => self::scheduleDate($row) !== '' && self::scheduleDate($row) === self::todayInScheduleTimezone($row)));
        $upcoming = array_values(array_filter($rows, static fn(object $row): bool => self::scheduleDate($row) > self::todayInScheduleTimezone($row)));
        echo '<section class="dzn-academy-class-state" id="dzn-academy-classes"><h2>Classes: today and next</h2><p>Live class state from the existing Classes read model. Times remain in each class’s scheduled time zone.</p><div class="dzn-academy-class-state__summary"><strong>'.(int) count($attention).'</strong> class'.(count($attention) === 1 ? '' : 'es').' need scheduling or integrity review · <strong>'.(int) count($todayRows).'</strong> scheduled today</div>';
        echo '<div id="dzn-academy-attention"><h3>Attention needed</h3>';
        if ($attention) {
            echo '<ul>';
            foreach (array_slice($attention, 0, 20) as $row) {
                $url = self::classUrl($row);
                $label = (string) ($row->student_name ?: $row->course_name ?: 'Class');
                echo '<li><a href="'.esc_url($url).'">'.esc_html($label).'</a> · '.esc_html((string) $row->class_status).'</li>';
            }
            if (count($attention) > 20) echo '<li>Plus '.(int) (count($attention) - 20).' more in Classes.</li>';
            echo '</ul>';
        } else {
            echo '<p>No classes currently need scheduling or integrity review.</p>';
        }
        echo '</div><div id="dzn-academy-today-upcoming"><h3>Today and upcoming lessons</h3>';
        if (!$todayRows && !$upcoming) {
            echo '<p>No scheduled classes are currently visible in the Classes workflow.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>Student / course</th><th>Teacher</th><th>Class state</th><th></th></tr></thead><tbody>';
            foreach (array_slice(array_merge($todayRows, $upcoming), 0, 20) as $row) {
                $url = self::classUrl($row);
                $action = ($row->record_model ?? '') === 'confirmed_intro_booking_v1' ? 'Open booking' : 'Open class';
                echo '<tr><td>'.esc_html((string) $row->schedule_label).'</td><td>'.esc_html((string) ($row->student_name ?: 'Student')).'<br><small>'.esc_html((string) ($row->course_name ?: 'Course')).'</small></td><td>'.esc_html((string) ($row->teacher_name ?: 'Teacher')).'</td><td>'.esc_html((string) $row->class_status).'</td><td><a class="button" href="'.esc_url($url).'">'.esc_html($action).'</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div><p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-lesson')).'">Open Classes</a></p></section>';
    }
    private static function classUrl(object $row): string {
        if (($row->record_model ?? '') === 'confirmed_intro_booking_v1') {
            return add_query_arg(array('page' => 'dzn-booking-requests', 'request_id' => (int) $row->booking_request_id), admin_url('admin.php'));
        }
        return add_query_arg(array('page' => 'dzn-lesson', 'id' => (int) $row->id), admin_url('admin.php'));
    }
    private static function scheduleDate(object $row): string {
        $instant = self::scheduleUtcInstant((string) ($row->schedule_starts_at_utc ?? ''));
        $timezone = (string) ($row->schedule_timezone ?? '');
        if ($instant !== null && self::validScheduleTimezone($timezone)) {
            return $instant->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        }
        $label = (string) ($row->schedule_label ?? '');
        return preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}) · /', $label, $matches) ? $matches[1] : '';
    }
    private static function todayInScheduleTimezone(object $row): string {
        $timezone = (string) ($row->schedule_timezone ?? '');
        if (self::validScheduleTimezone($timezone)) {
            return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        }
        $label = (string) ($row->schedule_label ?? '');
        $fallback = substr($label, (int) strrpos($label, ' ') + 1);
        if (self::validScheduleTimezone($fallback)) {
            return (new \DateTimeImmutable('now', new \DateTimeZone($fallback)))->format('Y-m-d');
        }
        return gmdate('Y-m-d');
    }
    private static function scheduleUtcInstant(string $value): ?\DateTimeImmutable {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $date;
    }
    private static function validScheduleTimezone(string $timezone): bool {
        if ($timezone === '') return false;
        try { new \DateTimeZone($timezone); return true; }
        catch (\Throwable) { return false; }
    }
    private static function attention(object $r):string{
        if(!$r->match_attention_code)return '<span aria-label="No active matching attention">—</span>';
        $labels=['no_current_coverage'=>'No current teacher coverage','instrument_unavailable'=>'Instrument unavailable','selected_course_unavailable'=>'Selected intro course unavailable','default_intro_course_unavailable'=>'Default intro course unavailable'];
        $label=$labels[(string)$r->match_attention_code]??'Matching needs attention';
        $action=current_user_can('dzn_prepare_booking_request_matches')?self::link('dzn-booking-requests','Review request',['request_id'=>(int)$r->request_id]):'Read-only';
        if($r->match_attention_code==='no_current_coverage'&&current_user_can('dzn_manage_teachers'))$action.=' '.self::link('dzn-teachers-menu','View supply gaps');
        return '<strong>'.esc_html($label).'</strong><br>'.$action;
    }
    private static function next(object $r):string{
        if($r->match_attention_code==='no_current_coverage'){
            $links=[];
            if(current_user_can('dzn_manage_teachers'))$links[]=self::link('dzn-teachers-menu','Review teacher availability');
            if(current_user_can('dzn_prepare_booking_request_matches'))$links[]=self::link('dzn-booking-requests','Reassess request',['request_id'=>(int)$r->request_id]);
            if(current_user_can('dzn_manage_booking_request_coordination')&&$r->case_id)$links[]=self::link('dzn-booking-request-coordination','Continue coordination',['case_id'=>(int)$r->case_id]);
            return $links?implode('<br>',$links):'Await authorised coverage review';
        }
        if(!$r->case_id)return current_user_can('dzn_manage_booking_request_coordination')?'<strong>Blocker:</strong> coordination has not started.<br>'.self::link('dzn-booking-request-coordination','Open coordination',['booking_request_id'=>(int)$r->request_id]):'<strong>Unavailable:</strong> coordination requires booking-coordination capability.';
        if(!$r->student_id)return current_user_can('dzn_resolve_booking_request_student_identity')&&current_user_can('dzn_view_student_acceptance_eligibility')?'<strong>Blocker:</strong> booking identity is unresolved; review evidence and resolve or record an outcome.<br>'.self::link('dzn-student-identity-authority','Review student identity',['booking_request_id'=>(int)$r->request_id]):'<strong>Unavailable:</strong> identity review requires identity-resolution and acceptance-eligibility access.';
        if(!$r->arrangement_id)return current_user_can('dzn_manage_booking_request_coordination')?'<strong>Blocker:</strong> no accepted Service Arrangement exists; confirm acceptance authority and complete coordination.<br>'.self::link('dzn-booking-request-coordination','Continue coordination',['case_id'=>(int)$r->case_id]):'<strong>Unavailable:</strong> no accepted arrangement; coordination capability is required.';
        if($r->arrangement_id&&!$r->enrolment_id)return current_user_can('dzn_convert_service_arrangements_to_enrolments')?'<strong>Blocker:</strong> accepted arrangement has not been converted to an Enrolment.<br>'.self::link('dzn-core-dataset-readiness','Convert accepted arrangement',['accepted_service_arrangement_id'=>(int)$r->arrangement_id]):'<strong>Unavailable:</strong> Enrolment conversion capability is required.';
        if($r->enrolment_id&&!$r->assignment_id)return current_user_can('dzn_manage_teacher_assignments')?'<strong>Blocker:</strong> Enrolment exists; initial assignment follows its accepted arrangement.<br>'.self::link('dzn-core-dataset-readiness','Assign accepted Teacher',['enrolment_id'=>(int)$r->enrolment_id]):'<strong>Unavailable:</strong> teacher-assignment capability is required.';
        if($r->enrolment_id&&$r->claim_state==='active')return '<strong>Student account:</strong> invitation is active; review its lifecycle before replacing it.';
        if($r->enrolment_id&&$r->claim_state!=='claimed')return current_user_can('dzn_issue_student_account_claims')?'<strong>Blocker:</strong> Student account has not been connected.<br>'.self::link('dzn-core-dataset-readiness','Offer Student account claim',['enrolment_id'=>(int)$r->enrolment_id]):'<strong>Unavailable:</strong> student-account claim capability is required.';
        if($r->assignment_id&&current_user_can('dzn_manage_teacher_assignments'))$replace=self::link('dzn-core-dataset-readiness','Review replacement by agreement',['enrolment_id'=>(int)$r->enrolment_id,'expected_assignment_id'=>(int)$r->assignment_id]);else $replace='';
        if($r->assignment_id&&!$r->term_id)return (current_user_can('dzn_manage_canonical_terms')?self::link('dzn-core-dataset-readiness','Create canonical Term',['enrolment_id'=>(int)$r->enrolment_id]):'Unavailable: canonical-Term capability is required.').'<br>'.$replace;
        if($r->term_id&&(int)$r->lesson_count===0)return (current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Issue first Lesson',['term_id'=>(int)$r->term_id,'teacher_assignment_id'=>(int)$r->assignment_id]):'Unavailable: canonical-Lesson capability is required.').'<br>'.$replace;
        return (current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Manage canonical lessons',['term_id'=>(int)$r->term_id,'teacher_assignment_id'=>(int)$r->assignment_id]):'Journey assigned; further lesson actions require canonical-Lesson capability.').'<br>'.$replace;
    }
    private static function claimStatus(object $r):string{if(!$r->enrolment_id)return 'Account after enrolment';if($r->claim_state==='claimed')return 'Account connected';if($r->claim_state==='active')return 'Account invitation active';if($r->claim_state==='revoked')return 'Account invitation needs review';return 'Account not offered';}
    private static function link(string $page,string $label,array $args=[]):string{$url=add_query_arg(['page'=>$page]+$args,admin_url('admin.php'));return '<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a>';}
}
