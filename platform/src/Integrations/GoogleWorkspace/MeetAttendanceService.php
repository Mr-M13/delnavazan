<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdempotency,CanonicalAttendanceIntakeService};
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
        private ?CanonicalAttendanceIntakeService $intake=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->api??=new GoogleWorkspaceApiClient($this->repository);
        $this->intake??=new CanonicalAttendanceIntakeService();
    }

    /** Discover participants and participant sessions for one reconciled conference. */
    public function reconcileClassroom(int $classroomId,?string $conferenceRecordName=null):array{
        $classroom=$this->repository->classroom($classroomId);
        if(!$classroom||(int)$classroom->lesson_id<1)return array('reconciled'=>false,'reason'=>'classroom_without_lesson');
        $record=$conferenceRecordName!==null
            ?$this->repository->conferenceRecordByName(GoogleWorkspaceClassroomRule::conferenceRecordName($conferenceRecordName))
            :$this->repository->conferenceRecordForClassroom($classroomId);
        if(!$record)return array('reconciled'=>false,'reason'=>'conference_record_required');
        $participants=$this->api->listParticipants((string)$record->conference_record_name);
        if($participants['status']<200||$participants['status']>=300)return array('reconciled'=>false,'reason'=>'participants_unavailable','response'=>$participants);
        $sessions=0;$ingested=0;$deferred=0;
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
                $mapping=$this->repository->verifiedAttendanceMapping($shape['account_digest']);
                $intake=$this->maybeIngest($classroom,$row,$shape,$mapping);
                if($intake==='ingested')$ingested++;
                if($intake==='deferred')$deferred++;
            }
        }
        return array('reconciled'=>true,'sessions'=>$sessions,'canonical_ingested'=>$ingested,'deferred'=>$deferred,'conference_record_id'=>(int)$record->id);
    }

    private function participantShape(array $participant):?array{
        $name=trim((string)($participant['name']??''));
        if($name==='')return null;
        $signed=$participant['signedinUser']??null;
        $anonymous=$participant['anonymousUser']??null;
        $phone=$participant['phoneUser']??null;
        if(is_array($signed)&&trim((string)($signed['user']??''))!==''){
            $account=trim((string)$signed['user']);
            return array('type'=>'signedin','account_key'=>$account,'account_digest'=>hash('sha256','dzn-meet-account:'.$account),'display_name'=>trim((string)($signed['displayName']??'')),'participant_name'=>$name);
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
            $this->repository->updateParticipantSession((int)$existing->id,array(
                'leave_at_utc'=>$leave?:$existing->leave_at_utc,
                'observed_at'=>gmdate('Y-m-d H:i:s'),
            ),array('id'=>(int)$existing->id));
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
        if(!function_exists('current_user_can')||!current_user_can('dzn_ingest_canonical_attendance_evidence')){
            $this->repository->updateParticipantSession((int)$row->id,array(
                'mapping_state'=>'verified_intake_deferred','mapped_role'=>$role,'mapped_participant_id'=>(int)$mapping->participant_id,
            ),array('id'=>(int)$row->id));
            return 'deferred';
        }
        $eventKey='meet-session:'.(string)$row->participant_session_name;
        $key='meet-attendance-'.substr(CanonicalAttendanceIdempotency::key($eventKey),0,40);
        try{
            $this->intake->ingestProviderEvidence((int)$classroom->lesson_id,(int)$classroom->schedule_version_id,array(
                'provider_code'=>'google_meet','participant_role'=>$role,
                'provider_account_key'=>$shape['account_key'],
                'provider_session_key'=>(string)$row->participant_session_name,
                'provider_event_key'=>$eventKey,
                'provider_payload_key'=>hash('sha256',(string)wp_json_encode(array($shape['account_digest'],$row->join_at_utc,$row->leave_at_utc),JSON_UNESCAPED_SLASHES) ?: ''),
                'observed_at'=>(string)$row->observed_at,
                'join_at_utc'=>$row->join_at_utc,'leave_at_utc'=>$row->leave_at_utc,
                'provenance_reference'=>'google-meet-conference:'.(string)$row->conference_record_id,
                'evidence_reference'=>'google-meet-session:'.(string)$row->participant_session_name,
            ),$key);
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
