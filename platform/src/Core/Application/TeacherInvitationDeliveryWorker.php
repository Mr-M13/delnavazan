<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

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
        $service=new PrincipalInvitationService();
        try{
            $payload=$service->prepareDelivery($generationId);
            $claimUrl=home_url('/teacher-invitation/');
            $subject='دعوت به پنل مدرس دلنوازان';
            $message="برای اتصال حساب مدرس دلنوازان، صفحه زیر را باز کنید و کد یک‌بارمصرف را وارد کنید:\n\n".$claimUrl."\n\nکد دعوت:\n".$payload['secret']."\n\nاین کد را با دیگران به اشتراک نگذارید.";
            $sent=wp_mail((string)$payload['recipient'],$subject,$message);
            unset($message,$payload['secret']);
            (new PrincipalInvitationRepository())->finishPreparedDelivery($generationId,$sent?'delivered':'failed',$sent?null:'mail_transport_rejected');
        }catch(\Throwable $e){
            // Preparation is atomic and fail-closed. Never log exception context: it may be transport-adjacent.
        }
    }
}
