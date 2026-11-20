<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdempotency,CanonicalAttendanceIdentityService,CanonicalAttendanceIntakeService,ProviderEvidenceSystemActor};
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Maps official Meet participant sessions to the canonical lesson.
 *
 * Signed-in participants expose only `users/{id}`. A Meet account therefore contributes qualifying
 * attendance only after an administrator records that account in the Phase-P participant identity
 * registry. Anonymous/phone guests expose only a display name and are retained as unverified
 * evidence for administrator reconciliation. Nothing here marks a lesson completed.
 */
final class MeetAttendanceService {
    private const MAX_SESSIONS=500;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceApiClient $api=null,
        private ?CanonicalAttendanceIntakeService $intake=null,
        private ?CanonicalAttendanceRepository $identities=null,
        private ?MeetIntroAttendanceService $introSettlement=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->api??=new GoogleWorkspaceApiClient($this->repository);
        $this->intake??=new CanonicalAttendanceIntakeService();
        $this->identities??=new CanonicalAttendanceRepository();
        $this->introSettlement??=new MeetIntroAttendanceService();
    }

    /** Discover participants and participant sessions for one reconciled conference. */
    public function reconcileClassroom(int $classroomId,?string $conferenceRecordName=null):array{
        $classroom=$this->repository->classroom($classroomId);
        if(!$classroom)return array('reconciled'=>false,'reason'=>'classroom_required');
        $isBooking=(int)$classroom->lesson_id<1&&(int)($classroom->booking_confirmation_id??0)>0;
        if((int)$classroom->lesson_id<1&&!$isBooking)return array('reconciled'=>false,'reason'=>'classroom_without_lesson');
        $record=$conferenceRecordName!==null
            ?$this->repository->conferenceRecordByName(GoogleWorkspaceClassroomRule::conferenceRecordName($conferenceRecordName))
            :$this->repository->conferenceRecordForClassroom($classroomId);
        if(!$record)return array('reconciled'=>false,'reason'=>'conference_record_required');
        $participants=$this->api->listParticipants((string)$record->conference_record_name);
        if($participants['status']<200||$participants['status']>=300)return array('reconciled'=>false,'reason'=>'participants_unavailable','response'=>$participants);
        $sessions=0;$ingested=0;$deferred=0;$bookingMapped=0;
        foreach((array)($participants['body']['participants']??array()) as $participant){
            if(!is_array($participant))continue;
            $shape=$this->participantShape($participant);
            if($shape===null)continue;
            $list=$this->api->listParticipantSessions((string)($participant['name']??''));
            if($list['status']<200||$list['status']>=300)continue;
            foreach((array)($list['body']['participantSessions']??array()) as $session){
                if(!is_array($session)||$sessions>=self::MAX_SESSIONS)continue;
                $sessions++;
                $row=$this->upsertSession($classroom,$record,$shape,$session);
                if($row===null)continue;
                if($isBooking){
                    if($this->mapBookingSession($row,$shape)==='mapped')$bookingMapped++;
                    else $deferred++;
                    continue;
                }
                $mapping=$this->repository->verifiedAttendanceMapping($shape['account_digest']);
                $intake=$this->maybeIngest($classroom,$row,$shape,$mapping);
                if($intake==='ingested')$ingested++;
                if($intake==='deferred')$deferred++;
            }
        }
        $result=array('reconciled'=>true,'sessions'=>$sessions,'conference_record_id'=>(int)$record->id);
        if($isBooking){
            $result['booking_mapped']=$bookingMapped;
            $result['booking_deferred']=$deferred;
            // The booking Lesson does not exist until the outcome is recorded, so the introductory
            // adapter re-assesses the durable participant sessions and records the delivered
            // outcome directly instead of entering Phase P.
            $result['auto_settlement']=$this->introSettlement->settleClassroom((int)$classroom->id);
            return $result;
        }
        $result['canonical_ingested']=$ingested;
        $result['deferred']=$deferred;
        return $result;
    }

    private function participantShape(array $participant):?array{
        $name=trim((string)($participant['name']??''));
        if($name==='')return null;
        $signed=$participant['signedinUser']??null;
        $anonymous=$participant['anonymousUser']??null;
        $phone=$participant['phoneUser']??null;
        if(is_array($signed)&&trim((string)($signed['user']??''))!==''){
            $account=trim((string)$signed['user']);
            // The identity registry is keyed by the canonical attendance evidence digest, never
            // by an adapter-local hash. Using a different digest made every verified Meet mapping
            // unreachable, so automatic attendance was always deferred.
            return array('type'=>'signedin','account_key'=>$account,'account_digest'=>CanonicalAttendanceIdempotency::evidence($account),'display_name'=>trim((string)($signed['displayName']??'')),'participant_name'=>$name);
        }
        if(is_array($anonymous)){
            $display=trim((string)($anonymous['displayName']??''));
            return array('type'=>'anonymous','account_key'=>'anonymous:'.$name,'account_digest'=>hash('sha256','dzn-meet-guest:'.$name),'display_name'=>$display,'participant_name'=>$name);
        }
        if(is_array($phone)){
            $display=trim((string)($phone['displayName']??''));
            return array('type'=>'phone','account_key'=>'phone:'.$name,'account_digest'=>hash('sha256','dzn-meet-phone:'.$name),'display_name'=>$display,'participant_name'=>$name);
        }
        return null;
    }

    private function upsertSession(object $classroom,object $record,array $shape,array $session):?object{
        $name=trim((string)($session['name']??''));
        if($name==='')return null;
        $join=GoogleWorkspaceClassroomRule::fromRfc3339((string)($session['startTime']??''));
        $leave=GoogleWorkspaceClassroomRule::fromRfc3339((string)($session['endTime']??''));
        $existing=$this->repository->participantSession($name);
        if($existing){
            $update=array(
                'leave_at_utc'=>$leave?:$existing->leave_at_utc,
                'observed_at'=>gmdate('Y-m-d H:i:s'),
            );
            if(($existing->join_at_utc===null||$existing->join_at_utc==='')&&$join!==null)$update['join_at_utc']=$join;
            // Repair rows written by the retired adapter digest so a verified mapping recorded
            // before this fix can be re-resolved from the canonical registry.
            if((string)($existing->participant_account_digest??'')!==(string)$shape['account_digest'])$update['participant_account_digest']=$shape['account_digest'];
            if((string)($existing->participant_type??'')!==(string)$shape['type'])$update['participant_type']=$shape['type'];
            $this->repository->updateParticipantSession((int)$existing->id,$update,array('id'=>(int)$existing->id));
            return $this->repository->participantSession($name);
        }
        $id=$this->repository->insertParticipantSession(array(
            'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,
            'conference_record_id'=>(int)$record->id,'participant_name'=>$shape['participant_name'],
            'participant_type'=>$shape['type'],'participant_account_digest'=>$shape['account_digest'],
            'participant_session_name'=>$name,'join_at_utc'=>$join,'leave_at_utc'=>$leave,
            'observed_at'=>gmdate('Y-m-d H:i:s'),'mapped_role'=>null,'mapped_participant_id'=>null,
            'mapping_state'=>'unverified','created_at'=>gmdate('Y-m-d H:i:s'),
        ));
        $row=$this->repository->participantSession($name);
        return $row?:($id>0?(object)array('id'=>$id,'participant_session_name'=>$name):null);
    }

    /**
     * Map one booking participant session for observability.
     *
     * Booking classrooms never enter Phase P; the introductory settlement re-resolves the exact
     * teacher/student from the durable registry itself. This state is operational visibility only.
     */
    private function mapBookingSession(object $row,array $shape):string{
        $id=(int)$row->id;
        if((string)$shape['type']!=='signedin'){
            $this->repository->updateParticipantSession($id,array('mapping_state'=>'unmapped','mapped_role'=>null,'mapped_participant_id'=>null),array('id'=>$id));
            return 'deferred';
        }
        $teacher=CanonicalAttendanceIdentityService::resolve($this->identities->mappingsForAccount('google_meet',(string)$shape['account_digest'],'teacher'));
        $student=CanonicalAttendanceIdentityService::resolve($this->identities->mappingsForAccount('google_meet',(string)$shape['account_digest'],'student'));
        $teacherResolved=$teacher['state']==='resolved';
        $studentResolved=$student['state']==='resolved';
        if($teacherResolved!==$studentResolved){
            $role=$teacherResolved?'teacher':'student';
            $participantId=(int)($teacherResolved?$teacher['participant_id']:$student['participant_id']);
            $this->repository->updateParticipantSession($id,array('mapping_state'=>'verified_booking','mapped_role'=>$role,'mapped_participant_id'=>$participantId),array('id'=>$id));
            return 'mapped';
        }
        $this->repository->updateParticipantSession($id,array(
            'mapping_state'=>($teacherResolved&&$studentResolved)?'ambiguous':'unmapped',
            'mapped_role'=>null,'mapped_participant_id'=>null,
        ),array('id'=>$id));
        return 'deferred';
    }

    /** Only a verified Phase-P mapping is allowed to become qualifying attendance. */
    private function maybeIngest(object $classroom,object $row,array $shape,?object $mapping):string{
        if(!$mapping||(string)$mapping->state!=='verified'){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>$mapping?'ambiguous':'unmapped','mapped_role'=>null,'mapped_participant_id'=>null,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
        $role=(string)$mapping->participant_role;
        if(!in_array($role,array('teacher','student'),true))return 'deferred';
        if(!ProviderEvidenceSystemActor::isConfigured()){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'verified_intake_deferred','mapped_role'=>$role,'mapped_participant_id'=>(int)$mapping->participant_id,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
        $eventKey='meet-session:'.(string)$row->participant_session_name;
        $key='meet-attendance-'.substr(CanonicalAttendanceIdempotency::key($eventKey),0,40);
        try{
            ProviderEvidenceSystemActor::run(fn()=> $this->intake->ingestProviderEvidence((int)$classroom->lesson_id,(int)$classroom->schedule_version_id,array(
                'provider_code'=>'google_meet','participant_role'=>$role,
                'provider_account_key'=>$shape['account_key'],
                'provider_session_key'=>(string)$row->participant_session_name,
                'provider_event_key'=>$eventKey,
                'provider_payload_key'=>hash('sha256',(string)wp_json_encode(array($shape['account_digest'],$row->join_at_utc,$row->leave_at_utc),JSON_UNESCAPED_SLASHES) ?: ''),
                'observed_at'=>(string)$row->observed_at,
                'join_at_utc'=>$row->join_at_utc,'leave_at_utc'=>$row->leave_at_utc,
                'provenance_reference'=>'google-meet-conference:'.(string)$row->conference_record_id,
                'evidence_reference'=>'google-meet-session:'.(string)$row->participant_session_name,
            ),$key));
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'verified_ingested','mapped_role'=>$role,'mapped_participant_id'=>(int)$mapping->participant_id,
            ),array('id'=>(int)$row->id));
            return 'ingested';
        }catch(\Throwable){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'verified_intake_failed','mapped_role'=>$role,'mapped_participant_id'=>(int)$mapping->participant_id,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
    }
}
