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
        $existing=$this->repository->activeClassroomForBooking($confirmationId);
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
        ),array('eligible'=>false,'reason'=>'booking_recording_not_configured','evaluated_at'=>gmdate('Y-m-d H:i:s')));
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
        $email=$this->repository->teacherEmail($newTeacherId);
        if(!GoogleWorkspaceClassroomRule::validEmail($email))throw new \InvalidArgumentException('meet_teacher_email_required');
        $space=$this->spaceFor($references);
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
        $space=$this->spaceFor($references);
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
        if($references===null)$references=$this->createOrConvergeEvent($classroom,$identity,$facts);
        $space=$this->spaceFor($references);
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
        $recordingOn=!empty($eligibility['eligible']);
        $configResponse=$this->api->patchSpace($space,array('config'=>array(
            'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
            'moderation'=>'ON',
            'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>$recordingOn?'ON':'OFF')),
        )),'config.accessType,config.moderation,config.artifactConfig.recordingConfig.autoRecordingGeneration');
        if($configResponse['status']<200||$configResponse['status']>=300){
            $this->recordProviderFailure($classroom,$configResponse,'space_config','meeting_access_config_failed',MeetCoHostRule::VERIFIED);
            throw new \RuntimeException(MeetProvisioningRule::failureCode(
                'space_config',
                MeetProvisioningRule::isRetryableResponse((int)$configResponse['status'],$configResponse['error_class']??null)
            ));
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

    private function createOrConvergeEvent(object $classroom,string $identity,array $facts):array{
        $eventId=GoogleWorkspaceClassroomRule::eventId($identity);
        $attendance=$this->attendanceEmails($facts);
        $body=array(
            'id'=>$eventId,'summary'=>(string)$facts['summary'],
            'description'=>(string)($facts['description']??''),
            'start'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$facts['starts_at_utc']),'timeZone'=>(string)$facts['timezone']),
            'end'=>array('dateTime'=>GoogleWorkspaceClassroomRule::rfc3339((string)$facts['ends_at_utc']),'timeZone'=>(string)$facts['timezone']),
            'attendees'=>$attendance,
            'guestsCanInviteOthers'=>false,'guestsCanModify'=>false,'guestsCanSeeOtherGuests'=>false,
            'conferenceData'=>array('createRequest'=>array(
                'requestId'=>substr(hash('sha256','dzn-meet:'.$identity),0,40),
                'conferenceSolutionKey'=>array('type'=>'hangoutsMeet'),
            )),
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
        if($reference===''||!hash_equals($eventId,$reference))throw new \RuntimeException('meet_calendar_identity_mismatch');
        $join=$this->joinUri($response['body']);
        if($join===null){
            // Google may finish conference creation asynchronously. Re-read the exact event instead
            // of creating a second one; the retry worker converges on the same event id.
            $read=$this->api->getCalendarEvent($eventId);
            if($read['status']>=200&&$read['status']<300)$join=$this->joinUri($read['body']);
        }
        if($join===null){
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
        $sealed=$this->vault->seal('meet_classroom',(int)$classroom->id,array(
            'provider_object_reference'=>$reference,'join_uri_reference'=>$join,
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
            'meet_uri_digest'=>hash('sha256','dzn-meet-uri:'.$join),
            'updated_at'=>$now,
        ),array('id'=>(int)$classroom->id));
        if($created)$this->audit((int)$classroom->id,'create','created',null,$response);
        return array('provider_object_reference'=>$reference,'join_uri_reference'=>$join);
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
    private function recordProviderFailure(object $classroom,array $response,string $operation,string $alertCode,?string $cohostState=null):void{
        $attempt=(int)($classroom->cohost_attempt_count??0)+1;
        $retryable=MeetProvisioningRule::isRetryableResponse((int)($response['status']??0),$response['error_class']??null)
            &&!MeetProvisioningRule::cohostAttemptExhausted($attempt);
        $code=MeetProvisioningRule::failureCode($operation,$retryable);
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

    private function spaceFor(array $references):string{
        $code=GoogleWorkspaceClassroomRule::meetingCode((string)$references['join_uri_reference']);
        $response=$this->api->getSpace($code);
        if($response['status']<200||$response['status']>=300)$this->assertOk($response,'meet_space_lookup_failed');
        $name=$response['body']['name']??'';
        if(is_string($name)&&$name!=='')return GoogleWorkspaceClassroomRule::spaceName($name);
        // The meeting code is a documented alias for the same space.
        return 'spaces/'.$code;
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

    private function audit(int $classroomId,string $operation,string $state,?string $reason,?array $response):void{
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>$operation,
                'command_digest'=>hash('sha256','meet-event:'.$operation.':'.$classroomId.':'.microtime(true).':'.random_int(1,PHP_INT_MAX)),
                'result_state'=>$state,'reason_code'=>$reason,
                'provider_http_status'=>isset($response['status'])?(int)$response['status']:null,
                'provider_error_class'=>isset($response['error_class'])?$response['error_class']:null,
                'provider_detail'=>isset($response['error_message'])?substr((string)$response['error_message'],0,255):null,
                'payload_digest'=>hash('sha256',(string)wp_json_encode($response['body']??array(),JSON_UNESCAPED_SLASHES) ?: ''),
                'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>0,
            ));
        }catch(\Throwable){}
    }
}
