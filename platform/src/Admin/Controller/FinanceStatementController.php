<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Read\{FinanceTeacherOptionsReadService,TeacherStatementReadService};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateService;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;

/**
 * Administrator surface for teacher compensation statements and teacher reconciliation, plus a
 * teacher-scoped statement download endpoint.
 *
 * View-only: state, version, period, recorded totals and counted archive exclusions. An issued
 * statement is never a payment or a payout state. Reconciliation never repairs anything.
 */
final class FinanceStatementController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-statements';
    public const PARENT_SLUG=Menu::ROOT_SLUG;

    public static function register():void{
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_dzn_teacher_statement_download',array(__CLASS__,'downloadStatement'));
        add_action('admin_post_nopriv_dzn_teacher_statement_download',array(__CLASS__,'downloadStatement'));
    }
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
                    $formatDifference=static fn(int $minor):string=>$currency!==''?PricingMoney::format($minor,$currency):number_format_i18n($minor);
                    echo '<table class="widefat striped"><thead><tr><th>Class</th><th>Expected</th><th>Observed</th></tr></thead><tbody>';
                    foreach($reconciliation['derived_differences'] as $difference){
                        $label=(string)($difference['lesson_label']??'Class record');
                        $lessonId=(int)($difference['lesson_id']??0);
                        $cell=esc_html($label);
                        if(current_user_can('dzn_manage_lessons')&&$lessonId>0){
                            $lessonUrl=add_query_arg(array('page'=>'dzn-lesson','id'=>$lessonId),admin_url('admin.php'));
                            $cell='<a href="'.esc_url($lessonUrl).'">'.$cell.'</a>';
                        }
                        echo '<tr><td>'.$cell.'</td><td>'.esc_html($formatDifference((int)$difference['expected_amount_minor'])).'</td><td>'.esc_html($formatDifference((int)$difference['observed_amount_minor'])).'</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            }catch(\Throwable){
                echo '<p class="notice notice-error">Statement reconciliation is unavailable for this record. No repair was attempted.</p>';
            }
        }
        echo '</div>';
    }
    /** Teacher-scoped statement download: renders one of the current Teacher's own statements using the
     *  active statement template and serves it as an HTML download. */
    public static function downloadStatement():void{
        // Auth and nonce checks first, before any output or processing.
        if(!is_user_logged_in()){
            wp_die('Forbidden','Unauthenticated',array('response'=>403));
        }
        check_admin_referer('dzn_teacher_statement_download');
        $statementId=absint($_POST['statement_id']??0);
        if($statementId<1){
            wp_die('Statement required','Invalid request',array('response'=>400));
        }
        // Cache-control before any content is produced.
        nocache_headers();
        header('Cache-Control:no-store,private,max-age=0');
        header('Pragma:no-cache');
        // Verify the statement belongs to the current teacher (ownership enforced server-side).
        $statement=null;
        try{
            foreach((new TeacherStatementReadService())->ownStatements() as $row){
                if((int)$row['statement_id']===$statementId){$statement=$row;break;}
            }
        }catch(\Throwable){$statement=null;}
        if($statement===null){
            wp_die('Forbidden','Statement unavailable',array('response'=>403));
        }
        $lines=array();
        try{$lines=(new TeacherStatementReadService())->statementLines($statementId);}catch(\Throwable){$lines=array();}
        $template=(new FinanceSettingsService())->template();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $teacherRow=$wpdb->get_row($wpdb->prepare("SELECT display_name,reference_code FROM {$p}teachers WHERE id=%d",(int)$statement['teacher_id']));
        $statement['teacher_name']=(string)($teacherRow->display_name??'');
        $statement['teacher_reference']=(string)($teacherRow->reference_code??'');
        $html=(new StatementTemplateService())->render($statement,$lines,$template,function_exists('get_bloginfo')?(string)get_bloginfo('name'):'Delnavazan');
        header('Content-Type:text/html; charset=utf-8');
        header('Content-Disposition:attachment; filename="teacher-statement-'.$statementId.'.html"');
        echo $html;
        exit;
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
