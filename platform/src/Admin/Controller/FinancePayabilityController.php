<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourPayService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Application\Finance\Integrity\FinanceSnapshotIntegrity;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceTeacherOptionsReadService;

/**
 * Administrator surface for Lesson payability (contract §9/§14).
 *
 * View-only: per-Lesson disposition, basis, policy version and override presence. A `pending` disposition
 * is shown as a blocker; nothing on this screen mutates a Finance fact.
 */
final class FinancePayabilityController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-payability';
    public const PARENT_SLUG=Menu::ROOT_SLUG;

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Lesson payability','Lesson payability',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $options=(new FinanceTeacherOptionsReadService)->options();
        $teacherId=isset($_GET['teacher_id'])&&is_scalar($_GET['teacher_id'])?absint($_GET['teacher_id']):0;
        if(!(new FinanceTeacherOptionsReadService)->isValid($teacherId))$teacherId=(int)($options[0]->id??0);
        echo '<div class="wrap"><h1>Lesson payability</h1>';
        echo '<p>Derived payability only. A pending disposition blocks statement issuance.</p>';
        self::teacherSelector($teacherId,$options);
        if($teacherId<1){echo '<p>No Teacher is available for payability review.</p></div>';return;}
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT evaluation.lesson_id,evaluation.disposition,evaluation.basis_code,evaluation.policy_key,evaluation.policy_version,evaluation.override_id,evaluation.applicable_slot,snapshot.derived_amount_minor base_amount_minor,snapshot.currency,snapshot.rate_amount_minor,snapshot.rate_id,snapshot.rate_version,lesson.reference_code lesson_ref,COALESCE(NULLIF(student.display_name,''),NULLIF(student.reference_code,''),'Student') student_name,COALESCE(NULLIF(course.name_en,''),NULLIF(course.name_fa,''),'Course') course_name FROM {$p}finance_payability_evaluations evaluation INNER JOIN {$p}lessons lesson ON lesson.id=evaluation.lesson_id INNER JOIN {$p}finance_lesson_snapshots snapshot ON snapshot.id=evaluation.snapshot_id LEFT JOIN {$p}students student ON student.id=lesson.student_id LEFT JOIN {$p}courses course ON course.id=lesson.course_id WHERE lesson.teacher_id=%d ORDER BY evaluation.lesson_id,evaluation.evaluation_sequence",$teacherId));
        if($wpdb->last_error!=='')throw new \RuntimeException('Payability read unavailable');
        $golden=new GoldenHourPayService();
        echo '<table class="widefat striped"><thead><tr><th>Class</th><th>Student / course</th><th>Disposition</th><th>Base pay</th><th>Golden-hour bonus</th><th>Total teacher pay</th><th>Area / window</th><th>Review</th><th>Basis</th><th>Policy</th><th>Override</th><th>Applicability</th></tr></thead><tbody>';
        foreach($rows as $row){
            $url=add_query_arg(array('page'=>'dzn-lesson','id'=>(int)$row->lesson_id),admin_url('admin.php'));
            $classLabel=esc_html((string)($row->lesson_ref?:'Class'));
            if(current_user_can('dzn_manage_lessons'))$classLabel='<a href="'.esc_url($url).'">'.$classLabel.'</a>';
            $disposition=self::disposition((string)$row->disposition);
            $basis=self::basis((string)$row->basis_code);
            $policy=(string)($row->policy_key??'');
            if($policy!=='')$policy.=(int)($row->policy_version??0)>0?' v'.(int)$row->policy_version:'';
            $currency=(string)($row->currency?:'TOM');
            $baseMinor=(int)($row->base_amount_minor??0);
            try {
                $effective=FinanceSnapshotIntegrity::effective((int)$row->lesson_id);
                $baseMinor=(int)$effective['amount_minor'];
                $currency=(string)$effective['currency'];
            } catch (\Throwable) { /* The stored snapshot remains the visible fallback; nothing is repaired. */ }
            $details=$golden->details((int)$row->lesson_id);
            $isPayable=(string)$row->disposition==='payable';
            $bonusMinor=$isPayable&&$details!==null?(int)($details['total_bonus_minor']??0):0;
            $totalMinor=$isPayable?($details===null?$baseMinor:(int)($details['committed_total_minor']??$baseMinor)):0;
            $highest=(int)($details['highest_basis_points']??0);
            $breakdown=(array)($details['window_breakdown']??array());
            $first=(array)($breakdown[0]??array());
            $bonusLabel=$bonusMinor>0
                ? '+'.PricingMoney::format($bonusMinor,$currency).' ('.number_format($highest/100,2).'% on '.(int)($details['eligible_minutes']??0).' minutes)'
                : '—';
            $windowParts=array();
            foreach((array)($details['window_definitions']??array())as$definition){
                $source=(string)($definition['source']??'');
                $label=$source===GoldenHourRule::GLOBAL_IRAN_KEY?'Iran global':$source;
                $windowParts[]=$label.' '.substr((string)($definition['start']??''),0,5).'–'.substr((string)($definition['end']??''),0,5).' '.(string)($definition['timezone']??'');
            }
            $window=$windowParts?implode('; ',$windowParts):'—';
            $review=$details!==null&&($details['review_reason']??null)!==null?'<span class="dzn-badge dzn-badge--gold">'.esc_html((string)$details['review_reason']).'<br>'.esc_html(GoldenHourRule::REVIEW_TEXT).'</span>':'—';
            echo '<tr><td>'.$classLabel.'</td><td>'.esc_html((string)$row->student_name).'<br><small>'.esc_html((string)$row->course_name).'</small></td><td><span class="dzn-badge '.esc_attr($disposition[1]).'">'.esc_html($disposition[0]).'</span></td><td>'.esc_html(PricingMoney::format($baseMinor,$currency)).'</td><td>'.esc_html($bonusLabel).'</td><td>'.esc_html(PricingMoney::format($totalMinor,$currency)).'</td><td>'.esc_html($window).'</td><td>'.$review.'</td><td>'.esc_html($basis).'</td><td>'.esc_html($policy!==''?$policy:'—').'</td><td>'.($row->override_id===null?'—':'<span class="dzn-badge dzn-badge--blue">Override applied</span>').'</td><td>'.esc_html($row->applicable_slot===null?'History':'Current').'</td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="12">No payability evaluations for this Teacher.</td></tr>';
        echo '</tbody></table><p class="description">Base: the snapshotted rate. Golden-hour bonus: percentage × eligible minutes only. Total: the amount the statement authority records. A bonus is committed at the Lesson finance snapshot; later policy changes never rewrite it.</p></div>';
    }
    /** @return array{0:string,1:string} label and badge class */
    private static function disposition(string $value): array {
        return match ($value) {
            'payable' => array('Payable', 'dzn-badge--teal'),
            'non_payable' => array('Not payable', 'dzn-badge--red'),
            'pending' => array('Pending review', 'dzn-badge--gold'),
            'override' => array('Administrator override', 'dzn-badge--blue'),
            'policy' => array('Policy decision', 'dzn-badge--gold'),
            default => array(ucfirst(str_replace('_', ' ', $value)), ''),
        };
    }

    private static function basis(string $value): string {
        return match ($value) {
            'delivered_occurrence' => 'Delivered class',
            'lesson_not_finalised' => 'Class not finalised',
            'delivery_review_required' => 'Delivery review required',
            'presumed_completion_review' => 'Presumed completion needs review',
            'teacher_non_delivery' => 'Teacher did not deliver',
            'academy_obligation' => 'Academy obligation',
            'student_no_show' => 'Student did not attend',
            'interruption' => 'Class interrupted',
            'occurrence_not_attempted' => 'Class did not occur',
            'introductory_non_payable' => 'Introductory policy',
            'administrator_override' => 'Administrator override',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private static function teacherSelector(int $selected,array $options):void{
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>Teacher <select name="teacher_id">';
        foreach($options as $option)echo '<option value="'.(int)$option->id.'"'.selected($selected,(int)$option->id,false).'>'.esc_html((string)$option->label).'</option>';
        echo '</select></label> <button class="button">View payability</button></form>';
    }
}
