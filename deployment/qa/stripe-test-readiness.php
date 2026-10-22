<?php
/** Run with authenticated wp eval-file; observes metadata only, never calls the vault or provider. */
use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionSupport as Support;
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI required');
$role=get_role(Support::WORKER_ROLE);
$principal=(int)get_option(Support::WORKER_PRINCIPAL_OPTION,0);
$user=$principal>0?get_user_by('id',$principal):null;
$reasons=[];
if($principal<1)$reasons[]='principal_unset';
elseif(!$user)$reasons[]='user_missing';
else{
    if(isset($user->user_status)&&(int)$user->user_status!==0)$reasons[]='user_inactive';
    if(array_values(array_unique(array_map('strval',(array)$user->roles)))!==[Support::WORKER_ROLE])$reasons[]='role_mismatch';
    foreach((array)$user->caps as $cap=>$grant)if($grant&&$cap!==Support::WORKER_ROLE){$reasons[]='direct_grant';break;}
    foreach(Support::WORKER_CAPABILITIES as $cap)if(empty($user->allcaps[$cap])){$reasons[]='required_capability_missing';break;}
    foreach((array)$user->allcaps as $cap=>$grant)if($grant&&!in_array($cap,Support::WORKER_ALLOWED_CAPABILITIES,true)){$reasons[]='excess_capability';break;}
}
$expected=array_fill_keys(array_merge(['read'],Support::WORKER_CAPABILITIES),true);
$grants=array_filter((array)($role->capabilities??[]));ksort($grants);ksort($expected);
echo wp_json_encode([
    'build'=>defined('DZN_PLATFORM_BUILD_ID')?DZN_PLATFORM_BUILD_ID:null,
    'schema'=>get_option('dzn_platform_schema_version'),
    'declared_schema'=>defined('DZN_PLATFORM_SCHEMA_VERSION')?DZN_PLATFORM_SCHEMA_VERSION:null,
    'payment_capability_marker'=>get_option('dzn_platform_capability_version_2a2t'),
    'worker_role_present'=>$role!==null,
    'worker_role_exact'=>$grants===$expected,
    'principal_configured'=>$principal>0,
    'worker_recognised'=>Support::workerPrincipalId()>0,
    'worker_blockers'=>$reasons,
    'live_execution_closed'=>!\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionRule::liveExecutionAuthorised('stripe'),
],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
