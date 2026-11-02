<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\LessonRescheduleService;

/**
 * Capability-protected administrator decisions for pending Teacher reschedule proposals.
 *
 * Confirming uses the same proposal/decision and canonical schedule path as Student acceptance, so
 * the proposal, audit event, notifications and schedule revision stay one transaction. Cancelling
 * only closes the pending proposal before a staff-initiated manual class change.
 */
final class RescheduleReviewController {
    private const CONFIRM='dzn_admin_reschedule_confirm';
    private const CANCEL='dzn_admin_reschedule_cancel';

    public static function register():void{
        add_action('admin_post_'.self::CONFIRM,array(__CLASS__,'confirm'));
        add_action('admin_post_'.self::CANCEL,array(__CLASS__,'cancel'));
    }

    public static function confirm():never{self::handle('confirm');}
    public static function cancel():never{self::handle('cancel');}

    private static function handle(string $operation):never{
        if(!is_user_logged_in())wp_die('Forbidden','Delnavazan',array('response'=>403));
        if(!current_user_can('dzn_manage_lessons')&&!current_user_can('dzn_manage_canonical_lesson_schedules'))wp_die('Forbidden','Delnavazan',array('response'=>403));
        check_admin_referer('dzn_admin_reschedule_'.$operation);
        $proposalUid=sanitize_text_field((string)wp_unslash($_POST['proposal_uid']??''));
        $decisionKey=sanitize_text_field((string)wp_unslash($_POST['decision_key']??''));
        if(strlen($decisionKey)<16)$decisionKey=wp_generate_uuid4();
        $status='saved';
        try{
            $service=new LessonRescheduleService();
            if('confirm'===$operation)$service->confirmAsAdministrator($proposalUid,$decisionKey);
            else $service->cancelAsAdministrator($proposalUid,$decisionKey);
        }catch(\Throwable $e){
            $status='error';
        }
        $url=add_query_arg(array('page'=>'dzn-academy-dashboard','reschedule_status'=>$status),admin_url('admin.php')).'#dzn-pending-reschedules';
        wp_safe_redirect($url);exit;
    }
}
