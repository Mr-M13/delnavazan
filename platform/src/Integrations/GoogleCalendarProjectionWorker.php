<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

final class GoogleCalendarProjectionWorker {
    public const HOOK='dzn_google_calendar_projection_dispatch';
    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{$schedules['dzn_five_minutes']=array('interval'=>300,'display'=>'Delnavazan every five minutes');return $schedules;});
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }
    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'dzn_five_minutes',self::HOOK);
    }
    public static function run():void{
        if(!GoogleOAuthController::configured()||ProviderProjectionWorkerContext::principalId()<1)return;
        (new ProviderProjectionWorkerContext())->run(static function():void{
            $repository=new ProviderIntegrationRepository();$service=new GoogleCalendarProjectionService();
            foreach($repository->pendingCalendarMappings(10) as $mapping){
                try{$service->dispatch((int)$mapping->id,'google-calendar-worker:'.(int)$mapping->id.':'.(int)$mapping->mapping_version);}
                catch(\Throwable $e){do_action('dzn_google_calendar_projection_dispatch_failed',(int)$mapping->id,get_class($e));}
            }
        });
    }
}
