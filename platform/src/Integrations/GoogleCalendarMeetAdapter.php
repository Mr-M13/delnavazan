<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationIdempotency;
use Delnavazan\Platform\Core\Application\ProviderIntegrationRule;
use Delnavazan\Platform\Core\Application\Port\{ProviderCalendarPort,ProviderEventNormalizer,ProviderMeetingPort};

/**
 * Google Calendar & Meet translation seam (Phase 2A.2-V).
 *
 * This is the only place in the plugin where a Google-specific name, scope literal or payload shape
 * is allowed to exist. It is deliberately a pure translation: it performs no HTTP request, opens no
 * OAuth client, reads no credential, configures no webhook and contacts nothing. Every method turns
 * provider-neutral facts into the exact Google request shape the caller will later send, or turns a
 * Google body that a caller already verified into provider-neutral facts.
 *
 * A caller that wants real traffic must supply its own transport outside this class. Nothing here can
 * create canonical truth, decide a participant identity, or settle attendance.
 */
final class GoogleCalendarMeetAdapter implements ProviderCalendarPort,ProviderMeetingPort,ProviderEventNormalizer {
    public const PROVIDER_CODE='google_calendar';
    public const EVIDENCE_PROVIDER_CODE='google_meet';
    /** Minimum Calendar scope set this seam is defined against; the approved set is deployment configuration. */
    public const CALENDAR_SCOPE='https://www.googleapis.com/auth/calendar.events';
    public const MEET_SCOPE='https://www.googleapis.com/auth/meetings.space.created';

