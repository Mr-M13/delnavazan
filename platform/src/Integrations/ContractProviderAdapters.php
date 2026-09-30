<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationIdempotency;
use Delnavazan\Platform\Core\Application\ProviderIntegrationRule;
use Delnavazan\Platform\Core\Application\Port\{ProviderCalendarPort,ProviderEventNormalizer,ProviderMeetingPort,ProviderOAuthPort};

/**
 * Deterministic no-I/O provider adapters used by the Phase-V evidence runs.
 *
 * Every method is a pure function of its input: no HTTP, no socket, no filesystem, no clock other
 * than the caller's explicit instant, and no credential. The adapters record the calls they received
 * in memory so the runtime suites can assert exactly what the authority asked for, and they return
 * synthetic provider references derived from the canonical facts, which is what a real provider would
 * return as an opaque reference.
 *
 * A test or a local rehearsal may therefore execute the whole integration path without contacting a
 * provider, without a live credential and without production data.
 */
final class ContractProviderAdapters implements ProviderOAuthPort,ProviderCalendarPort,ProviderMeetingPort,ProviderEventNormalizer {
    /** The synthetic transport has already acknowledged every provider write it was asked for. */
    public const PROJECTION_ACKNOWLEDGED='acknowledged';
    /** The synthetic transport only translates, mirroring the real Google seam. */
    public const PROJECTION_PENDING='pending';
    /** @var array<int,array{port:string,call:array<string,mixed>}> */
    private array $calls=array();
    private array $validatedTokens=array();
    private string $projectionMode=self::PROJECTION_ACKNOWLEDGED;

    /** @param array<string,string> $validatedTokens map of credential material => raw provider subject reference */
    public function __construct(array $validatedTokens=array(),string $projectionMode=self::PROJECTION_ACKNOWLEDGED){
        $this->validatedTokens=$validatedTokens;
        if(!in_array($projectionMode,array(self::PROJECTION_ACKNOWLEDGED,self::PROJECTION_PENDING),true))throw new \InvalidArgumentException('Controlled projection mode required');
        $this->projectionMode=$projectionMode;
    }

    /** @return array<int,array{port:string,call:array<string,mixed>}> */
    public function calls():array{return $this->calls;}

    public function count(string $port):int{
        $total=0;foreach($this->calls as $call)if($call['port']===$port)$total++;
        return $total;
    }

    public function authorizationUri(array $request):string{
        $this->calls[]=array('port'=>'oauth','call'=>array('operation'=>'authorization_uri','request'=>$this->safe($request)));
        return 'https://accounts.example.invalid/o/oauth2/v2/auth?state='.rawurlencode((string)($request['state']??''));
    }

    public function exchange(array $request):array{
        $this->calls[]=array('port'=>'oauth','call'=>array('operation'=>'exchange','request'=>$this->safe($request)));
        $subject='subject-'.substr(ProviderIntegrationIdempotency::evidence((string)($request['code']??'')),0,16);
        $material='material-'.substr(ProviderIntegrationIdempotency::providerPayload((string)($request['code']??'')),0,24);
        return array('granted_scope_snapshot'=>(string)($request['scope_snapshot']??''),'provider_subject_reference'=>$subject,'credential_material'=>$material,'consent_version'=>'consent-v1');
    }

    public function refresh(array $connection):array{
        $this->calls[]=array('port'=>'oauth','call'=>array('operation'=>'refresh','request'=>$this->safe($connection)));
        return array('credential_material'=>(string)($connection['credential_material']??''),'access_valid_until_utc'=>(string)($connection['now_utc']??''));
    }

    public function revoke(array $connection):array{
        $this->calls[]=array('port'=>'oauth','call'=>array('operation'=>'revoke','request'=>$this->safe($connection)));
        return array('revoked'=>true,'failure_reason_code'=>null);
    }

    public function inspect(array $connection):array{
        $this->calls[]=array('port'=>'oauth','call'=>array('operation'=>'inspect','request'=>$this->safe($connection)));
        $material=(string)($connection['credential_material']??'');
        if(!isset($this->validatedTokens[$material]))return array('usable'=>false,'provider_subject_reference'=>null,'granted_scope_snapshot'=>null,'failure_reason_code'=>'credential_unusable');
        return array('usable'=>true,'provider_subject_reference'=>$this->validatedTokens[$material],'granted_scope_snapshot'=>(string)($connection['scope_snapshot']??''),'failure_reason_code'=>null);
    }

