<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Pure preconditions for an explicit administrator classroom retry. */
final class MeetClassroomRetryRule {
    public const ELIGIBLE='eligible';
    public const RESET_ACTIVE='reset_active';
    public const RETRY_EXISTING='retry_existing';
    public const RESET_LATEST='reset_latest';
    public const REACTIVATE='reactivate';
    public const CREATE_NEW='create_new';
    public const REQUIRE_REASON='reason_required';
    public const REJECT='reject';

    public static function lessonEligibility(string $lifecycle,string $status,bool $archived,?string $endsAtUtc,int $now,bool $hasCurrentVersion):string{
        if($archived)return 'lesson_archived';
        if($lifecycle!=='authorised')return 'lesson_not_authorised';
        if($status==='cancelled')return 'lesson_cancelled';
        if($status==='completed')return 'lesson_completed';
        if(!$hasCurrentVersion)return 'schedule_version_unusable';
        $end=$endsAtUtc!==null?strtotime($endsAtUtc.' UTC'):false;
        if($end===false||$end<$now)return 'lesson_ended';
        return self::ELIGIBLE;
    }

    /**
     * Never resurrect a cancelled/retired row. A blocked/failed row with no active slot is recovered
     * by creating a new projection row with a deterministic event identity.
     */
    public static function classroomSelection(?object $active,?object $latest):string{
        if($active!==null){
            $state=(string)($active->classroom_state??'');
            if(in_array($state,array('cancelled','retired'),true))return 'reject_history';
            if(MeetCoHostRule::isReady($state,(string)($active->cohost_state??MeetCoHostRule::PENDING))
                &&!self::isPermanentBlock($state,(string)($active->cohost_state??''),$active->failure_reason_code??null))return 'already_ready';
            return self::RESET_ACTIVE;
        }
        if($latest!==null&&in_array((string)($latest->classroom_state??''),array('cancelled','retired'),true))return 'reject_history';
        return self::CREATE_NEW;
    }

    /**
     * Booking recovery selection.
     *
     * Unlike a missing lesson, a terminal booking already owns a provider event/space and a
     * historical classroom row. Recovery must reuse that exact row and references so an
     * administrator retry cannot create a second classroom row, event or space. Only a
     * cancelled/retired row is refused history; a genuinely missing row may create one.
     */
    public static function bookingSelection(?object $active,?object $latest):string{
        if($active!==null){
            $state=(string)($active->classroom_state??'');
            if(in_array($state,array('cancelled','retired'),true))return 'reject_history';
            if(MeetCoHostRule::isReady($state,(string)($active->cohost_state??MeetCoHostRule::PENDING))
                &&!self::isPermanentBlock($state,(string)($active->cohost_state??''),$active->failure_reason_code??null))return 'already_ready';
            // Preserve the bounded attempt count for an in-flight transient booking retry. Resetting
            // it would let the booking cycle past MAX_ATTEMPTS forever and disagree with the terminal
            // state persisted by recordProviderFailure().
            if($state==='provisioning'
                &&in_array((string)($active->cohost_state??MeetCoHostRule::PENDING),array(MeetCoHostRule::PENDING,MeetCoHostRule::RETRYABLE),true)
                &&!self::isPermanentBlock($state,(string)($active->cohost_state??''),$active->failure_reason_code??null))return self::RETRY_EXISTING;
            return self::RESET_ACTIVE;
        }
        if($latest!==null){
            $state=(string)($latest->classroom_state??'');
            $cohost=(string)($latest->cohost_state??MeetCoHostRule::PENDING);
            if(in_array($state,array('cancelled','retired'),true))return 'reject_history';
            if(MeetCoHostRule::isReady($state,$cohost)
                &&!self::isPermanentBlock($state,$cohost,$latest->failure_reason_code??null))return self::REACTIVATE;
            if(in_array($state,array('blocked','failed','provisioning','retryable'),true)
                ||in_array($cohost,array(MeetCoHostRule::PENDING,MeetCoHostRule::RETRYABLE,MeetCoHostRule::BLOCKED),true)
                ||self::isPermanentBlock($state,$cohost,$latest->failure_reason_code??null))return self::RESET_LATEST;
            return 'reject_history';
        }
        return self::CREATE_NEW;
    }

    public static function isPermanentBlock(string $classroomState,string $cohostState,?string $failureCode):bool{
        return $classroomState==='blocked'
            ||$cohostState===MeetCoHostRule::BLOCKED
            ||MeetProvisioningRule::isPermanentCode($failureCode);
    }

    public static function reasonRequired(int $actorUserId,string $reason):bool{
        return $actorUserId>0&&strlen(trim($reason))>=12&&strlen(trim($reason))<=255;
    }
}
