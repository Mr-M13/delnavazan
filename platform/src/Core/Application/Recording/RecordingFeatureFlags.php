<?php
namespace Delnavazan\Platform\Core\Application\Recording;

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

/**
 * Server-only recording feature gates.
 *
 * Production capture stays hard-disabled in this build. A disposable local/development/staging
 * environment may enable the staging cohort with a PHP constant and an explicit lesson cohort
 * option; no browser input, role or administrator checkbox can open this gate. Research export is
 * a separate hard-disabled decision and is never implied by recording consent.
 */
final class RecordingFeatureFlags {
    public const STAGING_CONSTANT='DZN_RECORDING_CAPTURE_STAGING_ENABLED';
    public const STAGING_COHORT_OPTION='dzn_recording_staging_cohort';
    public const SERVICE_HEALTH_OPTION='dzn_recording_service_health';
    public const LEGAL_HOLD_OPTION_PREFIX='dzn_recording_legal_hold_';
    public const EMERGENCY_STOP_OPTION='dzn_recording_emergency_stop';
    public const EMERGENCY_STOP_CONSTANT='DZN_RECORDING_EMERGENCY_STOP';
    public const PRODUCTION_APPROVAL_CONSTANT='DZN_RECORDING_CAPTURE_APPROVED';
    public const GLOBAL_CAPTURE_OPTION='dzn_recording_global_capture';

    public static function captureEnabledForLesson(int $lessonId):bool{
        if(self::emergencyStop()['engaged']===true)return false;
        if(!self::globalCaptureEnabled())return false;
        if($lessonId<1)return false;
        $environment=function_exists('wp_get_environment_type')?wp_get_environment_type():'production';
        // Production activation is a separate, explicit owner decision. The build kill-switch
        // (CAPTURE_ENABLED=false) still governs the staging path and is never flipped by config.
        $productionApproved=$environment==='production'
            &&defined(self::PRODUCTION_APPROVAL_CONSTANT)
            &&constant(self::PRODUCTION_APPROVAL_CONSTANT)===true;
        $stagingApproved=RecordingDecisionAuthorityService::CAPTURE_ENABLED===false
            &&in_array($environment,array('local','development','staging'),true)
            &&defined(self::STAGING_CONSTANT)
            &&constant(self::STAGING_CONSTANT)===true;
        if(!$productionApproved&&!$stagingApproved)return false;
        $cohort=function_exists('get_option')?get_option(self::STAGING_COHORT_OPTION,array()):array();
        if(is_string($cohort))$cohort=array_map('trim',explode(',',$cohort));
        if(!is_array($cohort)||$cohort===array())return false;
        foreach($cohort as $entry){
            if($stagingApproved&&(string)$entry==='all-staging')return true;
            if($productionApproved&&(string)$entry==='all-approved')return true;
            if(ctype_digit((string)$entry)&&(int)$entry===$lessonId)return true;
        }
        return false;
    }

    /**
     * Normal administrator switch. It is separate from the emergency stop: disabling it queues a
     * stop for active work and refuses new capture without claiming an incident.
     */
    public static function globalCaptureEnabled():bool{
        $stored=function_exists('get_option')?get_option(self::GLOBAL_CAPTURE_OPTION,null):null;
        if($stored===null||$stored===''){
            if(defined(self::PRODUCTION_APPROVAL_CONSTANT)&&constant(self::PRODUCTION_APPROVAL_CONSTANT)===true)return true;
            if(defined(self::STAGING_CONSTANT)&&constant(self::STAGING_CONSTANT)===true)return true;
            return false;
        }
        return $stored===true||$stored===1||$stored==='1'||$stored==='true'||$stored==='enabled';
    }

