<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Administrator surface for Lesson payability (contract §9/§14).
 *
 * View-only: per-Lesson disposition, basis, policy version and override presence. A `pending` disposition
 * is shown as a blocker; nothing on this screen mutates a Finance fact.
 */
final class FinancePayabilityController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-payability';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Lesson payability','Lesson payability',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results("SELECT evaluation.lesson_id,evaluation.disposition,evaluation.basis_code,evaluation.policy_key,evaluation.policy_version,evaluation.override_id,evaluation.applicable_slot FROM {$p}finance_payability_evaluations evaluation ORDER BY evaluation.lesson_id,evaluation.evaluation_sequence");
        echo '<div class="wrap"><h1>Lesson payability</h1>';
        echo '<p>Derived payability only. A pending disposition blocks statement issuance.</p>';
        echo '<table class="widefat"><thead><tr><th>Lesson</th><th>Disposition</th><th>Basis</th><th>Policy</th><th>Override</th><th>Applicable</th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.esc_html((string)$row->lesson_id).'</td><td>'.esc_html((string)$row->disposition).'</td><td>'.esc_html((string)$row->basis_code).'</td><td>'.esc_html((string)($row->policy_key??'')).'</td><td>'.esc_html((string)($row->override_id??'')).'</td><td>'.esc_html($row->applicable_slot===null?'history':'effective').'</td></tr>';
        echo '</tbody></table></div>';
    }
}
