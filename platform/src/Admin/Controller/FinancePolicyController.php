<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,FinanceRule};

/**
 * Administrator surface for the Phase 2A.2-U finance policy registry (contract §14).
 *
 * View-only: it lists the four declared keys, their recorded versions and their recorded statuses — never
 * a command digest, an evidence payload or a caller key. Every read re-checks
 * `dzn_view_finance_authority` server-side.
 */
final class FinancePolicyController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-policies';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Finance policies','Finance policies',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        $service=new FinancePolicyService();
        echo '<div class="wrap"><h1>Finance policies</h1>';
        echo '<p>Recorded finance authority only. Structural finance invariants are not configurable here.</p>';
        foreach(FinanceRule::POLICY_KEYS as $key){
            echo '<h2>'.esc_html($key).'</h2><table class="widefat"><thead><tr><th>Version</th><th>Value</th><th>Effective from</th><th>Status</th></tr></thead><tbody>';
            $rows=$service->history($key);
            foreach($rows as $row)echo '<tr><td>'.esc_html((string)$row['policy_version']).'</td><td>'.esc_html((string)$row['policy_value']).'</td><td>'.esc_html((string)$row['effective_from']).'</td><td>'.esc_html((string)$row['status']).'</td></tr>';
            if($rows===array())echo '<tr><td colspan="4">No version recorded (unset).</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