    public function project(array $command):array{
        $this->calls[]=array('port'=>(string)($command['provider_code']??''),'call'=>array('operation'=>(string)($command['operation']??'project'),'request'=>$this->safe($command)));
        $facts=array('provider_code'=>(string)($command['provider_code']??''),'operation'=>(string)($command['operation']??'project'),'lesson_id'=>(int)($command['lesson_id']??0),'schedule_version_id'=>(int)($command['schedule_version_id']??0));
        $occurred=(string)($command['now_utc']??'')?:gmdate('Y-m-d H:i:s');
        if($this->projectionMode===self::PROJECTION_PENDING){
            // A translation-only result carries no provider reference at all.
            return array(
                'provider_code'=>$facts['provider_code'],
                'provider_request'=>array('method'=>'POST','path'=>'/synthetic/'.$facts['provider_code'],'body'=>array('lesson_id'=>$facts['lesson_id'],'schedule_version_id'=>$facts['schedule_version_id'])),
                'provider_facts_digest'=>ProviderIntegrationIdempotency::payload($facts),
                'provider_occurred_at_utc'=>$occurred,
                'acknowledged'=>false,
            );
        }
        $reference='ref-'.substr(ProviderIntegrationIdempotency::payload($facts),0,24);
        return array('provider_object_reference'=>$reference,'join_uri_reference'=>$reference.'-join','provider_occurred_at_utc'=>$occurred,'acknowledged'=>true);
    }

    /**
     * Whether this delivery is an envelope the synthetic transport authenticated over the exact body.
     *
     * @param array<string,mixed> $envelope
     */
    public function verify(array $envelope):bool{
        try{
            $envelope=ProviderIntegrationRule::deliveryEnvelope($envelope);
        }catch(\InvalidArgumentException){
            return false;
        }
        return $this->bodyFacts((string)$envelope['raw_body'])!==null;
    }

    /**
     * Translate one authenticated envelope into provider-neutral facts.
     *
     * The facts are read from the digest-bound `raw_body` the named transport authenticated — verified
     * against the envelope's own body digest — and never from a caller-supplied outer array. The
     * sanitised envelope the ingest seam derives from a delivery deliberately carries no `facts` field,
     * and an outer field would be untrusted input in any case: a caller must not be able to state the
     * facts a delivery is ingested under.
     *
     * @param array<string,mixed> $envelope the sanitised authenticated envelope the caller already validated
     * @return array{provider_code:string,provider_event_key:string,provider_payload_key:string,participant_role:string,provider_account_key:string,observed_at:string,join_at_utc:?string,leave_at_utc:?string,lesson_id:?int,schedule_version_id:?int,provenance_reference:string,evidence_reference:string}
     */
    public function normalise(array $envelope):array{
        $raw=(string)($envelope['raw_body']??'');
        $digest=trim((string)($envelope['body_digest']??''));
        if($raw===''||$digest===''||!hash_equals(hash('sha256',$raw),$digest))throw new \InvalidArgumentException('Provider event body required');
        $decoded=$this->bodyFacts($raw);
        if($decoded===null)throw new \InvalidArgumentException('Provider event body required');
        $providerCode=ProviderIntegrationRule::evidenceProviderCode((string)($envelope['provider_code']??''));
        $eventKey=trim((string)($decoded['provider_event_key']??''));
        $account=trim((string)($decoded['provider_account_key']??''));
        $role=(string)($decoded['participant_role']??'');
        $observed=(string)($decoded['observed_at']??'');
        if($eventKey==='')throw new \InvalidArgumentException('Provider event key required');
        if($account==='')throw new \InvalidArgumentException('Provider account identity required');
        if(!in_array($role,array('teacher','student'),true))throw new \InvalidArgumentException('Controlled participant role required');
        if(!ProviderIntegrationRule::utc($observed))throw new \InvalidArgumentException('Valid UTC observed time required');
        $join=isset($decoded['join_at_utc'])?(string)$decoded['join_at_utc']:null;
        $leave=isset($decoded['leave_at_utc'])?(string)$decoded['leave_at_utc']:null;
        foreach(array($join,$leave) as $instant)if($instant!==null&&!ProviderIntegrationRule::utc($instant))throw new \InvalidArgumentException('Valid UTC join instant required');
        return array(
            'provider_code'=>$providerCode,
            'provider_event_key'=>$eventKey,
            'provider_payload_key'=>(string)($decoded['provider_payload_key']??$eventKey),
            'participant_role'=>$role,
            'provider_account_key'=>$account,
            'observed_at'=>$observed,
            'join_at_utc'=>$join,
            'leave_at_utc'=>$leave,
            // An occurrence binding only ever comes from the authenticated body, never from a hint.
            'lesson_id'=>isset($decoded['lesson_id'])?(int)$decoded['lesson_id']:null,
            'schedule_version_id'=>isset($decoded['schedule_version_id'])?(int)$decoded['schedule_version_id']:null,
            'provenance_reference'=>(string)($decoded['provenance_reference']??('provider-event-'.$eventKey)),
            'evidence_reference'=>(string)($decoded['evidence_reference']??('provider-event-ref-'.$eventKey)),
        );
    }

    /**
     * Decode one authenticated provider body.
     *
     * @return array<string,mixed>|null null when the body is empty or is not a JSON object
     */
    private function bodyFacts(string $raw):?array{
        if(trim($raw)==='')return null;
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:null;
    }

    /** Never record credential material in the call log. */
    private function safe(array $value):array{
        foreach($value as $key=>$item){
            if(str_contains((string)$key,'material')||str_contains((string)$key,'secret')||str_contains((string)$key,'token'))$value[$key]='[redacted]';
            elseif(is_array($item))$value[$key]=$this->safe($item);
        }
        return $value;
    }
}
