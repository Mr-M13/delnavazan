<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationService;

/** Explicit integration actor for the recoverable calendar hand-off after an accepted reschedule. */
final class LessonRescheduleCalendarActor {
    public const USER_OPTION='dzn_reschedule_calendar_system_user_id';
    public static function run(callable $work):mixed{
        $userId=(int)get_option(self::USER_OPTION,0);
        if($userId<1)throw new \RuntimeException('reschedule_calendar_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||!user_can($user,ProviderIntegrationService::MANAGE_CAPABILITY))throw new \RuntimeException('reschedule_calendar_actor_invalid');
        $previous=(int)get_current_user_id();wp_set_current_user($userId);
        try{return $work($userId);}finally{wp_set_current_user($previous>0?$previous:0);}
    }
}
