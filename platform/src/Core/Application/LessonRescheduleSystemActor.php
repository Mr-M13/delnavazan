<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Narrow system actor for committing an already-accepted reschedule proposal.
 *
 * Student acceptance is an authenticated application fact, but a student must never receive the
 * staff scheduling capability. This actor is configured explicitly, must hold only the canonical
 * scheduling capability, and never decides eligibility itself: the canonical scheduling service
 * still revalidates the aggregate, conflicts and policy before it mutates anything.
 */
final class LessonRescheduleSystemActor {
    public const USER_OPTION='dzn_reschedule_system_user_id';
    public static function run(callable $work):mixed{
        $userId=(int)get_option(self::USER_OPTION,0);
        if($userId<1)throw new \RuntimeException('reschedule_system_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||!user_can($user,'dzn_manage_canonical_lesson_schedules'))throw new \RuntimeException('reschedule_system_actor_invalid');
        $previous=get_current_user_id();wp_set_current_user($userId);
        try{return $work($userId);}finally{wp_set_current_user($previous>0?$previous:0);}
    }
}
