<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;

/**
 * Shared fail-closed input, actor, lock and worker-principal handling for Phase 2A.2-T (contract §6, §9.7, §13).
 *
 * The R1 `commercial_account_roots` row for the beneficiary Student is the single serialisation root of
 * every Phase-T command and every event decision that can reach commercial authority; the fixed R1 lock
 * order is inherited, never re-implemented and never inverted.
 */
final class PaymentExecutionSupport {
    /** The option naming the bounded, non-human service principal of §9.7. */
    public const WORKER_PRINCIPAL_OPTION='dzn_platform_payment_worker_principal';
    /** The exact four capabilities the worker principal must hold: no more, no fewer. */
    public const WORKER_CAPABILITIES=array(
        'dzn_ingest_payment_provider_events','dzn_ingest_commercial_payment_evidence',
        'dzn_manage_collection_intents','dzn_manage_renewal_cycles',
    );
    /** An administrative capability on the worker principal refuses it (§9.7). */
    public const WORKER_FORBIDDEN_CAPABILITIES=array('manage_options','dzn_manage_payment_execution','dzn_manage_payment_providers');

    public static function requireCapability(string $capability):void{
        if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');
    }
    public static function actor():int{
        $id=get_current_user_id();
        if($id<1)throw new \RuntimeException('Payment execution actor unavailable');
        return $id;
    }
    public static function now():string{return gmdate('Y-m-d H:i:s');}
    public static function keyString(string $key):string{return PaymentExecutionIdempotency::key($key);}

    /** Serialise a Phase-T mutation on its owning Student before any Phase-T row is read or written. */
    public static function lockAccountRoot(int $studentId,int $actor):void{
        $studentId=self::positiveInt($studentId,'Valid beneficiary Student required');
        (new CommercialAuthorityRepository())->lockAccountRoot($studentId,$actor);
    }

    /** Test/observability hook, mirroring the R1/R2 convention. Ids only; never a provider payload. */
    public static function hook(string $moment,...$arguments):void{
        if(function_exists('do_action'))do_action($moment,...$arguments);
    }

    public static function positiveInt(mixed $value,string $message):int{
        if(is_int($value))$int=$value;
        elseif(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)$int=(int)trim($value);
        else throw new \InvalidArgumentException($message);
        if($int<1)throw new \InvalidArgumentException($message);
        return $int;
    }
    public static function optionalPositiveInt(mixed $value,string $message):?int{
        if($value===null||$value==='')return null;
        return self::positiveInt($value,$message);
    }
    public static function utc(mixed $value,string $message):string{
        $value=is_string($value)?trim($value):'';
        if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)!==1||strtotime($value.' UTC')===false)throw new \InvalidArgumentException($message);
        return $value;
    }
    public static function utcOrNull(mixed $value,string $message):?string{
        if($value===null||$value==='')return null;
        return self::utc($value,$message);
    }
    public static function amount(mixed $value,string $message):int{
        if(is_int($value))$int=$value;
        elseif(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)$int=(int)trim($value);
        else throw new \InvalidArgumentException($message);
        if($int<0)throw new \InvalidArgumentException($message);
        return $int;
    }
    public static function currency(string $currency):string{
        $value=\Delnavazan\Platform\Core\Application\CommercialRule::currency($currency);
        if($value===null)throw new \InvalidArgumentException('Supported currency required');
        return $value;
    }
    public static function reason(array $input,string $field='reason_code'):string{
        $reason=(string)($input[$field]??'');
        if(preg_match('/^[a-z0-9_]{1,64}$/D',$reason)!==1)throw new \InvalidArgumentException('Controlled reason code required');
        return $reason;
    }
    public static function controlledReasonOrNull(mixed $value):bool{
        return $value===null||(is_string($value)&&preg_match('/^[a-z0-9_]{1,64}$/D',$value)===1);
    }
    public static function digestOrNull(mixed $value):bool{
        return $value===null||(is_string($value)&&preg_match('/^[0-9a-f]{64}$/D',$value)===1);
    }

    /**
     * The resolved §9.7 worker principal id, or 0 when the principal is unset, missing, inactive,
     * missing one of the four bounded capabilities, or holding an administrative capability.
     *
     * The option value and nothing else names the principal: no request, header, payload, query
     * argument or caller supplies an identity here.
     */
    public static function workerPrincipalId():int{
        if(!function_exists('get_option'))return 0;
        $principalId=(int)get_option(self::WORKER_PRINCIPAL_OPTION);
        if($principalId<1)return 0;
        $user=function_exists('get_user_by')?get_user_by('id',$principalId):null;
        if(!$user)return 0;
        if(isset($user->user_status)&&(int)$user->user_status!==0)return 0;
        $userCaps=(array)($user->allcaps??array());
        foreach(self::WORKER_CAPABILITIES as $capability)if(empty($userCaps[$capability]))return 0;
        foreach(self::WORKER_FORBIDDEN_CAPABILITIES as $capability)if(!empty($userCaps[$capability]))return 0;
        return $principalId;
    }
}
