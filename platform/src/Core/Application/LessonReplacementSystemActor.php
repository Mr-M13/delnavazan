<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Narrow system actor for issuing an accepted attendance replacement.
 *
 * The Student's acceptance is an authenticated application fact, but a Student never receives the
 * staff capabilities that issue canonical Lessons or schedules. This actor is configured
 * explicitly, holds only the canonical Lesson and schedule capabilities, and never decides
 * eligibility itself: both canonical services revalidate the aggregate, entitlement, conflicts
 * and policy before they mutate anything.
 */
final class LessonReplacementSystemActor {
    public const USER_OPTION='dzn_replacement_system_user_id';
    public static function run(callable $work):mixed{
        $userId=(int)get_option(self::USER_OPTION,0);
        if($userId<1)throw new \RuntimeException('replacement_system_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||(int)($user->user_status??0)!==0)throw new \RuntimeException('replacement_system_actor_invalid');
        if(!user_can($user,'dzn_manage_canonical_lessons')||!user_can($user,'dzn_manage_canonical_lesson_schedules'))throw new \RuntimeException('replacement_system_actor_invalid');
        // Issuance is not a general administrator and may not act as a provider integration.
        if(user_can($user,'manage_options')||user_can($user,'dzn_manage_provider_integrations'))throw new \RuntimeException('replacement_system_actor_invalid');
        $previous=(int)get_current_user_id();wp_set_current_user($userId);
        try{return $work($userId);}finally{wp_set_current_user($previous>0?$previous:0);}
    }
}
