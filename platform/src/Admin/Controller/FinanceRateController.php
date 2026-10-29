<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\{FinanceTeacherOptionsReadService,TeacherRateReadService};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/**
 * Administrator surface for effective-dated teacher rates (contract §7/§14).
 *
 * View-only: identifiers, scope, interval, state, amount and currency per Teacher — never a raw command
 * digest or an evidence payload.
 */
final class FinanceRateController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-rates';
    public const PARENT_SLUG=Menu::ROOT_SLUG;

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Teacher rates','Teacher rates',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        $options=(new FinanceTeacherOptionsReadService)->options();
        $teacherId=isset($_GET['teacher_id'])&&is_scalar($_GET['teacher_id'])?absint($_GET['teacher_id']):0;
        if(!(new FinanceTeacherOptionsReadService)->isValid($teacherId))$teacherId=(int)($options[0]->id??0);
        echo '<div class="wrap"><h1>Teacher rates</h1>';
        echo '<p>Effective-dated rates only. There is no current-rate column and no retroactive rewrite.</p>';
        self::teacherSelector($teacherId,$options);
        if($teacherId<1){echo '<p>No Teacher is available for rate review.</p></div>';return;}
        $timeline=(new TeacherRateReadService())->timeline($teacherId);
        echo '<table class="widefat striped"><thead><tr><th>Version</th><th>Scope</th><th>Effective from</th><th>Effective until</th><th>Status</th><th>Amount</th></tr></thead><tbody>';
        if(empty($timeline['rates']))echo '<tr><td colspan="6">No effective-dated rate is recorded for this Teacher.</td></tr>';
        foreach((array)$timeline['rates'] as $rate){
            $scope=(string)$rate['scope_kind']==='teacher_course'?'One course':((string)$rate['scope_kind']==='teacher'?'All courses':ucfirst(str_replace('_',' ',(string)$rate['scope_kind'])));
            $amount=PricingMoney::format((int)$rate['amount_minor'],(string)$rate['currency']);
            echo '<tr><td>'.esc_html('v'.(string)$rate['rate_version']).'</td><td>'.esc_html($scope).'</td><td>'.esc_html(self::instant((string)$rate['effective_from'])).'</td><td>'.esc_html(self::instant((string)($rate['effective_until']??''))).'</td><td><span class="dzn-badge' . ((string)$rate['status']==='active'?' dzn-badge--teal':'') . '">'.esc_html(ucfirst((string)$rate['status'])).'</span></td><td>'.esc_html($amount).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function instant(string $utc): string {
        if (trim($utc) === '') return '—';
        $timestamp = strtotime($utc . ' UTC');
        return $timestamp === false ? $utc : wp_date('j M Y, H:i', $timestamp);
    }

    private static function teacherSelector(int $selected,array $options):void{
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>Teacher <select name="teacher_id">';
        foreach($options as $option)echo '<option value="'.(int)$option->id.'"'.selected($selected,(int)$option->id,false).'>'.esc_html((string)$option->label).'</option>';
        echo '</select></label> <button class="button">View rates</button></form>';
    }
}
