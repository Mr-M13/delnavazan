<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\IntegrationSecretService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\GoogleOAuthTransport;

/**
 * OAuth lifecycle for the single Delnavazan-owned Workspace organizer account.
 *
 * Teachers never see this flow. Only an administrator with the integration-management capability
 * may connect, refresh, disconnect or revoke the organizer grant. State and PKCE verifier are
 * one-time, stored digest-only, and bound to the exact connection and principal.
 */
final class GoogleWorkspaceOAuthService {
    private const CALLBACK_ACTION='dzn_meet_oauth_callback';
    private const TTL_SECONDS=900;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?IntegrationSecretService $secrets=null,
        private ?GoogleOAuthTransport $transport=null,
        private ?IntegrationSettingsService $settings=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->secrets??=new IntegrationSecretService();
        $this->transport??=new GoogleOAuthTransport();
        $this->settings??=new IntegrationSettingsService();
    }

    public static function callbackUrl():string{
        return function_exists('admin_url')?admin_url('admin-post.php?action='.self::CALLBACK_ACTION):'';
    }

    public static function configured():bool{
        try{
            $settings=new IntegrationSettingsService();
            $client=trim($settings->value('google','client_id'));
            $secret=trim($settings->value('google','client_secret'));
            if($client===''&&defined('DZN_GOOGLE_OAUTH_CLIENT_ID'))$client=(string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID');
            if($secret===''&&defined('DZN_GOOGLE_OAUTH_CLIENT_SECRET'))$secret=(string)constant('DZN_GOOGLE_OAUTH_CLIENT_SECRET');
            return $client!==''&&$secret!=='';
        }catch(\Throwable){return false;}
    }

    /** Start the organizer consent flow. Returns the Google authorization URL. */
    public function begin():string{
        $this->requireManager();
        $redirect=$this->redirectUri();
        $state=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
        $now=gmdate('Y-m-d H:i:s');
        // A re-authorization must not take the currently working organizer offline. Keep the
        // connected row active and create a new authorizing lifecycle; completion settles the old row.
        $connectionId=$this->repository->insertConnection(array(
            'uid'=>Identifier::uid(),'organizer_email'=>GoogleWorkspaceClassroomRule::organizerEmail(),
            'connection_state'=>'authorizing','connection_version'=>1,'active_slot'=>null,
            'scope_snapshot'=>GoogleWorkspaceClassroomRule::scopeSnapshot(),
            'consent_version'=>GoogleWorkspaceClassroomRule::CONSENT_VERSION,
            'identity_digest'=>null,'identity_state'=>'unverified','failure_reason_code'=>null,
            'authorized_at'=>$now,'connected_at'=>null,'last_checked_at'=>null,
            'disconnected_at'=>null,'revoked_at'=>null,
            'created_at'=>$now,'created_by'=>$this->actor(),'updated_at'=>$now,'updated_by'=>$this->actor(),
        ));
        $this->repository->insertAuthorization(array(
            'uid'=>Identifier::uid(),'connection_id'=>$connectionId,'principal_user_id'=>$this->actor(),
            'client_reference'=>GoogleOAuthTransport::clientReference(),'redirect_uri'=>$redirect,
            'scope_snapshot'=>GoogleWorkspaceClassroomRule::scopeSnapshot(),
            'state_digest'=>hash('sha256','meet-oauth-state:'.$state),
            'verifier_digest'=>hash('sha256','meet-oauth-verifier:'.$verifier),
            'authorization_state'=>'issued','issued_at'=>$now,
            'expires_at'=>gmdate('Y-m-d H:i:s',time()+self::TTL_SECONDS),
            'consumed_at'=>null,'consumption_result'=>null,'failure_reason_code'=>null,
            'created_at'=>$now,'created_by'=>$this->actor(),
        ));
        // The PKCE verifier is sealed at rest in a short-lived server-side transient. It is never put
        // in a URL, cookie, browser storage or log.
        if(function_exists('set_transient')){
            $sealedVerifier=$this->secrets->seal($connectionId,'google_meet',$verifier);
            set_transient('dzn_meet_oauth_'.hash('sha256','meet-oauth-state:'.$state),array('sealed_verifier'=>$sealedVerifier,'expires_at'=>time()+self::TTL_SECONDS),self::TTL_SECONDS);
        }
        $query=http_build_query(array(
            'client_id'=>$this->clientId(),'redirect_uri'=>$redirect,'response_type'=>'code',
            'scope'=>GoogleWorkspaceClassroomRule::scopeSnapshot(),'state'=>$state,
            'code_challenge'=>$challenge,'code_challenge_method'=>'S256','access_type'=>'offline','prompt'=>'consent',
            'login_hint'=>GoogleWorkspaceClassroomRule::organizerEmail(),
        ),'','&',PHP_QUERY_RFC3986);
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /** Complete the organizer consent flow. */
    public function complete(string $state,string $code):array{
        $this->requireManager();
        $state=trim($state);$code=trim($code);
        if($state===''||$code==='')throw new \InvalidArgumentException('meet_oauth_callback_invalid');
        $stateDigest=hash('sha256','meet-oauth-state:'.$state);
        $intent=$this->repository->authorizationByState($stateDigest,true);
        if(!$intent)throw new \InvalidArgumentException('meet_oauth_state_invalid');
        $now=gmdate('Y-m-d H:i:s');
        if((string)$intent->authorization_state!=='issued')throw new \InvalidArgumentException('meet_oauth_state_settled');
        if((string)$intent->expires_at<$now)throw new \InvalidArgumentException('meet_oauth_state_expired');
        if((int)$intent->principal_user_id!==$this->actor())throw new \RuntimeException('Unauthorized');
        $verifier='';
        if(function_exists('get_transient')){
            $stored=get_transient('dzn_meet_oauth_'.$stateDigest);
            $sealedVerifier=is_array($stored)?($stored['sealed_verifier']??null):null;
            if(is_array($sealedVerifier)){
                try{$verifier=$this->secrets->open((int)$intent->connection_id,'google_meet',$sealedVerifier);}catch(\Throwable){$verifier='';}
            }
        }
        if($verifier==='')throw new \InvalidArgumentException('meet_oauth_verifier_unavailable');
        $exchanged=$this->transport->exchange(array(
            'client_reference'=>(string)$intent->client_reference,'redirect_uri'=>(string)$intent->redirect_uri,
            'code'=>$code,'code_verifier'=>$verifier,'scope_snapshot'=>(string)$intent->scope_snapshot,
        ));
        $granted=(string)($exchanged['granted_scope_snapshot']??'');
        if(!GoogleWorkspaceClassroomRule::scopeSetMatches($granted))throw new \InvalidArgumentException('meet_oauth_scope_mismatch');
        $email=(string)($exchanged['provider_account_email']??'');
        $verified=($exchanged['provider_email_verified']??false)===true;
        if(!$verified||$email===''||!hash_equals(GoogleWorkspaceClassroomRule::organizerEmail(),$email))throw new \InvalidArgumentException('meet_oauth_organizer_mismatch');
        $material=(string)($exchanged['credential_material']??'');
        if($material==='')throw new \InvalidArgumentException('meet_oauth_credential_unavailable');
        $this->repository->begin();
        try{
            $connection=$this->repository->connection((int)$intent->connection_id,true);
            if(!$connection||(string)$connection->connection_state!=='authorizing')throw new \InvalidArgumentException('meet_oauth_connection_required');
            // Settle the previously active lifecycle only after the new grant has been proven valid.
            $previous=$this->repository->activeConnection(true);
            if($previous&&(int)$previous->id!==(int)$connection->id){
                $this->repository->updateConnection((int)$previous->id,array('connection_state'=>'disconnected','active_slot'=>null,'connection_version'=>(int)$previous->connection_version+1,'disconnected_at'=>$now,'updated_at'=>$now,'updated_by'=>$this->actor()),array('id'=>(int)$previous->id));
            }
            $sealed=$this->secrets->seal((int)$connection->id,'google_meet',$material);
            $credentialId=$this->repository->insertCredential(array_merge($sealed,array(
                'uid'=>Identifier::uid(),'connection_id'=>(int)$connection->id,'state'=>'active',
                'valid_until_utc'=>$exchanged['access_valid_until_utc']??null,
                'quarantined_at'=>null,'quarantine_reason_code'=>null,'revoked_at'=>null,
                'created_at'=>$now,'created_by'=>$this->actor(),
            )));
            $this->repository->updateConnection((int)$connection->id,array(
                'connection_state'=>'connected','active_slot'=>1,
                'connection_version'=>(int)$connection->connection_version+1,
                'identity_digest'=>hash('sha256','meet-organizer:'.$email),
                'identity_state'=>'verified','connected_at'=>$now,'last_checked_at'=>$now,
                'failure_reason_code'=>null,'updated_at'=>$now,'updated_by'=>$this->actor(),
            ),array('id'=>(int)$connection->id));
            $this->repository->updateAuthorization((int)$intent->id,array('authorization_state'=>'consumed','consumed_at'=>$now,'consumption_result'=>'connected'),array('authorization_state'=>'issued'));
            $this->repository->commit();
        }catch(\Throwable $error){
            $this->repository->rollback();
            throw $error;
        }
        if(function_exists('delete_transient'))delete_transient('dzn_meet_oauth_'.$stateDigest);
        $this->audit((int)$connection->id,'oauth_connected','connected',null,null);
        return array('connection_id'=>(int)$connection->id,'credential_id'=>$credentialId,'organizer_email'=>$email,'connection_state'=>'connected');
    }

    public function refreshConnection(int $connectionId):void{
        $connection=$this->repository->connection($connectionId);
        if(!$connection||!in_array((string)$connection->connection_state,array('connected','refresh_failed'),true))throw new \InvalidArgumentException('meet_connection_unusable');
        $credential=$this->repository->credential($connectionId,true);
        if(!$credential)throw new \InvalidArgumentException('meet_credential_required');
        $material=$this->secrets->open($connectionId,'google_meet',$credential);
        try{
            $refreshed=$this->transport->refresh(array('credential_material'=>$material,'scope_snapshot'=>(string)$connection->scope_snapshot,'now_utc'=>gmdate('Y-m-d H:i:s')));
        }catch(\Throwable $error){
            $this->repository->updateConnection($connectionId,array('connection_state'=>'refresh_failed','active_slot'=>null,'connection_version'=>(int)$connection->connection_version+1,'failure_reason_code'=>'credential_refresh_failed','updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>0),array('id'=>$connectionId));
            $this->audit($connectionId,'oauth_refresh','refresh_failed','credential_refresh_failed',null);
            throw $error;
        }
        $now=gmdate('Y-m-d H:i:s');
        $sealed=$this->secrets->seal($connectionId,'google_meet',(string)$refreshed['credential_material']);
        $this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>$now,'quarantine_reason_code'=>'rotated'),array('state'=>'active'));
        $this->repository->insertCredential(array_merge($sealed,array(
            'uid'=>Identifier::uid(),'connection_id'=>$connectionId,'state'=>'active',
            'valid_until_utc'=>$refreshed['access_valid_until_utc']??null,'quarantined_at'=>null,
            'quarantine_reason_code'=>null,'revoked_at'=>null,'created_at'=>$now,'created_by'=>0,
        )));
        $this->repository->updateConnection($connectionId,array('connection_state'=>'connected','active_slot'=>1,'connection_version'=>(int)$connection->connection_version+1,'failure_reason_code'=>null,'last_checked_at'=>$now,'updated_at'=>$now,'updated_by'=>0),array('id'=>$connectionId));
        $this->audit($connectionId,'oauth_refresh','connected',null,null);
    }

    public function disconnect(bool $revoke=false):array{
        $this->requireManager();
        $connection=$this->repository->activeConnection();
        if(!$connection)throw new \InvalidArgumentException('meet_not_connected');
        $credential=$this->repository->credential((int)$connection->id,true);
        $state='disconnected';
        if($credential){
            $material=$this->secrets->open((int)$connection->id,'google_meet',$credential);
            $this->repository->updateCredential((int)$credential->id,array('state'=>'quarantined','quarantined_at'=>gmdate('Y-m-d H:i:s'),'quarantine_reason_code'=>$revoke?'revoking':'disconnected'),array('state'=>'active'));
            if($revoke){
                $result=$this->transport->revoke(array('credential_material'=>$material,'now_utc'=>gmdate('Y-m-d H:i:s')));
                $state=!empty($result['revoked'])?'revoked':'disconnect_local_only';
            }
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->updateConnection((int)$connection->id,array(
            'connection_state'=>$state==='revoked'?'revoked':'disconnected','active_slot'=>null,
            'connection_version'=>(int)$connection->connection_version+1,
            'failure_reason_code'=>$state==='disconnect_local_only'?'provider_revoke_failed':null,
            'disconnected_at'=>$now,'revoked_at'=>$state==='revoked'?$now:null,
            'updated_at'=>$now,'updated_by'=>$this->actor(),
        ),array('id'=>(int)$connection->id));
        $this->audit((int)$connection->id,'oauth_disconnect',$state,$state==='disconnect_local_only'?'provider_revoke_failed':null,null);
        return array('connection_state'=>$state,'revoke_attempted'=>$revoke);
    }

    /** Secret-free health snapshot for Settings → Integrations. */
    public function health():array{
        try{
            $connection=$this->repository->activeConnection();
            if(!$connection)$connection=$this->repository->connectionByState('connected')?:$this->repository->connectionByState('refresh_failed');
            if(!$connection){
                return array('state'=>'not_connected','organizer_email'=>GoogleWorkspaceClassroomRule::organizerEmail(),'configured'=>self::configured(),'callback_url'=>self::callbackUrl(),'scope_match'=>false,'credential_valid'=>false,'last_checked_at'=>null,'failure_reason_code'=>null);
            }
            $credential=$this->repository->credential((int)$connection->id);
            $valid=$credential&&($credential->valid_until_utc===null||strtotime((string)$credential->valid_until_utc.' UTC')>time());
            return array(
                'state'=>(string)$connection->connection_state,
                'organizer_email'=>(string)$connection->organizer_email,
                'configured'=>self::configured(),
                'callback_url'=>self::callbackUrl(),
                'scope_match'=>GoogleWorkspaceClassroomRule::scopeSetMatches((string)$connection->scope_snapshot),
                'credential_valid'=>$valid,
                'last_checked_at'=>$connection->last_checked_at,
                'failure_reason_code'=>$connection->failure_reason_code,
            );
        }catch(\Throwable){
            return array('state'=>'unavailable','organizer_email'=>GoogleWorkspaceClassroomRule::organizerEmail(),'configured'=>false,'callback_url'=>self::callbackUrl(),'scope_match'=>false,'credential_valid'=>false,'last_checked_at'=>null,'failure_reason_code'=>'meet_health_unavailable');
        }
    }

    private function redirectUri():string{
        $configured=trim($this->settings->value('google','meet_redirect_uri'));
        if($configured==='')$configured=self::callbackUrl();
        if(!str_starts_with($configured,'https://'))throw new \RuntimeException('meet_oauth_https_redirect_required');
        return $configured;
    }

    private function clientId():string{
        $client=trim($this->settings->value('google','client_id'));
        if($client===''&&defined('DZN_GOOGLE_OAUTH_CLIENT_ID'))$client=(string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID');
        if($client==='')throw new \RuntimeException('meet_oauth_not_configured');
        return $client;
    }

    private function requireManager():void{
        if(function_exists('current_user_can')&&!current_user_can('manage_options')&&!current_user_can('dzn_manage_provider_integrations'))throw new \RuntimeException('Unauthorized');
    }

    private function actor():int{
        $id=function_exists('get_current_user_id')?(int)get_current_user_id():0;
        if($id<1)throw new \RuntimeException('Unauthorized');
        return $id;
    }

    private function audit(int $connectionId,string $operation,string $state,?string $reason,?string $errorClass):void{
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>null,'operation'=>$operation,
                'command_digest'=>hash('sha256','meet-oauth-audit:'.$operation.':'.$connectionId.':'.microtime(true).':'.random_int(1,PHP_INT_MAX)),
                'result_state'=>$state,'reason_code'=>$reason,'provider_http_status'=>null,'provider_error_class'=>$errorClass,
                'payload_digest'=>hash('sha256',$operation.'|'.$connectionId.'|'.$state),
                'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$this->actor(),
            ));
        }catch(\Throwable){}
    }
}
