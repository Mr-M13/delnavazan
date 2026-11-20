<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdentityService,CanonicalAttendanceRule,PostIntroPreparationService,ProviderEvidenceSystemActor};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAttendanceRepository,PostIntroRepository};

/**
 * Automatic introductory attendance from durable Google Meet participant sessions.
 *
 * A booking-linked introductory Lesson is created only after an outcome is recorded, so the
 * ordinary Phase-P path can never see the occurrence. This adapter therefore re-assesses the
 * durable participant sessions against the same locked `CanonicalAttendanceRule` used for
 * standard classes and, only when the exact Teacher and Student resolve through verified
 * mappings and the qualifying overlap reaches the same threshold, records the delivered
 * introductory outcome through `PostIntroPreparationService`.
 *
 * The caller never supplies an assessment: the service re-reads the classroom, confirmation,
 * sessions and identity registry, waits until the post-class window has closed, and never
 * replaces an existing manual outcome. It creates no canonical Phase-P case or Phase-O outcome.
 */
final class MeetIntroAttendanceService {
    private const PROVIDER='google_meet';
    private const LOCK_PREFIX='dzn_intro_attendance_settle_';

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?PostIntroRepository $postIntro=null,
        private ?CanonicalAttendanceRepository $identities=null,
        private ?PostIntroPreparationService $preparation=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->postIntro??=new PostIntroRepository();
        $this->identities??=new CanonicalAttendanceRepository();
        $this->preparation??=new PostIntroPreparationService();
    }

    /**
     * Evaluate one booking classroom and record the delivered outcome when the evidence qualifies.
     *
     * @return array<string,mixed>
     */
    public function settleClassroom(int $classroomId,?string $now=null):array{
        if($classroomId<1)throw new \InvalidArgumentException('meet_classroom_required');
        $now??=gmdate('Y-m-d H:i:s');
        $lock=self::LOCK_PREFIX.$classroomId;
        if(!$this->repository->acquireLock($lock,5))return array('state'=>'locked','retry'=>true);
        try{
            $classroom=$this->repository->classroom($classroomId);
            if(!$classroom)return array('state'=>'classroom_missing','retry'=>false);
            $confirmationId=(int)($classroom->booking_confirmation_id??0);
            if((int)$classroom->lesson_id>0||$confirmationId<1)return array('state'=>'not_booking_classroom','retry'=>false);
            $confirmation=$this->postIntro->confirmationById($confirmationId);
            if(!$confirmation)return array('state'=>'confirmation_missing','retry'=>false);
            if((string)$confirmation->state!=='confirmed')return array('state'=>'confirmation_not_confirmed','retry'=>false);
            if($confirmation->privacy_erased_at!==null)return array('state'=>'privacy_erased','retry'=>false);
            if((int)($classroom->active_slot??0)!==1)return array('state'=>'classroom_inactive','retry'=>false);
            if(!in_array((string)($classroom->classroom_state??''),array('ready','rescheduled'),true))return array('state'=>'classroom_not_reconcilable','retry'=>false);
            if((string)$classroom->starts_at_utc!==(string)$confirmation->starts_at_utc||(string)$classroom->ends_at_utc!==(string)$confirmation->ends_at_utc){
                return array('state'=>'classroom_schedule_stale','retry'=>false);
            }
            $existing=$this->postIntro->outcomeByBooking((int)$confirmation->booking_request_id);
            if($existing)return array('state'=>'already_recorded','outcome'=>(string)$existing->outcome,'retry'=>false);

            // Do not decide before the 15-minute post-class window closes: a late qualifying join
            // must be part of the same assessment, exactly as it is for a standard occurrence.
            $window=CanonicalAttendanceRule::window((string)$confirmation->starts_at_utc,(string)$confirmation->ends_at_utc);
            if((string)$window['window_end_utc']>$now)return array('state'=>'window_open','window_end_utc'=>(string)$window['window_end_utc'],'retry'=>true);

            $assessment=$this->assess($classroom,$confirmation);
            if(empty($assessment['eligible']))return array('state'=>'not_eligible','retry'=>false,'assessment'=>$assessment);
            if(!ProviderEvidenceSystemActor::isConfigured())return array('state'=>'actor_unconfigured','retry'=>true,'assessment'=>$assessment);

            $record=$this->repository->conferenceRecordForClassroom((int)$classroom->id);
            $evidence=array(
                'provider_code'=>self::PROVIDER,
                'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
                'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,
                'qualifying_seconds'=>(int)$assessment['seconds'],
                'occurrence_start_utc'=>(string)$confirmation->starts_at_utc,
                'occurrence_end_utc'=>(string)$confirmation->ends_at_utc,
                'participant_roles'=>array('teacher','student'),
                'classroom_id'=>(int)$classroom->id,
                'conference_record_id'=>(int)($record->id??0),
                'session_count'=>(int)($assessment['session_count']??0),
                'segments'=>(array)($assessment['segments']??array()),
            );
            $auditActor=0;
            try{
                $recorded=ProviderEvidenceSystemActor::run(function(int $providerActorId) use ($confirmation,$evidence,&$auditActor){
                    $auditActor=$providerActorId;
                    return $this->preparation->recordProviderDelivered(
                        (string)$confirmation->uid,
                        $evidence,
                        'intro-attendance-auto-'.(string)$confirmation->uid
                    );
                });
            }catch(\Throwable $error){
                do_action('dzn_intro_attendance_auto_failed',(int)$classroom->id,get_class($error),(string)$error->getMessage());
                return array('state'=>'settlement_failed','retry'=>true,'assessment'=>$assessment,'error'=>get_class($error));
            }
            $this->auditDelivered($confirmation,$classroom,$assessment,$evidence,$auditActor);
            return array(
                'state'=>'delivered','retry'=>false,
                'outcome_id'=>(int)($recorded['outcome_id']??0),
                'created'=>!empty($recorded['created']),
                'assessment'=>$assessment,
            );
        }finally{
            $this->repository->releaseLock($lock);
        }
    }

    /** @return array<string,mixed> */
    private function assess(object $classroom,object $confirmation):array{
        $sessions=$this->repository->participantSessionsForClassroom((int)$classroom->id);
        $teacher=array();$student=array();
        $unmapped=0;$ambiguous=0;$mismatched=0;
        foreach($sessions as $row){
            $digest=(string)($row->participant_account_digest??'');
            if($digest===''){$unmapped++;continue;}
            $teacherRows=$this->identities->mappingsForAccount(self::PROVIDER,$digest,'teacher');
            $studentRows=$this->identities->mappingsForAccount(self::PROVIDER,$digest,'student');
            $teacherResolved=CanonicalAttendanceIdentityService::resolveExpected($teacherRows,'teacher',(int)$confirmation->teacher_id);
            $studentResolved=CanonicalAttendanceIdentityService::resolveExpected($studentRows,'student',(int)$confirmation->student_id);
            $isTeacher=$teacherResolved['state']==='resolved';
            $isStudent=$studentResolved['state']==='resolved';
            if($isTeacher&&$isStudent){$ambiguous++;continue;}
            if(!$isTeacher&&!$isStudent){
                if(($teacherResolved['state']??'')==='mismatch'||($studentResolved['state']??'')==='mismatch')$mismatched++;
                else $unmapped++;
                continue;
            }
            $interval=array(
                'join_at_utc'=>(string)($row->join_at_utc??''),
                'leave_at_utc'=>$row->leave_at_utc===null?null:(string)$row->leave_at_utc,
            );
            if($isTeacher)$teacher[]=$interval;else $student[]=$interval;
        }
        $assessment=CanonicalAttendanceRule::assess($teacher,$student,(string)$confirmation->starts_at_utc,(string)$confirmation->ends_at_utc);
        $anomalies=array();
        if(!$teacher)$anomalies[]='teacher_participation_unproven';
        if(!$student)$anomalies[]='student_participation_unproven';
        if($unmapped>0)$anomalies[]='provider_identity_unmapped';
        if($ambiguous>0)$anomalies[]='participant_ambiguous';
        if($mismatched>0)$anomalies[]='participant_identity_mismatch';
        if($assessment['seconds']<CanonicalAttendanceRule::THRESHOLD_SECONDS&&$teacher&&$student)$anomalies[]='overlap_below_threshold';
        $assessment['session_count']=count($sessions);
        $assessment['anomalies']=array_values(array_unique($anomalies));
        return $assessment;
    }

    /** Best-effort audit; the recorded outcome remains the authoritative fact. */
    private function auditDelivered(object $confirmation,object $classroom,array $assessment,array $evidence,int $actorId):void{
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb))return;
        $table=$wpdb->prefix.'dzn_platform_audit_events';
        $key=hash('sha256','intro-attendance-auto|'.(string)$confirmation->uid);
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE idempotency_key=%s",$key))>0)return;
        $detail=function_exists('wp_json_encode')?wp_json_encode(array(
            'resource'=>'booking_request',
            'booking_request_id'=>(int)$confirmation->booking_request_id,
            'confirmation_id'=>(int)$confirmation->id,
            'classroom_id'=>(int)$classroom->id,
            'conference_record_id'=>(int)($evidence['conference_record_id']??0),
            'provider_code'=>self::PROVIDER,
            'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
            'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,
            'qualifying_seconds'=>(int)$assessment['seconds'],
            'session_count'=>(int)($assessment['session_count']??0),
            'segments'=>(array)($assessment['segments']??array()),
            'participant_roles'=>array('teacher','student'),
        ),JSON_UNESCAPED_SLASHES):null;
        $ok=$wpdb->insert($table,array(
            'aggregate_type'=>'booking_request',
            'aggregate_id'=>(int)$confirmation->booking_request_id,
            'event_type'=>'intro_attendance.auto_delivered',
            'actor_type'=>'system','actor_id'=>$actorId>0?$actorId:null,
            'reason_code'=>'provider_evidence_delivered',
            'safe_detail'=>is_string($detail)?$detail:null,
            'idempotency_key'=>$key,
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
        ));
        if($ok===false)do_action('dzn_intro_attendance_audit_failed',(int)$classroom->id);
    }
}