    /** Repository-wide emergency stop. A true constant or the stored option engages it. */
    public static function emergencyStop():array{
        $option=function_exists('get_option')?get_option(self::EMERGENCY_STOP_OPTION,array()):array();
        $engaged=defined(self::EMERGENCY_STOP_CONSTANT)&&constant(self::EMERGENCY_STOP_CONSTANT)===true;
        if(is_array($option)&&($option['engaged']??false)===true)$engaged=true;
        return array(
            'engaged'=>$engaged,
            'reason'=>is_array($option)?(string)($option['reason']??''):'',
            'actor'=>is_array($option)?(int)($option['actor']??0):0,
            'at'=>is_array($option)?(int)($option['at']??0):0,
        );
    }

    public static function researchExportEnabled():bool{
        return RecordingDecisionAuthorityService::RESEARCH_EXPORT_ENABLED===true;
    }

    public static function serviceSecret():string{
        $value=defined('DZN_RECORDING_SERVICE_SECRET')?constant('DZN_RECORDING_SERVICE_SECRET'):getenv('DZN_RECORDING_SERVICE_SECRET');
        if(!is_string($value)||strlen($value)<32)throw new \RuntimeException('recording_service_secret_unavailable');
        return $value;
    }

    public static function archiveConfirmationSecret():string{
        $value=defined('DZN_MBOX_CONFIRMATION_SECRET')?constant('DZN_MBOX_CONFIRMATION_SECRET'):getenv('DZN_MBOX_CONFIRMATION_SECRET');
        if(!is_string($value)||strlen($value)<32)throw new \RuntimeException('recording_archive_confirmation_secret_unavailable');
        return $value;
    }

    public static function archiveConfirmationKeyId():string{
        $value=defined('DZN_MBOX_CONFIRMATION_KEY_ID')?constant('DZN_MBOX_CONFIRMATION_KEY_ID'):getenv('DZN_MBOX_CONFIRMATION_KEY_ID');
        return is_string($value)&&preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$value)===1?$value:'mbox-archive-v1';
    }

    public static function playbackSecret():string{
        $value=defined('DZN_RECORDING_PLAYBACK_SECRET')?constant('DZN_RECORDING_PLAYBACK_SECRET'):getenv('DZN_RECORDING_PLAYBACK_SECRET');
        if(!is_string($value)||strlen($value)<32)throw new \RuntimeException('recording_playback_secret_unavailable');
        return $value;
    }

    public static function serviceKeyId():string{
        $value=defined('DZN_RECORDING_SERVICE_KEY_ID')?constant('DZN_RECORDING_SERVICE_KEY_ID'):getenv('DZN_RECORDING_SERVICE_KEY_ID');
        return is_string($value)&&preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$value)===1?$value:'dzn-recorder-v1';
    }

    public static function playbackKeyId():string{
        $value=defined('DZN_RECORDING_PLAYBACK_KEY_ID')?constant('DZN_RECORDING_PLAYBACK_KEY_ID'):getenv('DZN_RECORDING_PLAYBACK_KEY_ID');
        return is_string($value)&&preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$value)===1?$value:'dzn-playback-v1';
    }

    public static function playbackGatewayUrl():string{
        $value=defined('DZN_RECORDING_PLAYBACK_GATEWAY_URL')?constant('DZN_RECORDING_PLAYBACK_GATEWAY_URL'):getenv('DZN_RECORDING_PLAYBACK_GATEWAY_URL');
        if(!is_string($value)||preg_match('#^https?://[A-Za-z0-9.-]+(?::[0-9]{2,5})?/?$#D',$value)!==1)throw new \RuntimeException('recording_playback_gateway_unavailable');
        return rtrim($value,'/');
    }

    /** Fresh, signed recorder health snapshot. It is never synthesized from an admin form. */
    public static function serviceHealth():array{
        $health=function_exists('get_option')?get_option(self::SERVICE_HEALTH_OPTION,array()):array();
        return is_array($health)?$health:array();
    }

    public static function freshServiceHealth(int $now,int $maxAgeSeconds=180):array{
        $health=self::serviceHealth();
        if((int)($health['received_at']??0)<$now-$maxAgeSeconds)throw new \RuntimeException('recording_service_health_stale');
        if(($health['ready']??false)!==true)throw new \RuntimeException('recording_service_not_ready');
        return $health;
    }
}
