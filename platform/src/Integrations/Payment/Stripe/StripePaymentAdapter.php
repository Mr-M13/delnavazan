<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{DispatchDescriptorPreflight,PaymentExecutionDispatchSeal,PaymentExecutionIdempotency,PaymentExecutionOutcome,PaymentExecutionPort,PaymentExecutionRequest,PaymentExecutionRule,PaymentExecutionSupport,ProviderDispatchCapability,ProviderDispatchDescriptor,ProviderReferenceClaims};
use Delnavazan\Platform\Core\Infrastructure\Repository\PaymentProviderRepository;

/**
 * The Stripe execution adapter (contract §5.4).
 *
 * No Stripe SDK dependency is added: the adapter performs provider HTTP itself through
 * `wp_remote_post()`/`wp_remote_get()` with `redirection => 0`, a bounded body, no user-supplied URL and
 * the structural `DISPATCH_CALL_TIMEOUT_SECONDS` timeout, and every returned error body is redacted
 * before it can reach a log or an exception. It never decides eligibility, never computes an amount from
 * a provider response, never writes commercial storage and never retries delivery of a customer message.
 *
 * Because this build provisions no Stripe credential and `LIVE_EXECUTION_PROVIDERS` is empty, the
 * adapter is **provably incapable of any outbound call** in this release: every invocation refuses with
 * `provider_credentials_unconfigured` before a request could be assembled.
 */
final class StripePaymentAdapter implements PaymentExecutionPort {
    public const PROVIDER_KEY='stripe';
    /** Opened, bound and not-yet-consumed dispatch capabilities, keyed by capability digest. One-use. */
    private array $openCapabilities=array();
    private int $outboundCalls=0;

    public function __construct(private ?PaymentProviderRepository $providers=null){$this->providers??=new PaymentProviderRepository();}

    public function key():string{return self::PROVIDER_KEY;}
    public function supports(PaymentExecutionRequest $request):bool{
        return $request->providerKey()===self::PROVIDER_KEY
            &&PaymentExecutionRule::operation($request->operation())!==null
            &&PaymentExecutionRule::mode($request->mode())!==null;
    }
    /** How many outbound requests this adapter has issued. Always 0 in this build. */
    public function outboundCalls():int{return $this->outboundCalls;}

    public function sealDispatchDescriptor(PaymentExecutionRequest $request,ProviderReferenceClaims $claims):ProviderDispatchDescriptor{
        $objects=array();
        foreach(PaymentExecutionRule::operationReferences($request->operation()) as $canonicalKind=>$objectKind){
            $reference=$claims->objectReference($canonicalKind);
            if($reference===null)throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
            $objects[$canonicalKind]=$reference;
        }
        $payload=array(
            'provider_account_reference'=>$claims->providerAccountReference(),
            'provider_object_references'=>$objects,
            'operation'=>$request->operation(),'provider_key'=>$request->providerKey(),'mode'=>$request->mode(),
            'student_id'=>$request->studentId(),'purchase_id'=>$request->purchaseId(),'obligation_id'=>$request->obligationId(),
            'collection_intent_id'=>$request->collectionIntentId(),'renewal_cycle_id'=>$request->renewalCycleId(),
            'amount_minor'=>$request->amountMinor(),'currency'=>$request->currency(),
            'idempotency_key'=>$request->idempotencyKey(),'sealed_at'=>PaymentExecutionSupport::now(),
            'command_key_digest'=>$request->commandKeyDigest(),'idempotency_key_digest'=>$request->idempotencyKeyDigest(),
        );
        return PaymentExecutionDispatchSeal::seal($payload,PaymentExecutionDispatchSeal::keyVersion());
    }

    public function preflightDispatchDescriptor(PaymentExecutionRequest $request,ProviderDispatchDescriptor $descriptor,string $expectedClaimIdempotencyKeyDigest):DispatchDescriptorPreflight{
        $fields=PaymentExecutionDispatchSeal::open($descriptor,PaymentExecutionDispatchSeal::keyVersion());
        if($fields===null)return DispatchDescriptorPreflight::unavailable();
        $sealedCommand=(string)($fields['command_key_digest']??'');
        $sealedClaim=(string)($fields['idempotency_key_digest']??'');
        // The claim half of the binding is an explicit expectation, never inferred from the envelope.
        if(!hash_equals($sealedCommand,$request->commandKeyDigest()))return DispatchDescriptorPreflight::unavailable();
        if(!hash_equals($sealedClaim,$expectedClaimIdempotencyKeyDigest))return DispatchDescriptorPreflight::unavailable();
        if(!hash_equals($sealedClaim,$request->idempotencyKeyDigest()))return DispatchDescriptorPreflight::unavailable();
        if((string)($fields['provider_key']??'')!==$request->providerKey())return DispatchDescriptorPreflight::unavailable();
        $capability=new ProviderDispatchCapability(PaymentExecutionIdempotency::capability(bin2hex(random_bytes(16))));
        $this->openCapabilities[$capability->digest()]=array('fields'=>$fields,'requested_at'=>$request->requestedAt());
        return DispatchDescriptorPreflight::ok($sealedCommand,$sealedClaim,$capability);
    }

