<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationService;

final class ProviderProjectionWorkerContext {
    public const PRINCIPAL_OPTION='dzn_platform_provider_projection_worker_principal';
    private static bool $active=false;
    public function run(callable $work):mixed{
        if(self::$active)throw new \RuntimeException('provider_worker_context_reentrant');
        $id=self::principalId();if($id<1)throw new \RuntimeException('provider_worker_principal_required');
        $previous=(int)get_current_user_id();self::$active=true;wp_set_current_user($id);
        try{
            if((int)get_current_user_id()!==$id||!current_user_can(ProviderIntegrationService::DISPATCH_CAPABILITY))throw new \RuntimeException('provider_worker_principal_required');
            return $work();
        }finally{wp_set_current_user($previous);self::$active=false;}
    }
    public static function principalId():int{
        $id=(int)get_option(self::PRINCIPAL_OPTION);if($id<1)return 0;
        $user=get_user_by('id',$id);if(!$user||((int)($user->user_status??0))!==0)return 0;
        $caps=(array)($user->allcaps??array());
        if(empty($caps[ProviderIntegrationService::DISPATCH_CAPABILITY]))return 0;
        foreach(array('manage_options',ProviderIntegrationService::MANAGE_CAPABILITY,ProviderIntegrationService::REVOKE_CAPABILITY,ProviderIntegrationService::INGEST_CAPABILITY,ProviderIntegrationService::CONNECT_CAPABILITY) as $forbidden)if(!empty($caps[$forbidden]))return 0;
        return $id;
    }
}
