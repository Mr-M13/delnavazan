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
        if(defined('DZN_MEET_CLASSROOM_ENABLED')){
            $value=constant('DZN_MEET_CLASSROOM_ENABLED');
            return !in_array(strtolower(trim((string)$value)),array('0','false','off','no'),true);
        }
        $env=getenv('DZN_MEET_CLASSROOM_ENABLED');
        if(is_string($env)&&trim($env)!=='')return !in_array(strtolower(trim($env)),array('0','false','off','no'),true);
        return true;
    }

    public static function configured():bool{
        try{
            if(!self::enabled())return false;
            if(!GoogleWorkspaceOAuthService::configured())return false;
            $health=(new GoogleWorkspaceOAuthService())->health();
            return (string)($health['state']??'')==='connected';
        }catch(\Throwable){return false;}
    }

    /** Ensure a canonical term lesson has one ready organizer-owned Meet classroom. */
    public function ensureForLesson(int $lessonId,int $scheduleVersionId):array{
        $lesson=$this->repository->lessonWithParticipants($lessonId);
        if(!$lesson)throw new \InvalidArgumentException('meet_lesson_required');
        $version=$this->repository->scheduleVersion($scheduleVersionId);
        if(!$version||(int)$version->lesson_id!==$lessonId||(int)$version->applicable_slot!==1||$version->superseded_at!==null)throw new \InvalidArgumentException('meet_schedule_version_unusable');
        if((string)$lesson->status==='cancelled'||$lesson->archived_at!==null)throw new \InvalidArgumentException('meet_lesson_not_active');
        $teacherEmail=(string)($lesson->teacher_email??'');
        $studentEmail=(string)($lesson->student_email??'');
        if(!GoogleWorkspaceClassroomRule::validEmail($teacherEmail))throw new \InvalidArgumentException('meet_teacher_email_required');
        $existing=$this->repository->activeClassroomForLesson($lessonId);
        if($existing&&in_array((string)$existing->classroom_state,array('ready','rescheduled'),true)&&(int)$existing->schedule_version_id===$scheduleVersionId){
            $references=$this->openReferences($existing);
            if($references!==null)return $this->result($existing,$references,true);
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
        $confirmation=$this->repository->bookingConfirmation($confirmationId);
        if(!$confirmation)throw new \InvalidArgumentException('meet_booking_confirmation_required');
        if((string)$confirmation->state==='confirmed'){
            $existing=$this->repository->activeClassroomForBooking($confirmationId);
            if($existing){
                $references=$this->openReferences($existing);
                if($references!==null)return $this->result($existing,$references,true);
            }
        }
        $teacherEmail=(string)($confirmation->teacher_email??'');
        $studentEmail=(string)($confirmation->student_email??'');
        if(!GoogleWorkspaceClassroomRule::validEmail($teacherEmail))throw new \InvalidArgumentException('meet_teacher_email_required');
        $existing=$this->repository->activeClassroomForBooking($confirmationId);
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
        $this->assertOk($event,'meet_calendar_update_failed');
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'schedule_version_id'=>$scheduleVersionId,'starts_at_utc'=>(string)$version->starts_at_utc,
            'ends_at_utc'=>(string)$version->ends_at_utc,'schedule_timezone'=>(string)$version->schedule_timezone,
            'classroom_state'=>'ready','version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'update','ready',null,$event);
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
        $this->removeOtherCoHosts($space,$email);
        $member=$this->assignCoHost($space,$email);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'teacher_id'=>$newTeacherId,'teacher_email_digest'=>$this->digestEmail($email),
            'version'=>(int)$classroom->version+1,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'replace_teacher',empty($member['warning'])?'ready':'warning',$member['reason']??null,$member['response']??null);
        return array('classroom_id'=>(int)$classroom->id,'cohost'=>$member);
    }

    /** Cancel the provider event but keep the classroom history and recording artifacts. */
    public function cancelLesson(int $lessonId):array{
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)return array('cancelled'=>false,'reason'=>'classroom_not_found');
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
        if(!$classroom)return array('cancelled'=>false,'reason'=>'classroom_not_found');
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
        $eligibility=$this->recording->lessonRecordingEligibility((int)$classroom->lesson_id);
        $references=$this->openReferences($classroom);
        if($references===null)return array('updated'=>false,'reason'=>'reference_unavailable');
        $space=$this->spaceFor($references);
        $response=$this->api->patchSpace($space,array('config'=>array(
            'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
            'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>!empty($eligibility['eligible'])?'ON':'OFF')),
        )));
        if($response['status']<200||$response['status']>=300){
            $this->audit($classroomId,'sync_recording','failed',$eligibility['reason']??'meet_space_patch_failed',$response);
            return array('updated'=>false,'reason'=>'meet_space_patch_failed','response_status'=>$response['status'],'error_class'=>$response['error_class']??null);
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom($classroomId,array(
            'recording_state'=>!empty($eligibility['eligible'])?'configured':'disabled',
            'recording_consent_digest'=>hash('sha256',wp_json_encode($eligibility,JSON_UNESCAPED_SLASHES) ?: ''),
            'recording_consent_at'=>$now,'updated_at'=>$now,'updated_by'=>0,
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
        $recordingOn=!empty($eligibility['eligible']);
        $moderation=empty($cohost['warning'])?'ON':'OFF';
        $configResponse=$this->api->patchSpace($space,array('config'=>array(
            'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
            'moderation'=>$moderation,
            'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>$recordingOn?'ON':'OFF')),
        )),'config.accessType,config.moderation,config.artifactConfig.recordingConfig.autoRecordingGeneration');
        $warning=null;
        if($configResponse['status']<200||$configResponse['status']>=300){
            // A tenant may restrict moderation or artifact policy. Keep the classroom usable and
            // report the exact refusal; recording stays off unless the patch is proven.
            $warning=array('code'=>'meet_space_config_refused','response'=>$configResponse);
            $this->api->patchSpace($space,array('config'=>array(
                'accessType'=>GoogleWorkspaceClassroomRule::accessType(),
                'artifactConfig'=>array('recordingConfig'=>array('autoRecordingGeneration'=>'OFF')),
            )),'config.accessType,config.artifactConfig.recordingConfig.autoRecordingGeneration');
            $recordingOn=false;
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateClassroom((int)$classroom->id,array(
            'classroom_state'=>'ready','active_slot'=>1,
            'space_name'=>$space,
            'recording_state'=>$recordingOn?'configured':'disabled',
            'recording_consent_digest'=>hash('sha256',wp_json_encode($eligibility,JSON_UNESCAPED_SLASHES) ?: ''),
            'recording_consent_at'=>$now,'version'=>(int)$classroom->version+1,
            'failure_reason_code'=>$warning['code']??null,'updated_at'=>$now,'updated_by'=>0,
        ),array('id'=>(int)$classroom->id));
        $this->audit((int)$classroom->id,'create','ready',$warning['code']??null,$configResponse);
        if(!empty($cohost['warning']))$this->audit((int)$classroom->id,'create','warning',$cohost['reason']??'cohost_assignment_refused',$cohost['response']??null);
        $fresh=$this->repository->classroom((int)$classroom->id);
        $result=$this->result($fresh,$references,false);
        $result['cohost']=$cohost;
        $result['recording_configured']=$recordingOn;
        $result['recording_eligibility']=$eligibility;
        $result['warnings']=$warning!==null?array($warning):array();
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
            $this->audit((int)$classroom->id,'create','failed','meet_calendar_projection_failed',$response);
            $this->repository->updateClassroom((int)$classroom->id,array('classroom_state'=>'failed','failure_reason_code'=>'meet_calendar_projection_failed','updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>0),array('id'=>(int)$classroom->id));
            throw new \RuntimeException('meet_calendar_projection_failed');
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
            $this->repository->updateClassroom((int)$classroom->id,array('classroom_state'=>'provisioning','failure_reason_code'=>'meet_conference_pending','updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>0),array('id'=>(int)$classroom->id));
            $this->audit((int)$classroom->id,'create','pending','meet_conference_pending',$response);
            throw new \RuntimeException('meet_conference_pending');
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
        if($response['status']>=200&&$response['status']<300)return array('assigned'=>true,'response'=>$response);
        // A repeated create is idempotent for our purposes if the member already exists.
        if($response['status']===409){
            $members=$this->api->listMembers($space);
            foreach((array)($members['body']['members']??array()) as $member){
                if(is_array($member)&&strtolower((string)($member['email']??''))===$email&&(string)($member['role']??'')==='COHOST')return array('assigned'=>true,'response'=>$members);
            }
        }
        // Supported alternative: keep the teacher as a Calendar invitee/participant and turn off
        // moderation so host-only restrictions do not block an absent-organizer lesson. The exact
        // provider response is reported, never hidden or replaced with browser automation.
        $fallback=$this->api->patchSpace($space,array('config'=>array('moderation'=>'OFF')),'config.moderation');
        return array(
            'assigned'=>false,'warning'=>true,'reason'=>'meet_cohost_assignment_refused',
            'response'=>$response,'fallback'=>$fallback,'fallback_mode'=>'participant_with_moderation_off',
        );
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
            'idempotent'=>$idempotent,
        );
    }

    private function assertOk(array $response,string $code):void{
        if($response['status']<200||$response['status']>=300)throw new \RuntimeException($code);
    }

    private function digestEmail(string $email):string{
        return hash('sha256','dzn-meet-participant:'.strtolower(trim($email)));
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
