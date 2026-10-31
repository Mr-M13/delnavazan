<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Keyed provenance digests for durable LiveKit event correlation.
 *
 * Only digests of provider event ids, participant SIDs, identities and interval facts are stored.
 * Raw webhook payloads, tokens, API secrets and launch tickets never reach this boundary.
 */
final class LiveKitAttendanceIdempotency {
    private const SALT='dzn_livekit_attendance';

    public static function digest(string $domain,string $value):string{
        if($value==='')throw new \InvalidArgumentException('livekit_attendance_value_required');
        return hash_hmac('sha256',$domain.':'.$value,wp_salt(self::SALT));
    }

    public static function event(string $value):string{
        return self::digest('event',$value);
    }

    public static function connection(string $value):string{
        return self::digest('connection',$value);
    }

    public static function identity(string $value):string{
        return self::digest('identity',$value);
    }

    public static function roomInstance(string $value):string{
        return self::digest('room_instance',$value);
    }

    /** Stable digest of the closed-interval facts, used to make a changed interval detectable. */
    public static function interval(array $facts):string{
        ksort($facts);
        return self::digest('interval',(string)wp_json_encode($facts,JSON_UNESCAPED_SLASHES));
    }
}
