<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionRule,PaymentExecutionSupport,PaymentSecretVault,ProviderVerificationContext,SignatureVerdict};

/**
 * Exact-raw-body Stripe signature verification (contract §9.4).
 *
 * Verification is performed over the exact raw request body bytes as received, before any JSON decode,
 * using the pre-parse scope resolution. Exactly one secret is ever tried — the active
 * `webhook_signing_secret` of the resolved `(provider_key, payment_provider_account_id, mode)` scope —
 * and the comparison is constant-time. A retired secret is never used, so a request signed with one is
 * `signature_invalid`. A header may carry multiple `v1` values while an endpoint secret is rotated;
 * any exact match under the one active secret is accepted, and the vault still tries no other secret.
 */
final class StripeSignatureVerifier {
    public function __construct(private ?PaymentSecretVault $vault=null){$this->vault??=new PaymentSecretVault();}

    /** @param array<string,string> $headers */
    public function verify(string $rawBody,array $headers,ProviderVerificationContext $context):SignatureVerdict{
        if(!$context->complete())return SignatureVerdict::refused('webhook_account_unresolved');
        if(trim($rawBody)==='')return SignatureVerdict::refused('empty_payload');
        if(strlen($rawBody)>PaymentExecutionRule::MAX_WEBHOOK_BYTES)return SignatureVerdict::refused('payload_too_large');
        $header=$this->header($headers,'stripe-signature');
        if($header===null||trim($header)==='')return SignatureVerdict::refused('signature_invalid');
        $parsed=$this->parse($header);
        if($parsed===null)return SignatureVerdict::refused('signature_invalid');
        if($parsed['version']!=='v1')return SignatureVerdict::refused('unsupported_signature_scheme');
        $keyVersion=$context->keyVersion();
        if($keyVersion==='')return SignatureVerdict::refused('webhook_secret_unconfigured');
        $secretId=$this->activeSecretId($context);
        if($secretId<1)return SignatureVerdict::refused('webhook_secret_unconfigured');
        $secret=$this->vault->reveal($secretId,'webhook_signing_secret',$context->providerKey());
        if($secret===null)return SignatureVerdict::refused('webhook_secret_unconfigured');
        $tolerance=min(PaymentExecutionRule::SIGNATURE_TOLERANCE_SECONDS,PaymentExecutionRule::TIMESTAMP_TOLERANCE_CLAMP_SECONDS);
        if(abs(time()-$parsed['timestamp'])>$tolerance)return SignatureVerdict::refused('signature_outside_tolerance',$keyVersion);
        $expected=hash_hmac('sha256',$parsed['timestamp'].'.'.$rawBody,$secret);
        // Stripe may add another `v1=` value while an endpoint secret is rotated. Any exact match under
        // the one active secret is valid; a retired vault secret is still never tried.
        $matched=false;
        foreach($parsed['signatures'] as $signature)if(hash_equals($expected,$signature)){$matched=true;break;}
        if(!$matched)return SignatureVerdict::refused('signature_invalid',$keyVersion);
        return SignatureVerdict::verified($keyVersion);
    }

    /** @return array{version:string,timestamp:int,signatures:list<string>}|null */
    private function parse(string $header):?array{
        $timestamp=null;$versions=array();
        foreach(explode(',',$header) as $part){
            $pair=explode('=',$part,2);
            if(count($pair)!==2)continue;
            $key=trim($pair[0]);$value=trim($pair[1]);
            if($key==='t'&&preg_match('/^\d{1,12}$/D',$value)===1){$timestamp=(int)$value;continue;}
            if($key!==''&&str_starts_with($key,'v')&&preg_match('/^[0-9a-f]{64}$/D',$value)===1)$versions[$key][]=$value;
        }
        if($timestamp===null||$versions===array())return null;
        $version=isset($versions['v1'])?'v1':(string)array_key_first($versions);
        $signatures=array_values(array_unique($versions[$version]));
        if($signatures===array())return null;
        return array('version'=>$version,'timestamp'=>$timestamp,'signatures'=>$signatures);
    }
    private function activeSecretId(ProviderVerificationContext $context):int{
        $row=(new \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentSecretRepository())->activeSecret($context->providerKey(),'webhook_signing_secret',$context->accountId(),$context->mode());
        if(!$row)return 0;
        if($context->keyVersion()!==''&&(string)$row->key_version!==$context->keyVersion())return 0;
        return (int)$row->id;
    }
    /** @param array<string,string> $headers */
    private function header(array $headers,string $name):?string{
        foreach($headers as $key=>$value)if(strtolower((string)$key)===$name)return is_string($value)?$value:null;
        return null;
    }
}
