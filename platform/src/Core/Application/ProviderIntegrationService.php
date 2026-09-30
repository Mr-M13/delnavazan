<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Port\{ProviderCalendarPort,ProviderMeetingPort,ProviderOAuthPort};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository,ProviderIntegrationRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider-neutral integration authority (Phase 2A.2-V).
 *
 * It owns exactly one thing: the recorded lifecycle of one Teacher's provider connection and the
 * integration references that hang off it. It never owns canonical identity, scheduling, delivery,
 * attendance, settlement, payment or notification truth.
 *
 *  - the Teacher OAuth lifecycle is per Core Teacher + provider code, one active connection at a time,
 *    with append-preserving history;
 *  - a reconnect always starts a new consent flow and can never resurrect a revoked or quarantined
 *    credential;
 *  - the authorization state is generated server-side, stored only as a digest, bound to the exact
 *    principal, Teacher, client, scope set and redirect target, and consumed exactly once;
 *  - reusable credentials are sealed by {@see IntegrationSecretService} and are never persisted,
 *    returned, logged or digested in the clear;
 *  - a calendar projection mirrors one exact applicable canonical schedule version and is a provider
 *    write, never canonical schedule authority;
 *  - provider participation facts are handed to the Phase-P intake seam; this service never writes
 *    Phase-O storage and never resolves a participant identity;
 *  - every command persists digest-only key and payload evidence and replays by unconditionally
 *    recomparing the reconstructed payload digest.
 */
final class ProviderIntegrationService {
    public const CONNECT_CAPABILITY='dzn_connect_own_provider_calendar';
    public const MANAGE_CAPABILITY='dzn_manage_provider_integrations';
    public const REVOKE_CAPABILITY='dzn_revoke_provider_integrations';
    public const VIEW_CAPABILITY='dzn_view_provider_integrations';
    public const INGEST_CAPABILITY='dzn_ingest_provider_events';
    private const AUTHORIZATION_TTL_SECONDS=900;
    private const CONSENT_VERSION='provider_consent_v1';

    public function __construct(
        private ?ProviderIntegrationRepository $repository=null,
        private ?IntegrationSecretService $secrets=null,
        private ?ProviderOAuthPort $oauth=null,
        private ?ProviderCalendarPort $calendar=null,
        private ?ProviderMeetingPort $meeting=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null
    ){
        $this->repository??=new ProviderIntegrationRepository();
        $this->secrets??=new IntegrationSecretService();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        if($this->oauth===null||$this->calendar===null||$this->meeting===null)throw new \RuntimeException('Provider adapter ports must be supplied by the Integrations module');
    }

