<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Pure readiness rule for co-host assignment.
 *
 * A Meet classroom is not ready merely because the Calendar event exists. The teacher must be
 * verified as a COHOST member, or an administrator must explicitly approve a documented exception.
 * There is no silent downgrade to an ordinary participant.
 */
final class MeetCoHostRule {
    public const PENDING='pending';
    public const VERIFIED='verified';
    public const RETRYABLE='retryable';
    public const BLOCKED='blocked';
    public const EXCEPTION_APPROVED='exception_approved';

    public static function isReady(string $classroomState,string $cohostState):bool{
        return in_array($classroomState,array('ready','rescheduled'),true)
            && in_array($cohostState,array(self::VERIFIED,self::EXCEPTION_APPROVED),true);
    }

    public static function isRetryableStatus(int $status):bool{
        return $status===0||$status===408||$status===429||$status>=500;
    }

    public static function isRetryableErrorClass(?string $errorClass):bool{
        $class=strtolower(trim((string)$errorClass));
        return in_array($class,array('transport_unavailable','backend_error','internal','resource_exhausted','rate_limit_exceeded','service_unavailable','deadline_exceeded','unavailable'),true);
    }

    /** Bounded retry backoff; a permanent refusal is never retried automatically. */
    public static function retryDelaySeconds(int $attempt):int{
        $attempt=max(1,min(8,$attempt));
        return min(3600,60*(2**($attempt-1)));
    }

    public static function exceptionAllowed(int $approverUserId,string $reason,string $capabilityGranted):bool{
        return $approverUserId>0
            && $capabilityGranted==='1'
            && strlen(trim($reason))>=12
            && strlen(trim($reason))<=255;
    }

    public static function canSilentlyDowngrade():bool{
        return false;
    }
}
