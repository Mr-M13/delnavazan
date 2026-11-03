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

    public static function captureEnabledForLesson(int $lessonId):bool{
        if(RecordingDecisionAuthorityService::CAPTURE_ENABLED!==false)return false;
        if(!defined(self::STAGING_CONSTANT)||constant(self::STAGING_CONSTANT)!==true)return false;
        if(!function_exists('wp_get_environment_type'))return false;
        if(!in_array(wp_get_environment_type(),array('local','development','staging'),true))return false;
        if($lessonId<1)return false;
        $cohort=function_exists('get_option')?get_option(self::STAGING_COHORT_OPTION,array()):array();
        if(is_string($cohort))$cohort=array_map('trim',explode(',',$cohort));
        if(!is_array($cohort)||$cohort===array())return false;
        foreach($cohort as $entry){
            if((string)$entry==='all-staging')return true;
            if(ctype_digit((string)$entry)&&(int)$entry===$lessonId)return true;
        }
        return false;
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
