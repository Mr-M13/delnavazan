<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/**
 * Pure, testable contracts for God Mode.
 *
 * Nothing here reads WordPress state. The authority, persistence and provider services depend on
 * these narrow decisions so security and isolation can be proven without a live installation.
 */
final class GodModeRule {
    public const CAPABILITY='dzn_access_god_mode';
    public const DESIGNATED_EMAIL='hamed.moqadasi@yahoo.com';
    public const ACTIVITY_TYPE='test';
    public const PRODUCTION_ACTIVITY='production';
    public const CAPABILITY_VERSION='god_mode_v1';

    public const MODE_DIRECT='direct';
    public const MODE_WORKFLOW='workflow';
    public const MODES=array(self::MODE_DIRECT,self::MODE_WORKFLOW);

    public const STATUS_SCHEDULED='scheduled';
    public const STATUS_IN_PROGRESS='in_progress';
    public const STATUS_COMPLETED='completed';
    public const STATUS_CANCELLED='cancelled';
    public const STATUS_FAILED='failed';
    public const STATUS_ARCHIVED='archived';
    public const STATUSES=array(self::STATUS_SCHEDULED,self::STATUS_IN_PROGRESS,self::STATUS_COMPLETED,self::STATUS_CANCELLED,self::STATUS_FAILED,self::STATUS_ARCHIVED);
    public const ACTIVE_STATUSES=array(self::STATUS_SCHEDULED,self::STATUS_IN_PROGRESS);

    public const RESULT_NOT_TESTED='not_tested';
    public const RESULT_RUNNING='running';
    public const RESULT_PASSED='passed';
    public const RESULT_FAILED='failed';
    public const RESULT_WARNING='warning';
    public const RESULT_AWAITING_MANUAL='awaiting_manual_verification';
    public const RESULTS=array(self::RESULT_NOT_TESTED,self::RESULT_RUNNING,self::RESULT_PASSED,self::RESULT_FAILED,self::RESULT_WARNING,self::RESULT_AWAITING_MANUAL);

    public const EVENT_REQUESTED='requested';
    public const EVENT_QUEUED='queued';
    public const EVENT_ATTEMPTED='attempted';
    public const EVENT_CONFIRMED='confirmed';
    public const EVENT_FAILED='failed';
    public const EVENT_NOT_APPLICABLE='not_applicable';
    public const EVENT_UNKNOWN='unknown';
    public const EVENT_STATES=array(self::EVENT_REQUESTED,self::EVENT_QUEUED,self::EVENT_ATTEMPTED,self::EVENT_CONFIRMED,self::EVENT_FAILED,self::EVENT_NOT_APPLICABLE,self::EVENT_UNKNOWN);

    public const STAGE_BOOKING_CREATED='booking_created';
    public const STAGE_TEACHER_ASSIGNED='teacher_assigned';
    public const STAGE_TEACHER_ACCEPTED='teacher_accepted';
    public const STAGE_CALENDAR_EVENT='calendar_event_created';
    public const STAGE_MEET_PROVISIONED='google_meet_provisioned';
    public const STAGE_INVITATION_SENT='invitation_sent';
    public const STAGE_TEACHER_JOINED='teacher_joined';
    public const STAGE_RECORDING_STARTED='recording_started';
    public const STAGE_ATTENDANCE_CONFIRMED='attendance_confirmed';
    public const STAGE_CLASS_COMPLETED='class_completed';
    public const STAGE_CLEANUP='cleanup';
    public const STAGES=array(
        self::STAGE_BOOKING_CREATED,self::STAGE_TEACHER_ASSIGNED,self::STAGE_TEACHER_ACCEPTED,
        self::STAGE_CALENDAR_EVENT,self::STAGE_MEET_PROVISIONED,self::STAGE_INVITATION_SENT,
        self::STAGE_TEACHER_JOINED,self::STAGE_RECORDING_STARTED,self::STAGE_ATTENDANCE_CONFIRMED,
        self::STAGE_CLASS_COMPLETED,self::STAGE_CLEANUP,
    );

    public const CHANNELS=array('portal','email','whatsapp','sms');

    /** A row is test activity only through the explicit persisted classification. */
    public static function isTestActivity(array $row):bool{
        return (string)($row['activity_type']??'')===self::ACTIVITY_TYPE;
    }

