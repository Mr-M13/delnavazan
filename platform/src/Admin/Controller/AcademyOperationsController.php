<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AcademyJourneyReadService;

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
        $page=isset($_GET['paged'])&&is_scalar($_GET['paged'])?max(1,absint($_GET['paged'])):1;
        $result=(new AcademyJourneyReadService())->search($query,$stage,$page);$rows=$result['rows'];
        echo '<div class="wrap"><h1>Academy</h1><p>Today’s work is collected here. Review the next action for each learner journey, then open the existing operational screen to complete it.</p><h2>Needs attention</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="dzn-academy-dashboard"><label>Search name, email, booking reference, instrument or course <input type="search" name="q" value="'.esc_attr($query).'"></label> <label>Needs action <select name="stage"><option value="needs_action"'.selected($stage,'needs_action',false).'>Action queue</option><option value="">All records</option>';
        foreach(array('needs_coordination'=>'Coordination','needs_identity'=>'Student identity review','needs_acceptance'=>'Acceptance','needs_enrolment'=>'Enrolment conversion','needs_assignment'=>'Teacher assignment','complete'=>'Assignment complete')as$value=>$label)echo '<option value="'.esc_attr($value).'"'.selected($stage,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <button class="button">Search</button></form><p>'.(int)$result['total'].' matching learner journeys · showing up to '.(int)$result['per_page'].' per page.</p>';
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
    private static function journeyDetail(object $r):void{
        echo '<section class="notice notice-info"><h2>Journey detail: '.esc_html((string)$r->request_ref).'</h2><p>'.esc_html((string)($r->student_name?:$r->contact_name?:'Identity unresolved')).($r->contact_email?' · '.esc_html((string)$r->contact_email):'').' · '.esc_html((string)($r->instrument_fa?:$r->instrument_en?:'Instrument unavailable')).($r->course_fa||$r->course_en?' · '.esc_html((string)($r->course_fa?:$r->course_en)):'').'</p><ol>';
        foreach(array('Booking submitted'=>true,'Coordination opened'=>(bool)$r->case_id,'Student identity resolved'=>(bool)$r->student_id,'Service Arrangement accepted'=>(bool)$r->arrangement_id,'Enrolment converted'=>(bool)$r->enrolment_id,'Teacher Assignment current'=>(bool)$r->assignment_id,'Student account connected'=>$r->claim_state==='claimed','Term created'=>(bool)$r->term_id)as$label=>$done)echo '<li>'.esc_html($label).': <strong>'.($done?'Complete':'Pending').'</strong></li>';
        echo '</ol><p><strong>Current blocker and next authorised action:</strong> '.self::next($r).'</p><p>Unavailable actions are identified by their required capability or missing predecessor stage above. Authority screens revalidate each command.</p></section>';
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
