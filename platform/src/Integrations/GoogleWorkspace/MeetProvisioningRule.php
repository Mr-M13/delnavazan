<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Pure retry vocabulary for organizer-owned Meet provisioning.
 *
 * HTTP transport failures, throttling and Google 5xx responses are transient. Authentication,
 * permission and malformed-request refusals are permanent until an operator changes configuration.
 * Unknown booking failures remain bounded-retryable so a transient provider problem cannot strand an
 * accepted booking, while an exhaustible attempt count still surfaces it to an administrator.
 */
final class MeetProvisioningRule {
    public const RETRYABLE='retryable';
    public const PERMANENT='permanent';

    private const RETRYABLE_CODES=array(
        'meet_calendar_projection_retryable',
        'meet_space_lookup_retryable',
        'meet_space_config_retryable',
        'meet_cohost_assignment_retryable',
        'meet_organizer_refresh_failed',
        'meet_organizer_not_connected',
        'meet_classroom_paused',
        'meet_conference_pending',
        'meet_classroom_projection_retryable',
        'booking_projection_busy',
        'booking_projection_pending',
        'provider_projection_retryable',
        'provider_credential_required',
        'provider_credential_refresh_required',
    );

    private const PERMANENT_CODES=array(
        'meet_calendar_projection_refused',
        'meet_space_lookup_refused',
        'meet_space_config_refused',
        'meet_cohost_assignment_blocked',
        'meet_cohost_assignment_refused',
        'meet_calendar_identity_mismatch',
        'meet_join_uri_untrusted',
        'meet_calendar_event_id_invalid',
        'meet_teacher_email_required',
        'meet_booking_confirmation_required',
        'meet_lesson_not_active',
        'meet_schedule_version_unusable',
        'meet_provisioning_blocked',
        'meet_conference_timeout',
        'meet_booking_not_active',
        'meet_request_encode_failed',
    );

    /** Classify one provider response without treating every 4xx as retryable. */
    public static function classifyResponse(int $status,?string $errorClass=null):string {
        if($status===0||$status===408||$status===425||$status===429||$status>=500)return self::RETRYABLE;
        if(MeetCoHostRule::isRetryableErrorClass($errorClass))return self::RETRYABLE;
        return self::PERMANENT;
    }

    public static function isRetryableResponse(int $status,?string $errorClass=null):bool {
        return self::classifyResponse($status,$errorClass)===self::RETRYABLE;
    }

    /** Stable persisted code for a provider operation. */
    public static function failureCode(string $operation,bool $retryable):string {
        $operation=trim($operation);
        if(!preg_match('/^[a-z0-9_]{3,48}$/D',$operation))$operation='classroom_projection';
        return 'meet_'.$operation.'_'.($retryable?'retryable':'refused');
    }

    public static function isRetryableCode(?string $code):bool {
        return in_array((string)$code,self::RETRYABLE_CODES,true)
            || str_ends_with((string)$code,'_retryable');
    }

    public static function isPermanentCode(?string $code):bool {
        return in_array((string)$code,self::PERMANENT_CODES,true)
            || str_ends_with((string)$code,'_refused')
            || str_ends_with((string)$code,'_blocked');
    }

    /** Whether a failed booking confirmation may be retried automatically. */
    public static function isRetryableBookingFailure(?string $code):bool {
        $code=trim((string)$code);
        if($code==='')return true;
        return !self::isPermanentCode($code);
    }

    /** A permanent co-host refusal is never retried automatically; an operator reset is required. */
    public static function mayAutoRetryClassroom(
        string $classroomState,
        string $cohostState,
        ?string $failureCode,
        ?string $nextAttemptAt,
        int $attemptCount,
        int $now
    ):bool {
        if($classroomState==='cancelled'||$classroomState==='retired')return false;
        if($cohostState===MeetCoHostRule::BLOCKED)return false;
        if(self::isPermanentCode($failureCode))return false;
        if($classroomState==='blocked'&&!self::isRetryableCode($failureCode))return false;
        if($attemptCount>=GoogleWorkspaceClassroomRule::MAX_ATTEMPTS)return false;
        if($nextAttemptAt!==null&&$nextAttemptAt!==''){
            $next=strtotime($nextAttemptAt.' UTC');
            if($next!==false&&$next>$now)return false;
        }
        if(in_array($classroomState,array('ready','rescheduled'),true)
            &&in_array($cohostState,array(MeetCoHostRule::VERIFIED,MeetCoHostRule::EXCEPTION_APPROVED),true))return false;
        return in_array($classroomState,array('provisioning','failed','blocked','ready','rescheduled'),true);
    }

    /** A retryable co-host assignment becomes an actionable permanent block at the bound. */
    public static function cohostAttemptExhausted(int $attemptCount):bool {
        return $attemptCount>=GoogleWorkspaceClassroomRule::MAX_ATTEMPTS;
    }
}
