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
        add_action(self::HOOK,array(__CLASS__,'run'));
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'five_minutes',self::HOOK);
        add_filter('cron_schedules',array(__CLASS__,'schedules'));
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
    private static function deliver(int $generationId):void{
        $repository=new PrincipalInvitationRepository();
        if(!apply_filters('dzn_teacher_invitation_email_transport_configured',false)){
            try{$repository->failPendingDelivery($generationId,'email_transport_not_configured');}catch(\Throwable){}
            return;
        }
        $service=new PrincipalInvitationService();
        try { $claimUrl=NotificationActionLinkPolicy::currentTeacherInvitation(); }
        catch (\Throwable) { try{$repository->failPendingDelivery($generationId,'notification_action_link_unavailable');}catch(\Throwable){} return; }
        try{
            $payload=$service->prepareDelivery($generationId);
            $copy=TeacherInvitationPresentation::values();
            $subject=TeacherInvitationPresentation::render($copy['subject'],$claimUrl,(string)$payload['secret']);
            $message=TeacherInvitationPresentation::render($copy['body'],$claimUrl,(string)$payload['secret']);
            $sent=wp_mail((string)$payload['recipient'],$subject,$message);
            unset($message,$payload['secret']);
            $repository->finishPreparedDelivery($generationId,$sent?'delivered':'failed',$sent?null:'mail_transport_rejected');
        }catch(\Throwable $e){
            // Preparation is atomic and fail-closed. Never log exception context: it may be transport-adjacent.
        }
    }
}
