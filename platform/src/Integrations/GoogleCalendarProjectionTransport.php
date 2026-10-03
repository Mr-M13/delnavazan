<?php
namespace Delnavazan\Platform\Integrations;

/**
 * Live HTTP transport for an already-authorised Google Calendar projection request.
 *
 * It owns no canonical state and persists nothing. The caller supplies credential material only for
 * the duration of one dispatch. A deterministic Calendar event id makes POST retries recoverable:
 * HTTP 409 converges by reading that exact event instead of creating another object.
 */
final class GoogleCalendarProjectionTransport {
    private const CALENDAR_ORIGIN='https://www.googleapis.com';

    /** @return array{provider_object_reference:string,join_uri_reference:?string,provider_occurred_at_utc:string,conference_pending:bool} */
    public function dispatch(array $request,string $credentialMaterial):array{
        if((string)($request['origin']??'')!==self::CALENDAR_ORIGIN||(string)($request['path']??'')!=='/calendar/v3/calendars/primary/events')throw new \InvalidArgumentException('google_calendar_request_not_allowed');
        if((string)($request['method']??'')!=='POST'||!is_array($request['body']??null)||!is_array($request['query']??null))throw new \InvalidArgumentException('google_calendar_request_incomplete');
        $eventId=trim((string)($request['body']['id']??''));
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$eventId))throw new \InvalidArgumentException('google_calendar_event_id_invalid');
        $access=$this->accessToken($credentialMaterial);
        $uri=self::CALENDAR_ORIGIN.$request['path'].'?'.http_build_query($request['query'],'','&',PHP_QUERY_RFC3986);
        $response=$this->send('POST',$uri,$access,$request['body']);
        if($response['code']===409){
            $response=$this->send('GET',self::CALENDAR_ORIGIN.'/calendar/v3/calendars/primary/events/'.rawurlencode($eventId).'?conferenceDataVersion=1',$access,null);
        }
        if($response['code']<200||$response['code']>=300)throw new \RuntimeException('google_calendar_projection_refused');
        $body=$response['body'];
        $reference=trim((string)($body['id']??''));
        if($reference===''||!hash_equals($eventId,$reference))throw new \RuntimeException('google_calendar_projection_identity_mismatch');
        $join=$this->joinUri($body);
        return array(
            'provider_object_reference'=>$reference,
            'join_uri_reference'=>$join,
            'provider_occurred_at_utc'=>gmdate('Y-m-d H:i:s'),
            'conference_pending'=>$join===null,
        );
    }

    /** Read the deterministic event again when Google's asynchronous conference creation was pending. */
    public function inspect(string $eventId,string $credentialMaterial):array{
        if(!preg_match('/^[0-9a-v]{5,1024}$/D',$eventId))throw new \InvalidArgumentException('google_calendar_event_id_invalid');
        $response=$this->send('GET',self::CALENDAR_ORIGIN.'/calendar/v3/calendars/primary/events/'.rawurlencode($eventId).'?conferenceDataVersion=1',$this->accessToken($credentialMaterial),null);
        if($response['code']<200||$response['code']>=300)throw new \RuntimeException('google_calendar_projection_inspection_failed');
        $reference=trim((string)($response['body']['id']??''));
        if($reference===''||!hash_equals($eventId,$reference))throw new \RuntimeException('google_calendar_projection_identity_mismatch');
        $join=$this->joinUri($response['body']);
        return array('provider_object_reference'=>$reference,'join_uri_reference'=>$join,'conference_pending'=>$join===null,'provider_occurred_at_utc'=>gmdate('Y-m-d H:i:s'));
    }

    private function send(string $method,string $uri,string $access,?array $body):array{
        $args=array('method'=>$method,'timeout'=>15,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$access,'Accept'=>'application/json'));
        if($body!==null){$encoded=wp_json_encode($body,JSON_UNESCAPED_SLASHES);if(!is_string($encoded))throw new \RuntimeException('google_calendar_request_encode_failed');$args['headers']['Content-Type']='application/json';$args['body']=$encoded;}
        $response=wp_remote_request($uri,$args);
        if(is_wp_error($response))throw new \RuntimeException('google_calendar_transport_failed');
        $decoded=json_decode((string)wp_remote_retrieve_body($response),true);
        return array('code'=>(int)wp_remote_retrieve_response_code($response),'body'=>is_array($decoded)?$decoded:array());
    }

    private function accessToken(string $material):string{
        $decoded=json_decode($material,true);
        $token=is_array($decoded)?trim((string)($decoded['access_token']??'')):'';
        if($token==='')throw new \RuntimeException('google_calendar_credential_invalid');
        return $token;
    }

    private function joinUri(array $event):?string{
        $uri=trim((string)($event['hangoutLink']??''));
        if($uri===''){
            foreach((array)($event['conferenceData']['entryPoints']??array()) as $entry){
                if(!is_array($entry)||(string)($entry['entryPointType']??'')!=='video')continue;
                $uri=trim((string)($entry['uri']??''));if($uri!=='')break;
            }
        }
        if($uri==='')return null;
        $parts=parse_url($uri);
        if(!$parts||($parts['scheme']??'')!=='https'||strtolower((string)($parts['host']??''))!=='meet.google.com')throw new \RuntimeException('google_meet_join_uri_untrusted');
        return $uri;
    }
}
