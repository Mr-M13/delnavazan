<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\FinanceReconciliationReadService;

/**
 * Administrator surface for reconciliation runs, findings and Finance exceptions (contract §11/§14).
 *
 * View-only diagnostics: counts, states and controlled reason codes only — never an amount that is not
 * already recorded on the row it is read from.
 */
final class FinanceReconciliationController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-reconciliation';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Finance reconciliation','Finance reconciliation',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        echo '<div class="wrap"><h1>Finance reconciliation</h1>';
        echo '<p>Read-only, exact-integer reconciliation. A run repairs nothing.</p>';
        $exceptions=(array)$wpdb->get_results("SELECT reason_code,severity,state,COUNT(*) AS total FROM {$p}finance_exceptions GROUP BY reason_code,severity,state ORDER BY reason_code");
        echo '<h2>Open exceptions</h2><table class="widefat"><thead><tr><th>Reason</th><th>Severity</th><th>State</th><th>Count</th></tr></thead><tbody>';
        foreach($exceptions as $row)echo '<tr><td>'.esc_html((string)$row->reason_code).'</td><td>'.esc_html((string)$row->severity).'</td><td>'.esc_html((string)$row->state).'</td><td>'.esc_html((string)$row->total).'</td></tr>';
        echo '</tbody></table>';
        $runId=(int)($wpdb->get_var("SELECT id FROM {$p}finance_reconciliation_runs ORDER BY id DESC LIMIT 1")?:0);
        if($runId>0){
            $run=(new FinanceReconciliationReadService())->run($runId);
            echo '<h2>Latest run</h2><p>State: '.esc_html((string)$run['state']).' — mismatches: '.esc_html((string)$run['mismatch_count']).'</p>';
            echo '<table class="widefat"><thead><tr><th>Code</th><th>Severity</th><th>Lesson</th><th>Expected</th><th>Observed</th></tr></thead><tbody>';
            foreach((array)$run['findings'] as $finding)echo '<tr><td>'.esc_html((string)$finding['finding_code']).'</td><td>'.esc_html((string)$finding['severity']).'</td><td>'.esc_html((string)($finding['lesson_id']??'')).'</td><td>'.esc_html((string)($finding['expected_amount_minor']??'')).'</td><td>'.esc_html((string)($finding['observed_amount_minor']??'')).'</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
