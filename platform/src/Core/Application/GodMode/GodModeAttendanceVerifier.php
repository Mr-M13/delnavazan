<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Integrations\GoogleWorkspace\{GoogleWorkspaceApiClient,GoogleWorkspaceClassroomRule,GoogleWorkspaceRepository};

/**
 * Read-only participant evidence for a test classroom.
 *
 * It records counts and participant-session availability, never display names, account keys or
 * recording content. A detected participant is evidence, not a canonical attendance claim.
 */
final class GodModeAttendanceVerifier {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceApiClient $api=null,
        private ?ProviderReferenceVault $vault=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->api??=new GoogleWorkspaceApiClient($this->repository);
        $this->vault??=new ProviderReferenceVault();
    }

    /** @return array{state:string,detail:string,evidence:array<string,mixed>} */
    public function verify(object $session):array{
        if((string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_activity_required');
        $classroomId=(int)($session->classroom_id??0);
        $classroom=$classroomId>0?$this->repository->classroom($classroomId):$this->repository->activeClassroomForTestSession((int)$session->id);
        if(!$classroom)return array('state'=>'unknown','detail'=>'No classroom exists, so participant evidence cannot be verified','evidence'=>array());
        $end=(int)strtotime((string)$session->ends_at_utc.' UTC');
        if($end>time())return array('state'=>'not_applicable','detail'=>'The test class has not ended','evidence'=>array('classroom_state'=>(string)$classroom->classroom_state));
        $ref=$this->repository->reference((int)$classroom->id);
        if(!$ref||(string)$ref->state!=='active')return array('state'=>'unknown','detail'=>'The classroom reference is unavailable','evidence'=>array());
        try{
            $references=$this->vault->open('meet_classroom',(int)$classroom->id,$ref);
            $code=GoogleWorkspaceClassroomRule::meetingCode((string)($references['join_uri_reference']??''));
        }catch(\Throwable){return array('state'=>'unknown','detail'=>'The classroom reference could not be opened','evidence'=>array());}
        $records=$this->api->listConferenceRecords($code);
        if(($records['status']??0)<200||($records['status']??0)>=300)return array('state'=>'unknown','detail'=>'Conference records are unavailable','evidence'=>array('provider_status'=>(int)($records['status']??0)));
        $start=(int)strtotime((string)$session->starts_at_utc.' UTC');
        $selected=null;$best=PHP_INT_MAX;
        foreach((array)($records['body']['conferenceRecords']??array()) as $record){
            if(!is_array($record))continue;
            $recordStart=GoogleWorkspaceClassroomRule::fromRfc3339((string)($record['startTime']??''));
            if($recordStart===null)continue;
            $distance=abs((int)strtotime($recordStart.' UTC')-$start);
            if($distance<12*3600&&$distance<$best){$selected=$record;$best=$distance;}
        }
        if($selected===null)return array('state'=>'unknown','detail'=>'No conference record matches this test session','evidence'=>array());
        $name=trim((string)($selected['name']??''));
        if($name==='')return array('state'=>'unknown','detail'=>'The conference record identity is unavailable','evidence'=>array());
        $participants=$this->api->listParticipants($name);
        if(($participants['status']??0)<200||($participants['status']??0)>=300)return array('state'=>'unknown','detail'=>'Participant evidence is unavailable','evidence'=>array('provider_status'=>(int)($participants['status']??0)));
        $mapping=array();foreach($this->repository->participantMappings((int)$session->id) as $row)$mapping[(string)$row->participant_digest]=(string)$row->role;
        $counts=array('signedin'=>0,'anonymous'=>0,'phone'=>0);
        $sessions=0;$entries=array();$verified=array('teacher'=>false,'student'=>false);
        foreach((array)($participants['body']['participants']??array()) as $participant){
            if(!is_array($participant))continue;
            $type='';$accountKey='';
            if(is_array($participant['signedinUser']??null)){$type='signedin';$accountKey=(string)($participant['signedinUser']['user']??'');}
            elseif(is_array($participant['anonymousUser']??null)){$type='anonymous';$accountKey='anonymous:'.(string)($participant['name']??'');}
            elseif(is_array($participant['phoneUser']??null)){$type='phone';$accountKey='phone:'.(string)($participant['name']??'');}
            if($type==='')continue;
            $counts[$type]++;
            $digest=hash('sha256','dzn-meet-'.($type==='signedin'?'account':'guest').':'.$accountKey);
            $list=$this->api->listParticipantSessions((string)($participant['name']??''));
            $sessionCount=($list['status']??0)>=200&&($list['status']??0)<300?count((array)($list['body']['participantSessions']??array())):0;
            $sessions+=$sessionCount;
            $role=(string)($mapping[$digest]??'');
            if($role!==''&&$sessionCount>0)$verified[$role]=true;
            $entries[]=array('digest'=>$digest,'type'=>$type,'session_count'=>$sessionCount,'mapped_role'=>$role!==''?$role:null);
        }
        $total=array_sum($counts);
        $verifiedAttendance=$verified['teacher']&&$verified['student'];
        $state=$total<1?'no_participant_evidence':($verifiedAttendance?'verified':(($verified['teacher']||$verified['student'])?'partial_mapping':'detected_unmapped'));
        $detail=match($state){
            'verified'=>'Both teacher and student participant identities are verified from official Meet evidence.',
            'partial_mapping'=>'Official participant evidence exists and one participant identity is mapped; the other role still needs verification.',
            'detected_unmapped'=>'Official participant evidence exists, but no participant identity is mapped to the test teacher or student.',
            default=>'No official participant evidence is available for this test session',
        };
        return array(
            'state'=>$state,
            'detail'=>$detail,
            'evidence'=>array('participant_count'=>$total,'participant_types'=>$counts,'participant_sessions'=>$sessions,'participants'=>$entries,'verified_teacher_present'=>$verified['teacher'],'verified_student_present'=>$verified['student'],'verified_attendance'=>$verifiedAttendance,'ownership_mapped'=>$verified['teacher']||$verified['student']),
        );
    }
}
