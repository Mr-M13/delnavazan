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
    /** Verified liaison digests for the current reconciliation run; one query per run. */
    private ?array $liaisonCache=null;

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
        // Bind any portal join claim recorded before this run to the participant who arrived with it,
        // so a class does not need an administrator to type an opaque Meet account id by hand.
        $claimMapping=(new MeetAttendanceClaimService($this->repository,$this->identities))->autoMap($classroom);
        // Learn the organizer/liaison account from the companion's recorded join before counting
        // presence: the liaison is one provider account and is never a participant.
        $this->learnLiaison($classroom);
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
            $result['claim_mapping']=$claimMapping;
            // Sessions written in this run are now available, so a fresh claim can be correlated
            // before the introductory adapter assesses overlap. A first pass has just stored the
            // organizer's session, so the liaison can also be attributed before the threshold is
            // evaluated instead of counting the organizer as a participant.
            $claimMapping=(new MeetAttendanceClaimService($this->repository,$this->identities))->autoMap($classroom);
            $this->learnLiaison($classroom);
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

    /**
     * Presence policy ingestion: every participant session is admitted as evidence.
     *
     * Identity no longer decides attendance. A verified mapping only supplies the canonical role; an
     * unattributed participant is stored as `attendee`. The organizer/liaison account is never
     * evidence, and a class whose companion join cannot be attributed yet is deferred rather than
     * counted, so the liaison can never help reach the two-person threshold.
     */
    private function maybeIngest(object $classroom,object $row,array $shape,?object $mapping):string{
        $digest=(string)($shape['account_digest']??'');
        if($digest!==''&&in_array($digest,$this->liaisonDigests(),true)){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'liaison','mapped_role'=>null,'mapped_participant_id'=>null,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
        if($this->liaisonRequired($classroom)){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'liaison_unresolved','mapped_role'=>null,'mapped_participant_id'=>null,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
        $verified=$mapping&&(string)$mapping->state==='verified'&&in_array((string)$mapping->participant_role,array('teacher','student'),true);
        $role=$verified?(string)$mapping->participant_role:'attendee';
        $participantId=$verified?(int)$mapping->participant_id:null;
        if(!ProviderEvidenceSystemActor::isConfigured()){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'verified_intake_deferred','mapped_role'=>$verified?$role:null,'mapped_participant_id'=>$participantId,
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
                'mapping_state'=>$verified?'verified_ingested':'observed_ingested','mapped_role'=>$verified?$role:null,'mapped_participant_id'=>$participantId,
            ),array('id'=>(int)$row->id));
            return 'ingested';
        }catch(\Throwable){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'intake_failed','mapped_role'=>$verified?$role:null,'mapped_participant_id'=>$participantId,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
    }

    /** @return array<int,string> */
    private function liaisonDigests():array{
        if($this->liaisonCache!==null)return $this->liaisonCache;
        try{$this->liaisonCache=$this->identities->liaisonDigests('google_meet');}
        catch(\Throwable){$this->liaisonCache=array();}
        return $this->liaisonCache;
    }

    /** A companion join exists for this classroom but the liaison account is not attributed yet. */
    private function liaisonRequired(object $classroom):bool{
        try{
            if($this->liaisonDigests())return false;
            return $this->repository->recorderJoinedAt((int)$classroom->id)!==null;
        }catch(\Throwable){return true;}
    }

    /**
     * Attribute the organizer/liaison account from the companion's recorded join.
     *
     * The companion opens the class as the one Delnavazan organizer and reports `joined`; the nearest
     * signed-in arrival inside the claim tolerance is that account. The binding is global (one
     * organizer) and conservative: a near-tie is left to an operator, never guessed.
     */
    private function learnLiaison(object $classroom):void{
        try{
            if($this->liaisonDigests())return;
            $joined=$this->repository->recorderJoinedAt((int)$classroom->id);
            if($joined===null)return;
            $anchor=strtotime($joined.' UTC');
            if($anchor===false)return;
            $candidates=array();
            foreach($this->repository->participantSessionsForClassroom((int)$classroom->id) as $session){
                if((string)($session->participant_type??'')!=='signedin')continue;
                $digest=(string)($session->participant_account_digest??'');
                $join=trim((string)($session->join_at_utc??''));
                if($digest===''||$join==='')continue;
                $distance=abs(strtotime($join.' UTC')-$anchor);
                if($distance>MeetAttendanceClaimService::TOLERANCE_SECONDS)continue;
                $candidates[$digest]=$distance;
            }
            if(!$candidates)return;
            asort($candidates);
            $digests=array_keys($candidates);$distances=array_values($candidates);
            if(count($digests)>1&&($distances[1]-$distances[0])<MeetAttendanceClaimService::DISTINCT_SECONDS)return;
            $digest=(string)$digests[0];
            $now=gmdate('Y-m-d H:i:s');
            $this->identities->insertMapping(array(
                'uid'=>Identifier::uid(),'provider_code'=>'google_meet','provider_account_digest'=>$digest,
                'participant_role'=>'liaison','participant_id'=>0,'state'=>'verified',
                'provenance_digest'=>CanonicalAttendanceIdempotency::evidence('recorder-join:'.(int)$classroom->id.':'.$joined),
                'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence('liaison-correlation:'.(int)$classroom->id),
                'verified_at'=>$now,'revoked_at'=>null,'mapping_version'=>1,
                'recorded_at'=>$now,'recorded_by'=>0,'updated_at'=>$now,'updated_by'=>0,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'operation'=>'attendance_liaison_learned',
                'command_digest'=>hash('sha256','liaison:'.(int)$classroom->id),
                'result_state'=>'liaison','reason_code'=>'recorder_join_correlation',
                'provider_http_status'=>null,'provider_error_class'=>null,
                'provider_detail'=>'organizer account excluded from presence counting',
                'payload_digest'=>hash('sha256',$digest),'created_at'=>$now,'created_by'=>0,
            ));
            $this->liaisonCache=array($digest);
        }catch(\Throwable){}
    }
}
