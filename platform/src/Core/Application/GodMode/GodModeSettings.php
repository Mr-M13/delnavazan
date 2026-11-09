<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/** Bounded, auditable configuration for the permanent testing capability. */
final class GodModeSettings {
    public const OPTION='dzn_god_mode_settings';

    /** @return array<string,mixed> */
    public static function defaults():array{
        $user=GodModeAuthority::designatedUserId();
        $email='';
        if($user>0){$account=get_userdata($user);if($account&&is_object($account))$email=strtolower(trim((string)($account->user_email??'')));}
        return array(
            'default_timezone'=>'UTC',
            'default_duration_minutes'=>30,
            'default_mode'=>GodModeRule::MODE_DIRECT,
            'default_notification_behavior'=>'portal',
            'approved_recipients'=>$email!==''?array($email):array(),
            'recording_default'=>false,
            'retention_days'=>30,
            'automatic_cleanup_eligibility'=>false,
            'conflict_warning_enabled'=>true,
            'diagnostic_logging'=>true,
            'last_changed_at'=>null,
            'last_changed_by'=>null,
        );
    }

    /** @return array<string,mixed> */
    public static function all():array{
        $stored=get_option(self::OPTION,array());
        $stored=is_array($stored)?$stored:array();
        $settings=array_merge(self::defaults(),$stored);
        $settings['approved_recipients']=array_values(array_filter(array_map('strval',(array)($settings['approved_recipients']??array()))));
        return $settings;
    }

    public static function get(string $key,mixed $fallback=null):mixed{
        $settings=self::all();
        return array_key_exists($key,$settings)?$settings[$key]:$fallback;
    }

    /** @return array<string,mixed> */
    public static function save(int $actorUserId,array $input):array{
        if($actorUserId<1||!GodModeAuthority::isAuthorised($actorUserId))throw new \RuntimeException('god_mode_forbidden');
        $current=self::all();
        $timezone=trim((string)($input['default_timezone']??$current['default_timezone']));
        if(!in_array($timezone,timezone_identifiers_list(),true))$timezone='UTC';
        $duration=(int)($input['default_duration_minutes']??$current['default_duration_minutes']);
        if($duration<5||$duration>1440)$duration=30;
        $mode=(string)($input['default_mode']??$current['default_mode']);
        if(!in_array($mode,GodModeRule::MODES,true))$mode=GodModeRule::MODE_DIRECT;
        $notification=(string)($input['default_notification_behavior']??$current['default_notification_behavior']);
        if(!in_array($notification,array('none','portal','email','all'),true))$notification='portal';
        $retention=(int)($input['retention_days']??$current['retention_days']);
        if($retention<1||$retention>365)$retention=30;
        $recipients=self::recipients((string)($input['approved_recipients']??implode("\n",(array)$current['approved_recipients'])));
        // The designated owner's address is always an approved testing recipient.
        $user=get_userdata($actorUserId);
        if($user&&is_object($user)){
            $owner=strtolower(trim((string)($user->user_email??'')));
            if($owner!==''&&!in_array($owner,$recipients,true))$recipients[]=$owner;
        }
        $settings=array(
            'default_timezone'=>$timezone,
            'default_duration_minutes'=>$duration,
            'default_mode'=>$mode,
            'default_notification_behavior'=>$notification,
            'approved_recipients'=>$recipients,
            'recording_default'=>!empty($input['recording_default']),
            'retention_days'=>$retention,
            'automatic_cleanup_eligibility'=>!empty($input['automatic_cleanup_eligibility']),
            'conflict_warning_enabled'=>!empty($input['conflict_warning_enabled']),
            'diagnostic_logging'=>!empty($input['diagnostic_logging']),
            'last_changed_at'=>gmdate('Y-m-d H:i:s'),
            'last_changed_by'=>$actorUserId,
        );
        update_option(self::OPTION,$settings,false);
        GodModeAudit::record($actorUserId,'settings_update','settings',null,'security_sensitive_settings_changed',wp_json_encode(array(
            'timezone'=>$timezone,'duration'=>$duration,'mode'=>$mode,'notification'=>$notification,
            'recipient_count'=>count($recipients),'retention'=>$retention,
            'auto_cleanup'=>$settings['automatic_cleanup_eligibility'],
            'conflict_warning'=>$settings['conflict_warning_enabled'],
            'diagnostic_logging'=>$settings['diagnostic_logging'],
        ),JSON_UNESCAPED_SLASHES) ?: null);
        return $settings;
    }

    /** @return list<string> */
    private static function recipients(string $raw):array{
        $parts=preg_split('/[\s,;]+/',$raw) ?: array();
        $out=array();
        foreach($parts as $part){
            $part=trim($part);
            if($part==='')continue;
            if(filter_var($part,FILTER_VALIDATE_EMAIL)||preg_match('/^\+[1-9][0-9]{7,14}$/',$part))$out[]=strtolower($part);
        }
        return array_values(array_unique($out));
    }
}
