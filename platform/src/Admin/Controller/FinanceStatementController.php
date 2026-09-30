<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;

/**
 * Administrator surface for teacher compensation statements (contract §10/§14).
 *
 * View-only: state, version, period, recorded totals and the counted archive exclusions. An issued
 * statement is never a payment or a payout state.
 */
final class FinanceStatementController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-statements';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Compensation statements','Compensation statements',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $teacherId=(int)($wpdb->get_var("SELECT teacher_id FROM {$p}finance_statements ORDER BY id LIMIT 1")?:0);
        echo '<div class="wrap"><h1>Compensation statements</h1>';
        echo '<p>Recorded compensation facts only: no payout, no bank detail, no invoice, no ledger.</p>';
        if($teacherId<1){echo '<p>No statement has been drafted yet.</p></div>';return;}
        $rows=(new TeacherStatementReadService())->statements($teacherId);
        echo '<table class="widefat"><thead><tr><th>Statement</th><th>Version</th><th>Period</th><th>State</th><th>Currency</th><th>Payable</th><th>Lines</th><th>Pending</th><th>Excluded</th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.esc_html((string)$row['statement_id']).'</td><td>'.esc_html((string)$row['statement_version']).'</td><td>'.esc_html((string)$row['period_start_utc'].' → '.(string)$row['period_end_utc']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)$row['currency']).'</td><td>'.esc_html((string)$row['payable_amount_minor']).'</td><td>'.esc_html((string)$row['total_line_count']).'</td><td>'.esc_html((string)$row['pending_line_count']).'</td><td>'.esc_html((string)$row['excluded_archived_count']).'</td></tr>';
        echo '</tbody></table></div>';
    }
}
