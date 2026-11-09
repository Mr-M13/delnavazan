<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Support\Identifier;

/** Append-only privileged-action audit. Never stores credentials, tokens or raw provider secrets. */
final class GodModeAudit {
    public static function record(int $wordpressUserId,string $action,string $targetKind,?int $targetId,string $reasonCode,?string $safeDetail=null):void{
        if($wordpressUserId<1)throw new \RuntimeException('god_mode_audit_actor_required');
        $action=self::code($action,'unknown_action');
        $targetKind=self::code($targetKind,'unknown');
        $reasonCode=self::code($reasonCode,'unspecified');
        $detail=self::redact($safeDetail);
        $now=gmdate('Y-m-d H:i:s');
        $fingerprint=hash('sha256',wp_json_encode(array(
            'method'=>strtoupper((string)($_SERVER['REQUEST_METHOD']??'')),
            'uri'=>substr((string)($_SERVER['REQUEST_URI']??''),0,255),
            'user'=>$wordpressUserId,'action'=>$action,'at'=>$now,
        ),JSON_UNESCAPED_SLASHES) ?: $now);
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'dzn_god_mode_audit_events',array(
            'uid'=>Identifier::uid(),'wordpress_user_id'=>$wordpressUserId,'action'=>$action,
            'target_kind'=>$targetKind,'target_id'=>$targetId,'reason_code'=>$reasonCode,
            'safe_detail'=>$detail,'request_fingerprint_digest'=>$fingerprint,
            'occurred_at'=>$now,'created_at'=>$now,
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('god_mode_audit_write_failed');
    }

    public static function redact(?string $value):?string{
        $value=trim((string)$value);
        if($value==='')return null;
        $value=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[redacted-email]',$value) ?? '';
        $value=preg_replace('/(?<![0-9])\+?[0-9][0-9\s\-().]{7,}[0-9]/','[redacted-phone]',$value) ?? '';
        $value=preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i','Bearer [redacted]',$value) ?? '';
        return substr($value,0,1000);
    }

    private static function code(string $value,string $fallback):string{
        $value=preg_replace('/[^a-z0-9_]/','',strtolower(trim($value))) ?? '';
        return $value!==''?substr($value,0,64):$fallback;
    }
}
