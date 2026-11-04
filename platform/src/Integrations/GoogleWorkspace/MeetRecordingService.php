<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Official Google Meet recording lifecycle and consent-controlled playback.
 *
 * Google Meet artifacts are written to the meeting organizer's Drive, which is the Delnavazan
 * organizer account. This service only reads the official artifacts:
 *   spaces.get → conferenceRecords.list → recordings.list → Drive files.get.
 * It never starts a second recorder, never injects into the Meet UI and never rewrites sharing.
 */
final class MeetRecordingService {
    private const END_GRACE_SECONDS=1800;
    private const PROCESSING_GRACE_SECONDS=7200;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceApiClient $api=null,
        private ?RecordingDecisionAuthorityService $recording=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->api??=new GoogleWorkspaceApiClient($this->repository);
        $this->recording??=new RecordingDecisionAuthorityService();
    }

    /** Reconcile one ended classroom: conference record, participant sessions and recordings. */
    public function reconcileClassroom(int $classroomId):array{
        $classroom=$this->repository->classroom($classroomId);
        if(!$classroom)throw new \InvalidArgumentException('meet_classroom_required');
        if((int)$classroom->lesson_id<1)return array('reconciled'=>false,'reason'=>'classroom_without_lesson');
        $references=$this->references($classroom);
        if($references===null)return array('reconciled'=>false,'reason'=>'reference_unavailable');
        $code=GoogleWorkspaceClassroomRule::meetingCode((string)$references['join_uri_reference']);
        $records=$this->api->listConferenceRecords($code);
        if($records['status']<200||$records['status']>=300)return array('reconciled'=>false,'reason'=>'conference_records_unavailable','response'=>$records);
        $selected=$this->selectRecord((array)($records['body']['conferenceRecords']??array()),$classroom);
        if($selected===null)return $this->handleMissingRecord($classroom);
        $record=$this->upsertConferenceRecord($classroom,$selected);
        $recordings=$this->api->listRecordings((string)$record->conference_record_name);
        $artifacts=array();
        if($recordings['status']>=200&&$recordings['status']<300){
            foreach((array)($recordings['body']['recordings']??array()) as $recording){
                if(is_array($recording))$artifacts[]=$this->upsertRecording($classroom,$record,$recording);
            }
        }
        $this->checkRecordingHealth($classroom,$record,$artifacts);
        return array('reconciled'=>true,'conference_record_id'=>(int)$record->id,'recordings'=>$artifacts);
    }

    /** Access token/file handle for the authenticated streaming controller. */
    public function streamHandleForFile(string $fileId):array{
        GoogleWorkspaceClassroomRule::driveFileId($fileId);
        return array('file_id'=>$fileId,'access_token'=>$this->api->accessToken());
    }

    // ---- Reconciliation --------------------------------------------------------------------
    private function selectRecord(array $records,object $classroom):?array{
        $lessonStart=(int)strtotime((string)$classroom->starts_at_utc.' UTC');
        $best=null;$bestDistance=PHP_INT_MAX;
        foreach($records as $record){
            if(!is_array($record))continue;
            $start=GoogleWorkspaceClassroomRule::fromRfc3339((string)($record['startTime']??''));
            if($start===null)continue;
            $distance=abs((int)strtotime($start.' UTC')-$lessonStart);
            // A conference record more than 12 hours away cannot belong to this occurrence.
            if($distance>12*3600)continue;
            if($distance<$bestDistance){$best=$record;$bestDistance=$distance;}
        }
        return $best;
    }

    private function upsertConferenceRecord(object $classroom,array $record):object{
        $name=GoogleWorkspaceClassroomRule::conferenceRecordName((string)($record['name']??''));
        $existing=$this->repository->conferenceRecordByName($name);
        if($existing)return $existing;
        $start=GoogleWorkspaceClassroomRule::fromRfc3339((string)($record['startTime']??''));
        $end=GoogleWorkspaceClassroomRule::fromRfc3339((string)($record['endTime']??''));
        $id=$this->repository->insertConferenceRecord(array(
            'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'conference_record_name'=>$name,
            'space_name'=>$record['space']??null,'start_time'=>$start,'end_time'=>$end,
            'recording_expected'=>(string)$classroom->recording_state==='configured'?1:0,
            'discovered_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),
        ));
        $this->repository->updateClassroom((int)$classroom->id,array('conference_record_name'=>$name,'updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>(int)$classroom->id));
        return $this->repository->conferenceRecordByName($name)?:(object)array('id'=>$id,'conference_record_name'=>$name);
    }

    private function upsertRecording(object $classroom,object $record,array $recording):array{
        $name=GoogleWorkspaceClassroomRule::recordingName((string)($recording['name']??''));
        $state=(string)($recording['state']??'');
        if(!in_array($state,GoogleWorkspaceClassroomRule::RECORDING_STATES,true))$state='STATE_UNSPECIFIED';
        $fileId=null;
        $destination=$recording['driveDestination']??null;
        if(is_array($destination)&&trim((string)($destination['file']??''))!=='')$fileId=GoogleWorkspaceClassroomRule::driveFileId((string)$destination['file']);
        $existing=$this->repository->recording($name);
        $now=gmdate('Y-m-d H:i:s');
        if(!$existing){
            $id=$this->repository->insertRecording(array(
                'uid'=>Identifier::uid(),'classroom_id'=>(int)$classroom->id,'conference_record_id'=>(int)$record->id,
                'recording_name'=>$name,'state'=>$state,'drive_file_id'=>$fileId,
                'export_uri_digest'=>isset($destination['exportUri'])?hash('sha256','dzn-drive-export:'.(string)$destination['exportUri']):null,
                'start_time'=>GoogleWorkspaceClassroomRule::fromRfc3339((string)($recording['startTime']??'')),
                'end_time'=>GoogleWorkspaceClassroomRule::fromRfc3339((string)($recording['endTime']??'')),
                'file_verified_at'=>null,'playback_verified_at'=>null,'publicly_shared'=>0,
                'privacy_state'=>MeetRecordingPrivacyRule::UNVERIFIED,'privacy_checked_at'=>null,
                'privacy_error_code'=>null,'privacy_error_detail'=>null,
                'consent_snapshot_digest'=>$classroom->recording_consent_digest,
                'failure_reason_code'=>null,'created_at'=>$now,'updated_at'=>$now,
            ));
            $existing=$this->repository->recording($name);
        }else{
            $this->repository->updateRecording((int)$existing->id,array(
                'state'=>$state,'drive_file_id'=>$fileId?:$existing->drive_file_id,
                'end_time'=>GoogleWorkspaceClassroomRule::fromRfc3339((string)($recording['endTime']??''))?:$existing->end_time,
                'updated_at'=>$now,
            ),array('id'=>(int)$existing->id));
            $existing=$this->repository->recording($name);
        }
        $result=array('recording_name'=>$name,'state'=>$state,'drive_file_id'=>$fileId,'verified'=>false);
        if($fileId!==null&&in_array($state,array('ENDED','FILE_GENERATED'),true)){
            $verification=$this->verifyDriveFile((int)$classroom->id,(int)$existing->id,$fileId);
            $result=array_merge($result,$verification);
        }
        return $result;
    }

    /**
     * Verify metadata, explicit Drive permissions, and a readable MP4.
     *
     * Missing permissions, an API refusal or any non-organizer share is a verification failure;
     * playback is denied. Only an explicit organizer-only permission list becomes verified_private.
     */
    private function verifyDriveFile(int $classroomId,int $artifactId,string $fileId):array{
        $metadata=$this->api->driveFile($fileId);
        if($metadata['status']<200||$metadata['status']>=300){
            return $this->markPrivacyFailure($classroomId,$artifactId,'drive_metadata_unavailable',$metadata,'drive_metadata_unavailable');
        }
        $mime=(string)($metadata['body']['mimeType']??'');
        $size=(int)($metadata['body']['size']??0);
        if($mime!=='video/mp4'||$size<1){
            $this->repository->updateRecording($artifactId,array('failure_reason_code'=>'recording_not_playable','updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$artifactId));
            return array('verified'=>false,'reason'=>'recording_not_playable','mime_type'=>$mime);
        }
        $permissions=$this->api->driveFilePermissions($fileId);
        if($permissions['status']<200||$permissions['status']>=300){
            return $this->markPrivacyFailure($classroomId,$artifactId,'drive_permissions_unavailable',$permissions,'drive_permissions_unavailable');
        }
        $list=$permissions['body']['permissions']??null;
        if(!is_array($list)){
            return $this->markPrivacyFailure($classroomId,$artifactId,'drive_permissions_missing',$permissions,'drive_permissions_missing');
        }
        try{$health=(new GoogleWorkspaceOAuthService($this->repository))->health();}catch(\Throwable){$health=array();}
        $organizer=(string)($health['organizer_email']??GoogleWorkspaceClassroomRule::organizerEmail());
        $privacy=MeetRecordingPrivacyRule::classify($list,$organizer);
        if(!MeetRecordingPrivacyRule::canPlayback($privacy['state'])){
            $this->repository->updateRecording($artifactId,array(
                'file_verified_at'=>gmdate('Y-m-d H:i:s'),'playback_verified_at'=>null,
                'publicly_shared'=>$privacy['state']===MeetRecordingPrivacyRule::PUBLIC_SHARED?1:0,
                'privacy_state'=>$privacy['state'],'privacy_checked_at'=>gmdate('Y-m-d H:i:s'),
                'privacy_error_code'=>$privacy['reason'],'privacy_error_detail'=>null,
                'failure_reason_code'=>'recording_privacy_not_private','updated_at'=>gmdate('Y-m-d H:i:s'),
            ),array('id'=>$artifactId));
            $alert=MeetRecordingPrivacyRule::alertCode($privacy['state']);
            if($alert!==null)$this->raiseAlert($classroomId,$alert,$privacy['reason'],null);
            return array('verified'=>false,'reason'=>'recording_privacy_not_private','privacy_state'=>$privacy['state'],'privacy_reason'=>$privacy['reason']);
        }
        $probe=$this->api->driveProbe($fileId);
        if(!in_array($probe['status'],array(200,206),true)){
            $this->repository->updateRecording($artifactId,array(
                'privacy_state'=>$privacy['state'],'privacy_checked_at'=>gmdate('Y-m-d H:i:s'),
                'file_verified_at'=>gmdate('Y-m-d H:i:s'),'playback_verified_at'=>null,
                'failure_reason_code'=>'recording_probe_failed','updated_at'=>gmdate('Y-m-d H:i:s'),
            ),array('id'=>$artifactId));
            $this->raiseAlert($classroomId,'recording_unplayable','recording_probe_failed',$this->providerDetail($probe));
            return array('verified'=>false,'reason'=>'recording_probe_failed','response_status'=>$probe['status']);
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateRecording($artifactId,array(
            'file_verified_at'=>$now,'playback_verified_at'=>$now,'publicly_shared'=>0,
            'privacy_state'=>$privacy['state'],'privacy_checked_at'=>$now,
            'privacy_error_code'=>null,'privacy_error_detail'=>null,
            'failure_reason_code'=>null,'updated_at'=>$now,
        ),array('id'=>$artifactId));
        $this->repository->updateClassroom($classroomId,array('recording_state'=>'recorded','updated_at'=>$now),array('id'=>$classroomId));
        return array('verified'=>true,'publicly_shared'=>false,'privacy_state'=>$privacy['state']);
    }

    private function markPrivacyFailure(int $classroomId,int $artifactId,string $reason,array $response,string $failureCode):array{
        $detail=$this->providerDetail($response);
        $this->repository->updateRecording($artifactId,array(
            'privacy_state'=>MeetRecordingPrivacyRule::VERIFICATION_FAILED,'privacy_checked_at'=>gmdate('Y-m-d H:i:s'),
            'privacy_error_code'=>$reason,'privacy_error_detail'=>$detail,
            'playback_verified_at'=>null,'failure_reason_code'=>$failureCode,'updated_at'=>gmdate('Y-m-d H:i:s'),
        ),array('id'=>$artifactId));
        $this->raiseAlert($classroomId,'recording_privacy_unverified',$reason,$detail);
        return array('verified'=>false,'reason'=>'recording_privacy_unverified','privacy_reason'=>$reason);
    }

    private function providerDetail(array $response):?string{
        $status=(int)($response['status']??0);
        $class=trim((string)($response['error_class']??''));
        $message=trim((string)($response['error_message']??''));
        $detail=trim(($status>0?'HTTP '.$status.' ':'HTTP 0 ').$class.($message!==''?' · '.$message:''));
        return $detail!==''?substr($detail,0,255):null;
    }

    private function checkRecordingHealth(object $classroom,object $record,array $artifacts):void{
        $now=time();
        $end=(int)strtotime((string)$classroom->ends_at_utc.' UTC');
        if((string)$classroom->recording_state==='configured'&&$end+self::PROCESSING_GRACE_SECONDS<$now&&$artifacts===array()){
            $this->raiseAlert((int)$classroom->id,'recording_missing','recording_expected_but_absent');
        }
        foreach($artifacts as $artifact){
            $state=(string)($artifact['state']??'');
            if(in_array($state,array('ENDED','FILE_GENERATED'),true)&&$now<$end&&(string)$classroom->recording_state==='configured'){
                $this->raiseAlert((int)$classroom->id,'recording_ended_early','recording_ended_unexpectedly');
            }
            if($state==='STARTED'&&$end+self::PROCESSING_GRACE_SECONDS<$now){
                $this->raiseAlert((int)$classroom->id,'recording_did_not_end','recording_ended_unexpectedly');
            }
            if(!in_array($state,array('ENDED','FILE_GENERATED'),true))continue;
            if(empty($artifact['verified'])&&$end+self::PROCESSING_GRACE_SECONDS<$now){
                $this->raiseAlert((int)$classroom->id,'recording_unplayable','recording_processing_stalled');
            }
        }
        if($record->end_time!==null&&$end+self::PROCESSING_GRACE_SECONDS<$now&&$classroom->recording_state==='configured'&&$artifacts===array()){
            $this->raiseAlert((int)$classroom->id,'recording_ended_without_artifact','recording_ended_unexpectedly');
        }
    }

    private function handleMissingRecord(object $classroom):array{
        $end=(int)strtotime((string)$classroom->ends_at_utc.' UTC');
        if($end+self::PROCESSING_GRACE_SECONDS<time()&&(string)$classroom->recording_state==='configured'){
            $this->raiseAlert((int)$classroom->id,'recording_missing','conference_record_absent');
        }
        $this->repository->updateClassroom((int)$classroom->id,array('updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>(int)$classroom->id));
        return array('reconciled'=>false,'reason'=>'conference_record_absent');
    }

    private function raiseAlert(int $classroomId,string $code,string $detail,?string $detailText=null):void{
        if($this->repository->openAlert($classroomId,$code))return;
        try{
            $this->repository->insertAlert(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'alert_code'=>$code,
                'detail_code'=>$detail,'detail_text'=>$detailText!==null?substr($detailText,0,255):null,
                'state'=>'open','created_at'=>gmdate('Y-m-d H:i:s'),
                'acknowledged_at'=>null,'acknowledged_by'=>null,
            ));
            if(function_exists('do_action'))do_action('dzn_meet_recording_alert',$classroomId,$code,$detail,$detailText);
            if(function_exists('wp_mail')&&function_exists('get_option')){
                $to=(string)get_option('admin_email');
                if($to!==''&&function_exists('is_email')&&is_email($to)){
                    wp_mail($to,'Delnavazan recording alert','Classroom #'.$classroomId.' · '.$code.' · '.$detail.($detailText?' · '.$detailText:'').'\n\nOpen Settings → Integrations for the recording health report.');
                }
            }
        }catch(\Throwable){}
    }

    private function references(object $classroom):?array{
        $row=$this->repository->reference((int)$classroom->id);
        if(!$row)return null;
        try{return (new \Delnavazan\Platform\Core\Application\ProviderReferenceVault())->open('meet_classroom',(int)$classroom->id,$row);}
        catch(\Throwable){return null;}
    }
}
