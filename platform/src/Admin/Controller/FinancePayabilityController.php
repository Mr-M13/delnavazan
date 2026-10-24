<?php
namespace Delnavazan\Platform\Admin\Controller;

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
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT evaluation.lesson_id,evaluation.disposition,evaluation.basis_code,evaluation.policy_key,evaluation.policy_version,evaluation.override_id,evaluation.applicable_slot FROM {$p}finance_payability_evaluations evaluation INNER JOIN {$p}lessons lesson ON lesson.id=evaluation.lesson_id WHERE lesson.teacher_id=%d ORDER BY evaluation.lesson_id,evaluation.evaluation_sequence",$teacherId));
        if($wpdb->last_error!=='')throw new \RuntimeException('Payability read unavailable');
        echo '<table class="widefat"><thead><tr><th>Lesson</th><th>Disposition</th><th>Basis</th><th>Policy</th><th>Override</th><th>Applicable</th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.esc_html((string)$row->lesson_id).'</td><td>'.esc_html((string)$row->disposition).'</td><td>'.esc_html((string)$row->basis_code).'</td><td>'.esc_html((string)($row->policy_key??'')).'</td><td>'.esc_html((string)($row->override_id??'')).'</td><td>'.esc_html($row->applicable_slot===null?'history':'effective').'</td></tr>';
        if(!$rows)echo '<tr><td colspan="6">No payability evaluations for this Teacher.</td></tr>';
        echo '</tbody></table></div>';
    }
    private static function teacherSelector(int $selected,array $options):void{
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>Teacher <select name="teacher_id">';
        foreach($options as $option)echo '<option value="'.(int)$option->id.'"'.selected($selected,(int)$option->id,false).'>'.esc_html((string)$option->label).'</option>';
        echo '</select></label> <button class="button">View payability</button></form>';
    }
}
