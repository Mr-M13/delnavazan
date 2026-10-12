<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Integrations\Notifications\TeacherInvitationPresentation;

/**
 * Bounded legacy invitation-delivery worker. Invitation delivery deliberately remains outside
 * NotificationService: the one-time secret is created only at this transport boundary.
 */
final class TeacherInvitationDeliveryWorker {
    public const HOOK='dzn_teacher_invitation_delivery_dispatch';

    public static function register():void{
        add_filter('cron_schedules',array(__CLASS__,'schedules'));
        add_action(self::HOOK,array(__CLASS__,'run'));
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'five_minutes',self::HOOK);
    }
    public static function schedules(array $schedules):array{
        $schedules['five_minutes']??=array('interval'=>300,'display'=>'Every five minutes');
        return $schedules;
    }
    public static function run():void{
        $environment=wp_get_environment_type();
        if(!in_array($environment,array('staging','production'),true))return;
        $rows=(new PrincipalInvitationRepository())->pendingDeliveryRows(10);
        foreach($rows as $row)self::deliver((int)$row->generation_id);
    }
    /** Explicitly dispatch a just-issued invitation where the live transport is allowed.
     * Returns an honest state: mail acceptance is not recipient delivery. */
    public static function dispatchNow(int $generationId):string{
        if(!in_array(wp_get_environment_type(),array('staging','production'),true))return 'queued';
        return self::deliver($generationId);
    }
    private static function deliver(int $generationId):string{
        $repository=new PrincipalInvitationRepository();
        if(!apply_filters('dzn_teacher_invitation_email_transport_configured',false)){
            try{$repository->failPendingDelivery($generationId,'email_transport_not_configured');}catch(\Throwable){}
            return 'failed';
        }
        $service=new PrincipalInvitationService();
        try { $claimUrl=NotificationActionLinkPolicy::currentTeacherInvitation(); }
        catch (\Throwable) { try{$repository->failPendingDelivery($generationId,'notification_action_link_unavailable');}catch(\Throwable){} return 'failed'; }
        try{
            $payload=$service->prepareDelivery($generationId);
            $copy=TeacherInvitationPresentation::values();
            $subject=TeacherInvitationPresentation::render($copy['subject'],$claimUrl,(string)$payload['secret']);
            $message=TeacherInvitationPresentation::render($copy['body'],$claimUrl,(string)$payload['secret']);
            $sent=wp_mail((string)$payload['recipient'],$subject,$message);
            unset($message,$payload['secret']);
            $repository->finishPreparedDelivery($generationId,$sent?'delivered':'failed',$sent?null:'mail_transport_rejected');
            return $sent?'accepted_by_transport':'failed';
        }catch(\Throwable $e){
            // Preparation is atomic and fail-closed. A prepared secret must never be
            // retried, and no transport context is logged because it can be sensitive.
            try{$repository->failPreparedDelivery($generationId,'mail_transport_exception');}catch(\Throwable){try{$repository->failPendingDelivery($generationId,'delivery_preparation_failed');}catch(\Throwable){}}
            return 'failed';
        }
    }
}
