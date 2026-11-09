<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\IntegrationSecretService;

/**
 * Minimal Google Workspace HTTP client for Calendar, Meet and Drive.
 *
 * The organizer credential is opened only for the duration of a request. Every call returns the
 * exact HTTP status and a bounded Google error classification so an unsupported tenant capability
 * (for example a refused co-host assignment) is reported instead of silently approximated.
 */
final class GoogleWorkspaceApiClient {
    /** @var callable(string,string,array,?string):array{status:int,body:array,raw:string} */
    private $transport;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?IntegrationSecretService $secrets=null,
        private ?GoogleWorkspaceOAuthService $oauth=null,
        ?callable $transport=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->secrets??=new IntegrationSecretService();
        $this->oauth??=new GoogleWorkspaceOAuthService($this->repository,$this->secrets);
        $this->transport=$transport??static function(string $method,string $uri,array $headers,?string $body):array{
            $args=array('method'=>$method,'timeout'=>30,'redirection'=>0,'headers'=>$headers);
            if($body!==null)$args['body']=$body;
            $response=wp_remote_request($uri,$args);
            if(is_wp_error($response))return array('status'=>0,'body'=>array(),'raw'=>'');
            $raw=(string)wp_remote_retrieve_body($response);
            $decoded=json_decode($raw,true);
            return array('status'=>(int)wp_remote_retrieve_response_code($response),'body'=>is_array($decoded)?$decoded:array(),'raw'=>$raw);
        };
    }

    public function organizerEmail():string{return GoogleWorkspaceClassroomRule::organizerEmail();}

    // ---- Calendar ---------------------------------------------------------------------------
    public function createCalendarEvent(array $event):array{
        $id=trim((string)($event['id']??''));
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$id))throw new \InvalidArgumentException('meet_calendar_event_id_invalid');
        return $this->call('POST','/calendar/v3/calendars/'.rawurlencode($this->organizerEmail()).'/events?conferenceDataVersion=1&sendUpdates=none',$event);
    }

    public function updateCalendarEvent(string $eventId,array $event):array{
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$eventId))throw new \InvalidArgumentException('meet_calendar_event_id_invalid');
        return $this->call('PATCH','/calendar/v3/calendars/'.rawurlencode($this->organizerEmail()).'/events/'.rawurlencode($eventId).'?conferenceDataVersion=1&sendUpdates=none',$event);
    }

    public function getCalendarEvent(string $eventId):array{
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$eventId))throw new \InvalidArgumentException('meet_calendar_event_id_invalid');
        return $this->call('GET','/calendar/v3/calendars/'.rawurlencode($this->organizerEmail()).'/events/'.rawurlencode($eventId).'?conferenceDataVersion=1');
    }

    public function cancelCalendarEvent(string $eventId):array{
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$eventId))throw new \InvalidArgumentException('meet_calendar_event_id_invalid');
        return $this->call('PATCH','/calendar/v3/calendars/'.rawurlencode($this->organizerEmail()).'/events/'.rawurlencode($eventId).'?conferenceDataVersion=1&sendUpdates=none',array('status'=>'cancelled'));
    }

    // ---- Meet -------------------------------------------------------------------------------
    /**
     * Create an app-owned meeting space.
     *
     * The Meet REST API has no client-supplied idempotency key for this call, so the caller must
     * persist the returned space name immediately and must not blindly repeat an ambiguous create.
     */
    public function createSpace():array{
        return $this->call('POST','/v2/spaces',new \stdClass());
    }

    public function getSpace(string $spaceOrCode):array{
        $id=trim($spaceOrCode);
        if(!preg_match('/^(spaces\/)?[A-Za-z0-9_-]{3,128}$/D',$id))throw new \InvalidArgumentException('meet_space_name_invalid');
        // The REST path is always /v2/spaces/{space}. A caller may pass the full resource name
        // `spaces/{space}` or the documented meeting-code alias; strip the resource prefix before
        // URL-encoding so the slash is a path separator, not %2F inside the space id.
        if(str_starts_with($id,'spaces/'))$id=substr($id,7);
        return $this->call('GET','/v2/spaces/'.rawurlencode($id));
    }

    public function patchSpace(string $spaceName,array $config,?string $updateMask=null):array{
        $space=GoogleWorkspaceClassroomRule::spaceName($spaceName);
        $mask=$updateMask??'config.accessType,config.artifactConfig.recordingConfig.autoRecordingGeneration';
        return $this->call('PATCH','/v2/'.$space.'?updateMask='.rawurlencode($mask),$config);
    }

    public function createMember(string $spaceName,string $email,string $role='COHOST'):array{
        $space=GoogleWorkspaceClassroomRule::spaceName($spaceName);
        $email=GoogleWorkspaceClassroomRule::email($email);
        if(!in_array($role,array('COHOST','ROLE_UNSPECIFIED'),true))throw new \InvalidArgumentException('meet_member_role_invalid');
        return $this->call('POST','/v2/'.$space.'/members',array('email'=>$email,'role'=>$role));
    }

    public function listMembers(string $spaceName):array{
        $space=GoogleWorkspaceClassroomRule::spaceName($spaceName);
        return $this->call('GET','/v2/'.$space.'/members?pageSize=100');
    }

    public function deleteMember(string $memberName):array{
        $name=trim($memberName);
        if(!preg_match('/^spaces\/[A-Za-z0-9_-]{3,128}\/members\/[A-Za-z0-9_-]{1,160}$/D',$name))throw new \InvalidArgumentException('meet_member_name_invalid');
        return $this->call('DELETE','/v2/'.$name);
    }

    public function endActiveConference(string $spaceName):array{
        $space=GoogleWorkspaceClassroomRule::spaceName($spaceName);
        return $this->call('POST','/v2/'.$space.':endActiveConference',array());
    }

    public function listConferenceRecords(string $meetingCode):array{
        $code=trim($meetingCode);
        if(!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/D',$code))throw new \InvalidArgumentException('meet_meeting_code_untrusted');
        $filter='space.meeting_code = "'.$code.'"';
        return $this->call('GET','/v2/conferenceRecords?pageSize=25&filter='.rawurlencode($filter));
    }

    public function listParticipants(string $conferenceRecord):array{
        $record=GoogleWorkspaceClassroomRule::conferenceRecordName($conferenceRecord);
        return $this->call('GET','/v2/'.$record.'/participants?pageSize=250');
    }

    public function listParticipantSessions(string $participant):array{
        $name=trim($participant);
        if(!preg_match('/^conferenceRecords\/[A-Za-z0-9_-]{3,160}\/participants\/[A-Za-z0-9_-]{1,160}$/D',$name))throw new \InvalidArgumentException('meet_participant_name_invalid');
        return $this->call('GET','/v2/'.$name.'/participantSessions?pageSize=250');
    }

    public function listRecordings(string $conferenceRecord):array{
        $record=GoogleWorkspaceClassroomRule::conferenceRecordName($conferenceRecord);
        return $this->call('GET','/v2/'.$record.'/recordings?pageSize=100');
    }

    // ---- Drive ------------------------------------------------------------------------------
    public function driveFile(string $fileId):array{
        $id=GoogleWorkspaceClassroomRule::driveFileId($fileId);
        $fields='id,name,mimeType,size,videoMediaMetadata,capabilities,webViewLink';
        return $this->call('GET','/drive/v3/files/'.rawurlencode($id).'?fields='.rawurlencode($fields).'&supportsAllDrives=true');
    }

    public function driveFilePermissions(string $fileId):array{
        $id=GoogleWorkspaceClassroomRule::driveFileId($fileId);
        return $this->call('GET','/drive/v3/files/'.rawurlencode($id).'/permissions?fields=permissions(id,type,role,emailAddress,domain,allowFileDiscovery)&supportsAllDrives=true');
    }

    /** Verify that the first byte of the recording is readable without downloading the file. */
    public function driveProbe(string $fileId):array{
        $id=GoogleWorkspaceClassroomRule::driveFileId($fileId);
        return $this->call('GET','/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true',null,array('Range'=>'bytes=0-0'));
    }

    /** A short-lived access token for a streaming controller. Never returned to a browser. */
    public function accessToken():string{
        return $this->token(false);
    }

    // ---- Request plumbing -------------------------------------------------------------------
    private function call(string $method,string $path,array|object|null $body=null,array $extraHeaders=array()):array{
        $origin=$this->origin($path);
        $uri=$origin.$path;
        $token=$this->token(false);
        $response=$this->send($method,$uri,$token,$body,$extraHeaders);
        if($response['status']===401){
            $token=$this->token(true);
            $response=$this->send($method,$uri,$token,$body,$extraHeaders);
        }
        $response['error_class']=$this->errorClass($response);
        $response['error_message']=$this->errorMessage($response['body']);
        return $response;
    }

    private function origin(string $path):string{
        if(str_starts_with($path,'/v2/'))return GoogleWorkspaceClassroomRule::MEET_ORIGIN;
        if(str_starts_with($path,'/drive/'))return GoogleWorkspaceClassroomRule::DRIVE_ORIGIN;
        return GoogleWorkspaceClassroomRule::CALENDAR_ORIGIN;
    }

    private function send(string $method,string $uri,string $token,array|object|null $body,array $extraHeaders):array{
        $headers=array_merge(array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json'),$extraHeaders);
        $encoded=null;
        if($body!==null){
            $encoded=wp_json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($encoded))throw new \RuntimeException('meet_request_encode_failed');
            $headers['Content-Type']='application/json';
        }
        $response=($this->transport)($method,$uri,$headers,$encoded);
        return array('status'=>(int)($response['status']??0),'body'=>is_array($response['body']??null)?$response['body']:array(),'raw'=>(string)($response['raw']??''));
    }

    private function token(bool $forceRefresh):string{
        $connection=$this->repository->activeConnection();
        if(!$connection||(string)$connection->connection_state!=='connected')throw new \RuntimeException('meet_organizer_not_connected');
        $credential=$this->repository->credential((int)$connection->id);
        if(!$credential)throw new \RuntimeException('meet_organizer_credential_required');
        $validUntil=$credential->valid_until_utc===null?0:(int)strtotime((string)$credential->valid_until_utc.' UTC');
        if($forceRefresh||$validUntil<=time()+90){
            $this->oauth->refreshConnection((int)$connection->id);
            $credential=$this->repository->credential((int)$connection->id);
            if(!$credential)throw new \RuntimeException('meet_organizer_credential_required');
        }
        $material=$this->secrets->open((int)$connection->id,'google_meet',$credential);
        $decoded=json_decode($material,true);
        $token=is_array($decoded)?trim((string)($decoded['access_token']??'')):'';
        if($token==='')throw new \RuntimeException('meet_organizer_credential_invalid');
        return $token;
    }

    private function errorClass(array $response):?string{
        if($response['status']>=200&&$response['status']<300)return null;
        $error=$response['body']['error']??null;
        if(is_array($error)){
            $status=trim((string)($error['status']??''));
            if($status!=='')return strtolower(preg_replace('/[^A-Za-z0-9_]+/','_',$status)??'provider_error');
            $errors=$error['errors']??array();
            if(is_array($errors)&&isset($errors[0]['reason']))return strtolower(preg_replace('/[^A-Za-z0-9_]+/','_',(string)$errors[0]['reason'])??'provider_error');
        }
        return $response['status']===0?'transport_unavailable':'http_'.$response['status'];
    }

    private function errorMessage(array $body):?string{
        $message=$body['error']['message']??null;
        if(!is_string($message)||trim($message)==='')return null;
        $message=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($message))??'';
        $message=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[redacted-email]',$message)??'';
        return function_exists('mb_substr')?mb_substr($message,0,240,'UTF-8'):substr($message,0,240);
    }
}
