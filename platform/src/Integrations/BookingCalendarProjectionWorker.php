<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;

/**
 * Durable retry for accepted introductory bookings.
 *
 * The central organizer flow does not depend on the retired Teacher Google grant or its least-
 * privilege projection principal. The legacy teacher-calendar path still runs inside that principal
 * boundary so its dispatch capability cannot be bypassed.
 */
final class BookingCalendarProjectionWorker {
    public const HOOK='dzn_booking_calendar_projection_dispatch';

    public static function register():void{
        add_filter('cron_schedules',static function(array $s):array{$s['dzn_two_minutes']??=array('interval'=>120,'display'=>'Delnavazan every two minutes');return $s;});
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'dzn_two_minutes',self::HOOK);
    }

    public static function run():void{
        $central=class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService')
            &&\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService::configured();
        if(!$central&&(!GoogleOAuthController::configured()||ProviderProjectionWorkerContext::principalId()<1))return;
        $work=static function():void{
            $repository=new BookingTeacherResponseRepository();
            $service=new BookingCalendarProjectionService($repository);
            foreach($repository->retryableConfirmations(10)as$row){
                try{$service->dispatch((int)$row->id);}
                catch(\Throwable $error){do_action('dzn_booking_calendar_projection_failed',(int)$row->id,get_class($error));}
            }
        };
        if($central){$work();return;}
        (new ProviderProjectionWorkerContext())->run($work);
    }
}
