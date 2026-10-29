<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\{FinanceTeacherOptionsReadService,TeacherStatementReadService};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/**
 * Administrator surface for teacher compensation statements and teacher reconciliation.
 *
 * View-only: state, version, period, recorded totals and counted archive exclusions. An issued
 * statement is never a payment or a payout state. Reconciliation never repairs anything.
 */
final class FinanceStatementController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-statements';
    public const PARENT_SLUG=Menu::ROOT_SLUG;

    public static function register():void{add_action('admin_menu',array(__CLASS__,'menu'));}
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Compensation statements','Compensation statements',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        $options=(new FinanceTeacherOptionsReadService)->options();
        $teacherId=isset($_GET['teacher_id'])&&is_scalar($_GET['teacher_id'])?absint($_GET['teacher_id']):0;
        if(!(new FinanceTeacherOptionsReadService)->isValid($teacherId))$teacherId=(int)($options[0]->id??0);
        echo '<div class="wrap"><h1>Compensation statements</h1>';
        echo '<p>Recorded compensation facts only: no payout, no bank detail, no invoice, no ledger. Reconciliation is read-only and repairs nothing.</p>';
        self::teacherSelector($teacherId,$options);
        if($teacherId<1){echo '<p>No Teacher is available for statement review.</p></div>';return;}
        $service=new TeacherStatementReadService();
        $rows=$service->statements($teacherId);
        if(!$rows){echo '<p>No statement has been drafted for this Teacher.</p></div>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Statement</th><th>Period</th><th>State</th><th>Payable</th><th>Lines</th><th>Pending</th><th>Excluded</th></tr></thead><tbody>';
        foreach($rows as $row){
            $url=add_query_arg(array('page'=>self::MENU_SLUG,'teacher_id'=>$teacherId,'statement_id'=>(int)$row['statement_id']),admin_url('admin.php'));
            $period=(string)($row['period_label']??'');
            if($period==='')$period=self::shortDate((string)$row['period_start_utc']).' → '.self::shortDate((string)$row['period_end_utc']);
            $state=ucfirst(str_replace('_',' ',(string)$row['state']));
            $payable=PricingMoney::format((int)$row['payable_amount_minor'],(string)$row['currency']);
            echo '<tr><td><a href="'.esc_url($url).'">Statement v'.esc_html((string)$row['statement_version']).'</a></td><td>'.esc_html($period).'</td><td><span class="dzn-badge' . ((string)$row['state']==='issued'?' dzn-badge--teal':' dzn-badge--gold') . '">'.esc_html($state).'</span></td><td>'.esc_html($payable).'</td><td>'.esc_html((string)$row['total_line_count']).'</td><td>'.esc_html((string)$row['pending_line_count']).'</td><td>'.esc_html((string)$row['excluded_archived_count']).'</td></tr>';
        }
        echo '</tbody></table>';
        $statementId=isset($_GET['statement_id'])&&is_scalar($_GET['statement_id'])?absint($_GET['statement_id']):(int)$rows[0]['statement_id'];
        $known=false;foreach($rows as $row)if((int)$row['statement_id']===$statementId){$known=true;break;}
        if($known){
            try{
                $reconciliation=$service->statementReconciliation($statementId);
                $currency='';
                foreach($rows as $row)if((int)$row['statement_id']===$statementId){$currency=(string)$row['currency'];break;}
                echo '<h2>Teacher reconciliation</h2>';
                echo '<p><strong>'.($reconciliation['reconciled']?'Reconciled':'Differences found').'</strong> · recorded payable '.esc_html(PricingMoney::format((int)$reconciliation['recorded_totals']['payable_amount_minor'],$currency)).'</p>';
                if($reconciliation['derived_differences']){
                    echo '<table class="widefat"><thead><tr><th>Lesson</th><th>Expected</th><th>Observed</th></tr></thead><tbody>';
                    foreach($reconciliation['derived_differences'] as $difference)echo '<tr><td>'.esc_html((string)$difference['lesson_id']).'</td><td>'.esc_html((string)$difference['expected_amount_minor']).'</td><td>'.esc_html((string)$difference['observed_amount_minor']).'</td></tr>';
                    echo '</tbody></table>';
                }
            }catch(\Throwable){
                echo '<p class="notice notice-error">Statement reconciliation is unavailable for this record. No repair was attempted.</p>';
            }
        }
        echo '</div>';
    }
    private static function shortDate(string $utc): string {
        $timestamp = trim($utc) === '' ? false : strtotime($utc . ' UTC');
        return $timestamp === false ? $utc : wp_date('j M Y', $timestamp);
    }

    private static function teacherSelector(int $selected,array $options):void{
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::MENU_SLUG).'"><label>Teacher <select name="teacher_id">';
        foreach($options as $option)echo '<option value="'.(int)$option->id.'"'.selected($selected,(int)$option->id,false).'>'.esc_html((string)$option->label).'</option>';
        echo '</select></label> <button class="button">View statements</button></form>';
    }
}
