<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Phase 2A.2-V provider-neutral integration rule: the controlled vocabulary and the pure facts the
 * Google Calendar/Meet adapter boundary and the integration authority both have to agree on.
 *
 * The domain never names a provider object. `provider_code` is a controlled token, every provider
 * reference value is keyed-digested before it reaches storage, and the only admitted codes at this
 * phase boundary are the Google Calendar projection code and the Google Meet conference/evidence
 * code. A free-form provider name, a raw provider payload, a raw provider key or a provider account
 * e-mail is never accepted here.
 *
 * This class owns no authority: it decides nothing about identity, scheduling, delivery, attendance,
 * settlement, payment or notification. It only states what the owning modules are allowed to be told.
 */
final class ProviderIntegrationRule {
    public const RULE_VERSION='provider_neutral_google_calendar_meet_v1';
    public const COMMAND_DOMAIN='provider_integration_v1';
    /** Admitted provider codes at the Phase-V adapter boundary. */
    public const PROVIDER_CODES=array('google_calendar','google_meet');
    /** Provider codes Phase V may submit to the Phase-P evidence seam. */
    public const EVIDENCE_PROVIDER_CODES=array('google_meet');
    public const PURPOSES=array('connection_identity','calendar_event','meeting_conference');
    public const CONNECTION_STATES=array('disconnected','authorizing','connected','refresh_failed','revoking','revoke_failed','revoked');
    public const CREDENTIAL_STATES=array('active','quarantined','revoked');
    /** A projection may be recorded as `pending` while only its provider translation exists. */
    public const MAPPING_STATES=array('pending','verified','unverified','revoked');
    public const AUTHORIZATION_STATES=array('issued','consumed','expired','rejected');
    /** Receipt states: a receipt is always written in its receive state and never mutated afterwards. */
    public const INGEST_STATES=array('received','admitted','converged','conflicted','refused');
    /** The append-only handoff outcomes a provider-event receipt may accumulate, in order. */
    public const INGEST_OUTCOMES=array('admitted','refused');
    public const CONFLICT_KINDS=array('changed_payload','cross_lesson','cross_schedule_version','cross_context','cross_participant','cross_interval');
    /** Controlled provenance channels for Phase-V commands. A free-form channel is never accepted. */
    public const EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference','provider_callback','system_ingest');
    /**
     * Controlled identities of a trusted transport that has already performed the cryptographic
     * validation of one provider delivery. Phase V never verifies a provider secret itself, so it
     * refuses a raw delivery outright and accepts only an envelope a named transport authenticated.
     */
    public const PROOF_TRANSPORTS=array('google_channel_jwt','google_pubsub_oidc','deployment_gateway');
    public const OPERATIONS=array(
        'begin_authorization','complete_authorization','refresh_connection','disconnect_connection','revoke_connection',
        'record_identity_mapping','revoke_identity_mapping','project_calendar_event','retract_calendar_projection',
        'project_meeting_conference','retract_meeting_projection',
        'acknowledge_calendar_projection','acknowledge_meeting_projection','ingest_provider_event',
    );
    /** Lifecycle exits, per the locked V-D2 owner decision. */
    private const TRANSITIONS=array(
        'disconnected'=>array('authorizing'),
        'authorizing'=>array('connected','disconnected'),
        'connected'=>array('refresh_failed','disconnected','revoking'),
        'refresh_failed'=>array('connected','disconnected','revoking'),
        'revoking'=>array('revoked','revoke_failed'),
        'revoke_failed'=>array('revoking','revoked','disconnected'),
        'revoked'=>array(),
    );
    /** Connection states in which a provider call may be attempted at all. */
    public const USABLE_CONNECTION_STATES=array('connected');

    public static function providerCode(string $code):string{
        $code=strtolower(trim($code));
        if(!in_array($code,self::PROVIDER_CODES,true))throw new \InvalidArgumentException('Controlled provider code required');
        return $code;
    }

    public static function evidenceProviderCode(string $code):string{
        $code=self::providerCode($code);
        if(!in_array($code,self::EVIDENCE_PROVIDER_CODES,true))throw new \InvalidArgumentException('provider_code_cannot_carry_attendance_evidence');
        return $code;
    }

    public static function purpose(string $purpose):string{
        if(!in_array($purpose,self::PURPOSES,true))throw new \InvalidArgumentException('Controlled mapping purpose required');
        return $purpose;
    }

    /** The identity of a trusted transport that has already authenticated one provider delivery. */
    public static function proofTransport(string $transport):string{
        $transport=strtolower(trim($transport));
        if(!in_array($transport,self::PROOF_TRANSPORTS,true))throw new \InvalidArgumentException('provider_event_unverified');
        return $transport;
    }

