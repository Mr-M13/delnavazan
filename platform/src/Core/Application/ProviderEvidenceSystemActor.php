<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Narrow server-owned actor for authenticated provider evidence.
 *
 * Signed webhooks have no logged-in administrator. This actor is a deliberately bounded integration
 * principal: it may only record durable provider identity mappings and submit provider evidence to
 * the existing Phase-P intake authority. It is never an administrator, never holds a broad provider
 * integration capability, and never decides attendance itself. Every canonical service revalidates
 * the aggregate, identity registry, cutover policy and lifecycle before any consequence.
 */
final class ProviderEvidenceSystemActor {
    public const USER_OPTION='dzn_provider_evidence_system_user_id';
    public const CAPABILITIES=array(
        'dzn_ingest_canonical_attendance_evidence',
        'dzn_manage_canonical_attendance_identity',
    );

    /** Whether a structurally valid, narrowly scoped actor is configured. */
    public static function isConfigured():bool{
        try{
            self::userId();
            return true;
        }catch(\Throwable){
            return false;
        }
    }

    /** Run work under the integration principal and restore the previous WordPress user. */
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
        if($userId<1)throw new \RuntimeException('provider_evidence_system_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||(int)($user->user_status??0)!==0)throw new \RuntimeException('provider_evidence_system_actor_invalid');
        foreach(self::CAPABILITIES as$capability)if(!user_can($user,$capability))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        // The integration boundary is not a general administrator and may not operate the wider
        // provider integration control plane. A misconfigured broad account fails closed.
        if(user_can($user,'manage_options')||user_can($user,'dzn_manage_provider_integrations'))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        return $userId;
    }
}
