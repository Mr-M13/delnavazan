<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionIdempotency,ProviderEventEnvelope,ProviderEventTranslator,ProviderVerificationContext,SignatureVerdict};

/**
 * Stripe event normalisation (contract §10).
 *
 * The translator recognises a controlled set of Stripe event types and normalises them into the
 * provider-neutral vocabulary. It writes nothing: the intake service submits the resulting facts to the
 * existing R1 evidence boundary, which alone decides acceptance. Amounts are passed through exactly as
 * observed — never rounded, converted or filtered — so R1's own comparison records any mismatch. The
 * raw payload is never stored; only its digest and the raw type digest survive.
 */
final class StripeEventTranslator implements ProviderEventTranslator {
    public const PROVIDER_KEY='stripe';
    /** Normalised type → the raw Stripe types that carry it. */
    private const TYPE_MAP=array(
        'payment_succeeded'=>array('payment_intent.succeeded','charge.succeeded','invoice.paid'),
        'payment_failed'=>array('payment_intent.payment_failed','charge.failed','invoice.payment_failed'),
        'payment_requires_action'=>array('payment_intent.processing','payment_intent.requires_action'),
        'refund_recorded'=>array('charge.refunded','refund.created','refund.updated'),
        'mandate_recorded'=>array('setup_intent.succeeded','payment_method.attached','mandate.updated'),
    );
    /** Provider recurring semantics stay an explicit owner decision: recorded, never acted upon (T-D9). */
    private const RECURRING_UNRESOLVED_PREFIXES=array('customer.subscription.','invoice.upcoming','invoice.created');
    private const METHOD_FAMILIES=array('card','bank_redirect','direct_debit','wallet','other');

    public function __construct(private ?StripeSignatureVerifier $verifier=null){$this->verifier??=new StripeSignatureVerifier();}

    public function key():string{return self::PROVIDER_KEY;}

    public function verify(string $rawBody,array $headers,ProviderVerificationContext $context):SignatureVerdict{
        return $this->verifier->verify($rawBody,$headers,$context);
    }

    public function translate(string $rawBody,array $headers,ProviderVerificationContext $context,string $receivedAt):array{
        $decoded=json_decode($rawBody,true);
        if(!is_array($decoded))return array();
        $rawType=isset($decoded['type'])&&is_string($decoded['type'])?$decoded['type']:'';
        if($rawType==='')return array();
        $eventReference=isset($decoded['id'])&&is_string($decoded['id'])?$decoded['id']:'';
        if($eventReference==='')return array();
        $object=isset($decoded['data']['object'])&&is_array($decoded['data']['object'])?$decoded['data']['object']:array();
        $metadata=isset($object['metadata'])&&is_array($object['metadata'])?$object['metadata']:array();
        return array(new ProviderEventEnvelope(
            $context->providerKey(),$eventReference,$this->normaliseType($rawType),$rawType,
            PaymentExecutionIdempotency::payloadDigest($rawBody),$this->occurredAt($decoded),
            $this->amount($object),$this->currency($object),$this->reference($metadata,'obligation_reference'),
            $this->reference($metadata,'provider_account_reference'),
            isset($object['id'])&&is_string($object['id'])?$object['id']:null,$this->methodFamily($object)
        ));
    }

    /** Map one raw Stripe type into the controlled normalised vocabulary. */
    public function normaliseType(string $rawType):string{
        foreach(self::TYPE_MAP as $normalised=>$rawTypes)if(in_array($rawType,$rawTypes,true))return $normalised;
        foreach(self::RECURRING_UNRESOLVED_PREFIXES as $prefix)if(str_starts_with($rawType,$prefix))return 'provider_recurring_semantics_unresolved';
        return 'unrecognised_provider_event';
    }

    /** Card, wallets and bank redirects are recorded as a neutral method family, never as authority. */
    public function methodFamily(array $object):string{
        $type='';
        if(isset($object['payment_method_details']['type'])&&is_string($object['payment_method_details']['type']))$type=$object['payment_method_details']['type'];
        elseif(isset($object['payment_method_types'][0])&&is_string($object['payment_method_types'][0]))$type=$object['payment_method_types'][0];
        elseif(isset($object['type'])&&is_string($object['type']))$type=$object['type'];
        $family=match($type){
            'card','card_present'=>'card',
            'ideal','bancontact','sofort','giropay','eps'=>'bank_redirect',
            'sepa_debit','acss_debit','bacs_debit','au_becs_debit'=>'direct_debit',
            'link','apple_pay','google_pay','paypal'=>'wallet',
            default=>'other',
        };
        return in_array($family,self::METHOD_FAMILIES,true)?$family:'other';
    }

    private function occurredAt(array $decoded):?string{
        foreach(array('created','created_at') as $key)if(isset($decoded[$key])&&is_numeric($decoded[$key]))return gmdate('Y-m-d H:i:s',(int)$decoded[$key]);
        return null;
    }
    private function amount(array $object):?int{
        foreach(array('amount','amount_received','amount_paid','amount_refunded','total') as $key)if(isset($object[$key])&&is_numeric($object[$key]))return (int)$object[$key];
        return null;
    }
    private function currency(array $object):?string{
        return isset($object['currency'])&&is_string($object['currency'])&&trim($object['currency'])!==''?strtoupper(trim($object['currency'])):null;
    }
    private function reference(array $metadata,string $key):?string{
        $value=$metadata[$key]??null;
        return is_string($value)&&trim($value)!==''?trim($value):null;
    }
}
