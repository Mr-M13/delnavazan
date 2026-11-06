<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository,StripeLiveActivationRepository};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter,StripeEventTranslator};

/**
 * Dashboard-managed Stripe LIVE configuration.
 *
 * Live credentials are encrypted by the existing payment-secret vault and never returned, rendered or
 * logged. Saving credentials only records configuration: it never enables live execution. Activation is
 * a separate, confirmed administrator action that requires the live readiness checklist to pass.
 *
 * Test configuration remains owned by `StripeTestConfigurationService`; the two accounts, credential
 * scopes and webhook selectors are completely separate.
 */
final class StripeLiveConfigurationService {
    public const LIVE_ACCOUNT_REFERENCE=StripeProviderMode::LIVE_ACCOUNT_REFERENCE;
    private const CONNECTION_OPTION=StripeProviderMode::LIVE_CONNECTION_OPTION;
    private const WEBHOOK_VERIFICATION_OPTION=StripeProviderMode::LIVE_WEBHOOK_VERIFICATION_OPTION;
    private PaymentProviderRepository $providers;
    private PaymentSecretRepository $secrets;
    private PaymentSecretVault $vault;

    public function __construct(?PaymentProviderRepository $providers=null,?PaymentSecretRepository $secrets=null,?PaymentSecretVault $vault=null){
        $this->providers=$providers??new PaymentProviderRepository();
        $this->secrets=$secrets??new PaymentSecretRepository();
        $this->vault=$vault??new PaymentSecretVault($this->secrets,$this->providers);
    }