    /**
     * The exact authorization request shape for one consent attempt.
     *
     * PKCE, the state value, the exact redirect target and the teacher/client/scope binding are all
     * decided by the integration authority; this method only renders what it was given and never
     * invents a client, a redirect target or an extra scope.
     *
     * @param array{client_reference:string,redirect_uri:string,scope_snapshot:string,state:string,code_challenge:string} $request
     */
    public function authorizationUri(array $request):string{
        $client=trim((string)($request['client_reference']??''));
        $redirect=(string)($request['redirect_uri']??'');
        $scopes=ProviderIntegrationRule::scopeSnapshot($request['scope_snapshot']??'');
        $state=trim((string)($request['state']??''));
        $challenge=trim((string)($request['code_challenge']??''));
        if($client===''||$state===''||$challenge==='')throw new \InvalidArgumentException('Controlled authorization request required');
        if(!str_starts_with($redirect,'https://'))throw new \InvalidArgumentException('Exact HTTPS redirect target required');
        $query=http_build_query(array(
            'client_id'=>$client,
            'redirect_uri'=>$redirect,
            'response_type'=>'code',
            'scope'=>$scopes,
            'state'=>$state,
            'code_challenge'=>$challenge,
            'code_challenge_method'=>'S256',
            'access_type'=>'offline',
            'prompt'=>'consent',
        ),'', '&', PHP_QUERY_RFC3986);
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /** @param array<string,mixed> $command */
    public function project(array $command):array{
        $starts=(string)($command['starts_at_utc']??'');$ends=(string)($command['ends_at_utc']??'');
        if(!ProviderIntegrationRule::utc($starts)||!ProviderIntegrationRule::utc($ends))throw new \InvalidArgumentException('canonical_schedule_version_unusable');
        $providerCode=ProviderIntegrationRule::providerCode((string)($command['provider_code']??''));
        $operation=(string)($command['operation']??'project');
        $lessonId=(int)($command['lesson_id']??0);$versionId=(int)($command['schedule_version_id']??0);
        if($lessonId<1||$versionId<1)throw new \InvalidArgumentException('Exact canonical Lesson and schedule version required');
        // The provider write carries only the exact canonical interval and its wall-clock provenance.
        $facts=array(
            'provider_code'=>$providerCode,'operation'=>$operation,'lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,
            'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,
            'schedule_timezone'=>(string)($command['schedule_timezone']??''),
            'local_wall_date'=>(string)($command['local_wall_date']??''),
            'local_wall_time'=>(string)($command['local_wall_time']??''),
            'teacher_subject_reference'=>isset($command['teacher_subject_reference'])?(string)$command['teacher_subject_reference']:null,
            'projection_reference'=>isset($command['projection_reference'])?(string)$command['projection_reference']:null,
        );
        // This seam has no transport, no credential and no HTTP client, so it can never return an
        // acknowledged provider reference. It returns the exact request a transport would send and
        // explicitly reports the projection as unacknowledged: the authority records it as `pending`
        // and only a separate acknowledged provider result may ever mark the mapping verified.
        $request=$this->requestShape($facts);
        return array(
            'provider_code'=>$providerCode,
            'provider_request'=>$request,
            'google_request'=>$request,
            'provider_facts_digest'=>ProviderIntegrationIdempotency::payload($facts),
            'provider_occurred_at_utc'=>gmdate('Y-m-d H:i:s'),
            'acknowledged'=>false,
        );
    }

    /**
     * @param array<string,mixed> $envelope an authenticated delivery envelope, never a raw delivery
     */
    public function verify(array $envelope):bool{
        // A Google channel token is a routing header, never proof of authenticity: anyone can send
        // one. Only an envelope that names the trusted transport which performed the cryptographic
        // validation, binds the exact raw body by digest and carries that transport's own proof
        // reference is accepted here, so a fabricated delivery can never reach the normaliser.
        try{
            ProviderIntegrationRule::deliveryEnvelope($envelope);
        }catch(\InvalidArgumentException){
            return false;
        }
        $body=(string)($envelope['raw_body']??'');
        return $body!==''&&is_array(json_decode($body,true));
    }

    /**
     * @param array<string,mixed> $envelope the authenticated envelope the caller already verified
     */
    public function normalise(array $envelope):array{
        $decoded=json_decode((string)($envelope['raw_body']??''),true);
        if(!is_array($decoded))throw new \InvalidArgumentException('Provider event body required');
        $eventKey=trim((string)($decoded['event_key']??''));
        $account=trim((string)($decoded['participant_reference']??''));
        $observed=(string)($decoded['observed_at']??'');
        if($eventKey===''||$account===''||!ProviderIntegrationRule::utc($observed))throw new \InvalidArgumentException('Provider event fact incomplete');
        $role=(string)($decoded['participant_role']??'');
        $join=isset($decoded['join_at_utc'])?(string)$decoded['join_at_utc']:null;
        $leave=isset($decoded['leave_at_utc'])?(string)$decoded['leave_at_utc']:null;
        foreach(array($join,$leave) as $instant)if($instant!==null&&!ProviderIntegrationRule::utc($instant))throw new \InvalidArgumentException('Provider event fact incomplete');
        // An occurrence binding only ever comes from the authenticated body the projection wrote into
        // the provider object, never from a caller-supplied hint.
        $lessonId=$decoded['lesson_id']??$decoded['dzn_lesson_id']??null;
        $versionId=$decoded['schedule_version_id']??$decoded['dzn_schedule_version_id']??null;
        return array(
            'provider_code'=>self::EVIDENCE_PROVIDER_CODE,
            'provider_event_key'=>$eventKey,
            'provider_payload_key'=>(string)($decoded['payload_key']??$eventKey),
            'participant_role'=>$role,
            'provider_account_key'=>$account,
            'observed_at'=>$observed,
            'join_at_utc'=>$join,
            'leave_at_utc'=>$leave,
            'lesson_id'=>$lessonId===null?null:(int)$lessonId,
            'schedule_version_id'=>$versionId===null?null:(int)$versionId,
            'provenance_reference'=>(string)($decoded['provenance_reference']??('google-meet-'.$eventKey)),
            'evidence_reference'=>(string)($decoded['evidence_reference']??('google-meet-event-'.$eventKey)),
        );
    }

    /** The literal Google request shape; a transport maps it to HTTP and nothing else consumes it. */
    private function requestShape(array $facts):array{
        $summary='Lesson '.$facts['lesson_id'].' (schedule version '.$facts['schedule_version_id'].')';
        return array(
            'method'=>$facts['operation']==='retract'?'DELETE':'POST',
            'path'=>$facts['provider_code']===self::PROVIDER_CODE?'/calendar/v3/calendars/primary/events':'/v2/spaces',
            'body'=>array(
                'summary'=>$summary,
                'start'=>array('dateTime'=>$this->rfc3339($facts['starts_at_utc']),'timeZone'=>$facts['schedule_timezone']),
                'end'=>array('dateTime'=>$this->rfc3339($facts['ends_at_utc']),'timeZone'=>$facts['schedule_timezone']),
                'conferenceDataVersion'=>1,
                'privateExtendedProperties'=>array(
                    'dzn_lesson_id'=>(string)$facts['lesson_id'],
                    'dzn_schedule_version_id'=>(string)$facts['schedule_version_id'],
                    'dzn_local_wall_date'=>$facts['local_wall_date'],
                    'dzn_local_wall_time'=>$facts['local_wall_time'],
                ),
            ),
        );
    }

    private function rfc3339(string $utc):string{
        return str_replace(' ','T',substr($utc,0,19)).'Z';
    }
}
