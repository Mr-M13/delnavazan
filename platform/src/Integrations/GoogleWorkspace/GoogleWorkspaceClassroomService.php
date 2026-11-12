<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\{ProviderReferenceVault,RecordingDecisionAuthorityService};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Creates and maintains one Google Meet classroom per lesson on the Delnavazan organizer account.
 *
 * The workflow is idempotent at three levels:
 *  - the Calendar event id is deterministic for the canonical lesson;
 *  - a unique classroom row exists per lesson/booking;
 *  - an append-only command digest records every attempted mutation.
 *
 * Retrying after an ambiguous transport failure therefore converges on the same provider objects
 * instead of creating duplicate meetings.
 */
final class GoogleWorkspaceClassroomService {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceApiClient $api=null,
        private ?GoogleWorkspaceOAuthService $oauth=null,
        private ?ProviderReferenceVault $vault=null,
        private ?RecordingDecisionAuthorityService $recording=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->oauth??=new GoogleWorkspaceOAuthService($this->repository);
        $this->api??=new GoogleWorkspaceApiClient($this->repository,null,$this->oauth);
        $this->vault??=new ProviderReferenceVault();
        $this->recording??=new RecordingDecisionAuthorityService();
    }

    /** Emergency switch: false stops new organizer provisioning but leaves existing links usable. */
    public static function enabled():bool{
        return GoogleWorkspaceFeatureFlags::classroomEnabled();
    }

    /** Whether the organizer grant is usable, independent of the provisioning pause switch. */
    public static function organizerConnected():bool{
        try{
            if(!GoogleWorkspaceOAuthService::configured())return false;
            $health=(new GoogleWorkspaceOAuthService())->health();
            return (string)($health['state']??'')==='connected';
        }catch(\Throwable){return false;}
    }

    /** New provisioning is allowed only while both the organizer and the emergency switch permit it. */
    public static function configured():bool{
        return self::enabled()&&self::organizerConnected();
    }

    /** Ensure a canonical term lesson has one ready organizer-owned Meet classroom. */
    public function ensureForLesson(int $lessonId,int $scheduleVersionId):array{
        if(!self::enabled())throw new \RuntimeException('meet_classroom_paused');
        $lesson=$this->repository->lessonWithParticipants($lessonId);
        if(!$lesson)throw new \InvalidArgumentException('meet_lesson_required');
        $version=$this->repository->scheduleVersion($scheduleVersionId);
        if(!$version||(int)$version->lesson_id!==$lessonId||(int)$version->applicable_slot!==1||$version->superseded_at!==null)throw new \InvalidArgumentException('meet_schedule_version_unusable');
        if((string)($lesson->lifecycle_state??'')!=='authorised'||(string)$lesson->status==='cancelled'||$lesson->archived_at!==null)throw new \InvalidArgumentException('meet_lesson_not_active');
        $teacherEmail=(string)($lesson->teacher_email??'');
        $studentEmail=(string)($lesson->student_email??'');
        if(!GoogleWorkspaceClassroomRule::validEmail($teacherEmail))throw new \InvalidArgumentException('meet_teacher_email_required');
        $existing=$this->repository->activeClassroomForLesson($lessonId);
        if($existing){
            $this->assertNotPermanentlyBlocked($existing);
            if(in_array((string)$existing->classroom_state,array('ready','rescheduled'),true)&&(int)$existing->schedule_version_id===$scheduleVersionId
                &&MeetCoHostRule::isReady((string)$existing->classroom_state,(string)$existing->cohost_state)){
                $references=$this->openReferences($existing);
                if($references!==null)return $this->result($existing,$references,true);
            }
        }
        $identity='lesson:'.$lessonId;
        $classroom=$existing?:$this->createClassroomRow(array(
            'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'booking_confirmation_id'=>null,
            'classroom_kind'=>'canonical_term_lesson','teacher_id'=>(int)$lesson->teacher_id,
            'student_id'=>(int)$lesson->student_id,
            'teacher_email_digest'=>$this->digestEmail($teacherEmail),
            'student_email_digest'=>GoogleWorkspaceClassroomRule::validEmail($studentEmail)?$this->digestEmail($studentEmail):null,
            'starts_at_utc'=>(string)$version->starts_at_utc,'ends_at_utc'=>(string)$version->ends_at_utc,
            'schedule_timezone'=>(string)$version->schedule_timezone,
        ));
        return $this->provision($classroom,$identity,array(
            'summary'=>'Delnavazan music lesson',
            'description'=>'Delnavazan lesson · '.$lesson->uid,
            'teacher_name'=>(string)$lesson->teacher_name,
            'student_name'=>(string)$lesson->student_name,
            'course_name'=>(string)($lesson->course_name??'Delnavazan'),
            'teacher_email'=>$teacherEmail,
            'student_email'=>$studentEmail,
            'starts_at_utc'=>(string)$version->starts_at_utc,
            'ends_at_utc'=>(string)$version->ends_at_utc,
            'timezone'=>(string)$version->schedule_timezone,
            'lesson_id'=>$lessonId,
            'schedule_version_id'=>$scheduleVersionId,
            'lesson_uid'=>(string)$lesson->uid,
        ),$this->recording->lessonRecordingEligibility($lessonId));
    }

    /** Ensure an introductory booking has one organizer-owned Meet classroom. */
    public function ensureForBooking(int $confirmationId):array{
        if(!self::enabled())throw new \RuntimeException('meet_classroom_paused');
        $confirmation=$this->repository->bookingConfirmation($confirmationId);
        if(!$confirmation)throw new \InvalidArgumentException('meet_booking_confirmation_required');
        if((string)$confirmation->state==='cancelled')throw new \InvalidArgumentException('meet_booking_not_active');
        if((string)$confirmation->ends_at_utc<gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('meet_booking_ended');
        $active=$this->repository->activeClassroomForBooking($confirmationId);
        $latest=$active?:$this->repository->latestClassroomForBooking($confirmationId);
        $selection=MeetClassroomRetryRule::bookingSelection($active,$latest);
        if($selection==='reject_history')throw new \InvalidArgumentException('meet_classroom_history_not_retryable');
        $existing=$active;
        if($selection===MeetClassroomRetryRule::REACTIVATE){
            $now=gmdate('Y-m-d H:i:s');
            $this->repository->updateClassroom((int)$latest->id,array(
                'active_slot'=>1,'version'=>(int)$latest->version+1,'updated_at'=>$now,
                'updated_by'=>function_exists('get_current_user_id')?(int)get_current_user_id():0,
            ),array('id'=>(int)$latest->id));
            $existing=$this->repository->classroom((int)$latest->id);
            $references=$existing?$this->openReferences($existing):null;
            if($references!==null)return $this->result($existing,$references,true);
            $selection=MeetClassroomRetryRule::RESET_LATEST;
        }
        if($selection===MeetClassroomRetryRule::RETRY_EXISTING&&$active!==null){
            $existing=$active;
        }elseif($selection===MeetClassroomRetryRule::RESET_ACTIVE&&$active!==null){
            $existing=$this->resetBookingClassroomForRetry($active);
        }elseif($selection===MeetClassroomRetryRule::RESET_LATEST&&$latest!==null){
            $existing=$this->resetBookingClassroomForRetry($latest);
        }elseif($selection===MeetClassroomRetryRule::CREATE_NEW){
            $existing=null;
        }
        if($existing){
            $this->assertNotPermanentlyBlocked($existing);
            if(MeetCoHostRule::isReady((string)$existing->classroom_state,(string)$existing->cohost_state)){
                $references=$this->openReferences($existing);
                if($references!==null)return $this->result($existing,$references,true);
            }
        }
        $teacherEmail=(string)($confirmation->teacher_email??'');
        $studentEmail=(string)($confirmation->student_email??'');
        if(!GoogleWorkspaceClassroomRule::validEmail($teacherEmail))throw new \InvalidArgumentException('meet_teacher_email_required');
        $classroom=$existing?:$this->createClassroomRow(array(
            'lesson_id'=>null,'schedule_version_id'=>null,'booking_confirmation_id'=>$confirmationId,
            'classroom_kind'=>'booking_confirmation','teacher_id'=>(int)$confirmation->teacher_id,
            'student_id'=>0,
            'teacher_email_digest'=>$this->digestEmail($teacherEmail),
            'student_email_digest'=>GoogleWorkspaceClassroomRule::validEmail($studentEmail)?$this->digestEmail($studentEmail):null,
            'starts_at_utc'=>(string)$confirmation->starts_at_utc,'ends_at_utc'=>(string)$confirmation->ends_at_utc,
            'schedule_timezone'=>(string)$confirmation->schedule_timezone,
        ));
        return $this->provision($classroom,'booking:'.$confirmationId,array(
            'summary'=>'Delnavazan introductory lesson',
            'description'=>'Delnavazan introductory lesson · '.$confirmation->uid,
            'teacher_name'=>(string)($confirmation->teacher_name??''),
            'student_name'=>(string)($confirmation->student_name??''),
            'course_name'=>'Delnavazan',
            'teacher_email'=>$teacherEmail,
            'student_email'=>$studentEmail,
            'starts_at_utc'=>(string)$confirmation->starts_at_utc,
            'ends_at_utc'=>(string)$confirmation->ends_at_utc,
            'timezone'=>(string)$confirmation->schedule_timezone,
            'lesson_id'=>null,'schedule_version_id'=>null,'lesson_uid'=>(string)$confirmation->uid,
        ),array('eligible'=>true,'reason'=>'booking_recording_default_on','evaluated_at'=>gmdate('Y-m-d H:i:s')));
    }

    /**
     * Ensure one organizer-owned Meet classroom for an explicitly classified God Mode test session.
     *
     * The test session is not a canonical Lesson and never becomes finance-bearing. Provisioning
     * still uses the same Calendar/Meet/co-host/recording pathway as production, so evidence is
     * genuine. A requested recording is configured now; activation and availability remain
     * separately verifiable and are never inferred from this call.
     */
    public function ensureForTestSession(int $testSessionId,array $session):array{
        if(!self::enabled())throw new \RuntimeException('meet_classroom_paused');
        if((string)($session['activity_type']??'')!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        if($testSessionId<1||(string)($session['starts_at_utc']??'')==='')throw new \InvalidArgumentException('god_mode_test_session_identity_required');
        $teacherEmail=$this->repository->teacherEmail((int)($session['teacher_id']??0));
        if(!GoogleWorkspaceClassroomRule::validEmail($teacherEmail))throw new \InvalidArgumentException('meet_teacher_email_required');
        $studentEmail=(string)($session['tester_email']??'');
        $recordingOn=!empty($session['recording_requested']);
        $existing=$this->repository->activeClassroomForTestSession($testSessionId);
        if($existing){
            $this->assertNotPermanentlyBlocked($existing);
            if(in_array((string)$existing->classroom_state,array('ready','rescheduled'),true)
                &&MeetCoHostRule::isReady((string)$existing->classroom_state,(string)$existing->cohost_state)){
                $references=$this->openReferences($existing);
                if($references!==null){
                    $result=$this->result($existing,$references,true);
                    $result['recording_configured']=(string)$existing->recording_state==='configured';
                    return $result;
                }
            }
        }
        $classroom=$existing?:$this->createClassroomRow(array(
            'test_session_id'=>$testSessionId,'lesson_id'=>null,'schedule_version_id'=>null,'booking_confirmation_id'=>null,
            'classroom_kind'=>'god_mode_test','teacher_id'=>(int)$session['teacher_id'],'student_id'=>(int)$session['student_id'],
            'teacher_email_digest'=>$this->digestEmail($teacherEmail),
            'student_email_digest'=>GoogleWorkspaceClassroomRule::validEmail($studentEmail)?$this->digestEmail($studentEmail):null,
            'starts_at_utc'=>(string)$session['starts_at_utc'],'ends_at_utc'=>(string)$session['ends_at_utc'],
            'schedule_timezone'=>(string)$session['schedule_timezone'],
        ));
        $eligibility=array(
            'eligible'=>$recordingOn,
            'reason'=>$recordingOn?'god_mode_test_recording_requested':'god_mode_test_recording_not_requested',
            'evaluated_at'=>gmdate('Y-m-d H:i:s'),
            'manual_verification_required'=>true,
        );
        return $this->provision($classroom,'god-mode-test:'.$testSessionId,array(
            'summary'=>'Delnavazan TEST class · '.$session['reference_code'],
            'description'=>'Explicit God Mode test activity · '.$session['reference_code'].' · not a production lesson',
            'teacher_name'=>(string)($session['teacher_name']??''),
            'student_name'=>(string)($session['student_name']??'Super Tester'),
            'course_name'=>(string)($session['course_name']??'Delnavazan test'),
            'teacher_email'=>$teacherEmail,
            'student_email'=>$studentEmail,
            'starts_at_utc'=>(string)$session['starts_at_utc'],
            'ends_at_utc'=>(string)$session['ends_at_utc'],
            'timezone'=>(string)$session['schedule_timezone'],
            'lesson_id'=>null,'schedule_version_id'=>null,'lesson_uid'=>(string)$session['reference_code'],
        ),$eligibility);
    }

    /** Cancel a test session's provider event without deleting classroom history or recordings. */
    public function cancelForTestSession(int $testSessionId):array{
        $classroom=$this->repository->activeClassroomForTestSession($testSessionId);
        if(!$classroom)$classroom=$this->repository->latestClassroomForTestSession($testSessionId);
        if(!$classroom||(string)$classroom->classroom_state==='cancelled')return array('cancelled'=>false,'reason'=>'classroom_not_found');
        $references=$this->openReferences($classroom);
        if($references!==null){
            $response=$this->api->cancelCalendarEvent((string)$references['provider_object_reference']);
            if(!in_array($response['status'],array(200,204,404,410),true))$this->assertOk($response,'meet_calendar_cancel_failed');
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array('classroom_state'=>'cancelled','active_slot'=>null,'cancelled_at'=>$now,'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'cancel','cancelled','god_mode_test_cancel',null);
        return array('cancelled'=>true,'classroom_id'=>(int)$classroom->id);
    }

    /** Move the single test classroom to a new interval; never creates a second Meet space. */
    public function rescheduleForTestSession(int $testSessionId,string $startsAtUtc,string $endsAtUtc,string $timezone):array{
        $classroom=$this->repository->activeClassroomForTestSession($testSessionId);
        if(!$classroom)throw new \InvalidArgumentException('god_mode_test_classroom_required');
        $references=$this->openReferences($classroom);
        if($references===null)throw new \InvalidArgumentException('god_mode_test_reference_required');
        $event=$this->api->updateCalendarEvent((string)$references['provider_object_reference'],array(
            'start'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339($startsAtUtc),'timeZone'=>$timezone),
            'end'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339($endsAtUtc),'timeZone'=>$timezone),
        ));
        if($event['status']<200||$event['status']>=300){
            $this->recordProviderFailure($classroom,$event,'calendar_projection','calendar_projection_failed');
            throw new \RuntimeException('god_mode_test_reschedule_failed');
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'starts_at_utc'=>$startsAtUtc,'ends_at_utc'=>$endsAtUtc,'schedule_timezone'=>$timezone,
            'classroom_state'=>'rescheduled','version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'update','rescheduled','god_mode_test_reschedule',$event);
        $fresh=$this->repository->classroom((int)$classroom->id);
        return $this->result($fresh,$references,false);
    }

    /** Open the vaulted Meet join URI for a test session after the caller re-proved authority. */
    public function joinUriForTestSession(int $testSessionId):string{
        $classroom=$this->repository->activeClassroomForTestSession($testSessionId);
        if(!$classroom||!MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??'')))throw new \RuntimeException('meet_classroom_unavailable');
        $uri=$this->openReferences($classroom);
        if($uri===null)throw new \RuntimeException('meet_classroom_unavailable');
        return (string)$uri['join_uri_reference'];
    }

    /**
     * Explicitly reopen one historical blocked booking classroom.
     *
     * This keeps the existing row, sealed Calendar/Meet references and provider identities. The
     * caller must have already established that a retry is appropriate; the row is never marked
     * ready here, and the normal provisioning path must still verify co-host membership.
     */
    private function resetBookingClassroomForRetry(object $classroom):object{
        $actor=function_exists('get_current_user_id')?(int)get_current_user_id():0;
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'provisioning','active_slot'=>1,
            'cohost_state'=>MeetCoHostRule::PENDING,'cohost_error_code'=>null,'cohost_error_detail'=>null,
            'cohost_attempt_count'=>0,'cohost_next_attempt_at'=>null,
            'cohost_exception_state'=>null,'cohost_exception_approved_by'=>null,
            'cohost_exception_approved_at'=>null,'cohost_exception_reason'=>null,
            'failure_reason_code'=>null,'last_provider_response_code'=>null,
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'retry_booking','reset','booking_projection_retry',null,$actor);
        return $this->repository->classroom((int)$classroom->id)??$classroom;
    }

    /** Reschedule an existing classroom without creating a second Meet space. */
    public function rescheduleLesson(int $lessonId,int $scheduleVersionId):array{
        $version=$this->repository->scheduleVersion($scheduleVersionId);
        if(!$version||(int)$version->lesson_id!==$lessonId||(int)$version->applicable_slot!==1||$version->superseded_at!==null)throw new \InvalidArgumentException('meet_schedule_version_unusable');
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)return $this->ensureForLesson($lessonId,$scheduleVersionId);
        $references=$this->openReferences($classroom);
        if($references===null)return $this->ensureForLesson($lessonId,$scheduleVersionId);
        $event=$this->api->updateCalendarEvent((string)$references['provider_object_reference'],array(
            'start'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$version->starts_at_utc),'timeZone'=>(string)$version->schedule_timezone),
            'end'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$version->ends_at_utc),'timeZone'=>(string)$version->schedule_timezone),
            'extendedProperties'=>array('private'=>array('dzn_schedule_version_id'=>(string)$scheduleVersionId)),
        ));
        if($event['status']<200||$event['status']>=300){
            $this->recordProviderFailure($classroom,$event,'calendar_projection','calendar_projection_failed');
            throw new \RuntimeException(MeetProvisioningRule::failureCode(
                'calendar_projection',
                MeetProvisioningRule::isRetryableResponse((int)$event['status'],$event['error_class']??null)
            ));
        }
        $now=gmdate('Y-m-d H:i:s');
        $state=MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??''))?'ready':(string)$classroom->classroom_state;
        $this->repository->updateClassroom((int)$classroom->id,array(
            'schedule_version_id'=>$scheduleVersionId,'starts_at_utc'=>(string)$version->starts_at_utc,
            'ends_at_utc'=>(string)$version->ends_at_utc,'schedule_timezone'=>(string)$version->schedule_timezone,
            'classroom_state'=>$state,'active_slot'=>in_array($state,array('ready','rescheduled'),true)?1:null,
            'cohost_attempt_count'=>0,'cohost_next_attempt_at'=>null,
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'update','ready',null,$event);
        $fresh=$this->repository->classroom((int)$classroom->id);
        return $this->result($fresh,$references,false);
    }

    /** Reschedule the one organizer-owned introductory event without creating a second space. */
    public function rescheduleForBooking(int $confirmationId,string $startsAtUtc,string $endsAtUtc,string $timezone):array{
        $confirmation=$this->repository->bookingConfirmation($confirmationId);
        if(!$confirmation)throw new \InvalidArgumentException('meet_booking_confirmation_required');
        $classroom=$this->repository->activeClassroomForBooking($confirmationId);
        if(!$classroom)return $this->ensureForBooking($confirmationId);
        $references=$this->openReferences($classroom);
        if($references===null)return $this->ensureForBooking($confirmationId);
        $event=$this->api->updateCalendarEvent((string)$references['provider_object_reference'],array(
            'start'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339($startsAtUtc),'timeZone'=>$timezone),
            'end'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339($endsAtUtc),'timeZone'=>$timezone),
            'extendedProperties'=>array('private'=>array('dzn_booking_confirmation_uid'=>(string)$confirmation->uid)),
        ));
        if($event['status']<200||$event['status']>=300){
            $this->recordProviderFailure($classroom,$event,'calendar_projection','calendar_projection_failed');
            throw new \RuntimeException(MeetProvisioningRule::failureCode(
                'calendar_projection',
                MeetProvisioningRule::isRetryableResponse((int)$event['status'],$event['error_class']??null)
            ));
        }
        $now=gmdate('Y-m-d H:i:s');
        $state=MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??''))?'ready':(string)$classroom->classroom_state;
        $this->repository->updateClassroom((int)$classroom->id,array(
            'starts_at_utc'=>$startsAtUtc,'ends_at_utc'=>$endsAtUtc,'schedule_timezone'=>$timezone,
            'classroom_state'=>$state,'cohost_attempt_count'=>0,'cohost_next_attempt_at'=>null,
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'update','rescheduled',null,$event);
        $fresh=$this->repository->classroom((int)$classroom->id);
        return $this->result($fresh,$references,false);
    }

    /** Replace the scheduled teacher and move the co-host role to the new teacher. */
    public function replaceTeacher(int $lessonId,int $newTeacherId):array{
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)throw new \InvalidArgumentException('meet_classroom_required');
        $references=$this->openReferences($classroom);
        if($references===null)throw new \InvalidArgumentException('meet_reference_required');
        if(!MeetCoHostRule::canManageMembers($references))throw new \RuntimeException('meet_cohost_calendar_space_unsupported');
        $email=$this->repository->teacherEmail($newTeacherId);
        if(!GoogleWorkspaceClassroomRule::validEmail($email))throw new \InvalidArgumentException('meet_teacher_email_required');
        $space=$this->spaceFor($references,$classroom);
        $member=$this->assignCoHost($space,$email);
        $now=gmdate('Y-m-d H:i:s');
        $attempt=(int)$classroom->cohost_attempt_count+1;
        if(($member['state']??'')!==MeetCoHostRule::VERIFIED){
            $retryable=($member['state']??'')===MeetCoHostRule::RETRYABLE&&!MeetProvisioningRule::cohostAttemptExhausted($attempt);
            $this->repository->updateClassroom((int)$classroom->id,array(
                'classroom_state'=>$retryable?'provisioning':'blocked','active_slot'=>$retryable?1:null,
                'cohost_state'=>$retryable?MeetCoHostRule::RETRYABLE:MeetCoHostRule::BLOCKED,
                'cohost_error_code'=>$member['reason']??'meet_cohost_assignment_failed',
                'cohost_error_detail'=>substr((string)($member['detail']??''),0,255),
                'cohost_attempt_count'=>$attempt,
                'cohost_next_attempt_at'=>$retryable?gmdate('Y-m-d H:i:s',time()+MeetCoHostRule::retryDelaySeconds($attempt)):null,
                'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
            ),array('id'=>(int)$classroom->id));
            $this->audit((int)$classroom->id,'replace_teacher',$retryable?'retryable':'blocked',$member['reason']??'meet_cohost_assignment_failed',$member['response']??null);
            $this->raiseClassroomAlert((int)$classroom->id,'cohost_assignment_failed',$member['reason']??'meet_cohost_assignment_failed',$member['detail']??null);
            throw new \RuntimeException($retryable?'meet_cohost_assignment_retryable':'meet_cohost_assignment_blocked');
        }
        $lesson=$this->repository->lessonWithParticipants($lessonId);
        if($lesson){
            $event=$this->api->updateCalendarEvent((string)$references['provider_object_reference'],array(
                'attendees'=>$this->attendanceEmails(array(
                    'teacher_email'=>$email,
                    'student_email'=>(string)($lesson->student_email??''),
                )),
                'extendedProperties'=>array('private'=>array(
                    'dzn_lesson_id'=>(string)$lessonId,
                    'dzn_schedule_version_id'=>(string)$classroom->schedule_version_id,
                )),
            ));
            if($event['status']<200||$event['status']>=300){
                $this->recordProviderFailure($classroom,$event,'calendar_projection','calendar_projection_failed');
                throw new \RuntimeException(MeetProvisioningRule::failureCode(
                    'calendar_projection',
                    MeetProvisioningRule::isRetryableResponse((int)$event['status'],$event['error_class']??null)
                ));
            }
        }
        $this->repository->updateClassroom((int)$classroom->id,array(
            'teacher_id'=>$newTeacherId,'teacher_email_digest'=>$this->digestEmail($email),
            'classroom_state'=>'ready','active_slot'=>1,'cohost_state'=>MeetCoHostRule::VERIFIED,
            'cohost_verified_at'=>$now,'cohost_error_code'=>null,'cohost_error_detail'=>null,
            'cohost_next_attempt_at'=>null,'cohost_attempt_count'=>0,'cohost_exception_state'=>null,'failure_reason_code'=>null,
            'last_provider_response_code'=>null,
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->removeOtherCoHosts($space,$email);
        $this->audit((int)$classroom->id,'replace_teacher','ready',$member['reason']??null,$member['response']??null);
        return array('classroom_id'=>(int)$classroom->id,'cohost'=>$member);
    }

    /** Cancel the provider event but keep the classroom history and recording artifacts. */
    public function cancelLesson(int $lessonId):array{
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)$classroom=$this->repository->latestClassroomForLesson($lessonId);
        if(!$classroom||(string)$classroom->classroom_state==='cancelled')return array('cancelled'=>false,'reason'=>'classroom_not_found');
        $references=$this->openReferences($classroom);
        if($references!==null){
            $response=$this->api->cancelCalendarEvent((string)$references['provider_object_reference']);
            if(!in_array($response['status'],array(200,204,404,410),true))$this->assertOk($response,'meet_calendar_cancel_failed');
            if(!empty($references['join_uri_reference'])){
                try{$this->api->endActiveConference($this->spaceFor($references));}catch(\Throwable){}
            }
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'cancelled','active_slot'=>null,'cancelled_at'=>$now,
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'cancel','cancelled',null,null);
        return array('cancelled'=>true,'classroom_id'=>(int)$classroom->id);
    }

    /** Cancel an introductory booking classroom without deleting its history. */
    public function cancelForBooking(int $confirmationId):array{
        $classroom=$this->repository->activeClassroomForBooking($confirmationId);
        if(!$classroom)$classroom=$this->repository->latestClassroomForBooking($confirmationId);
        if(!$classroom||(string)$classroom->classroom_state==='cancelled')return array('cancelled'=>false,'reason'=>'classroom_not_found');
        $references=$this->openReferences($classroom);
        if($references!==null){
            $response=$this->api->cancelCalendarEvent((string)$references['provider_object_reference']);
            if(!in_array($response['status'],array(200,204,404,410),true))$this->assertOk($response,'meet_calendar_cancel_failed');
            if(!empty($references['join_uri_reference'])){try{$this->api->endActiveConference($this->spaceFor($references));}catch(\Throwable){}}
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array('classroom_state'=>'cancelled','active_slot'=>null,'cancelled_at'=>$now,'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'cancel','cancelled',null,null);
        return array('cancelled'=>true,'classroom_id'=>(int)$classroom->id);
    }

    /** Re-evaluate recording configuration for one upcoming classroom. */
    public function syncRecordingConfiguration(int $classroomId):array{
        $classroom=$this->repository->classroom($classroomId);
        if(!$classroom||(int)$classroom->lesson_id<1)return array('updated'=>false,'reason'=>'classroom_not_found');
        if(!MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)$classroom->cohost_state)){
            return array('updated'=>false,'reason'=>'classroom_not_ready');
        }
        $eligibility=$this->recording->lessonRecordingEligibility((int)$classroom->lesson_id);
        $references=$this->openReferences($classroom);
        if($references===null)return array('updated'=>false,'reason'=>'reference_unavailable');
        $space=$this->spaceFor($references,$classroom);
        $response=$this->api->patchSpace($space,array('config'=>array(
            'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
            'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>!empty($eligibility['eligible'])?'ON':'OFF')),
        )));
        $now=gmdate('Y-m-d H:i:s');
        if($response['status']<200||$response['status']>=300){
            $detail=$this->providerDetail($response);
            $this->repository->updateClassroom($classroomId,array(
                'recording_state'=>'reconciliation_failed','failure_reason_code'=>'meet_recording_config_failed',
                'updated_at'=>$now,'updated_by'=>0,
            ),array('id'=>$classroomId));
            $this->audit($classroomId,'sync_recording','failed','meet_recording_config_failed',$response);
            $this->raiseClassroomAlert($classroomId,'recording_config_failed','meet_recording_config_failed',$detail);
            return array('updated'=>false,'reason'=>'meet_recording_config_failed','response_status'=>$response['status'],'error_class'=>$response['error_class']??null,'detail'=>$detail);
        }
        $this->repository->updateClassroom($classroomId,array(
            'recording_state'=>!empty($eligibility['eligible'])?'configured':'disabled',
            'recording_consent_digest'=>hash('sha256',wp_json_encode($eligibility,JSON_UNESCAPED_SLASHES) ?: ''),
            'recording_consent_at'=>$now,'failure_reason_code'=>null,
            'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>$classroomId));
        $this->audit($classroomId,'update',!empty($eligibility['eligible'])?'recording_enabled':'recording_disabled',$eligibility['reason']??null,$response);
        return array('updated'=>true,'recording_state'=>!empty($eligibility['eligible'])?'configured':'disabled','eligibility'=>$eligibility);
    }

    // ---- Provisioning -----------------------------------------------------------------------
    private function provision(object $classroom,string $identity,array $facts,array $eligibility):array{
        $references=$this->openReferences($classroom);
        if($references!==null&&!MeetCoHostRule::canManageMembers($references)){
            // Preserve the existing Calendar event and invitation, but replace its legacy
            // Calendar-created conference with an app-owned Meet space. The replacement space is
            // sealed before the Calendar PATCH so a retry can converge without creating a second
            // space after an ambiguous provider response.
            $references=$this->replaceLegacyCalendarSpace($classroom,$references);
        }
        if($references===null){
            $pending=$this->openSpaceReference($classroom);
            if($pending===null&&$this->hasProviderEvidence($classroom)){
                // A prior attempt already recorded provider identity but its sealed reference is
                // unavailable. Creating a new space here could orphan a second meeting while the
                // deterministic Calendar event still points at the old one.
                $this->blockMissingClassroomReference($classroom);
            }
            $space=$pending!==null?$this->resumeSpaceReference($classroom,$pending):$this->createOrConvergeSpace($classroom);
            $references=$pending!==null&&!empty($pending['replacement_event_reference'])
                ?$this->attachReplacementSpace($classroom,$pending,$space)
                :$this->createOrConvergeEvent($classroom,$identity,$facts,$space);
        }
        $space=$this->spaceFor($references,$classroom);
        $recordingOn=!empty($eligibility['eligible']);
        // Configure moderation and access before assigning the co-host. A space is only useful when
        // the teacher can join without knocking, and moderation is the feature that enables co-hosts
        // to co-manage the call. This also proves the space is writable by the organizer grant.
        $configResponse=$this->api->patchSpace($space,array('config'=>array(
            'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
            'moderation'=>'ON',
            'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>$recordingOn?'ON':'OFF')),
        )),'config.accessType,config.moderation,config.artifactConfig.recordingConfig.autoRecordingGeneration');
        if($configResponse['status']<200||$configResponse['status']>=300){
            $this->recordProviderFailure($classroom,$configResponse,'space_config','meeting_access_config_failed',MeetCoHostRule::PENDING);
            throw new \RuntimeException(MeetProvisioningRule::failureCode(
                'space_config',
                MeetProvisioningRule::isRetryableResponse((int)$configResponse['status'],$configResponse['error_class']??null)
            ));
        }
        $cohost=$this->assignCoHost($space,(string)$facts['teacher_email']);
        $now=gmdate('Y-m-d H:i:s');
        if(($cohost['state']??'')!==MeetCoHostRule::VERIFIED){
            $attempt=(int)$classroom->cohost_attempt_count+1;
            $transient=($cohost['state']??'')===MeetCoHostRule::RETRYABLE;
            $retryable=$transient&&!MeetProvisioningRule::cohostAttemptExhausted($attempt);
            $next=$retryable?gmdate('Y-m-d H:i:s',time()+MeetCoHostRule::retryDelaySeconds($attempt)):null;
            $reason=$retryable?'meet_cohost_assignment_retryable':'meet_cohost_assignment_blocked';
            $this->repository->updateClassroom((int)$classroom->id,array(
                'classroom_state'=>$retryable?'provisioning':'blocked','active_slot'=>$retryable?1:null,
                'cohost_state'=>$retryable?MeetCoHostRule::RETRYABLE:MeetCoHostRule::BLOCKED,
                'cohost_error_code'=>$cohost['reason']??'meet_cohost_assignment_failed',
                'cohost_error_detail'=>substr((string)($cohost['detail']??''),0,255),
                'cohost_attempt_count'=>$attempt,'cohost_next_attempt_at'=>$next,
                'last_provider_response_code'=>(int)($cohost['response']['status']??0),
                'recording_state'=>'disabled','failure_reason_code'=>$reason,
                'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
            ),array('id'=>(int)$classroom->id));
            $this->audit((int)$classroom->id,'create',$retryable?'retryable':'blocked',$cohost['reason']??'meet_cohost_assignment_failed',$cohost['response']??null);
            $this->raiseClassroomAlert((int)$classroom->id,'cohost_assignment_failed',$cohost['reason']??'meet_cohost_assignment_failed',$cohost['detail']??null);
            throw new \RuntimeException($reason);
        }
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'ready','active_slot'=>1,
            'space_name'=>$space,'cohost_state'=>MeetCoHostRule::VERIFIED,
            'cohost_verified_at'=>$now,'cohost_error_code'=>null,'cohost_error_detail'=>null,
            'cohost_attempt_count'=>1,'cohost_next_attempt_at'=>null,
            'recording_state'=>$recordingOn?'configured':'disabled',
            'recording_consent_digest'=>hash('sha256',wp_json_encode($eligibility,JSON_UNESCAPED_SLASHES) ?: ''),
            'recording_consent_at'=>$now,'version'=>(int)$classroom->version+1,
            'failure_reason_code'=>null,'last_provider_response_code'=>null,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','ready',null,$configResponse);
        $fresh=$this->repository->classroom((int)$classroom->id);
        $result=$this->result($fresh,$references,false);
        $result['cohost']=$cohost;
        $result['recording_configured']=$recordingOn;
        $result['recording_eligibility']=$eligibility;
        $result['warnings']=array();
        return $result;
    }

    /**
     * Create one app-owned Meet space, or reuse the pending space reference.
     *
     * `spaces.create` has no client-supplied idempotency key. The returned name is therefore sealed
     * and persisted before the Calendar event is attempted. A clear refusal or 429 may be retried;
     * an ambiguous transport/5xx response is never retried automatically because Google may have
     * created a space whose name was lost with the response.
     */
    private function createOrConvergeSpace(object $classroom):array{
        return $this->createOrConvergeSpaceWithContext($classroom,array());
    }

    /** Create and seal one app-owned space with optional legacy-event replacement context. */
    private function createOrConvergeSpaceWithContext(object $classroom,array $context):array{
        $replacementEventId='';
        $replacementOldCode='';
        if(!empty($context['replacement_event_reference'])){
            $replacementEventId=trim((string)$context['replacement_event_reference']);
            $replacementOldCode=trim((string)($context['replacement_from_conference_code']??''));
            if(!preg_match('/^[0-9a-v]{5,1024}$/D',$replacementEventId)||!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/D',$replacementOldCode)){
                throw new \InvalidArgumentException('meet_legacy_replacement_context_invalid');
            }
        }
        $response=$this->api->createSpace();
        if($response['status']<200||$response['status']>=300){
            $attempt=(int)($classroom->cohost_attempt_count??0)+1;
            $code=MeetProvisioningRule::spaceCreateFailureCode((int)($response['status']??0),$response['error_class']??null);
            $retryable=$code==='meet_space_create_retryable'&&!MeetProvisioningRule::cohostAttemptExhausted($attempt);
            if($code==='meet_space_create_retryable'&&!$retryable)$code='meet_space_create_ambiguous';
            $persisted=$this->recordProviderFailure($classroom,$response,'space_create','space_create_failed',null,$code,$retryable);
            throw new \RuntimeException($persisted);
        }
        $spaceName=trim((string)($response['body']['name']??''));
        $joinUri=trim((string)($response['body']['meetingUri']??''));
        if($spaceName===''||$joinUri===''){
            $this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        try{
            $space=GoogleWorkspaceClassroomRule::spaceName($spaceName);
            $join=GoogleWorkspaceClassroomRule::meetUri($joinUri);
            $meetingCode=GoogleWorkspaceClassroomRule::meetingCode($join);
        }catch(\Throwable){
            $this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        $providerCode=trim((string)($response['body']['meetingCode']??''));
        if($providerCode!==''&&!hash_equals($meetingCode,$providerCode)){
            $this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        $now=gmdate('Y-m-d H:i:s');
        $pending=array(
            'provider_object_reference'=>'','join_uri_reference'=>$join,
            'space_name'=>$space,'space_origin'=>MeetCoHostRule::SPACE_ORIGIN_APP,
            'space_organizer'=>GoogleWorkspaceClassroomRule::organizerEmail(),
        );
        if($replacementEventId!==''){
            $pending['replacement_event_reference']=$replacementEventId;
            $pending['replacement_from_conference_code']=$replacementOldCode;
        }
        $sealed=$this->vault->seal('meet_classroom',(int)$classroom->id,$pending);
        $previous=$this->repository->reference((int)$classroom->id);
        if($previous)$this->repository->retireReference((int)$previous->id,0,$now);
        $this->repository->insertReference(array_merge($sealed,array(
            'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'state'=>'active','active_slot'=>1,
            'created_at'=>$now,'created_by'=>0,'retired_at'=>null,'retired_by'=>null,
        )));
        $this->repository->updateClassroom((int)$classroom->id,array(
            'space_name'=>$space,'meet_uri_digest'=>hash('sha256','dzn-meet-uri:'.$join),
            'updated_at'=>$now,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','space_created',null,$response);
        return array('space_name'=>$space,'join_uri_reference'=>$join,'meeting_code'=>$meetingCode);
    }

    /**
     * Start recovery of a pre-repair Calendar-created Meet conference.
     *
     * The Calendar event id and old conference identity are carried into the durable pending
     * app-space reference. If the Calendar PATCH is interrupted, the next retry reuses the same
     * app space and event instead of orphaning another meeting or sending another invitation.
     */
    private function replaceLegacyCalendarSpace(object $classroom,array $legacy):array{
        $eventId=trim((string)($legacy['provider_object_reference']??''));
        $oldJoin=GoogleWorkspaceClassroomRule::meetUri((string)($legacy['join_uri_reference']??''));
        $oldCode=GoogleWorkspaceClassroomRule::meetingCode($oldJoin);
        if($eventId===''){
            $this->blockMissingClassroomReference($classroom);
        }
        $space=$this->createOrConvergeSpaceWithContext($classroom,array(
            'replacement_event_reference'=>$eventId,
            'replacement_from_conference_code'=>$oldCode,
        ));
        $pending=$this->openSpaceReference($classroom);
        if($pending===null)throw new \RuntimeException('meet_classroom_reference_missing');
        return $this->attachReplacementSpace($classroom,$pending,$space);
    }

    /** Patch the existing Calendar event to the durable replacement app space and verify it. */
    private function attachReplacementSpace(object $classroom,array $pending,array $space):array{
        $eventId=trim((string)($pending['replacement_event_reference']??''));
        $oldCode=trim((string)($pending['replacement_from_conference_code']??''));
        $spaceName=GoogleWorkspaceClassroomRule::spaceName((string)($space['space_name']??''));
        $join=GoogleWorkspaceClassroomRule::meetUri((string)($space['join_uri_reference']??''));
        $meetingCode=GoogleWorkspaceClassroomRule::meetingCode($join);
        if($eventId===''||$oldCode==='')throw new \RuntimeException('meet_legacy_replacement_context_invalid');

        $current=$this->api->getCalendarEvent($eventId);
        if($current['status']<200||$current['status']>=300){
            $failure=$this->recordProviderFailure($classroom,$current,'calendar_projection','calendar_projection_failed');
            throw new \RuntimeException($failure);
        }
        $currentCode=$this->conferenceIdentity($current['body']);
        if($currentCode===null||(!hash_equals($oldCode,$currentCode)&&!hash_equals($meetingCode,$currentCode))){
            $this->recordProviderFailure($classroom,$current,'calendar_conference','calendar_conference_mismatch',null,'meet_calendar_conference_mismatch',false);
            throw new \RuntimeException('meet_calendar_conference_mismatch');
        }

        $response=$current;
        if(!hash_equals($meetingCode,$currentCode)){
            $response=$this->api->updateCalendarEvent($eventId,array('conferenceData'=>array(
                'conferenceSolution'=>array('key'=>array('type'=>'hangoutsMeet')),
                'conferenceId'=>$meetingCode,
                'entryPoints'=>array(array(
                    'entryPointType'=>'video','uri'=>$join,'label'=>'meet.google.com/'.$meetingCode,
                )),
            )));
            if($response['status']<200||$response['status']>=300){
                $failure=$this->recordProviderFailure($classroom,$response,'calendar_projection','calendar_projection_failed');
                throw new \RuntimeException($failure);
            }
        }

        $attachedCode=$this->conferenceIdentity($response['body']);
        $eventJoin=$this->joinUri($response['body']);
        if($attachedCode===null||!hash_equals($meetingCode,$attachedCode)||$eventJoin===null
            ||!hash_equals($meetingCode,GoogleWorkspaceClassroomRule::meetingCode($eventJoin))){
            $read=$this->api->getCalendarEvent($eventId);
            if($read['status']>=200&&$read['status']<300){
                $response=$read;
                $attachedCode=$this->conferenceIdentity($read['body']);
                $eventJoin=$this->joinUri($read['body']);
            }
        }
        if($attachedCode===null||!hash_equals($meetingCode,$attachedCode)||$eventJoin===null
            ||!hash_equals($meetingCode,GoogleWorkspaceClassroomRule::meetingCode($eventJoin))){
            $this->recordProviderFailure($classroom,$response,'calendar_conference','calendar_conference_mismatch',null,'meet_calendar_conference_mismatch',false);
            throw new \RuntimeException('meet_calendar_conference_mismatch');
        }

        $references=array(
            'provider_object_reference'=>$eventId,'join_uri_reference'=>$eventJoin,
            'space_name'=>$spaceName,'space_origin'=>MeetCoHostRule::SPACE_ORIGIN_APP,
            'space_organizer'=>GoogleWorkspaceClassroomRule::organizerEmail(),
        );
        $sealed=$this->vault->seal('meet_classroom',(int)$classroom->id,$references);
        $now=gmdate('Y-m-d H:i:s');
        $previous=$this->repository->reference((int)$classroom->id);
        if($previous)$this->repository->retireReference((int)$previous->id,0,$now);
        $this->repository->insertReference(array_merge($sealed,array(
            'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'state'=>'active','active_slot'=>1,
            'created_at'=>$now,'created_by'=>0,'retired_at'=>null,'retired_by'=>null,
        )));
        $this->repository->updateClassroom((int)$classroom->id,array(
            'space_name'=>$spaceName,
            'event_id_digest'=>hash('sha256','dzn-meet-event:'.$eventId),
            'meet_uri_digest'=>hash('sha256','dzn-meet-uri:'.$eventJoin),
            'failure_reason_code'=>null,'last_provider_response_code'=>null,'updated_at'=>$now,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'update','legacy_space_replaced','meet_legacy_calendar_space_replaced',$response);
        return $references;
    }

    /** Re-prove a persisted app space before attaching its Calendar event. */
    private function resumeSpaceReference(object $classroom,array $references):array{
        $expected=GoogleWorkspaceClassroomRule::spaceName((string)($references['space_name']??''));
        $organizer=trim((string)($references['space_organizer']??''));
        if($organizer!==''&&!hash_equals(GoogleWorkspaceClassroomRule::organizerEmail(),$organizer)){
            $this->recordProviderFailure($classroom,array('status'=>0,'body'=>array(),'error_class'=>'identity_mismatch'),'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        $response=$this->api->getSpace($expected);
        if($response['status']<200||$response['status']>=300){
            $failure=$this->recordProviderFailure($classroom,$response,'space_lookup','space_lookup_failed');
            throw new \RuntimeException($failure);
        }
        $actual=trim((string)($response['body']['name']??''));
        if($actual===''||!hash_equals($expected,GoogleWorkspaceClassroomRule::spaceName($actual))){
            $this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        $join=$this->joinUri($response['body']);
        if($join===null)$join=GoogleWorkspaceClassroomRule::meetUri((string)($references['join_uri_reference']??''));
        $expectedJoin=GoogleWorkspaceClassroomRule::meetUri((string)($references['join_uri_reference']??''));
        if(GoogleWorkspaceClassroomRule::meetingCode($join)!==GoogleWorkspaceClassroomRule::meetingCode($expectedJoin)){
            $this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        return array('space_name'=>$expected,'join_uri_reference'=>$join,'meeting_code'=>GoogleWorkspaceClassroomRule::meetingCode($join));
    }

    private function createOrConvergeEvent(object $classroom,string $identity,array $facts,array $space):array{
        $eventId=GoogleWorkspaceClassroomRule::eventId($identity);
        $spaceName=GoogleWorkspaceClassroomRule::spaceName((string)($space['space_name']??''));
        $join=GoogleWorkspaceClassroomRule::meetUri((string)($space['join_uri_reference']??''));
        $meetingCode=GoogleWorkspaceClassroomRule::meetingCode($join);
        $attendance=$this->attendanceEmails($facts);
        $body=array(
            'id'=>$eventId,'summary'=>(string)$facts['summary'],
            'description'=>(string)($facts['description']??''),
            'start'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$facts['starts_at_utc']),'timeZone'=>(string)$facts['timezone']),
            'end'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$facts['ends_at_utc']),'timeZone'=>(string)$facts['timezone']),
            'attendees'=>$attendance,
            'guestsCanInviteOthers'=>false,'guestsCanModify'=>false,'guestsCanSeeOtherGuests'=>false,
            // Attach the existing app-created space. Calendar documents the
            // {conferenceSolution, entryPoints} form as the representation of existing conference
            // details; createRequest is only for asking Google to generate a new conference.
            'conferenceData'=>array(
                'conferenceSolution'=>array('key'=>array('type'=>'hangoutsMeet')),
                'conferenceId'=>$meetingCode,
                'entryPoints'=>array(array(
                    'entryPointType'=>'video','uri'=>$join,'label'=>'meet.google.com/'.$meetingCode,
                )),
            ),
            'extendedProperties'=>array('private'=>array(
                'dzn_lesson_id'=>$facts['lesson_id']===null?'':(string)$facts['lesson_id'],
                'dzn_schedule_version_id'=>$facts['schedule_version_id']===null?'':(string)$facts['schedule_version_id'],
                'dzn_classroom_uid'=>(string)$classroom->uid,
                'dzn_lesson_reference'=>(string)$facts['lesson_uid'],
            )),
        );
        $response=$this->api->createCalendarEvent($body);
        $created=false;
        if($response['status']===409){
            $response=$this->api->getCalendarEvent($eventId);
        }elseif($response['status']>=200&&$response['status']<300){
            $created=true;
        }
        if($response['status']<200||$response['status']>=300){
            $this->recordProviderFailure($classroom,$response,'calendar_projection','calendar_projection_failed');
            throw new \RuntimeException(MeetProvisioningRule::failureCode(
                'calendar_projection',
                MeetProvisioningRule::isRetryableResponse((int)$response['status'],$response['error_class']??null)
            ));
        }
        $reference=trim((string)($response['body']['id']??''));
        if($reference===''||!hash_equals($eventId,$reference)){
            $this->recordProviderFailure($classroom,$response,'calendar_identity','calendar_identity_mismatch',null,'meet_calendar_identity_mismatch',false);
            throw new \RuntimeException('meet_calendar_identity_mismatch');
        }
        $attachedCode=$this->conferenceIdentity($response['body']);
        if($attachedCode!==null&&!hash_equals($meetingCode,$attachedCode)){
            $this->recordProviderFailure($classroom,$response,'calendar_conference','calendar_conference_mismatch',null,'meet_calendar_conference_mismatch',false);
            throw new \RuntimeException('meet_calendar_conference_mismatch');
        }
        $eventJoin=$this->joinUri($response['body']);
        if($eventJoin===null){
            // Google may finish conference publication asynchronously. Re-read the exact event
            // instead of creating a second one; the retry worker converges on the same event id.
            $read=$this->api->getCalendarEvent($eventId);
            if($read['status']>=200&&$read['status']<300)$eventJoin=$this->joinUri($read['body']);
        }
        if($eventJoin===null){
            $attempt=(int)($classroom->cohost_attempt_count??0)+1;
            $retryable=!MeetProvisioningRule::cohostAttemptExhausted($attempt);
            $now=gmdate('Y-m-d H:i:s');
            $this->repository->updateClassroom((int)$classroom->id,array(
                'classroom_state'=>$retryable?'provisioning':'blocked','active_slot'=>$retryable?1:null,
                'cohost_attempt_count'=>$attempt,
                'cohost_next_attempt_at'=>$retryable?gmdate('Y-m-d H:i:s',time()+MeetCoHostRule::retryDelaySeconds($attempt)):null,
                'failure_reason_code'=>$retryable?'meet_conference_pending':'meet_conference_timeout',
                'last_provider_response_code'=>(int)($response['status']??0),
                'updated_at'=>$now,'updated_by'=>0,
            ),array('id'=>(int)$classroom->id));
            $this->audit((int)$classroom->id,'create',$retryable?'pending':'blocked',$retryable?'meet_conference_pending':'meet_conference_timeout',$response);
            if(!$retryable)$this->raiseClassroomAlert((int)$classroom->id,'conference_pending_timeout','meet_conference_timeout',$this->providerDetail($response));
            throw new \RuntimeException($retryable?'meet_conference_pending':'meet_conference_timeout');
        }
        if(GoogleWorkspaceClassroomRule::meetingCode($eventJoin)!==$meetingCode){
            $this->recordProviderFailure($classroom,$response,'calendar_conference','calendar_conference_mismatch',null,'meet_calendar_conference_mismatch',false);
            throw new \RuntimeException('meet_calendar_conference_mismatch');
        }
        $sealed=$this->vault->seal('meet_classroom',(int)$classroom->id,array(
            'provider_object_reference'=>$reference,'join_uri_reference'=>$eventJoin,
            'space_name'=>$spaceName,'space_origin'=>MeetCoHostRule::SPACE_ORIGIN_APP,
            'space_organizer'=>GoogleWorkspaceClassroomRule::organizerEmail(),
        ));
        $now=gmdate('Y-m-d H:i:s');
        $previous=$this->repository->reference((int)$classroom->id);
        if($previous)$this->repository->retireReference((int)$previous->id,0,$now);
        $this->repository->insertReference(array_merge($sealed,array(
            'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'state'=>'active','active_slot'=>1,
            'created_at'=>$now,'created_by'=>0,'retired_at'=>null,'retired_by'=>null,
        )));
        $this->repository->updateClassroom((int)$classroom->id,array(
            'event_id_digest'=>hash('sha256','dzn-meet-event:'.$reference),
            'meet_uri_digest'=>hash('sha256','dzn-meet-uri:'.$eventJoin),
            'updated_at'=>$now,
        ),array('id'=>(int)$classroom->id));
        if($created)$this->audit((int)$classroom->id,'create','created',null,$response);
        return array(
            'provider_object_reference'=>$reference,'join_uri_reference'=>$eventJoin,
            'space_name'=>$spaceName,'space_origin'=>MeetCoHostRule::SPACE_ORIGIN_APP,
            'space_organizer'=>GoogleWorkspaceClassroomRule::organizerEmail(),
        );
    }

    /** The conference identity Calendar attached to an event, if it reports one. */
    private function conferenceIdentity(array $event):?string{
        $code=trim((string)($event['conferenceData']['conferenceId']??''));
        if($code!=='')return $code;
        $uri=$this->joinUri($event);
        return $uri!==null?GoogleWorkspaceClassroomRule::meetingCode($uri):null;
    }

    /** Whether the row already records provider identity that must not be duplicated. */
    private function hasProviderEvidence(object $classroom):bool{
        return trim((string)($classroom->space_name??''))!==''
            ||trim((string)($classroom->event_id_digest??''))!==''
            ||trim((string)($classroom->meet_uri_digest??''))!=='';
    }

    /** Fail before any provider create when the row has evidence but no open reference. */
    private function blockMissingClassroomReference(object $classroom):void{
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'blocked','active_slot'=>null,
            'cohost_state'=>MeetCoHostRule::BLOCKED,
            'cohost_error_code'=>'meet_classroom_reference_missing',
            'cohost_error_detail'=>'The classroom records provider identity but its sealed reference is unavailable; refusing to create a second space or event.',
            'cohost_next_attempt_at'=>null,
            'failure_reason_code'=>'meet_classroom_reference_missing',
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','blocked','meet_classroom_reference_missing',null);
        $this->raiseClassroomAlert((int)$classroom->id,'classroom_reference_missing','meet_classroom_reference_missing','Provider identity exists but the sealed reference is unavailable.');
        throw new \RuntimeException('meet_classroom_reference_missing');
    }

    /** Keep a pre-repair Calendar-created classroom closed without touching its provider objects. */
    private function blockLegacyCalendarSpace(object $classroom):void{
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'blocked','active_slot'=>null,
            'cohost_state'=>MeetCoHostRule::BLOCKED,
            'cohost_error_code'=>'meet_cohost_calendar_space_unsupported',
            'cohost_error_detail'=>'The existing Meet space was created by Calendar; meetings.space.created only manages spaces created by this app.',
            'cohost_next_attempt_at'=>null,
            'failure_reason_code'=>'meet_cohost_calendar_space_unsupported',
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','blocked','meet_cohost_calendar_space_unsupported',null);
        $this->raiseClassroomAlert((int)$classroom->id,'cohost_calendar_space_unsupported','meet_cohost_calendar_space_unsupported','Calendar-created Meet space cannot be managed with meetings.space.created.');
        throw new \RuntimeException('meet_cohost_calendar_space_unsupported');
    }

    private function assignCoHost(string $space,string $email):array{
        $email=GoogleWorkspaceClassroomRule::email($email);
        $response=$this->api->createMember($space,$email,'COHOST');
        if($response['status']>=200&&$response['status']<300){
            $verified=$this->verifyCoHostMember($space,$email);
            if($verified!==null)return $verified;
            return array('state'=>MeetCoHostRule::RETRYABLE,'reason'=>'cohost_membership_not_visible','response'=>$response,'detail'=>$this->providerDetail($response));
        }
        // A retried create can have succeeded provider-side while the response was lost. Verify the
        // exact COHOST membership before classifying any non-2xx response as a failure.
        $verified=$this->verifyCoHostMember($space,$email);
        if($verified!==null)return $verified;
        if(MeetCoHostRule::isRetryableStatus((int)$response['status'])||MeetCoHostRule::isRetryableErrorClass($response['error_class']??null)){
            return array('state'=>MeetCoHostRule::RETRYABLE,'reason'=>'meet_cohost_assignment_retryable','response'=>$response,'detail'=>$this->providerDetail($response));
        }
        return array('state'=>MeetCoHostRule::BLOCKED,'reason'=>'meet_cohost_assignment_refused','response'=>$response,'detail'=>$this->providerDetail($response));
    }

    private function verifyCoHostMember(string $space,string $email):?array{
        $members=$this->api->listMembers($space);
        if($members['status']<200||$members['status']>=300)return null;
        foreach((array)($members['body']['members']??array()) as $member){
            if(!is_array($member))continue;
            if(strtolower((string)($member['email']??''))===strtolower($email)&&(string)($member['role']??'')==='COHOST'){
                return array('state'=>MeetCoHostRule::VERIFIED,'reason'=>'cohost_verified','response'=>$members);
            }
        }
        return null;
    }

    /**
     * Explicit, audited retry of one lesson projection.
     *
     * Preconditions are checked while the lesson and applicable schedule are locked. An active row is
     * reset with a version compare-and-swap; an inactive historical row is never reactivated. When no
     * active row exists, a new idempotent projection is created after the transaction commits.
     */
    public function retryClassroom(int $lessonId,int $actorUserId,string $reason,string $reasonCode='manual_retry_authorized'):array{
        if(!function_exists('current_user_can')||(!current_user_can('dzn_manage_lessons')&&!current_user_can('manage_options')))throw new \RuntimeException('Unauthorized');
        if(!MeetClassroomRetryRule::reasonRequired($actorUserId,$reason))throw new \InvalidArgumentException('meet_retry_reason_required');
        $reasonCode=preg_match('/^[a-z0-9_]{3,64}$/D',$reasonCode)?$reasonCode:'manual_retry_authorized';
        $lock='dzn_meet_retry_lesson_'.$lessonId;
        if(!$this->repository->acquireLock($lock,10))throw new \RuntimeException('meet_retry_busy');
        try{
            $classroomId=0;$reset=false;$alreadyReady=false;
            $this->repository->begin();
            try{
                $lesson=$this->repository->lessonWithParticipants($lessonId,true);
                if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('meet_lesson_required');
                $version=$this->repository->currentScheduleVersion($lessonId,true);
                $active=$this->repository->activeClassroomForLesson($lessonId,true);
                $latest=$this->repository->latestClassroomForLesson($lessonId);
                $eligibility=MeetClassroomRetryRule::lessonEligibility(
                    (string)($lesson->lifecycle_state??''),(string)$lesson->status,$lesson->archived_at!==null,
                    $version!==null?(string)$version->ends_at_utc:null,time(),$version!==null
                );
                if($eligibility!==MeetClassroomRetryRule::ELIGIBLE)throw new \InvalidArgumentException($eligibility);
                $scheduleRow=$active?:$latest;
                if($scheduleRow&&(int)$scheduleRow->schedule_version_id!==(int)$version->id)throw new \InvalidArgumentException('meet_classroom_schedule_stale');
                $selection=MeetClassroomRetryRule::classroomSelection($active,$latest);
                if($selection==='reject_history')throw new \InvalidArgumentException('meet_classroom_history_not_retryable');
                if($selection==='already_ready'){
                    $alreadyReady=true;
                    $classroomId=(int)$active->id;
                }elseif($selection===MeetClassroomRetryRule::RESET_ACTIVE){
                    $classroomId=(int)$active->id;
                    $updated=$this->repository->updateClassroom($classroomId,array(
                        'classroom_state'=>'provisioning','cohost_state'=>MeetCoHostRule::PENDING,
                        'cohost_error_code'=>null,'cohost_error_detail'=>null,'cohost_next_attempt_at'=>null,
                        'cohost_attempt_count'=>0,'failure_reason_code'=>null,'last_provider_response_code'=>null,
                        'cohost_exception_state'=>null,'cohost_exception_approved_by'=>null,
                        'cohost_exception_approved_at'=>null,'cohost_exception_reason'=>null,
                        'version'=>(int)$active->version+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>$actorUserId,
                    ),array('id'=>$classroomId,'version'=>(int)$active->version,'active_slot'=>1));
                    if($updated!==1)throw new \RuntimeException('meet_classroom_retry_conflict');
                    $reset=true;
                    $this->auditRetry($classroomId,$actorUserId,$reasonCode,$reason,'provisioning');
                }elseif($selection===MeetClassroomRetryRule::RESET_LATEST&&$latest!==null){
                    // A blocked/failed lesson with no active slot may already own the deterministic
                    // Calendar event and an app-space reference. Re-open that exact row instead of
                    // creating a new projection that would create another space before Calendar
                    // convergence revealed the old event.
                    $classroomId=(int)$latest->id;
                    $updated=$this->repository->updateClassroom($classroomId,array(
                        'classroom_state'=>'provisioning','active_slot'=>1,'cohost_state'=>MeetCoHostRule::PENDING,
                        'cohost_error_code'=>null,'cohost_error_detail'=>null,'cohost_next_attempt_at'=>null,
                        'cohost_attempt_count'=>0,'failure_reason_code'=>null,'last_provider_response_code'=>null,
                        'cohost_exception_state'=>null,'cohost_exception_approved_by'=>null,
                        'cohost_exception_approved_at'=>null,'cohost_exception_reason'=>null,
                        'version'=>(int)$latest->version+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>$actorUserId,
                    ),array('id'=>$classroomId,'version'=>(int)$latest->version));
                    if($updated!==1)throw new \RuntimeException('meet_classroom_retry_conflict');
                    $reset=true;
                    $this->auditRetry($classroomId,$actorUserId,$reasonCode,$reason,'provisioning');
                }
                $this->repository->commit();
            }catch(\Throwable $error){
                $this->repository->rollback();
                throw $error;
            }
            if($alreadyReady){
                return array('lesson_id'=>$lessonId,'classroom_id'=>$classroomId,'state'=>'ready','idempotent'=>true,'reset'=>false);
            }
            try{
                $result=$this->ensureForLesson($lessonId,(int)$version->id);
            }catch(\Throwable $error){
                if($classroomId<1){
                    $failedRow=$this->repository->latestClassroomForLesson($lessonId);
                    $classroomId=$failedRow?(int)$failedRow->id:0;
                }
                if($classroomId>0)$this->auditRetry($classroomId,$actorUserId,$reasonCode,$reason,'failed');
                throw $error;
            }
            $classroomId=(int)($result['classroom_id']??$classroomId);
            $this->auditRetry($classroomId,$actorUserId,$reasonCode,$reason,(string)($result['state']??'ready'));
            return array('lesson_id'=>$lessonId,'classroom_id'=>$classroomId,'state'=>(string)($result['state']??'ready'),'idempotent'=>false,'reset'=>$reset);
        }finally{
            $this->repository->releaseLock($lock);
        }
    }

    /** Administrator-approved exception; never an automatic fallback. */
    public function approveCoHostException(int $lessonId,int $approverUserId,string $reasonCode,string $reasonText):array{
        if(!function_exists('current_user_can')||(!current_user_can('dzn_manage_lessons')&&!current_user_can('manage_options')))throw new \RuntimeException('Unauthorized');
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)$classroom=$this->repository->latestClassroomForLesson($lessonId);
        if(!$classroom)throw new \InvalidArgumentException('meet_classroom_required');
        if((string)$classroom->classroom_state==='cancelled')throw new \InvalidArgumentException('meet_classroom_not_active');
        if(!MeetCoHostRule::exceptionAllowed($approverUserId,$reasonText,'1'))throw new \InvalidArgumentException('cohost_exception_reason_required');
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'ready','active_slot'=>1,'cohost_state'=>MeetCoHostRule::EXCEPTION_APPROVED,
            'cohost_exception_state'=>'approved','cohost_exception_approved_by'=>$approverUserId,
            'cohost_exception_approved_at'=>$now,'cohost_exception_reason'=>substr(trim($reasonText),0,255),
            'cohost_next_attempt_at'=>null,'cohost_attempt_count'=>0,
            'failure_reason_code'=>'cohost_exception_approved','version'=>(int)$classroom->version+1,
            'updated_at'=>$now,'updated_by'=>$approverUserId,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','exception_approved',substr($reasonCode,0,64),null);
        return array('classroom_id'=>(int)$classroom->id,'cohost_state'=>MeetCoHostRule::EXCEPTION_APPROVED,'approved_by'=>$approverUserId);
    }

    /** A permanent refusal remains blocked until an operator explicitly resets it. */
    private function assertNotPermanentlyBlocked(object $classroom):void{
        $cohost=(string)($classroom->cohost_state??MeetCoHostRule::PENDING);
        $failure=trim((string)($classroom->failure_reason_code??''));
        if($cohost===MeetCoHostRule::BLOCKED)throw new \RuntimeException('meet_cohost_assignment_blocked');
        if((string)$classroom->classroom_state==='blocked')throw new \RuntimeException($failure!==''?$failure:'meet_provisioning_blocked');
        if(MeetProvisioningRule::isPermanentCode($failure))throw new \RuntimeException($failure);
    }

    /** Persist the exact provider response and make failure visibility actionable. */
    private function recordProviderFailure(object $classroom,array $response,string $operation,string $alertCode,?string $cohostState=null,?string $codeOverride=null,?bool $retryableOverride=null):string{
        $attempt=(int)($classroom->cohost_attempt_count??0)+1;
        $retryable=$retryableOverride??(MeetProvisioningRule::isRetryableResponse((int)($response['status']??0),$response['error_class']??null)
            &&!MeetProvisioningRule::cohostAttemptExhausted($attempt));
        $code=$codeOverride??MeetProvisioningRule::failureCode($operation,$retryable);
        $now=gmdate('Y-m-d H:i:s');
        $data=array(
            'classroom_state'=>$retryable?'provisioning':'blocked',
            'active_slot'=>$retryable?1:null,
            'failure_reason_code'=>$code,
            'last_provider_response_code'=>(int)($response['status']??0),
            'cohost_attempt_count'=>$attempt,
            'cohost_next_attempt_at'=>$retryable?gmdate('Y-m-d H:i:s',time()+MeetCoHostRule::retryDelaySeconds($attempt)):null,
            'recording_state'=>'disabled',
            'version'=>(int)$classroom->version+1,
            'updated_at'=>$now,
            'updated_by'=>0,
        );
        if($cohostState!==null){
            $data['cohost_state']=$cohostState;
            if($cohostState===MeetCoHostRule::VERIFIED)$data['cohost_verified_at']=$now;
        }
        $this->repository->updateClassroom((int)$classroom->id,$data,array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,$operation==='calendar_projection'?'create':'update',$retryable?'retryable':'blocked',$code,$response);
        $this->raiseClassroomAlert((int)$classroom->id,$alertCode,$code,$this->providerDetail($response));
        return $code;
    }

    private function removeOtherCoHosts(string $space,string $keepEmail):void{
        $members=$this->api->listMembers($space);
        if($members['status']<200||$members['status']>=300)return;
        foreach((array)($members['body']['members']??array()) as $member){
            if(!is_array($member)||(string)($member['role']??'')!=='COHOST')continue;
            $memberEmail=strtolower((string)($member['email']??''));
            if($memberEmail===''||$memberEmail===strtolower($keepEmail))continue;
            $name=(string)($member['name']??'');
            if($name!=='')$this->api->deleteMember($name);
        }
    }

    private function attendanceEmails(array $facts):array{
        $attendees=array();
        if(GoogleWorkspaceClassroomRule::validEmail((string)($facts['teacher_email']??'')))$attendees[]=array('email'=>(string)$facts['teacher_email'],'optional'=>false,'responseStatus'=>'needsAction');
        if(GoogleWorkspaceClassroomRule::validEmail((string)($facts['student_email']??'')))$attendees[]=array('email'=>(string)$facts['student_email'],'optional'=>false,'responseStatus'=>'needsAction');
        return $attendees;
    }

    private function joinUri(array $event):?string{
        $uri=trim((string)($event['hangoutLink']??''));
        if($uri===''){
            foreach((array)($event['conferenceData']['entryPoints']??array()) as $entry){
                if(!is_array($entry)||(string)($entry['entryPointType']??'')!=='video')continue;
                $uri=trim((string)($entry['uri']??''));
                if($uri!=='')break;
            }
        }
        return $uri!==''?GoogleWorkspaceClassroomRule::meetUri($uri):null;
    }

    private function spaceFor(array $references,?object $classroom=null):string{
        $organizer=trim((string)($references['space_organizer']??''));
        if($organizer!==''&&!hash_equals(GoogleWorkspaceClassroomRule::organizerEmail(),$organizer)){
            if($classroom!==null){
                $failure=$this->recordProviderFailure($classroom,array('status'=>0,'body'=>array(),'error_class'=>'identity_mismatch'),'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
                throw new \RuntimeException($failure);
            }
            throw new \RuntimeException('meet_space_identity_mismatch');
        }
        $code=GoogleWorkspaceClassroomRule::meetingCode((string)$references['join_uri_reference']);
        $expected=trim((string)($references['space_name']??''));
        $lookup=$expected!==''?GoogleWorkspaceClassroomRule::spaceName($expected):$code;
        $response=$this->api->getSpace($lookup);
        if($response['status']<200||$response['status']>=300){
            if($classroom!==null){
                // The persisted state and the thrown code must share one bounded decision.
                $failure=$this->recordProviderFailure($classroom,$response,'space_lookup','space_lookup_failed');
                throw new \RuntimeException($failure);
            }
            $this->assertOk($response,'meet_space_lookup_failed');
        }
        $name=trim((string)($response['body']['name']??''));
        if($name!==''){
            $resolved=GoogleWorkspaceClassroomRule::spaceName($name);
            if($expected!==''&&!hash_equals($expected,$resolved)){
                if($classroom!==null){
                    $failure=$this->recordProviderFailure($classroom,$response,'space_identity','space_identity_mismatch',null,'meet_space_identity_mismatch',false);
                    throw new \RuntimeException($failure);
                }
                throw new \RuntimeException('meet_space_identity_mismatch');
            }
            return $resolved;
        }
        // The meeting code is a documented alias for the same space, but member operations require
        // the exact spaces/{space} resource name. Never derive that name from the meeting code.
        if($expected!=='')return GoogleWorkspaceClassroomRule::spaceName($expected);
        throw new \RuntimeException('meet_space_identity_mismatch');
    }

    private function createClassroomRow(array $data):object{
        $now=gmdate('Y-m-d H:i:s');
        $id=$this->repository->insertClassroom(array_merge(array(
            'uid'=>Identifier::uid(),'organizer_email'=>GoogleWorkspaceClassroomRule::organizerEmail(),
            'calendar_id'=>GoogleWorkspaceClassroomRule::organizerEmail(),
            'event_id_digest'=>null,'space_name'=>null,'meet_uri_digest'=>null,
            'conference_record_name'=>null,'classroom_state'=>'provisioning','active_slot'=>1,
            'recording_state'=>'disabled','recording_consent_digest'=>null,'recording_consent_at'=>null,
            'version'=>1,'failure_reason_code'=>null,'last_provider_response_code'=>null,
            'created_at'=>$now,'created_by'=>0,'updated_at'=>$now,'updated_by'=>0,'cancelled_at'=>null,
        ),$data));
        $row=$this->repository->classroom($id);
        if(!$row)throw new \RuntimeException('meet_classroom_persistence_failed');
        return $row;
    }

    private function openReferences(object $classroom):?array{
        $row=$this->repository->reference((int)$classroom->id);
        if(!$row||(string)$row->state!=='active')return null;
        try{
            $references=$this->vault->open('meet_classroom',(int)$classroom->id,$row);
            if(empty($references['provider_object_reference'])||empty($references['join_uri_reference']))return null;
            return $references;
        }catch(\Throwable){return null;}
    }

    /**
     * Open the sealed app-space reference created before the Calendar event exists.
     *
     * A full reference is handled by openReferences(); this method is only for the intermediate
     * state where the space name and join URI are durable but the Calendar event still needs to be
     * created or converged. Legacy Calendar-created references never carry the app origin marker.
     */
    private function openSpaceReference(object $classroom):?array{
        $row=$this->repository->reference((int)$classroom->id);
        if(!$row||(string)$row->state!=='active')return null;
        try{
            $references=$this->vault->open('meet_classroom',(int)$classroom->id,$row);
        }catch(\Throwable){return null;}
        if(!MeetCoHostRule::isAppOwnedReference($references))return null;
        if(!empty($references['provider_object_reference'])||empty($references['join_uri_reference']))return null;
        return $references;
    }

    private function teacherEmailForClassroom(object $classroom):string{
        $teacher=$this->repository->lessonWithParticipants((int)$classroom->lesson_id);
        return (string)($teacher->teacher_email??'');
    }

    private function result(object $classroom,array $references,bool $idempotent):array{
        return array(
            'classroom_id'=>(int)$classroom->id,'classroom_uid'=>(string)$classroom->uid,
            'state'=>(string)$classroom->classroom_state,
            'provider_object_reference'=>(string)$references['provider_object_reference'],
            'join_uri_reference'=>(string)$references['join_uri_reference'],
            'provider_code'=>'google_calendar','recording_state'=>(string)$classroom->recording_state,
            'cohost_state'=>(string)($classroom->cohost_state??MeetCoHostRule::PENDING),
            'cohost_ready'=>MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??'')),
            'idempotent'=>$idempotent,
        );
    }

    private function assertOk(array $response,string $code):void{
        if($response['status']<200||$response['status']>=300)throw new \RuntimeException($code);
    }

    private function digestEmail(string $email):string{
        return hash('sha256','dzn-meet-participant:'.strtolower(trim($email)));
    }

    private function providerDetail(array $response):?string{
        $status=(int)($response['status']??0);
        $class=trim((string)($response['error_class']??''));
        $message=trim((string)($response['error_message']??''));
        $detail=trim(($status>0?'HTTP '.$status:'HTTP 0').' '.$class.($message!==''?' · '.$message:''));
        $detail=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[redacted-email]',$detail)??'';
        return $detail!==''?substr($detail,0,255):null;
    }

    private function raiseClassroomAlert(int $classroomId,string $code,string $detail,?string $detailText):void{
        try{
            if($this->repository->openAlert($classroomId,$code))return;
            $this->repository->insertAlert(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'alert_code'=>$code,
                'detail_code'=>substr($detail,0,64),'detail_text'=>$detailText!==null?substr($detailText,0,255):null,
                'state'=>'open','created_at'=>gmdate('Y-m-d H:i:s'),'acknowledged_at'=>null,'acknowledged_by'=>null,
            ));
            if(function_exists('do_action'))do_action('dzn_meet_classroom_alert',$classroomId,$code,$detail,$detailText);
        }catch(\Throwable){}
    }

    private function auditRetry(int $classroomId,int $actorUserId,string $reasonCode,string $reason,string $state):void{
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>'retry_classroom',
                'command_digest'=>hash('sha256','meet-retry:'.$classroomId.':'.$actorUserId.':'.microtime(true).':'.random_int(1,PHP_INT_MAX)),
                'result_state'=>$state,'reason_code'=>$reasonCode,
                'provider_http_status'=>null,'provider_error_class'=>null,
                'provider_detail'=>substr(trim($reason),0,255),
                'payload_digest'=>hash('sha256','retry|'.$classroomId.'|'.$reasonCode.'|'.$state),
                'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$actorUserId,
            ));
        }catch(\Throwable){}
    }

    private function audit(int $classroomId,string $operation,string $state,?string $reason,?array $response,?int $actor=null):void{
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>$operation,
                'command_digest'=>hash('sha256','meet-event:'.$operation.':'.$classroomId.':'.microtime(true).':'.random_int(1,PHP_INT_MAX)),
                'result_state'=>$state,'reason_code'=>$reason,
                'provider_http_status'=>isset($response['status'])?(int)$response['status']:null,
                'provider_error_class'=>isset($response['error_class'])?$response['error_class']:null,
                'provider_detail'=>isset($response['error_message'])?substr((string)$response['error_message'],0,255):null,
                'payload_digest'=>hash('sha256',(string)wp_json_encode($response['body']??array(),JSON_UNESCAPED_SLASHES) ?: ''),
                'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$actor??0,
            ));
        }catch(\Throwable){}
    }
}