    public function save(array $input):array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        if(!StripeProviderMode::liveEnvironmentAllowed())throw new \RuntimeException('stripe_live_environment_required');
        $publishable=trim((string)($input['publishable_key']??''));
        $apiKey=trim((string)($input['secret_key']??''));
        $webhook=trim((string)($input['webhook_signing_secret']??''));
        $accountIdentifier=trim((string)($input['account_identifier']??''));
        if($publishable!==''&&preg_match(StripeProviderMode::publishablePattern('live'),$publishable)!==1)throw new \InvalidArgumentException('invalid_stripe_live_publishable_key');
        if($apiKey!==''&&preg_match(StripeProviderMode::apiKeyPattern('live'),$apiKey)!==1)throw new \InvalidArgumentException('invalid_stripe_live_secret_key');
        if($webhook!==''&&preg_match(StripeProviderMode::webhookSecretPattern(),$webhook)!==1)throw new \InvalidArgumentException('invalid_stripe_live_webhook_secret');
        if($accountIdentifier!==''&&preg_match('/^acct_[A-Za-z0-9]{8,}$/D',$accountIdentifier)!==1)throw new \InvalidArgumentException('invalid_stripe_live_account_identifier');
        $accounts=$this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY);
        $live=array_values(array_filter($accounts,static fn($a)=>(string)$a->mode==='live'&&(string)$a->state==='active'));
        if(count($live)>1)throw new \RuntimeException('stripe_live_account_scope_ambiguous');
        if(!$live){
            foreach($accounts as $account)if((string)$account->reference_code===self::LIVE_ACCOUNT_REFERENCE)
                throw new \RuntimeException('stripe_live_account_state_unavailable');
            if($publishable===''||$apiKey===''||$webhook==='')throw new \InvalidArgumentException('complete_stripe_live_configuration_required');
            $created=(new PaymentProviderAccountService($this->providers))->register(array(
                'provider_key'=>'stripe','mode'=>'live','reference_code'=>self::LIVE_ACCOUNT_REFERENCE,
                'account_reference'=>self::LIVE_ACCOUNT_REFERENCE,
                'execution_state'=>'disabled','credential_state'=>'configured','evidence_channel'=>'staff_record',
                'evidence_reference'=>'stripe_live_settings','evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),'stripe-live-settings-account-'.wp_generate_uuid4());
            $accountId=(int)$created['provider_account_id'];
        }else{
            if((string)$live[0]->reference_code!==self::LIVE_ACCOUNT_REFERENCE
                ||(string)$live[0]->credential_state!=='configured'
                ||PaymentProviderAccountService::canonicalReference($live[0])===null)
                throw new \RuntimeException('stripe_live_account_state_unavailable');
            $accountId=(int)$live[0]->id;
        }
        $nonce=wp_generate_uuid4();
        $stored=array();
        foreach(array('api_key'=>$apiKey,'webhook_signing_secret'=>$webhook) as $class=>$value){
            if($value==='')continue;
            $result=$this->vault->storeStripeLiveConfiguration($accountId,$class,$value,array('nonce'=>$nonce),'stripe-live-settings-'.$class.'-'.wp_generate_uuid4());
            if(empty($result['stored']))throw new \RuntimeException('stripe_live_secret_store_refused');
            $stored[]=$class;
        }
        if($publishable!=='')update_option(StripeProviderMode::LIVE_PUBLISHABLE_OPTION,$publishable,false);
        if($accountIdentifier!=='')update_option(StripeProviderMode::LIVE_ACCOUNT_IDENTIFIER_OPTION,$accountIdentifier,false);
        // Any credential or account change invalidates prior verification and stops live charging until
        // the operator re-runs the checks and confirms activation again. This is the rotation safeguard.
        if($publishable!==''||$apiKey!==''||$webhook!==''||$accountIdentifier!==''){
            $this->invalidateVerification();
            $this->failClosedAfterConfigurationChange($accountId);
        }
        $state=$this->savedState();
        if((int)($state['account_id']??0)!==$accountId
            ||($publishable!==''&&!$state['publishable'])
            ||($apiKey!==''&&!$state['secret'])
            ||($webhook!==''&&!$state['webhook'])){
            throw new \RuntimeException('stripe_live_configuration_persistence_unconfirmed');
        }
        return array('account_id'=>$accountId,'stored'=>$stored,'publishable_key_saved'=>$publishable!=='','activated'=>false);
    }

    /** Bounded authenticated read of Stripe's live balance. Never returns provider text or a credential. */
    public function testConnection():array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        if(!StripeProviderMode::liveSecretReadable())return array('state'=>'blocked','code'=>'stripe_live_environment_required');
        $accounts=$this->liveAccounts();
        if(count($accounts)!==1)return array('state'=>'blocked','code'=>'live_account_unavailable');
        $accountId=(int)$accounts[0]->id;
        $secret=$this->secrets->activeSecret('stripe','api_key',$accountId,'live');
        $webhook=$this->secrets->activeSecret('stripe','webhook_signing_secret',$accountId,'live');
        if(!$secret||!$webhook)return $this->recordConnection('blocked','live_secret_unconfigured');
        try{
            if(!$this->vault->validateStripeLiveCredentials((int)$secret->id,(int)$webhook->id))return $this->recordConnection('blocked','live_secret_unavailable');
            $key=$this->vault->revealForStripeLiveConnection((int)$secret->id);
        }catch(\Throwable){return $this->recordConnection('blocked','live_secret_validation_failed');}
        if(!is_string($key)||preg_match(StripeProviderMode::apiKeyPattern('live'),$key)!==1)return $this->recordConnection('blocked','live_secret_unavailable');
        if(!function_exists('wp_remote_get')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body'))
            return $this->recordConnection('blocked','http_unavailable');
        try{
            $response=wp_remote_get('https://api.stripe.com/v1/balance',array(
                'timeout'=>15,'redirection'=>0,'sslverify'=>true,
                'headers'=>array('Authorization'=>'Bearer '.$key,'Accept'=>'application/json'),
            ));
        }catch(\Throwable){return $this->recordConnection('failed','stripe_transport_failed');}
        finally{unset($key);}
        if(is_wp_error($response))return $this->recordConnection('failed','stripe_unreachable');
        $status=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status===401)return $this->recordConnection('failed','stripe_live_authentication_failed');
        if($status===403)return $this->recordConnection('failed','stripe_live_permission_denied');
        if($status===429)return $this->recordConnection('failed','stripe_rate_limited');
        if($status>=500)return $this->recordConnection('failed','stripe_api_unavailable');
        if($status!==200)return $this->recordConnection('failed','stripe_api_request_failed');
        if(!is_array($body)||($body['object']??null)!=='balance'||!array_key_exists('livemode',$body)||!is_bool($body['livemode']))
            return $this->recordConnection('failed','stripe_response_invalid');
        if($body['livemode']!==true)return $this->recordConnection('failed','stripe_live_mode_mismatch');
        return $this->recordConnection('success','stripe_live_account_reachable');
    }

    /**
     * Explicitly verify the Stripe event destination from Stripe's own API.
     *
     * The endpoint URL, enabled status, subscribed event list and payload/API version are read back from
     * Stripe. No signing secret is ever returned by that API; the secret is checked separately in the
     * encrypted vault. A successful snapshot is the only thing that makes webhook readiness "verified".
     */
    public function verifyWebhookDestination():array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        if(!StripeProviderMode::liveSecretReadable())return $this->recordWebhookVerification('blocked','stripe_live_environment_required');
        $accounts=$this->liveAccounts();
        if(count($accounts)!==1)return $this->recordWebhookVerification('blocked','live_account_unavailable');
        $accountId=(int)$accounts[0]->id;
        $secret=$this->secrets->activeSecret('stripe','api_key',$accountId,'live');
        $webhook=$this->secrets->activeSecret('stripe','webhook_signing_secret',$accountId,'live');
        if(!$secret||!$webhook)return $this->recordWebhookVerification('blocked','live_secret_unconfigured');
        try{
            if(!$this->vault->validateStripeLiveCredentials((int)$secret->id,(int)$webhook->id))return $this->recordWebhookVerification('blocked','live_secret_unavailable');
            $key=$this->vault->revealForStripeLiveConnection((int)$secret->id);
        }catch(\Throwable){return $this->recordWebhookVerification('blocked','live_secret_validation_failed');}
        if(!is_string($key)||preg_match(StripeProviderMode::apiKeyPattern('live'),$key)!==1)return $this->recordWebhookVerification('blocked','live_secret_unavailable');
        if(!function_exists('wp_remote_get')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body'))
            return $this->recordWebhookVerification('blocked','http_unavailable');
        try{
            $response=wp_remote_get('https://api.stripe.com/v1/webhook_endpoints?limit=100',array(
                'timeout'=>15,'redirection'=>0,'sslverify'=>true,
                'headers'=>array('Authorization'=>'Bearer '.$key,'Accept'=>'application/json'),
            ));
        }catch(\Throwable){return $this->recordWebhookVerification('failed','stripe_transport_failed');}
        finally{unset($key);}
        if(is_wp_error($response))return $this->recordWebhookVerification('failed','stripe_unreachable');
        $status=(int)wp_remote_retrieve_response_code($response);
        if($status===401)return $this->recordWebhookVerification('failed','stripe_live_authentication_failed');
        if($status===403)return $this->recordWebhookVerification('failed','stripe_live_permission_denied');
        if($status>=500)return $this->recordWebhookVerification('failed','stripe_api_unavailable');
        if($status!==200)return $this->recordWebhookVerification('failed','stripe_api_request_failed');
        $list=json_decode((string)wp_remote_retrieve_body($response),true);
        if(!is_array($list)||($list['object']??null)!=='list'||!is_array($list['data']??null))
            return $this->recordWebhookVerification('failed','stripe_response_invalid');
        $url=$this->webhookUrl();
        $found=null;
        foreach($list['data'] as $endpoint){
            if(!is_array($endpoint))continue;
            if(isset($endpoint['url'])&&is_string($endpoint['url'])&&hash_equals($url,$endpoint['url'])){$found=$endpoint;break;}
        }
        if($found===null)return $this->recordWebhookVerification('failed',($list['has_more']??false)===true?'stripe_live_webhook_search_incomplete':'stripe_live_webhook_endpoint_not_found');
        if(($found['livemode']??null)!==true)return $this->recordWebhookVerification('failed','stripe_live_webhook_mode_mismatch');
        if((string)($found['status']??'')!=='enabled')return $this->recordWebhookVerification('failed','stripe_live_webhook_disabled');
        $events=$found['enabled_events']??null;
        if(!is_array($events)||$events===array())return $this->recordWebhookVerification('failed','stripe_live_webhook_events_missing');
        $missing=in_array('*',$events,true)?array():array_values(array_diff(StripeEventTranslator::requiredWebhookEvents(),$events));
        if($missing!==array())return $this->recordWebhookVerification('failed','stripe_live_webhook_events_incomplete',$missing);
        $apiVersion=trim((string)($found['api_version']??''));
        if($apiVersion==='')return $this->recordWebhookVerification('failed','stripe_live_webhook_api_version_missing');
        if(!StripeProviderMode::supportedWebhookApiVersion($apiVersion))
            return $this->recordWebhookVerification('failed','stripe_live_webhook_api_version_unsupported',array(),$apiVersion,$events,(string)($found['id']??''));
        return $this->recordWebhookVerification('success','stripe_live_webhook_verified',array(),$apiVersion,$events,(string)($found['id']??''));
    }

    /** Separate, confirmed activation. Never called by save or by opening the dashboard. */
    public function activate():array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        $actor=PaymentExecutionSupport::actor();
        $state=$this->savedState();
        $accountId=(int)($state['account_id']??0);
        if($accountId<1)throw new \RuntimeException('stripe_live_account_unavailable');
        $readiness=(new StripeLiveReadinessService($this->providers,$this->secrets,$this->vault))->read();
        if(empty($readiness['ready'])||empty($readiness['verified']))throw new \RuntimeException('stripe_live_readiness_blocked');
        $digest=$this->readinessDigest($readiness);
        $activation=array(
            'status'=>StripeProviderMode::ACTIVATION_ACTIVATING,'account_id'=>$accountId,
            'account_reference'=>self::LIVE_ACCOUNT_REFERENCE,'activated_at'=>null,'deactivated_at'=>null,
            'actor'=>$actor,'readiness_digest'=>$digest,
        );
        update_option(StripeProviderMode::LIVE_ACTIVATION_OPTION,$activation,false);
        try{
            // Re-prove readiness after publishing the activating marker. A concurrent credential
            // rotation invalidates the verification snapshots and must win over this activation.
            $current=(new StripeLiveReadinessService($this->providers,$this->secrets,$this->vault))->read();
            if(empty($current['ready'])||empty($current['verified'])
                ||!hash_equals($digest,$this->readinessDigest($current)))
                throw new \RuntimeException('stripe_live_readiness_changed');
            $owned=StripeProviderMode::activation();
            if($owned['status']!==StripeProviderMode::ACTIVATION_ACTIVATING
                ||$owned['account_id']!==$accountId||!is_string($owned['readiness_digest'])
                ||!hash_equals($digest,$owned['readiness_digest']))
                throw new \RuntimeException('stripe_live_activation_superseded');
            $transition=(new PaymentProviderAccountService($this->providers))->setExecutionState($accountId,
                array('execution_state'=>'enabled','reason_code'=>'deliberate_stripe_live_activation',
                    'evidence_channel'=>'authenticated_platform',
                    'evidence_reference'=>'stripe_live_activation:'.$accountId.':'.$digest,
                    'evidence_at'=>gmdate('Y-m-d H:i:s')),
                'stripe-live-activation-'.wp_generate_uuid4());
            $owned=StripeProviderMode::activation();
            if($owned['status']!==StripeProviderMode::ACTIVATION_ACTIVATING
                ||$owned['account_id']!==$accountId||!is_string($owned['readiness_digest'])
                ||!hash_equals($digest,$owned['readiness_digest']))
                throw new \RuntimeException('stripe_live_activation_superseded');
            $activation['status']=StripeProviderMode::ACTIVATION_ACTIVE;
            $activation['activated_at']=gmdate('Y-m-d H:i:s');
            // Evidence is part of activation: if it cannot be written, activation is rolled back rather
            // than leaving live charging enabled without an append-only record.
            $this->recordActivationEvent('activated','live_activation_confirmed',$digest);
            update_option(StripeProviderMode::LIVE_ACTIVATION_OPTION,$activation,false);
        }catch(\Throwable $e){
            try{
                (new PaymentProviderAccountService($this->providers))->setExecutionState($accountId,
                    array('execution_state'=>'disabled','reason_code'=>'stripe_live_activation_rollback',
                        'evidence_channel'=>'authenticated_platform','evidence_reference'=>'stripe_live_activation_rollback:'.$accountId,
                        'evidence_at'=>gmdate('Y-m-d H:i:s')),
                    'stripe-live-activation-rollback-'.wp_generate_uuid4());
            }catch(\Throwable $ignored){}
            update_option(StripeProviderMode::LIVE_ACTIVATION_OPTION,array_merge($activation,array('status'=>StripeProviderMode::ACTIVATION_BLOCKED)),false);
            try{$this->recordActivationEvent('blocked','live_activation_failed',null);}catch(\Throwable $ignored){}
            throw $e;
        }
        return array_merge($transition,array('activation_status'=>StripeProviderMode::ACTIVATION_ACTIVE,'activated_at'=>$activation['activated_at']));
    }

    /** Stop live charging. Credentials, webhook configuration and every historical record are preserved. */
    public function deactivate():array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        $actor=PaymentExecutionSupport::actor();
        $state=$this->savedState();
        $accountId=(int)($state['account_id']??0);
        $result=array('provider_account_id'=>$accountId,'execution_state'=>'disabled');
        if($accountId>0&&!empty($state['execution_enabled'])){
            $result=(new PaymentProviderAccountService($this->providers))->setExecutionState($accountId,
                array('execution_state'=>'disabled','reason_code'=>'deliberate_stripe_live_deactivation',
                    'evidence_channel'=>'authenticated_platform',
                    'evidence_reference'=>'stripe_live_deactivation:'.$accountId,
                    'evidence_at'=>gmdate('Y-m-d H:i:s')),
                'stripe-live-deactivation-'.wp_generate_uuid4());
        }
        $previous=StripeProviderMode::activation();
        update_option(StripeProviderMode::LIVE_ACTIVATION_OPTION,array(
            'status'=>StripeProviderMode::ACTIVATION_INACTIVE,'account_id'=>$accountId,
            'account_reference'=>self::LIVE_ACCOUNT_REFERENCE,'activated_at'=>$previous['activated_at'],
            'deactivated_at'=>gmdate('Y-m-d H:i:s'),'actor'=>$actor,'readiness_digest'=>null,
        ),false);
        $this->recordActivationEvent('deactivated','live_deactivation_confirmed',null);
        return array_merge($result,array('activation_status'=>StripeProviderMode::ACTIVATION_INACTIVE));
    }

    /** @return array<string,mixed> Secret-free configuration and last-check state. */
    public function savedState():array{
        $accounts=$this->liveAccounts();
        if(count($accounts)!==1)return array(
            'account_id'=>0,'reference_code'=>'','account_reference'=>null,
            'publishable'=>false,'secret'=>false,'webhook'=>false,'execution_enabled'=>false,
            'activation'=>StripeProviderMode::activation(),'account_identifier'=>false,
        );
        $id=(int)$accounts[0]->id;
        return array(
            'account_id'=>$id,
            'reference_code'=>(string)($accounts[0]->reference_code??''),
            'account_reference'=>PaymentProviderAccountService::canonicalReference($accounts[0]),
            'publishable'=>$this->publishableKey()!=='',
            'secret'=>$this->secrets->activeSecret('stripe','api_key',$id,'live')!==null,
            'webhook'=>$this->secrets->activeSecret('stripe','webhook_signing_secret',$id,'live')!==null,
            'execution_enabled'=>(string)($accounts[0]->execution_state??'')==='enabled',
            'activation'=>StripeProviderMode::activation(),
            'account_identifier'=>trim((string)get_option(StripeProviderMode::LIVE_ACCOUNT_IDENTIFIER_OPTION,''))!=='',
        );
    }

    public function publishableKey():string{
        $key=(string)get_option(StripeProviderMode::LIVE_PUBLISHABLE_OPTION,'');
        return preg_match(StripeProviderMode::publishablePattern('live'),$key)===1?$key:'';
    }
    public function webhookReference():string{
        $state=$this->savedState();
        if((int)($state['account_id']??0)<1)return self::LIVE_ACCOUNT_REFERENCE;
        $account=$this->providers->account((int)$state['account_id']);
        return $account?PaymentProviderAccountService::canonicalReference($account)??self::LIVE_ACCOUNT_REFERENCE:self::LIVE_ACCOUNT_REFERENCE;
    }
    public function webhookUrl():string{
        if(!function_exists('rest_url'))return '';
        return (string)rest_url('delnavazan-platform/v1/payment-provider-events/stripe/'.$this->webhookReference());
    }
    public static function connectionResult():array{
        $raw=function_exists('get_option')?get_option(self::CONNECTION_OPTION,array()):array();
        return is_array($raw)?$raw:array();
    }
    public static function webhookVerification():array{
        $raw=function_exists('get_option')?get_option(self::WEBHOOK_VERIFICATION_OPTION,array()):array();
        return is_array($raw)?$raw:array();
    }

    /** @return list<object> */
    private function liveAccounts():array{
        return array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),static fn($a)=>(string)$a->mode==='live'&&(string)$a->state==='active'));
    }
    private function recordConnection(string $state,string $code):array{
        $record=array('state'=>$state,'code'=>$code,'at'=>gmdate('Y-m-d H:i:s'),'livemode'=>true,'account_id'=>(int)($this->savedState()['account_id']??0));
        update_option(self::CONNECTION_OPTION,$record,false);
        return $record;
    }
    private function recordWebhookVerification(string $state,string $code,array $missing=array(),string $apiVersion='',array $events=array(),string $endpointId=''):array{
        $record=array(
            'state'=>$state,'code'=>$code,'at'=>gmdate('Y-m-d H:i:s'),'url'=>$this->webhookUrl(),
            'account_id'=>(int)($this->savedState()['account_id']??0),'missing_events'=>$missing,
            'api_version'=>$apiVersion,'enabled_events'=>array_values(array_map('strval',$events)),
            'endpoint_id'=>$endpointId,
        );
        update_option(self::WEBHOOK_VERIFICATION_OPTION,$record,false);
        return $record;
    }
    private function invalidateVerification():void{
        update_option(self::CONNECTION_OPTION,array('state'=>'stale','code'=>'configuration_changed','at'=>gmdate('Y-m-d H:i:s'),'livemode'=>true),false);
        update_option(self::WEBHOOK_VERIFICATION_OPTION,array('state'=>'stale','code'=>'configuration_changed','at'=>gmdate('Y-m-d H:i:s'),'url'=>$this->webhookUrl()),false);
    }
    private function failClosedAfterConfigurationChange(int $accountId):void{
        $activation=StripeProviderMode::activation();
        if(!in_array($activation['status'],array(StripeProviderMode::ACTIVATION_ACTIVATING,StripeProviderMode::ACTIVATION_ACTIVE),true))return;
        try{
            if($this->providers->account($accountId)&&(string)$this->providers->account($accountId)->execution_state==='enabled'){
                (new PaymentProviderAccountService($this->providers))->setExecutionState($accountId,array(
                    'execution_state'=>'disabled','reason_code'=>'stripe_live_credentials_changed',
                    'evidence_channel'=>'authenticated_platform','evidence_reference'=>'stripe_live_rotation:'.$accountId,
                    'evidence_at'=>gmdate('Y-m-d H:i:s'),
                ),'stripe-live-rotation-'.wp_generate_uuid4());
            }
        }finally{
            update_option(StripeProviderMode::LIVE_ACTIVATION_OPTION,array(
                'status'=>StripeProviderMode::ACTIVATION_BLOCKED,'account_id'=>$accountId,
                'account_reference'=>self::LIVE_ACCOUNT_REFERENCE,'activated_at'=>$activation['activated_at'],
                'deactivated_at'=>gmdate('Y-m-d H:i:s'),'actor'=>$activation['actor'],'readiness_digest'=>null,
            ),false);
            $this->recordActivationEvent('blocked','stripe_live_credentials_changed',null);
        }
    }
    private function recordActivationEvent(string $type,string $reason,?string $readinessDigest):void{
        $actor=function_exists('get_current_user_id')?(int)get_current_user_id():0;
        $now=gmdate('Y-m-d H:i:s');
        (new StripeLiveActivationRepository())->insert(array(
            'uid'=>Identifier::uid(),'event_type'=>$type,'reason_code'=>$reason,'account_id'=>(int)($this->savedState()['account_id']??0)?:null,
            'readiness_digest'=>$readinessDigest,'actor_id'=>$actor>0?$actor:null,'occurred_at'=>$now,'created_at'=>$now,
        ));
        if(!function_exists('get_option'))return;
        $history=(array)get_option(StripeProviderMode::LIVE_ACTIVATION_HISTORY_OPTION,array());
        $history[]=array('type'=>$type,'reason_code'=>$reason,'at'=>$now,'actor'=>$actor,'readiness_digest'=>$readinessDigest);
        update_option(StripeProviderMode::LIVE_ACTIVATION_HISTORY_OPTION,array_slice($history,-50),false);
    }
    /** Bind activation to the blocking verdicts, not presentation text that changes by stage. */
    private function readinessDigest(array $readiness):string{
        $states=array();
        foreach((array)($readiness['checks']??array()) as $key=>$check)$states[(string)$key]=(string)($check['state']??'blocked');
        ksort($states);
        return hash('sha256',wp_json_encode(array('account_id'=>(int)($readiness['account_id']??0),'states'=>$states)));
    }
}