    /**
     * Reduce one provider delivery to the authenticated envelope only.
     *
     * Phase V performs no provider cryptography and holds no provider secret: a delivery is trusted
     * only when a named transport has already validated it and hands over the exact body it validated,
     * bound to the authenticated instant and to the transport's own proof reference. A raw delivery —
     * a bare body, a header set, or a channel token with no transport proof — is refused, so a
     * fabricated payload can never become attendance evidence. The proof reference is digested here
     * and the raw value never reaches storage.
     *
     * @param array<string,mixed> $delivery
     * @return array{provider_code:string,transport:string,authenticated_at:string,raw_body:string,body_digest:string,proof_reference_digest:string}
     */
    public static function deliveryEnvelope(array $delivery):array{
        $providerCode=self::evidenceProviderCode((string)($delivery['provider_code']??''));
        $transport=self::proofTransport((string)($delivery['transport']??''));
        if(($delivery['authenticated']??null)!==true)throw new \InvalidArgumentException('provider_event_unverified');
        $authenticatedAt=(string)($delivery['authenticated_at']??'');
        if(!self::utc($authenticatedAt)||$authenticatedAt>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('provider_event_unverified');
        $body=(string)($delivery['raw_body']??'');
        if(trim($body)==='')throw new \InvalidArgumentException('provider_event_unverified');
        $digest=trim((string)($delivery['body_digest']??''));
        if(!hash_equals(hash('sha256',$body),$digest))throw new \InvalidArgumentException('provider_event_unverified');
        $proof=trim((string)($delivery['proof_reference']??''));
        if(strlen($proof)<16)throw new \InvalidArgumentException('provider_event_unverified');
        return array(
            'provider_code'=>$providerCode,'transport'=>$transport,'authenticated_at'=>$authenticatedAt,
            'raw_body'=>$body,'body_digest'=>$digest,
            'proof_reference_digest'=>ProviderIntegrationIdempotency::proof($proof),
        );
    }

    /** Whether one recorded connection state may move to another recorded connection state. */
    public static function canTransition(string $from,string $to):bool{
        return isset(self::TRANSITIONS[$from])&&in_array($to,self::TRANSITIONS[$from],true);
    }

    public static function usable(string $state):bool{
        return in_array($state,self::USABLE_CONNECTION_STATES,true);
    }

    /**
     * Exact UTC instant shape, never a wall-clock or local value.
     *
     * A provider fact that carries no unambiguous UTC instant can never be turned into canonical
     * evidence, so the shape is validated before any digest is computed.
     */
    public static function utc(mixed $value):bool{
        return is_string($value)&&(bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)&&false!==strtotime($value.' UTC');
    }

    public static function digest(mixed $value):bool{
        return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value);
    }

    /** A redirect target is compared exactly; a prefix or suffix match is never accepted. */
    public static function sameRedirectUri(string $expected,string $candidate):bool{
        $expected=trim($expected);$candidate=trim($candidate);
        if($expected===''||$candidate==='')return false;
        if(!str_starts_with($expected,'https://')||!str_starts_with($candidate,'https://'))return false;
        return hash_equals($expected,$candidate);
    }

    /**
     * Normalise a requested scope set into a stable, ordered snapshot.
     *
     * The granted set is compared against the requested set as an exact set, so an extra granted
     * scope or a missing one both fail closed.
     */
    public static function scopeSnapshot(mixed $scopes):string{
        if(is_string($scopes))$scopes=preg_split('/\s+/',trim($scopes))?:array();
        if(!is_array($scopes))throw new \InvalidArgumentException('Controlled scope set required');
        $clean=array();
        foreach($scopes as $scope){
            $scope=trim((string)$scope);
            if($scope==='')continue;
            if(!preg_match('#^[a-z0-9_.:/@-]{3,191}$#i',$scope))throw new \InvalidArgumentException('Controlled scope set required');
            $clean[$scope]=true;
        }
        if(!$clean)throw new \InvalidArgumentException('Controlled scope set required');
        $clean=array_keys($clean);sort($clean,SORT_STRING);
        if(count($clean)>12)throw new \InvalidArgumentException('Controlled scope set required');
        return implode(' ',$clean);
    }

    public static function grantedScopesMatch(string $requested,string $granted):bool{
        return hash_equals(self::scopeSnapshot($requested),self::scopeSnapshot($granted));
    }

    /**
     * Normalise the provenance of one command.
     *
     * The reference is digested here, so a raw provider reference or a raw human reference can never
     * reach storage through an evidence field.
     */
    public static function evidenceFacts(array $input):array{
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,self::EVIDENCE_CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $reference=trim((string)($input['evidence_reference']??''));
        $at=(string)($input['evidence_at']??'');
        if($reference==='')throw new \InvalidArgumentException('Evidence reference required');
        if(!self::utc($at))throw new \InvalidArgumentException('Valid UTC evidence instant required');
        return array(
            'evidence_channel'=>$channel,
            'evidence_reference_digest'=>ProviderIntegrationIdempotency::evidence($reference),
            'evidence_at'=>$at,
        );
    }

    /**
     * The provider facts of one exact canonical schedule version.
     *
     * A projection copies these facts; it never derives an interval, never rounds and never consults
     * the wall clock. A schedule version whose provenance is incomplete cannot be projected.
     */
    public static function scheduleProjectionFacts(object $version):array{
        $starts=(string)($version->starts_at_utc??'');
        $ends=(string)($version->ends_at_utc??'');
        $timezone=(string)($version->schedule_timezone??'');
        $localDate=(string)($version->local_wall_date??'');
        $localTime=(string)($version->local_wall_time??'');
        if(!self::utc($starts)||!self::utc($ends)||$starts>=$ends)throw new \InvalidArgumentException('canonical_schedule_version_unusable');
        if($timezone===''||$localDate===''||$localTime==='')throw new \InvalidArgumentException('canonical_schedule_version_unusable');
        return array(
            'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'schedule_timezone'=>$timezone,
            'local_wall_date'=>$localDate,'local_wall_time'=>$localTime,
            'schedule_version_id'=>(int)($version->id??0),
            'version_number'=>(int)($version->version_number??0),
        );
    }
}
