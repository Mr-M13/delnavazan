<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\TeacherRateReadService;

/**
 * Administrator surface for effective-dated teacher rates (contract §7/§14).
 *
 * View-only: identifiers, scope, interval, state, amount and currency per Teacher — never a raw command
 * digest or an evidence payload.
 */
final class FinanceRateController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-rates';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Teacher rates','Teacher rates',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $service=new TeacherRateReadService();
        $teacherId=(int)($wpdb->get_var("SELECT teacher_id FROM {$p}finance_teacher_rates ORDER BY id LIMIT 1")?:0);
        echo '<div class="wrap"><h1>Teacher rates</h1>';
        echo '<p>Effective-dated rates only. There is no current-rate column and no retroactive rewrite.</p>';
        if($teacherId<1){echo '<p>No rate has been recorded yet.</p></div>';return;}
        $timeline=$service->timeline($teacherId);
        echo '<h2>Teacher '.esc_html((string)$teacherId).'</h2><table class="widefat"><thead><tr><th>Rate</th><th>Version</th><th>Scope</th><th>Effective from</th><th>Effective until</th><th>Status</th><th>Amount</th><th>Currency</th></tr></thead><tbody>';
        foreach((array)$timeline['rates'] as $rate)echo '<tr><td>'.esc_html((string)$rate['rate_id']).'</td><td>'.esc_html((string)$rate['rate_version']).'</td><td>'.esc_html((string)$rate['scope_kind']).'</td><td>'.esc_html((string)$rate['effective_from']).'</td><td>'.esc_html((string)($rate['effective_until']??'')).'</td><td>'.esc_html((string)$rate['status']).'</td><td>'.esc_html((string)$rate['amount_minor']).'</td><td>'.esc_html((string)$rate['currency']).'</td></tr>';
        echo '</tbody></table></div>';
    }
}
