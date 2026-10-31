<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Narrow server-owned settlement principal for already-admitted provider evidence.
 *
 * A signed webhook has no administrator. The webhook principal may record provider evidence but
 * must not complete a Lesson. This second actor is used only by the scheduled reconciliation worker,
 * holds only the capabilities the existing staged settlement coordinator already requires, and
 * never adjudicates attendance or chooses an outcome. `CanonicalAttendanceSettlementService`,
 * `CanonicalLessonDeliveryService` and `CanonicalLessonAuthorityService` each revalidate the durable
 * case, aggregate, immutable schedule version and lifecycle before mutating anything.
 */
final class CanonicalAttendanceSettlementSystemActor {
    public const USER_OPTION='dzn_attendance_settlement_system_user_id';
    public const CAPABILITIES=array(
        'dzn_ingest_canonical_attendance_evidence',
        'dzn_manage_canonical_lesson_delivery',
        'dzn_manage_canonical_lessons',
    );

    public static function isConfigured():bool{
        try{
            self::userId();
            return true;
        }catch(\Throwable){
            return false;
        }
    }

    public static function run(callable $work):mixed{
        $userId=self::userId();
        $previous=(int)get_current_user_id();
        wp_set_current_user($userId);
        try{
            return $work($userId);
        }finally{
            wp_set_current_user($previous>0?$previous:0);
        }
    }

    private static function userId():int{
        $userId=(int)get_option(self::USER_OPTION,0);
        if($userId<1)throw new \RuntimeException('attendance_settlement_system_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||(int)($user->user_status??0)!==0)throw new \RuntimeException('attendance_settlement_system_actor_invalid');
        foreach(self::CAPABILITIES as$capability)if(!user_can($user,$capability))throw new \RuntimeException('attendance_settlement_system_actor_invalid');
        // No general administration, provider control plane or attendance adjudication.
        if(user_can($user,'manage_options')||user_can($user,'dzn_manage_provider_integrations')||user_can($user,'dzn_manage_canonical_attendance_review'))throw new \RuntimeException('attendance_settlement_system_actor_invalid');
        return $userId;
    }
}
