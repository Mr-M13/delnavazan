<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\FinanceReconciliationService;
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceReconciliationReadService;

/**
 * Administrator surface for reconciliation runs, findings and Finance exceptions (contract §11/§14).
 *
 * Runs and findings stay view-only. An **open** Finance exception may be resolved by an operator
 * holding the statement-management authority, with a short note; the resolution goes through
 * FinanceReconciliationService::resolveException, so it keeps the command key, the lock, the
 * compare-and-swap and the audit row. A run still repairs nothing. See OPS-24.
 */
final class FinanceReconciliationController {
    public const CAPABILITY='dzn_view_finance_authority';
    public const MENU_SLUG='dzn-finance-reconciliation';
    public const PARENT_SLUG=Menu::ROOT_SLUG;
    public const RESOLVE_ACTION='dzn_finance_exception_resolve';
    private const NOTICE_KEY='dzn_finance_exception_notice_';

    public static function register():void{
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_'.self::RESOLVE_ACTION,array(__CLASS__,'handleResolve'));
    }
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Finance reconciliation','Finance reconciliation',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }

    public static function handleResolve():void{
        if(!current_user_can('dzn_manage_finance_statements'))wp_die('Forbidden',403);
        check_admin_referer(self::RESOLVE_ACTION);
        $exceptionId=absint($_POST['exception_id']??0);
        $note=trim(sanitize_textarea_field((string)wp_unslash($_POST['resolution_note']??'')));
        $commandKey=sanitize_text_field((string)wp_unslash($_POST['command_key']??''));
        if($commandKey==='')$commandKey='finance-exception-'.wp_generate_uuid4();
        try{
            if($exceptionId<1)throw new \InvalidArgumentException('finance_exception_required');
            (new FinanceReconciliationService())->resolveException($exceptionId,array('resolution_note'=>$note),$commandKey);
            $notice=array('ok'=>true,'message'=>'Exception resolved and audited. Nothing was repaired automatically.');
        }catch(\Throwable $error){
            error_log('[Delnavazan finance] exception resolution refused: '.get_class($error).' '.$error->getMessage());
            $notice=array('ok'=>false,'message'=>self::message($error));
        }
        set_transient(self::NOTICE_KEY.get_current_user_id(),$notice,120);
        wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG),admin_url('admin.php')));
        exit;
    }

    private static function message(\Throwable $error):string{
        return match((string)$error->getMessage()){
            'finance_exception_required'=>'Choose the exception to resolve.',
            'finance_parent_not_live'=>'This exception changed while you were working. Reload and review it.',
            'finance_vocabulary_member_not_allowed'=>'Record a short note that explains the resolution.',
            'Unauthorized'=>'You do not have the statement-management authority.',
            default=>'The exception could not be resolved; nothing was changed.',
        };
    }

    private static function notice():?array{
        $key=self::NOTICE_KEY.get_current_user_id();
        $notice=get_transient($key);
        if(!is_array($notice))return null;
        delete_transient($key);
        return $notice;
    }

    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        echo '<div class="wrap"><h1>Finance reconciliation</h1>';
        $notice=self::notice();
        if(is_array($notice))echo '<div class="notice '.($notice['ok']?'notice-success':'notice-error').' inline is-dismissible"><p>'.esc_html((string)$notice['message']).'</p></div>';
        echo '<p>Runs and findings are read-only and repair nothing. An open exception can be resolved with a note; the resolution is audited and changes no amount.</p>';
        $exceptions=(array)$wpdb->get_results("SELECT reason_code,severity,state,COUNT(*) AS total FROM {$p}finance_exceptions GROUP BY reason_code,severity,state ORDER BY reason_code");
        echo '<h2>Exceptions by reason</h2><table class="widefat"><thead><tr><th>Reason</th><th>Severity</th><th>State</th><th>Count</th></tr></thead><tbody>';
        foreach($exceptions as $row)echo '<tr><td>'.esc_html((string)$row->reason_code).'</td><td>'.esc_html((string)$row->severity).'</td><td>'.esc_html((string)$row->state).'</td><td>'.esc_html((string)$row->total).'</td></tr>';
        echo '</tbody></table>';

        $canResolve=current_user_can('dzn_manage_finance_statements');
        $open=(array)$wpdb->get_results("SELECT id,reason_code,severity,teacher_id,lesson_id,statement_id,created_at FROM {$p}finance_exceptions WHERE state='open' ORDER BY id DESC LIMIT 50");
        echo '<h2>Open exceptions</h2>';
        if($open===array()){echo '<p>No open Finance exception.</p>';}
        else{
            echo '<table class="widefat"><thead><tr><th>#</th><th>Reason</th><th>Severity</th><th>Teacher</th><th>Lesson</th><th>Statement</th><th>Recorded</th>'.($canResolve?'<th>Resolve</th>':'').'</tr></thead><tbody>';
            foreach($open as $row){
                echo '<tr><td>'.esc_html((string)$row->id).'</td><td>'.esc_html((string)$row->reason_code).'</td><td>'.esc_html((string)$row->severity).'</td>'
                    .'<td>'.esc_html((string)($row->teacher_id??'—')).'</td><td>'.esc_html((string)($row->lesson_id??'—')).'</td><td>'.esc_html((string)($row->statement_id??'—')).'</td><td>'.esc_html((string)$row->created_at).'</td>';
                if($canResolve){
                    echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
                        .'<input type="hidden" name="action" value="'.esc_attr(self::RESOLVE_ACTION).'">'
                        .'<input type="hidden" name="exception_id" value="'.esc_attr((string)$row->id).'">'
                        .'<input type="hidden" name="command_key" value="'.esc_attr('finance-exception-'.$row->id.'-'.wp_generate_uuid4()).'">'
                        .wp_nonce_field(self::RESOLVE_ACTION,'_wpnonce',true,false)
                        .'<label>Note<input type="text" name="resolution_note" maxlength="190" required></label> '
                        .'<button class="button button-small">Resolve</button></form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            if(!$canResolve)echo '<p class="description">Resolving an exception needs the statement-management authority.</p>';
        }

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