    /**
     * Start one consent flow for one exact Core Teacher.
     *
     * The acting principal must resolve to that Teacher through the Phase-J teacher principal link;
     * an administrator acting for another Teacher needs the integration-management capability, and a
     * Teacher may only ever connect their own calendar. The returned `state` and `code_verifier` exist
     * only in this response: neither is ever persisted or logged, and a replay of the identical command
     * returns the recorded connection result without any one-time material.
     *
     * A reconnect starts a new lifecycle: an already-connected connection keeps its active slot and
     * stays usable while the new consent is pending, and the new consent settles every competing
     * lifecycle explicitly when it completes.
     */
    public function beginAuthorization(array $input,string $key):array{
        $actor=$this->actor();
        $providerCode=ProviderIntegrationRule::providerCode((string)($input['provider_code']??''));
        $teacherId=(int)($input['teacher_id']??0);
        $own=$this->resolvesOwnTeacher($actor,$teacherId);
        if(!$own)$this->requireCapability(self::MANAGE_CAPABILITY);
        elseif(!current_user_can(self::CONNECT_CAPABILITY))throw new \RuntimeException('Unauthorized');
        if($teacherId<1)throw new \InvalidArgumentException('canonical_teacher_required');
        $clientReference=trim((string)($input['client_reference']??''));
        if($clientReference===''||strlen($clientReference)>64)throw new \InvalidArgumentException('Controlled client reference required');
        $redirectUri=(string)($input['redirect_uri']??'');
        if(!str_starts_with($redirectUri,'https://'))throw new \InvalidArgumentException('Exact HTTPS redirect target required');
        $scopeSnapshot=ProviderIntegrationRule::scopeSnapshot($input['scope_snapshot']??'');
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        // The idempotency digest is taken over the deterministic request facts only. The one-time
        // state, the PKCE verifier and the expiry are generated strictly after the recorded-command
        // check, so repeating one command key reconstructs the identical digest and converges instead
        // of minting fresh randomness that could never match its own recorded payload.
        $facts=array(
            'domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'begin_authorization',
            'provider_code'=>$providerCode,'teacher_id'=>$teacherId,'principal_user_id'=>$actor,
            'client_reference'=>$clientReference,'redirect_uri'=>$redirectUri,'scope_snapshot'=>$scopeSnapshot,
        );
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $digest=ProviderIntegrationIdempotency::key($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,'begin_authorization',$this->authorizationReplayResult($winner,$facts));
                $this->repository->commit();
                return $replay;
            }
            $teacher=$this->repository->teacher($teacherId,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \InvalidArgumentException('canonical_teacher_required');
            // Revalidate the principal link inside the transaction: the link may have been revoked
            // between the capability check and this write.
            if(!$this->resolvesOwnTeacher($actor,$teacherId)&&!current_user_can(self::MANAGE_CAPABILITY))throw new \RuntimeException('Unauthorized');
            $now=gmdate('Y-m-d H:i:s');
            $expiresAt=gmdate('Y-m-d H:i:s',time()+self::AUTHORIZATION_TTL_SECONDS);
            $state=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
            $stateDigest=ProviderIntegrationIdempotency::evidence($state);
            $verifierDigest=ProviderIntegrationIdempotency::evidence($verifier);
            $sequence=$this->repository->maxLifecycleSequence($providerCode,$teacherId)+1;
            $connectionId=$this->repository->insertConnection(array(
                'uid'=>Identifier::uid(),'provider_code'=>$providerCode,'teacher_id'=>$teacherId,
                'lifecycle_sequence'=>$sequence,'connection_state'=>'authorizing','connection_version'=>1,
                'active_slot'=>null,'scope_snapshot'=>$scopeSnapshot,'consent_version'=>self::CONSENT_VERSION,
                'identity_digest'=>null,'identity_state'=>'unverified','failure_reason_code'=>null,
                'authorized_at'=>$now,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            // The intent is bound to exactly the connection this command created, so completing it can
            // only ever settle that one lifecycle — never whichever sibling happens to be newest.
            $authorizationId=$this->repository->insertAuthorization(array(
                'uid'=>Identifier::uid(),'provider_code'=>$providerCode,'teacher_id'=>$teacherId,
                'connection_id'=>$connectionId,
                'principal_user_id'=>$actor,'client_reference'=>$clientReference,'redirect_uri'=>$redirectUri,
                'scope_snapshot'=>$scopeSnapshot,'state_digest'=>$stateDigest,'verifier_digest'=>$verifierDigest,
                'authorization_state'=>'issued','issued_at'=>$now,'expires_at'=>$expiresAt,
                'consumed_at'=>null,'consumption_result'=>null,'failure_reason_code'=>null,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->recordCommand($digest,$payload,'begin_authorization',$providerCode,$teacherId,$connectionId,null,null,$authorizationId,'authorizing',$now,$actor);
            $this->repository->commit();
            return array(
                'connection_id'=>$connectionId,'authorization_id'=>$authorizationId,'provider_code'=>$providerCode,
                'teacher_id'=>$teacherId,'state'=>$state,'code_verifier'=>$verifier,'code_challenge'=>$challenge,
                'code_challenge_method'=>'S256','redirect_uri'=>$redirectUri,'scope_snapshot'=>$scopeSnapshot,
                'expires_at'=>$expiresAt,'authorization_state'=>'issued','evidence'=>$evidence,
                'operation'=>'begin_authorization','created'=>true,
            );
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'begin_authorization',$this->authorizationReplayResult($winner,$facts));
            throw $e;
        }
    }

    /**
     * Complete one consent flow.
     *
     * The state is consumed exactly once, the returned scope set must equal the requested set, and the
     * returned provider subject must not already belong to a different Teacher. A consumed, expired,
     * unknown or mismatched state fails closed and never revives the authorizing connection.
     */
    public function completeAuthorization(array $input,string $key):array{
        $actor=$this->actor();
        $state=trim((string)($input['state']??''));
        $code=trim((string)($input['code']??''));
        $verifier=trim((string)($input['code_verifier']??''));
        $redirectUri=(string)($input['redirect_uri']??'');
        if($state===''||$code===''||$verifier===''||!str_starts_with($redirectUri,'https://'))throw new \InvalidArgumentException('Controlled authorization completion required');
        $stateDigest=ProviderIntegrationIdempotency::evidence($state);
        $verifierDigest=ProviderIntegrationIdempotency::evidence($verifier);
        $digest=ProviderIntegrationIdempotency::key($key);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            $intent=$this->repository->authorizationByState($stateDigest,true);
            if(!$intent)throw new \InvalidArgumentException('authorization_state_invalid');
            $facts=array(
                'domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'complete_authorization',
                'authorization_id'=>(int)$intent->id,'provider_code'=>(string)$intent->provider_code,
                'teacher_id'=>(int)$intent->teacher_id,'state_digest'=>$stateDigest,'verifier_digest'=>$verifierDigest,
                'redirect_uri'=>$redirectUri,'code_digest'=>ProviderIntegrationIdempotency::evidence($code),
            );
            $payload=ProviderIntegrationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,'complete_authorization',array('connection_id'=>$winner->connection_id===null?null:(int)$winner->connection_id,'connection_state'=>'connected','operation'=>'complete_authorization','idempotent'=>true));
                $this->repository->commit();
                return $replay;
            }
            $providerCode=(string)$intent->provider_code;
            $teacherId=(int)$intent->teacher_id;
            if((string)$intent->authorization_state!=='issued')throw new \InvalidArgumentException('authorization_state_already_settled');
            if((string)$intent->expires_at<$now){$this->repository->updateAuthorization((int)$intent->id,array('authorization_state'=>'expired','failure_reason_code'=>'authorization_expired'),array());throw new \InvalidArgumentException('authorization_state_expired');}
            if(!hash_equals((string)$intent->verifier_digest,$verifierDigest))throw new \InvalidArgumentException('authorization_verifier_mismatch');
            if(!ProviderIntegrationRule::sameRedirectUri((string)$intent->redirect_uri,$redirectUri))throw new \InvalidArgumentException('authorization_redirect_mismatch');
            $this->requireCapabilityFor($actor,$teacherId,array(self::CONNECT_CAPABILITY,self::MANAGE_CAPABILITY));
            // Complete exactly the lifecycle this intent was issued for: never whichever authorizing
            // sibling happens to be newest. Two pending attempts therefore settle themselves, instead
            // of one state being able to finish another lifecycle.
            $connectionId=(int)$intent->connection_id;
            if($connectionId<1)throw new \InvalidArgumentException('authorization_connection_required');
            $connection=$this->repository->connection($connectionId,true);
            if(!$connection
                ||(string)$connection->provider_code!==$providerCode
                ||(int)$connection->teacher_id!==$teacherId
                ||(string)$connection->connection_state!=='authorizing')throw new \InvalidArgumentException('authorization_connection_required');
            $this->settleCompetingLifecycles($providerCode,$teacherId,$connectionId,$now,$actor);
            $exchanged=$this->oauth->exchange(array(
                'client_reference'=>(string)$intent->client_reference,'redirect_uri'=>(string)$intent->redirect_uri,
                'code'=>$code,'code_verifier'=>$verifier,'scope_snapshot'=>(string)$intent->scope_snapshot,'now_utc'=>$now,
            ));
            $granted=(string)($exchanged['granted_scope_snapshot']??'');
            if(!ProviderIntegrationRule::grantedScopesMatch((string)$intent->scope_snapshot,$granted))throw new \InvalidArgumentException('authorization_scope_mismatch');
            $subjectReference=trim((string)($exchanged['provider_subject_reference']??''));
            $material=(string)($exchanged['credential_material']??'');
            if($subjectReference===''||$material==='')throw new \InvalidArgumentException('authorization_identity_unusable');
            $subjectDigest=ProviderIntegrationIdempotency::subject($subjectReference,'connection_identity');
            $claimed=$this->repository->activeIdentityMapping($providerCode,$subjectDigest,true);
            if($claimed&&(int)$claimed->teacher_id!==$teacherId)throw new \InvalidArgumentException('provider_identity_already_bound');
            $sealed=$this->secrets->seal($connection->id,$providerCode,$material);
            $credentialId=$this->repository->insertCredential(array_merge($sealed,array(
                'uid'=>Identifier::uid(),'connection_id'=>$connection->id,'state'=>'active',
                'valid_until_utc'=>null,'quarantined_at'=>null,'quarantine_reason_code'=>null,'revoked_at'=>null,
                'created_at'=>$now,'created_by'=>$actor,
            )));
            $mappingId=$this->mintIdentityMapping($connection->id,$providerCode,$teacherId,$subjectDigest,$now,$actor,$subjectReference,'connection_identity');
            $this->repository->updateConnection((int)$connection->id,array(
                'connection_state'=>'connected','active_slot'=>1,'connection_version'=>(int)$connection->connection_version+1,
                'identity_digest'=>$subjectDigest,'identity_state'=>'verified','connected_at'=>$now,'failure_reason_code'=>null,
                'updated_at'=>$now,'updated_by'=>$actor,
            ),array('connection_state'=>'authorizing'));
            $this->repository->updateAuthorization((int)$intent->id,array('authorization_state'=>'consumed','consumed_at'=>$now,'consumption_result'=>'connected'),array('authorization_state'=>'issued'));
            $this->recordCommand($digest,$payload,'complete_authorization',$providerCode,$teacherId,(int)$connection->id,null,null,$mappingId,'connected',$now,$actor);
            // Deterministic gated boundary for the disposable concurrency runner: the connection,
            // credential and identity rows are written and the transaction is still open.
            do_action('dzn_phase_2a2v_after_connection_write',(int)$connection->id);
            $this->repository->commit();
            return array(
                'connection_id'=>(int)$connection->id,'connection_state'=>'connected','identity_mapping_id'=>$mappingId,
                'credential_id'=>$credentialId,'scope_snapshot'=>$granted,'consent_version'=>(string)($exchanged['consent_version']??''),
                'provider_code'=>$providerCode,'teacher_id'=>$teacherId,'operation'=>'complete_authorization','created'=>true,
            );
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'complete_authorization',array('connection_id'=>$winner->connection_id===null?null:(int)$winner->connection_id,'connection_state'=>'connected','operation'=>'complete_authorization','idempotent'=>true));
            throw $e;
        }
    }

    /** Refresh one connected (or previously failed) connection's renewable credential. */
    public function refreshConnection(int $connectionId,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'refresh_connection','connection_id'=>$connectionId);
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$replay=$this->replay($winner,$payload,'refresh_connection',$this->connectionResult($this->repository->connection($connectionId),'refresh_connection'));$this->repository->commit();return $replay;}
            $connection=$this->repository->connection($connectionId,true);
            if(!$connection)throw new \InvalidArgumentException('provider_connection_required');
            if(!in_array((string)$connection->connection_state,array('connected','refresh_failed'),true))throw new \InvalidArgumentException('provider_connection_unusable');
            $credential=$this->repository->credential($connectionId,true);
            if(!$credential)throw new \InvalidArgumentException('provider_credential_required');
            $material=$this->secrets->open($connectionId,(string)$connection->provider_code,$credential);
            try{
                $refreshed=$this->oauth->refresh(array('credential_material'=>$material,'scope_snapshot'=>(string)$connection->scope_snapshot,'now_utc'=>$now));
                $replacement=$this->secrets->seal($connectionId,(string)$connection->provider_code,(string)$refreshed['credential_material']);
                $this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'rotated'),array('state'=>'active'));
                $this->repository->insertCredential(array_merge($replacement,array(
                    'uid'=>Identifier::uid(),'connection_id'=>$connectionId,'state'=>'active',
                    'valid_until_utc'=>$refreshed['access_valid_until_utc']??null,'quarantined_at'=>null,
                    'quarantine_reason_code'=>null,'revoked_at'=>null,'created_at'=>$now,'created_by'=>$actor,
                )));
                $this->repository->updateConnection($connectionId,array('connection_state'=>'connected','connection_version'=>(int)$connection->connection_version+1,'failure_reason_code'=>null,'updated_at'=>$now,'updated_by'=>$actor),array());
                $state='connected';
            }catch(\Throwable$refreshFailure){
                $this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'refresh_failed'),array('state'=>'active'));
                $this->repository->updateConnection($connectionId,array('connection_state'=>'refresh_failed','active_slot'=>null,'connection_version'=>(int)$connection->connection_version+1,'failure_reason_code'=>'credential_refresh_failed','updated_at'=>$now,'updated_by'=>$actor),array());
                $state='refresh_failed';
            }
            $this->recordCommand($digest,$payload,'refresh_connection',(string)$connection->provider_code,(int)$connection->teacher_id,$connectionId,null,null,null,$state,$now,$actor);
            $this->repository->commit();
            return array('connection_id'=>$connectionId,'connection_state'=>$state,'evidence'=>$evidence,'operation'=>'refresh_connection','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'refresh_connection',$this->connectionResult($this->repository->connection($connectionId),'refresh_connection'));
            throw $e;
        }
    }

    /**
     * Disconnect one connection locally.
     *
     * The credential is quarantined immediately and the connection becomes unusable without any
     * provider call. It is never a revoke: the provider may still hold the grant until an explicit
     * revoke records its outcome.
     */
    public function disconnectConnection(int $connectionId,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'disconnect_connection','connection_id'=>$connectionId);
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$replay=$this->replay($winner,$payload,'disconnect_connection',$this->connectionResult($this->repository->connection($connectionId),'disconnect_connection'));$this->repository->commit();return $replay;}
            $connection=$this->repository->connection($connectionId,true);
            if(!$connection)throw new \InvalidArgumentException('provider_connection_required');
            if(!ProviderIntegrationRule::canTransition((string)$connection->connection_state,'disconnected'))throw new \InvalidArgumentException('provider_connection_transition_refused');
            $credential=$this->repository->credential($connectionId,true);
            if($credential)$this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'disconnected'),array('state'=>'active'));
            $this->repository->updateConnection($connectionId,array('connection_state'=>'disconnected','active_slot'=>null,'connection_version'=>(int)$connection->connection_version+1,'disconnected_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor),array());
            $this->quarantineIdentityMappings((string)$connection->provider_code,(int)$connection->teacher_id,$now,$actor,'disconnected');
            $this->recordCommand($digest,$payload,'disconnect_connection',(string)$connection->provider_code,(int)$connection->teacher_id,$connectionId,null,null,null,'disconnected',$now,$actor);
            $this->repository->commit();
            return array('connection_id'=>$connectionId,'connection_state'=>'disconnected','evidence'=>$evidence,'operation'=>'disconnect_connection','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'disconnect_connection',$this->connectionResult($this->repository->connection($connectionId),'disconnect_connection'));
            throw $e;
        }
    }

    /**
     * Request provider revocation.
     *
     * A failed provider revoke is recorded as a retryable outcome and never leaves the connection
     * usable locally: the credential is quarantined before the provider is asked.
     */
    public function revokeConnection(int $connectionId,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::REVOKE_CAPABILITY);
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'revoke_connection','connection_id'=>$connectionId);
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$replay=$this->replay($winner,$payload,'revoke_connection',$this->connectionResult($this->repository->connection($connectionId),'revoke_connection'));$this->repository->commit();return $replay;}
            $connection=$this->repository->connection($connectionId,true);
            if(!$connection)throw new \InvalidArgumentException('provider_connection_required');
            if(!in_array((string)$connection->connection_state,array('connected','refresh_failed','revoking','revoke_failed'),true))throw new \InvalidArgumentException('provider_connection_transition_refused');
            $credential=$this->repository->credential($connectionId,true);
            if($credential)$this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'revoking'),array('state'=>'active'));
            $this->quarantineIdentityMappings((string)$connection->provider_code,(int)$connection->teacher_id,$now,$actor,'revoking');
            $this->repository->updateConnection($connectionId,array('connection_state'=>'revoking','active_slot'=>null,'connection_version'=>(int)$connection->connection_version+1,'updated_at'=>$now,'updated_by'=>$actor),array());
            $revoked=$this->oauth->revoke(array('credential_material'=>$credential?'[sealed]':'','now_utc'=>$now));
            if(!empty($revoked['revoked'])){
                $state='revoked';
                $this->repository->updateConnection($connectionId,array('connection_state'=>'revoked','revoked_at'=>$now,'failure_reason_code'=>null,'updated_at'=>$now,'updated_by'=>$actor),array());
                if($credential)$this->repository->updateCredential((int)$credential->id,array('state'=>'revoked','revoked_at'=>$now),array());
            }else{
                $state='revoke_failed';
                $this->repository->updateConnection($connectionId,array('connection_state'=>'revoke_failed','failure_reason_code'=>(string)($revoked['failure_reason_code']??'provider_revoke_failed'),'updated_at'=>$now,'updated_by'=>$actor),array());
            }
            $this->recordCommand($digest,$payload,'revoke_connection',(string)$connection->provider_code,(int)$connection->teacher_id,$connectionId,null,null,null,$state,$now,$actor);
            $this->repository->commit();
            return array('connection_id'=>$connectionId,'connection_state'=>$state,'retryable'=>$state==='revoke_failed','evidence'=>$evidence,'operation'=>'revoke_connection','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'revoke_connection',$this->connectionResult($this->repository->connection($connectionId),'revoke_connection'));
            throw $e;
        }
    }

    /** Record or refresh the connection-identity mapping of one provider subject for one Teacher. */
    public function recordIdentityMapping(array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $providerCode=ProviderIntegrationRule::providerCode((string)($input['provider_code']??''));
        $teacherId=(int)($input['teacher_id']??0);
        $reference=trim((string)($input['provider_subject_reference']??''));
        $state=(string)($input['mapping_state']??'verified');
        if(!in_array($state,array('verified','unverified'),true))throw new \InvalidArgumentException('Controlled mapping state required');
        if($teacherId<1||$reference==='')throw new \InvalidArgumentException('canonical_teacher_required');
        $subjectDigest=ProviderIntegrationIdempotency::subject($reference,'connection_identity');
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'record_identity_mapping','provider_code'=>$providerCode,'teacher_id'=>$teacherId,'subject_digest'=>$subjectDigest,'mapping_state'=>$state);
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$replay=$this->replay($winner,$payload,'record_identity_mapping',array('mapping_id'=>$winner->mapping_id===null?null:(int)$winner->mapping_id,'operation'=>'record_identity_mapping','idempotent'=>true));$this->repository->commit();return $replay;}
            $teacher=$this->repository->teacher($teacherId,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \InvalidArgumentException('canonical_teacher_required');
            $claimed=$this->repository->activeIdentityMapping($providerCode,$subjectDigest,true);
            if($claimed&&(int)$claimed->teacher_id!==$teacherId)throw new \InvalidArgumentException('provider_identity_already_bound');
            $connection=$this->repository->activeConnection($providerCode,$teacherId,true);
            $mappingId=$this->mintIdentityMapping($connection?(int)$connection->id:null,$providerCode,$teacherId,$subjectDigest,$now,$actor,$reference,'connection_identity',$state,$evidence);
            if($state==='verified'&&$connection){
                $this->repository->updateConnection((int)$connection->id,array('identity_digest'=>$subjectDigest,'identity_state'=>'verified','updated_at'=>$now,'updated_by'=>$actor),array());
            }
            $this->recordCommand($digest,$payload,'record_identity_mapping',$providerCode,$teacherId,$connection?(int)$connection->id:null,null,null,$mappingId,$state,$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'mapping_state'=>$state,'teacher_id'=>$teacherId,'operation'=>'record_identity_mapping','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'record_identity_mapping',array('mapping_id'=>$winner->mapping_id===null?null:(int)$winner->mapping_id,'operation'=>'record_identity_mapping','idempotent'=>true));
            throw $e;
        }
    }

    /**
     * Revoke one connection-identity mapping so it can never again be read as verified.
     *
     * Revoking a mapping is append-preserving: the row keeps its history and gains a retirement
     * instant and a new version. It never re-identifies another Teacher, and it never reinterprets
     * evidence that was already stored under the old mapping.
     */
    public function revokeIdentityMapping(int $mappingId,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $reason=trim((string)($input['reason_code']??''));
        if($reason==='')throw new \InvalidArgumentException('Reason code required');
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>'revoke_identity_mapping','mapping_id'=>$mappingId,'reason_code'=>$reason);
        $payload=ProviderIntegrationIdempotency::payload($facts);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,'revoke_identity_mapping',array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','operation'=>'revoke_identity_mapping','idempotent'=>true));
                $this->repository->commit();
                return $replay;
            }
            $mapping=$this->repository->identityMapping($mappingId,true);
            if(!$mapping)throw new \InvalidArgumentException('integration_mapping_required');
            if(!ProviderIntegrationValidator::mappingShape($mapping,'connection_identity'))throw new \InvalidArgumentException('integration_mapping_malformed');
            $this->repository->updateIdentityMapping($mappingId,array(
                'mapping_state'=>'revoked','active_slot'=>null,'mapping_version'=>(int)$mapping->mapping_version+1,
                'revoked_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor,
            ),array('mapping_state'=>'verified'));
            $this->recordCommand($digest,$payload,'revoke_identity_mapping',(string)$mapping->provider_code,(int)$mapping->teacher_id,$mapping->connection_id===null?null:(int)$mapping->connection_id,null,null,$mappingId,'revoked',$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','evidence'=>$evidence,'operation'=>'revoke_identity_mapping','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'revoke_identity_mapping',array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','operation'=>'revoke_identity_mapping','idempotent'=>true));
            throw $e;
        }
    }

    /**
     * Project one exact applicable canonical schedule version to the calendar.
     *
     * The projection copies the canonical interval and its wall-clock provenance, and its recorded
     * result is an integration reference only. It never creates, revises or releases the schedule.
     */
    public function projectCalendarEvent(array $input,string $key):array{
        return $this->project('google_calendar',(string)($input['projection_reference']??''),$input,$key);
    }

    /** Project one exact occurrence's conference reference. A conference never proves attendance. */
    public function projectMeetingConference(array $input,string $key):array{
        return $this->project('google_meet',(string)($input['projection_reference']??''),$input,$key);
    }

    /** Retract one calendar projection: the mapping is quarantined, canonical truth is untouched. */
    public function retractCalendarProjection(int $mappingId,array $input,string $key):array{
        return $this->retract($mappingId,'calendar_event','retract_calendar_projection',$input,$key);
    }

    /** Retract one meeting projection: the conference reference stops being an active reference only. */
    public function retractMeetingProjection(int $mappingId,array $input,string $key):array{
        return $this->retract($mappingId,'meeting_conference','retract_meeting_projection',$input,$key);
    }

    /**
     * Record the acknowledged provider result for one pending calendar projection.
     *
     * A translation-only adapter (the real Google seam) never acknowledges its own write, so the
     * acknowledgement is a separate command: only this transition may mark a calendar mapping verified,
     * and the acknowledged provider reference is digested here and never stored in the clear.
     */
    public function acknowledgeCalendarProjection(int $mappingId,array $input,string $key):array{
        return $this->acknowledge($mappingId,'calendar_event','acknowledge_calendar_projection',$input,$key);
    }

    /** Record the acknowledged provider result for one pending conference projection. */
    public function acknowledgeMeetingProjection(int $mappingId,array $input,string $key):array{
        return $this->acknowledge($mappingId,'meeting_conference','acknowledge_meeting_projection',$input,$key);
    }

    private function project(string $providerCode,string $projectionReference,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $providerCode=ProviderIntegrationRule::providerCode($providerCode);
        $lessonId=(int)($input['lesson_id']??0);
        $scheduleVersionId=(int)($input['schedule_version_id']??0);
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $operation=$providerCode==='google_meet'?'project_meeting_conference':'project_calendar_event';
        $digest=ProviderIntegrationIdempotency::key($key);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            $roots=$this->repository->lockLessonRoots($lessonId);
            $applicable=ProviderIntegrationValidator::projectionApplicable($lessonId,$scheduleVersionId,$this->schedules,$this->lessons,true);
            if(!$applicable['applicable'])throw new \InvalidArgumentException((string)$applicable['reason']);
            if(!ProviderIntegrationValidator::occurrenceAggregateValid($lessonId,$this->repository,$this->schedules,$this->lessons,true))throw new \InvalidArgumentException('canonical_lesson_aggregate_invalid');
            $facts=array(
                'domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>$operation,'provider_code'=>$providerCode,
                'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                'projection_reference_digest'=>ProviderIntegrationIdempotency::subject($projectionReference,$providerCode==='google_meet'?'meeting_conference':'calendar_event'),
                'starts_at_utc'=>$applicable['facts']['starts_at_utc'],'ends_at_utc'=>$applicable['facts']['ends_at_utc'],
                'schedule_timezone'=>$applicable['facts']['schedule_timezone'],
            );
            $payload=ProviderIntegrationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,$operation,array('mapping_id'=>$winner->mapping_id===null?null:(int)$winner->mapping_id,'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'operation'=>$operation,'idempotent'=>true));
                $this->repository->commit();
                return $replay;
            }
            $existing=$providerCode==='google_meet'
                ?($this->repository->activeMeetingMapping($lessonId,$scheduleVersionId,true)??$this->repository->pendingMeetingMapping($lessonId,$scheduleVersionId,true))
                :($this->repository->activeCalendarMapping($lessonId,$scheduleVersionId,true)??$this->repository->pendingCalendarMapping($lessonId,$scheduleVersionId,true));
            if($existing)throw new \InvalidArgumentException('projection_already_recorded');
            $connection=$this->connectionForProjection($providerCode,$lessonId,$now);
            $subjectReference=$this->connectionSubjectReference((int)$connection->id,(string)$connection->provider_code,$connection);
            $command=array(
                'provider_code'=>$providerCode,'operation'=>'project','lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                'starts_at_utc'=>$applicable['facts']['starts_at_utc'],'ends_at_utc'=>$applicable['facts']['ends_at_utc'],
                'schedule_timezone'=>$applicable['facts']['schedule_timezone'],'local_wall_date'=>$applicable['facts']['local_wall_date'],
                'local_wall_time'=>$applicable['facts']['local_wall_time'],'teacher_subject_reference'=>$subjectReference,
                'projection_reference'=>$projectionReference,'now_utc'=>$now,
            );
            $result=$providerCode==='google_meet'?$this->meeting->project($command):$this->calendar->project($command);
            $objectReference=trim((string)($result['provider_object_reference']??''));
            if($objectReference===''){
                // A port without a transport (the real Google seam) can only ever return the translation
                // it would send. The translation is recorded as an explicitly `pending` projection, and
                // the mapping only becomes a verified provider reference through a separate acknowledged
                // provider result: a translation alone never claims that the provider object exists.
                $request=$result['provider_request']??$result['google_request']??null;
                $translationDigest=(string)($result['provider_facts_digest']??'');
                if(!is_array($request)||!ProviderIntegrationRule::digest($translationDigest))throw new \RuntimeException('provider_reference_unusable');
                $mappingId=$this->recordPendingProjection($providerCode,$connection,$lessonId,$scheduleVersionId,$applicable['facts'],$translationDigest,$now,$actor);
                $this->recordCommand($digest,$payload,$operation,$providerCode,(int)$connection->teacher_id,(int)$connection->id,$lessonId,$scheduleVersionId,$mappingId,'pending',$now,$actor);
                // Deterministic gated boundary: the pending translation is written and the transaction
                // is still open.
                do_action('dzn_phase_2a2v_after_projection_write',$mappingId);
                $this->repository->commit();
                return array('mapping_id'=>$mappingId,'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'provider_code'=>$providerCode,'operation'=>$operation,'projection_state'=>'pending','acknowledgement_required'=>true,'evidence'=>$evidence,'created'=>true);
            }
            $mapping=array(
                'uid'=>Identifier::uid(),'connection_id'=>(int)$connection->id,'provider_code'=>$providerCode,
                'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                'projection_state'=>'verified','mapping_version'=>1,'active_slot'=>1,'superseded_by_mapping_id'=>null,
                'starts_at_utc'=>$applicable['facts']['starts_at_utc'],'ends_at_utc'=>$applicable['facts']['ends_at_utc'],
                'schedule_timezone'=>$applicable['facts']['schedule_timezone'],'local_wall_date'=>$applicable['facts']['local_wall_date'],
                'local_wall_time'=>$applicable['facts']['local_wall_time'],
                'provider_occurred_at_utc'=>(string)($result['provider_occurred_at_utc']??$now),
                'recorded_at'=>$now,'recorded_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            );
            if($providerCode==='google_meet'){
                $mapping['conference_digest']=ProviderIntegrationIdempotency::subject($objectReference,'meeting_conference');
                $mapping['join_uri_digest']=ProviderIntegrationIdempotency::subject((string)($result['join_uri_reference']??$objectReference),'meeting_conference');
                $mappingId=$this->repository->insertMeetingMapping($mapping);
            }else{
                $mapping['event_digest']=ProviderIntegrationIdempotency::subject($objectReference,'calendar_event');
                $mappingId=$this->repository->insertCalendarMapping($mapping);
            }
            $this->recordCommand($digest,$payload,$operation,$providerCode,(int)$connection->teacher_id,(int)$connection->id,$lessonId,$scheduleVersionId,$mappingId,'verified',$now,$actor);
            // Deterministic gated boundary: the integration reference is written and the provider
            // write has already returned, while the transaction is still open.
            do_action('dzn_phase_2a2v_after_projection_write',$mappingId);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'provider_code'=>$providerCode,'operation'=>$operation,'evidence'=>$evidence,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            // A projection command whose facts were reconstructed from a canonical schedule version can
            // only be replayed from inside its transaction; a duplicate-key race therefore fails closed
            // here and converges when the caller re-issues the identical command.
            throw $e;
        }
    }

    private function retract(int $mappingId,string $purpose,string $operation,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $reason=trim((string)($input['reason_code']??''));
        if($reason==='')throw new \InvalidArgumentException('Reason code required');
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            $mapping=$purpose==='meeting_conference'?$this->repository->meetingMapping($mappingId,true):$this->repository->calendarMapping($mappingId,true);
            if(!$mapping)throw new \InvalidArgumentException('integration_mapping_required');
            if(!ProviderIntegrationValidator::mappingShape($mapping,$purpose))throw new \InvalidArgumentException('integration_mapping_malformed');
            $facts=array('domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>$operation,'mapping_id'=>$mappingId,'reason_code'=>$reason);
            $payload=ProviderIntegrationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,$operation,array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','operation'=>$operation,'idempotent'=>true));
                $this->repository->commit();
                return $replay;
            }
            // Retraction quarantines the local reference only; canonical scheduling and delivery truth
            // are never touched, and no provider delete is attempted without an authorised transport.
            // A projection that is still awaiting its acknowledged provider result is retractable too,
            // because no verified provider reference was ever recorded for it. An already-retracted
            // mapping converges on the recorded revocation instead of re-withdrawing it.
            $state=(string)$mapping->projection_state;
            if($state==='revoked')return array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','evidence'=>$evidence,'operation'=>$operation,'idempotent'=>true,'created'=>false);
            if(!in_array($state,array('pending','verified'),true))throw new \InvalidArgumentException('integration_mapping_malformed');
            $update=array('projection_state'=>'revoked','active_slot'=>null,'mapping_version'=>(int)$mapping->mapping_version+1,'revoked_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor);
            if($purpose==='meeting_conference')$this->repository->updateMeetingMapping($mappingId,$update,array('projection_state'=>$state));
            else $this->repository->updateCalendarMapping($mappingId,$update,array('projection_state'=>$state));
            $this->recordCommand($digest,$payload,$operation,(string)$mapping->provider_code,null,(int)$mapping->connection_id,(int)$mapping->lesson_id,(int)$mapping->schedule_version_id,$mappingId,'revoked',$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'mapping_state'=>'revoked','evidence'=>$evidence,'operation'=>$operation,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            // Retraction evidence is reconstructed inside the transaction, so a duplicate-key race fails
            // closed here and converges on the caller's identical re-issue.
            throw $e;
        }
    }

    /**
     * Record the provider translation of one exact occurrence without claiming the object exists.
     *
     * The row carries the translation digest as its provider reference and holds no active slot, so no
     * read, retraction or duplicate projection can treat it as a verified provider object. Only an
     * acknowledged provider result may promote it.
     */
    private function recordPendingProjection(string $providerCode,object $connection,int $lessonId,int $scheduleVersionId,array $facts,string $translationDigest,string $now,int $actor):int{
        $mapping=array(
            'uid'=>Identifier::uid(),'connection_id'=>(int)$connection->id,'provider_code'=>$providerCode,
            'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
            'projection_state'=>'pending','mapping_version'=>1,'active_slot'=>null,'superseded_by_mapping_id'=>null,
            'starts_at_utc'=>$facts['starts_at_utc'],'ends_at_utc'=>$facts['ends_at_utc'],
            'schedule_timezone'=>$facts['schedule_timezone'],'local_wall_date'=>$facts['local_wall_date'],
            'local_wall_time'=>$facts['local_wall_time'],
            'provider_occurred_at_utc'=>$now,
            'recorded_at'=>$now,'recorded_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
        );
        if($providerCode==='google_meet'){
            $mapping['conference_digest']=$translationDigest;
            $mapping['join_uri_digest']=$translationDigest;
            return $this->repository->insertMeetingMapping($mapping);
        }
        $mapping['event_digest']=$translationDigest;
        return $this->repository->insertCalendarMapping($mapping);
    }

    /**
     * Promote one pending projection with the acknowledged provider result and nothing else.
     *
     * The acknowledged reference is digested immediately, so the raw provider value never reaches
     * storage, the command evidence or a read model.
     */
    private function acknowledge(int $mappingId,string $purpose,string $operation,array $input,string $key):array{
        $actor=$this->actor();
        $this->requireCapability(self::MANAGE_CAPABILITY);
        $reference=trim((string)($input['provider_object_reference']??''));
        if($reference==='')throw new \InvalidArgumentException('Acknowledged provider reference required');
        $objectDigest=ProviderIntegrationIdempotency::subject($reference,$purpose);
        $evidence=ProviderIntegrationRule::evidenceFacts($input);
        $digest=ProviderIntegrationIdempotency::key($key);
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try{
            // The mapping is read first only as an *unlocked* hint that names its Lesson. The canonical
            // chain is then taken in the declared Phase-V order and the mapping row is locked after it:
            // locking the mapping before the chain would form the reverse cycle against a projection,
            // which locks the chain first and only then this row.
            $hint=$purpose==='meeting_conference'?$this->repository->meetingMapping($mappingId):$this->repository->calendarMapping($mappingId);
            if(!$hint)throw new \InvalidArgumentException('integration_mapping_required');
            if(!ProviderIntegrationValidator::mappingShape($hint,$purpose))throw new \InvalidArgumentException('integration_mapping_malformed');
            // The acknowledgement arrives after the projection, so the canonical occurrence is
            // revalidated under the Phase-V lock order: a version that went stale in between may never
            // be promoted to a verified provider reference.
            $this->repository->lockLessonRoots((int)$hint->lesson_id);
            $mapping=$purpose==='meeting_conference'?$this->repository->meetingMapping($mappingId,true):$this->repository->calendarMapping($mappingId,true);
            if(!$mapping||(int)$mapping->lesson_id!==(int)$hint->lesson_id)throw new \InvalidArgumentException('integration_mapping_required');
            if(!ProviderIntegrationValidator::mappingShape($mapping,$purpose))throw new \InvalidArgumentException('integration_mapping_malformed');
            $applicable=ProviderIntegrationValidator::projectionApplicable((int)$mapping->lesson_id,(int)$mapping->schedule_version_id,$this->schedules,$this->lessons,true);
            if(!$applicable['applicable'])throw new \InvalidArgumentException((string)$applicable['reason']);
            if(!ProviderIntegrationValidator::occurrenceAggregateValid((int)$mapping->lesson_id,$this->repository,$this->schedules,$this->lessons,true))throw new \InvalidArgumentException('canonical_lesson_aggregate_invalid');
            $facts=array(
                'domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>$operation,'mapping_id'=>$mappingId,
                'lesson_id'=>(int)$mapping->lesson_id,'schedule_version_id'=>(int)$mapping->schedule_version_id,
                'provider_object_digest'=>$objectDigest,
            );
            $payload=ProviderIntegrationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload,$operation,array('mapping_id'=>$mappingId,'mapping_state'=>'verified','operation'=>$operation,'idempotent'=>true));
                $this->repository->commit();
                return $replay;
            }
            if((string)$mapping->projection_state!=='pending')throw new \InvalidArgumentException('projection_not_pending');
            $update=array('projection_state'=>'verified','active_slot'=>1,'mapping_version'=>(int)$mapping->mapping_version+1,'updated_at'=>$now,'updated_by'=>$actor);
            if($purpose==='meeting_conference'){
                $update['conference_digest']=$objectDigest;
                $update['join_uri_digest']=ProviderIntegrationIdempotency::subject((string)($input['join_uri_reference']??$reference),$purpose);
                $this->repository->updateMeetingMapping($mappingId,$update,array('projection_state'=>'pending'));
            }else{
                $update['event_digest']=$objectDigest;
                $this->repository->updateCalendarMapping($mappingId,$update,array('projection_state'=>'pending'));
            }
            $this->recordCommand($digest,$payload,$operation,(string)$mapping->provider_code,null,(int)$mapping->connection_id,(int)$mapping->lesson_id,(int)$mapping->schedule_version_id,$mappingId,'verified',$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'mapping_state'=>'verified','provider_code'=>(string)$mapping->provider_code,'lesson_id'=>(int)$mapping->lesson_id,'schedule_version_id'=>(int)$mapping->schedule_version_id,'evidence'=>$evidence,'operation'=>$operation,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            // A duplicate key on the provider reference index means an acknowledged provider object was
            // already claimed by another active projection: a durable refusal, never a silent overwrite.
            if(in_array($this->repository->duplicate($e),array('provider_event','provider_conference'),true))throw new \InvalidArgumentException('projection_already_recorded');
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,$operation,array('mapping_id'=>$mappingId,'mapping_state'=>'verified','operation'=>$operation,'idempotent'=>true));
            throw $e;
        }
    }

    /** The owner of the schedule occurrence's Teacher must also own the connection that projects it. */
    private function connectionForProjection(string $providerCode,int $lessonId,string $now):object{
        $lesson=$this->lessons->lesson($lessonId,true);
        if(!$lesson)throw new \InvalidArgumentException('canonical_lesson_required');
        $teacherId=(int)($lesson->teacher_id??0);
        if($teacherId<1)throw new \InvalidArgumentException('canonical_teacher_required');
        // Teacher archival is revalidated here: an archived Teacher may never be given a new provider
        // write through an existing connection.
        $teacher=$this->repository->teacher($teacherId,true);
        if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \InvalidArgumentException('canonical_teacher_required');
        $connection=$this->repository->activeConnection($providerCode,$teacherId,true);
        if(!$connection||!ProviderIntegrationRule::usable((string)$connection->connection_state))throw new \InvalidArgumentException('provider_connection_unusable');
        if(!ProviderIntegrationValidator::connectionShape($connection))throw new \InvalidArgumentException('provider_connection_malformed');
        return $connection;
    }

    private function connectionSubjectReference(int $connectionId,string $providerCode,object $connection):?string{
        $credential=$this->repository->credential($connectionId,true);
        if(!$credential)throw new \InvalidArgumentException('provider_credential_required');
        if(!ProviderIntegrationValidator::credentialShape($credential))throw new \InvalidArgumentException('provider_credential_malformed');
        if(!$this->secrets->supports((string)$credential->key_version,(string)$credential->cipher_version))throw new \RuntimeException('Integration credential key material unavailable');
        // Re-open the sealed credential so a corrupt row fails the projection closed before any provider
        // write is attempted; the material itself is never recorded, logged or digested.
        $this->secrets->open($connectionId,$providerCode,$credential);
        return ProviderIntegrationRule::digest($connection->identity_digest??null)?(string)$connection->identity_digest:null;
    }

    private function mintIdentityMapping(?int $connectionId,string $providerCode,int $teacherId,string $subjectDigest,string $now,int $actor,string $reference,string $purpose,string $state='verified',?array $evidence=null):int{
        $mappingId=$this->repository->insertIdentityMapping(array(
            'uid'=>Identifier::uid(),'connection_id'=>$connectionId,'provider_code'=>$providerCode,
            'teacher_id'=>$teacherId,'subject_digest'=>$subjectDigest,'mapping_state'=>$state,
            'mapping_version'=>1,'active_slot'=>$state==='verified'?1:null,'superseded_by_mapping_id'=>null,
            'provenance_digest'=>ProviderIntegrationIdempotency::evidence($reference),
            'evidence_reference_digest'=>$evidence['evidence_reference_digest']??ProviderIntegrationIdempotency::evidence('provider-identity-'.$subjectDigest),
            'verified_at'=>$state==='verified'?$now:null,'revoked_at'=>null,
            'recorded_at'=>$now,'recorded_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
        ));
        return $mappingId;
    }

    private function quarantineIdentityMappings(string $providerCode,int $teacherId,string $now,int $actor,string $reason):void{
        foreach($this->repository->identityMappings($providerCode,$teacherId) as $mapping){
            if((string)$mapping->mapping_state!=='verified')continue;
            $this->repository->updateIdentityMapping((int)$mapping->id,array(
                'mapping_state'=>'revoked','active_slot'=>null,'mapping_version'=>(int)$mapping->mapping_version+1,
                'revoked_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor,
            ),array('mapping_state'=>'verified'));
        }
    }

    /**
     * Exactly one active connection per Teacher and provider code.
     *
     * Completing one consent settles every competing lifecycle explicitly and append-preservingly: a
     * sibling consent that is still pending is closed and its own issued intent is recorded as
     * rejected, an already-connected sibling is disconnected and its credential quarantined, and the
     * unique active slot remains the durable guarantee. Nothing is decided by recency or by a tie-break.
     */
    private function settleCompetingLifecycles(string $providerCode,int $teacherId,int $completedConnectionId,string $now,int $actor):void{
        foreach($this->repository->issuedAuthorizations($providerCode,$teacherId) as $intent){
            if((int)$intent->connection_id===$completedConnectionId)continue;
            $this->repository->updateAuthorization((int)$intent->id,array(
                'authorization_state'=>'rejected','consumed_at'=>$now,'consumption_result'=>'superseded',
                'failure_reason_code'=>'superseded_authorization',
            ),array('authorization_state'=>'issued'));
        }
        foreach($this->repository->authorizingConnections($providerCode,$teacherId) as $sibling){
            if((int)$sibling->id===$completedConnectionId)continue;
            $this->repository->updateConnection((int)$sibling->id,array(
                'connection_state'=>'disconnected','active_slot'=>null,
                'connection_version'=>(int)$sibling->connection_version+1,'disconnected_at'=>$now,
                'updated_at'=>$now,'updated_by'=>$actor,
            ),array('connection_state'=>'authorizing'));
        }
        foreach($this->repository->connections($providerCode,$teacherId) as $existing){
            if((int)$existing->id===$completedConnectionId)continue;
            if((string)$existing->connection_state==='connected'){
                $this->repository->updateConnection((int)$existing->id,array(
                    'connection_state'=>'disconnected','active_slot'=>null,
                    'connection_version'=>(int)$existing->connection_version+1,'disconnected_at'=>$now,
                    'updated_at'=>$now,'updated_by'=>$actor,
                ),array('connection_state'=>'connected'));
                $credential=$this->repository->credential((int)$existing->id,false);
                if($credential)$this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'reconnect'),array('state'=>'active'));
            }
        }
    }

    private function connectionResult(?object $connection,string $operation):array{
        if(!$connection)throw new \RuntimeException('Emptied integration result');
        return array('connection_id'=>(int)$connection->id,'connection_state'=>(string)$connection->connection_state,'operation'=>$operation,'idempotent'=>true);
    }

    /**
     * The recorded result of one consent initiation.
     *
     * A replay converges on the connection the command created and reports the intent that is bound to
     * it. The one-time state, verifier and challenge were returned exactly once when the command was
     * first executed and are never reconstructible — only their digests were ever persisted.
     */
    private function authorizationReplayResult(object $command,array $facts):array{
        $connectionId=$command->connection_id===null?null:(int)$command->connection_id;
        if($connectionId===null)throw new \RuntimeException('Emptied integration result');
        $intent=$this->repository->authorizationForConnection($connectionId);
        if(!$intent)throw new \RuntimeException('Emptied integration result');
        return array(
            'connection_id'=>$connectionId,'authorization_id'=>(int)$intent->id,
            'provider_code'=>(string)$command->provider_code,'teacher_id'=>(int)$facts['teacher_id'],
            'scope_snapshot'=>(string)$intent->scope_snapshot,'expires_at'=>(string)$intent->expires_at,
            'authorization_state'=>(string)$intent->authorization_state,
            'operation'=>'begin_authorization','idempotent'=>true,
        );
    }

    private function recordCommand(string $digest,string $payload,string $operation,string $providerCode,?int $teacherId,?int $connectionId,?int $lessonId,?int $scheduleVersionId,?int $mappingId,string $state,string $now,int $actor):int{
        return $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>ProviderIntegrationRule::COMMAND_DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'provider_code'=>$providerCode,
            'teacher_id'=>$teacherId,'connection_id'=>$connectionId,'lesson_id'=>$lessonId,
            'schedule_version_id'=>$scheduleVersionId,'mapping_id'=>$mappingId,'result_state'=>$state,'result_id'=>$mappingId,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    /**
     * Digest-only replay.
     *
     * The reconstructed payload digest is compared unconditionally, the operation and domain must
     * match exactly, and the recorded result aggregate is revalidated before an idempotent success is
     * returned; anything else is a durable refusal, never a silent success.
     */
    private function replay(object $command,string $payload,string $operation,array $result):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$command->command_domain!==ProviderIntegrationRule::COMMAND_DOMAIN)throw new \RuntimeException('Contaminated provider integration command');
        if((string)$command->operation!==$operation)throw new IdempotencyConflictException('Idempotency conflict');
        if(!ProviderIntegrationValidator::commandShape($command,$operation,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        return array_merge($result,array('idempotent'=>true));
    }

    private function resolvesOwnTeacher(int $userId,int $teacherId):bool{
        if($userId<1||$teacherId<1)return false;
        $teacher=$this->repository->teacherForPrincipalUser($userId,false);
        return $teacher&&(int)$teacher->id===$teacherId;
    }

    private function requireCapabilityFor(int $actor,int $teacherId,array $capabilities):void{
        foreach($capabilities as $capability)if(current_user_can($capability))return;
        throw new \RuntimeException('Unauthorized');
    }

    private function requireCapability(string $capability):void{
        if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');
    }

    private function actor():int{
        $id=get_current_user_id();
        if($id<1)throw new \RuntimeException('Integration actor unavailable');
        return $id;
    }
}
