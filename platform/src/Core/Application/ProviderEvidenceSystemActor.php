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
    public const USER_MARKER='dzn_provider_evidence_system_actor';
    public const ROLE='dzn_provider_evidence';
    public const LOGIN='dzn_provider_evidence';
    public const CAPABILITIES=array(
        'read',
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

    /**
     * Explicitly provision or repair the dedicated integration identity.
     *
     * This is intentionally never called by a webhook, cron worker, or ordinary request.  An
     * authenticated administrator must invoke it through an audited operator command.
     */
    public static function provision():int{
        if(!function_exists('current_user_can')||!current_user_can('manage_options'))throw new \RuntimeException('provider_evidence_system_actor_unauthorized');
        $role=get_role(self::ROLE);
        if(!$role){
            $role=add_role(self::ROLE,'Delnavazan Provider Evidence',array_fill_keys(self::CAPABILITIES,true));
            if(!$role)throw new \RuntimeException('provider_evidence_system_actor_role_unavailable');
        }
        foreach((array)($role->capabilities??array()) as$capability=>$granted)if($granted&&!in_array((string)$capability,self::CAPABILITIES,true))$role->remove_cap((string)$capability);
        foreach(self::CAPABILITIES as$capability)if(!$role->has_cap($capability))$role->add_cap($capability);
        $user=get_user_by('login',self::LOGIN);
        if($user instanceof \WP_User){
            $configured=(int)get_option(self::USER_OPTION,0);
            if(get_user_meta((int)$user->ID,self::USER_MARKER,true)!=='1'&&$configured!==(int)$user->ID)throw new \RuntimeException('provider_evidence_system_actor_conflict');
            $user->set_role(self::ROLE);
            foreach((array)($user->caps??array()) as$capability=>$granted)if($granted&&(string)$capability!==self::ROLE)$user->remove_cap((string)$capability);
            $userId=(int)$user->ID;
        }else{
            $created=wp_insert_user(array('user_login'=>self::LOGIN,'user_pass'=>wp_generate_password(64,true,true),'user_email'=>self::LOGIN.'@example.invalid','display_name'=>'Delnavazan Provider Evidence','role'=>self::ROLE));
            if(is_wp_error($created)||(int)$created<1)throw new \RuntimeException('provider_evidence_system_actor_user_unavailable');
            $userId=(int)$created;
        }
        update_user_meta($userId,self::USER_MARKER,'1');
        update_option(self::USER_OPTION,$userId,false);
        return self::userId();
    }

    private static function userId():int{
        $userId=(int)get_option(self::USER_OPTION,0);
        if($userId<1)throw new \RuntimeException('provider_evidence_system_actor_unconfigured');
        $user=get_user_by('id',$userId);
        if(!$user instanceof \WP_User||(int)($user->user_status??0)!==0)throw new \RuntimeException('provider_evidence_system_actor_invalid');
        if(get_user_meta($userId,self::USER_MARKER,true)!=='1')throw new \RuntimeException('provider_evidence_system_actor_invalid');
        $roles=array_values(array_unique(array_map('strval',(array)($user->roles??array()))));
        if($roles!==array(self::ROLE))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        foreach((array)($user->caps??array()) as$capability=>$granted)if($granted&&(string)$capability!==self::ROLE)throw new \RuntimeException('provider_evidence_system_actor_invalid');
        foreach(self::CAPABILITIES as$capability)if(!user_can($user,$capability))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        // The integration boundary is not a general administrator and may not operate the wider
        // provider integration control plane. A misconfigured broad account fails closed.
        foreach((array)($user->allcaps??array()) as$capability=>$granted)if($granted&&!in_array((string)$capability,self::CAPABILITIES,true)&&!in_array((string)$capability,array(self::ROLE),true))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        if(user_can($user,'manage_options')||user_can($user,'dzn_manage_provider_integrations'))throw new \RuntimeException('provider_evidence_system_actor_invalid');
        return $userId;
    }
}