    /** Financial code must refuse test activity instead of trusting a caller-side filter. */
    public static function assertBillable(array $facts):void{
        if(self::isTestActivity($facts))throw new \InvalidArgumentException('finance_test_activity_isolated');
    }

    public static function isBillable(array $facts):bool{
        return !self::isTestActivity($facts);
    }

    /**
     * Authority is granted by user id, never by an email field alone.
     *
     * @param array{designated_user_id:int,user_id:int,email_digest:string,stored_email_digest:string,manage_options:bool,capability:bool,auth_state:string} $context
     */
    public static function authorityAllows(array $context):bool{
        $designated=(int)($context['designated_user_id']??0);
        $userId=(int)($context['user_id']??0);
        if($designated<1||$userId<1||$designated!==$userId)return false;
        if(empty($context['manage_options'])||empty($context['capability']))return false;
        if((string)($context['auth_state']??'')!=='active')return false;
        $stored=(string)($context['stored_email_digest']??'');
        $actual=(string)($context['email_digest']??'');
        return $stored!==''&&$actual!==''&&hash_equals($stored,$actual);
    }

    /**
     * Resolve conflict handling without ever cancelling or moving a genuine class.
     *
     * @param list<array<string,mixed>> $realConflicts
     * @param list<array<string,mixed>> $testConflicts
     * @return array{allowed:bool,requires_acknowledgement:bool,real_conflicts:int,test_conflicts:int,summary:string}
     */
    public static function conflictDecision(array $realConflicts,array $testConflicts,bool $acknowledged):array{
        $real=count($realConflicts);
        $test=count($testConflicts);
        $requires=$real>0||$test>0;
        $summary=array();
        if($real>0)$summary[]=$real.' genuine class conflict'.($real===1?'':'s');
        if($test>0)$summary[]=$test.' other test session conflict'.($test===1?'':'s');
        return array(
            'allowed'=>!$requires||$acknowledged,
            'requires_acknowledgement'=>$requires,
            'real_conflicts'=>$real,
            'test_conflicts'=>$test,
            'summary'=>$summary?implode('; ',$summary):'No conflict detected',
        );
    }

    /**
     * Permanent cleanup is only for an archived test row with no pending provider work.
     *
     * @param array<string,mixed> $session
     * @param array{pending_provider_operations:int,recording_retained:bool} $context
     */
    public static function cleanupRefusal(array $session,array $context):?string{
        if(!self::isTestActivity($session))return 'not_a_test_session';
        if((string)($session['status']??'')!==self::STATUS_ARCHIVED)return 'session_not_archived';
        if((int)($context['pending_provider_operations']??0)>0)return 'pending_provider_operations';
        if(!empty($context['recording_retained']))return 'recording_evidence_must_be_retained';
        return null;
    }

    /** Archive eligibility is a lifecycle transition, not a deletion. */
    public static function canArchive(array $session):bool{
        return self::isTestActivity($session)
            && in_array((string)($session['status']??''),array(self::STATUS_COMPLETED,self::STATUS_CANCELLED,self::STATUS_FAILED),true);
    }

    /** Provider state is never inferred from a preceding success. */
    public static function timelineStateLabel(string $state):string{
        return match($state){
            self::EVENT_REQUESTED=>'Requested',
            self::EVENT_QUEUED=>'Queued',
            self::EVENT_ATTEMPTED=>'Attempted',
            self::EVENT_CONFIRMED=>'Confirmed',
            self::EVENT_FAILED=>'Failed',
            self::EVENT_NOT_APPLICABLE=>'Not applicable',
            default=>'Unknown / insufficient evidence',
        };
    }

    public static function resultLabel(string $result):string{
        return match($result){
            self::RESULT_NOT_TESTED=>'Not tested',
            self::RESULT_RUNNING=>'Running',
            self::RESULT_PASSED=>'Passed',
            self::RESULT_FAILED=>'Failed',
            self::RESULT_WARNING=>'Warning',
            self::RESULT_AWAITING_MANUAL=>'Awaiting manual verification',
            default=>'Unknown',
        };
    }

    /** Test activity is never counted in operational or financial KPIs. */
    public static function operationalExclusionSql(string $alias='l'):string{
        return $alias.".activity_type<>'".self::ACTIVITY_TYPE."'";
    }
}
