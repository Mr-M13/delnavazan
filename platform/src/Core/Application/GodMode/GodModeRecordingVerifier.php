<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Integrations\GoogleWorkspace\{GoogleWorkspaceApiClient,GoogleWorkspaceClassroomRule,GoogleWorkspaceRepository};

/**
 * Independent verification of recording configuration versus actual availability.
 *
 * A configured space is evidence only that auto-recording was requested. A recording is reported
 * as verified only when the official Meet conference record exposes a recording artifact. Missing
 * evidence is reported as unknown, never as success.
 */
final class GodModeRecordingVerifier {
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
        if(empty($session->recording_requested))return array('state'=>'not_requested','detail'=>'Recording was not requested','evidence'=>array());
        $classroomId=(int)($session->classroom_id??0);
        $classroom=$classroomId>0?$this->repository->classroom($classroomId):$this->repository->activeClassroomForTestSession((int)$session->id);
        if(!$classroom)return array('state'=>'unknown','detail'=>'No classroom exists, so recording availability cannot be verified','evidence'=>array('recording_requested'=>true));
        $configured=(string)$classroom->recording_state==='configured';
        $start=(int)strtotime((string)$session->starts_at_utc.' UTC');
        $end=(int)strtotime((string)$session->ends_at_utc.' UTC');
        $now=time();
        if($now<$start)return array('state'=>'requested','detail'=>'Recording is configured for a future class; activation cannot be verified yet','evidence'=>array('recording_configured'=>$configured,'classroom_state'=>(string)$classroom->classroom_state));
        if(!$configured)return array('state'=>'not_configured','detail'=>'Recording was requested but the provider configuration is not confirmed','evidence'=>array('classroom_state'=>(string)$classroom->classroom_state,'recording_state'=>(string)$classroom->recording_state));
        if($now<=$end)return array('state'=>'configured','detail'=>'Recording is configured; the class has not ended','evidence'=>array('recording_configured'=>true));
        $ref=$this->repository->reference((int)$classroom->id);
        if(!$ref||(string)$ref->state!=='active')return array('state'=>'unknown','detail'=>'The classroom reference is unavailable','evidence'=>array('recording_configured'=>true));
        try{
            $references=$this->vault->open('meet_classroom',(int)$classroom->id,$ref);
            $code=GoogleWorkspaceClassroomRule::meetingCode((string)($references['join_uri_reference']??''));
        }catch(\Throwable){return array('state'=>'unknown','detail'=>'The classroom reference could not be opened','evidence'=>array('recording_configured'=>true));}
        $records=$this->api->listConferenceRecords($code);
        if(($records['status']??0)<200||($records['status']??0)>=300)return array('state'=>'unknown','detail'=>'Conference records are unavailable','evidence'=>array('provider_status'=>(int)($records['status']??0)));
        $selected=null;$best=PHP_INT_MAX;
        foreach((array)($records['body']['conferenceRecords']??array()) as $record){
            if(!is_array($record))continue;
            $startTime=GoogleWorkspaceClassroomRule::fromRfc3339((string)($record['startTime']??''));
            if($startTime===null)continue;
            $distance=abs((int)strtotime($startTime.' UTC')-$start);
            if($distance<12*3600&&$distance<$best){$selected=$record;$best=$distance;}
        }
        if($selected===null)return array('state'=>'unknown','detail'=>'No conference record matches this test session','evidence'=>array('recording_configured'=>true));
        $name=trim((string)($selected['name']??''));
        if($name==='')return array('state'=>'unknown','detail'=>'The conference record identity is unavailable','evidence'=>array());
        $spaceMatched=null;
        try{
            $expectedSpace=GoogleWorkspaceClassroomRule::spaceName((string)($references['space_name']??''));
            $providerSpace=trim((string)($selected['space']??''));
            if($providerSpace!=='')$spaceMatched=hash_equals($expectedSpace,$providerSpace);
        }catch(\Throwable){$spaceMatched=null;}
        if($spaceMatched===false)return array('state'=>'unknown','detail'=>'The conference record does not belong to this test classroom space','evidence'=>array('space_matched'=>false));
        $recordings=$this->api->listRecordings($name);
        if(($recordings['status']??0)<200||($recordings['status']??0)>=300)return array('state'=>'unknown','detail'=>'Recording artifacts are unavailable','evidence'=>array('provider_status'=>(int)($recordings['status']??0)));
        $states=array();$driveFiles=0;
        foreach((array)($recordings['body']['recordings']??array()) as $recording){
            if(!is_array($recording))continue;
            $states[]=(string)($recording['state']??'STATE_UNSPECIFIED');
            $destination=$recording['driveDestination']??null;
            if(is_array($destination)&&trim((string)($destination['file']??''))!=='')$driveFiles++;
        }
        if(!$states)return array('state'=>'awaiting_artifact','detail'=>'The conference ended but no recording artifact is available yet','evidence'=>array('recording_configured'=>true,'space_matched'=>$spaceMatched));
        $finalised=count(array_filter($states,static fn(string $state):bool=>in_array($state,array('ENDED','FILE_GENERATED'),true)))>0;
        $available=$finalised&&$driveFiles>0;
        return array(
            'state'=>$available?'verified':($states?'active':'unknown'),
            'detail'=>$available?'A finalised recording artifact with a Drive destination is available in the organizer account':($finalised?'A recording is finalised but no Drive destination is verified':'Recording artifacts exist but are not finalised'),
            'evidence'=>array('artifact_count'=>count($states),'artifact_states'=>array_values(array_unique($states)),'drive_destination_count'=>$driveFiles,'space_matched'=>$spaceMatched,'recording_configured'=>true),
        );
    }
}