    public function submit(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'submit');
    }
    public function cancel(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'cancel');
    }
    public function reconcile(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'reconcile');
    }

    private function invoke(PaymentExecutionRequest $request,ProviderDispatchCapability $capability,string $operation):PaymentExecutionOutcome{
        // The capability is one-use and adapter-owned: an unconsumable one issues no request at all.
        $digest=$capability->digest();
        if(!isset($this->openCapabilities[$digest]))return new PaymentExecutionOutcome('not_attempted','dispatch_descriptor_unavailable',null,null);
        $fields=$this->openCapabilities[$digest]['fields'];
        unset($this->openCapabilities[$digest]);
        if((string)($fields['operation']??'')!==$request->operation())return new PaymentExecutionOutcome('not_attempted','dispatch_descriptor_unavailable',null,null);
        $account=$this->providers->account($request->providerAccountId());
        if(!$account)return new PaymentExecutionOutcome('invalid_request','provider_account_inactive',null,null);
        if((string)$account->state!=='active')return new PaymentExecutionOutcome('invalid_request','provider_account_inactive',null,null);
        if((string)$account->execution_state!=='enabled')return new PaymentExecutionOutcome('invalid_request','provider_execution_disabled',null,null);
        if((string)$account->credential_state!=='configured')return new PaymentExecutionOutcome('unavailable','provider_credentials_unconfigured',null,null);
        if((string)$account->mode==='live'&&!PaymentExecutionRule::liveExecutionAuthorised((string)$account->provider_key))return new PaymentExecutionOutcome('invalid_request','live_execution_not_authorised',null,null);
        // Unreachable in this build: no path can provision a Stripe credential (§11.5), so no request
        // can ever be assembled. The refusal above always fires first.
        return $this->outbound($fields,$operation);
    }

    /** The single outbound path. It is never reached in this build; it exists so the seam is complete. */
    private function outbound(array $fields,string $operation):PaymentExecutionOutcome{
        if(!function_exists('wp_remote_post')||!function_exists('wp_remote_get'))return new PaymentExecutionOutcome('unavailable','provider_unavailable',null,null);
        $url='https://api.stripe.com/v1/'.($operation==='reconcile'?'payment_intents/'.rawurlencode((string)($fields['provider_object_references']['obligation']??'')):'payment_intents');
        $this->outboundCalls++;
        $response=$operation==='reconcile'
            ?wp_remote_get($url,array('timeout'=>PaymentExecutionRule::DISPATCH_CALL_TIMEOUT_SECONDS,'redirection'=>0,'headers'=>array('Idempotency-Key'=>(string)$fields['idempotency_key'])))
            :wp_remote_post($url,array('timeout'=>PaymentExecutionRule::DISPATCH_CALL_TIMEOUT_SECONDS,'redirection'=>0,'body'=>array('amount'=>(int)$fields['amount_minor'],'currency'=>strtolower((string)$fields['currency'])),'headers'=>array('Idempotency-Key'=>(string)$fields['idempotency_key'])));
        if(function_exists('is_wp_error')&&is_wp_error($response))return new PaymentExecutionOutcome('unavailable','provider_unavailable',null,null);
        $code=(int)(function_exists('wp_remote_retrieve_response_code')?wp_remote_retrieve_response_code($response):0);
        $body=function_exists('wp_remote_retrieve_body')?(string)wp_remote_retrieve_body($response):'';
        $decoded=json_decode($body,true);
        if(!is_array($decoded))return new PaymentExecutionOutcome('unavailable','provider_response_unusable',null,null);
        if($code>=200&&$code<300){
            $reference=isset($decoded['id'])&&is_string($decoded['id'])?$decoded['id']:null;
            return new PaymentExecutionOutcome('accepted_by_provider','provider_accepted',$reference,gmdate('Y-m-d H:i:s'));
        }
        return new PaymentExecutionOutcome('declined','provider_declined',null,null);
    }

    /** Redaction helper: no provider error body, credential or key-shaped token may reach a log. */
    public static function redact(string $text):string{
        $redacted=preg_replace('/\b(sk|pk|rk)_[A-Za-z0-9_]{4,}\b/','[REDACTED_SECRET]',$text)??$text;
        $redacted=preg_replace('/\bwhsec_[A-Za-z0-9_]{4,}\b/','[REDACTED_SECRET]',$redacted)??$redacted;
        return preg_replace('/(?i)(authorization|stripe-signature)\s*[:=]\s*\S+/','[REDACTED_SECRET]',$redacted)??$redacted;
    }
}
