<?php
namespace Delnavazan\Platform\Core\Application;

/** Shared, fail-closed input and authority handling for the Phase 2A.2-R1 commercial commands. */
final class CommercialSupport {
    public static function requireCapability(string $capability):void{
        if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');
    }
    public static function actor(string $message='Commercial actor unavailable'):int{
        $id=get_current_user_id();
        if($id<1)throw new \RuntimeException($message);
        return $id;
    }
    public static function now():string{return gmdate('Y-m-d H:i:s');}
    public static function keyString(string $key):string{return CommercialIdempotency::key($key);}

    /**
     * Controlled evidence: a channel, an exact past-or-present UTC instant and a digest-only
     * reference. Raw references never persist.
     */
    public static function evidence(array $input,string $referenceKey='evidence_reference'):array{
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,CommercialRule::EVIDENCE_CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($input['evidence_at']??'');
        if(!CommercialValidator::utc($at)||$at>self::now())throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        $reference=(string)($input[$referenceKey]??'');
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return array('channel'=>$channel,'at'=>$at,'digest'=>CommercialIdempotency::evidence($reference));
    }
    /** A controlled lower-case reason code, never free text. */
    public static function reason(array $input,string $field='reason_code'):string{
        $reason=(string)($input[$field]??'');
        if(preg_match('/^[a-z0-9_]{1,64}$/D',$reason)!==1)throw new \InvalidArgumentException('Controlled reason code required');
        return $reason;
    }
    public static function positiveInt(mixed $value,string $message,int $max=PHP_INT_MAX):int{
        if(is_int($value))$int=$value;
        elseif(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)$int=(int)trim($value);
        else throw new \InvalidArgumentException($message);
        if($int<1||$int>$max)throw new \InvalidArgumentException($message);
        return $int;
    }
    /** Exact minor units: never a float, never a locale-formatted string. */
    public static function amount(mixed $value,string $message):int{
        if(is_int($value))return CommercialMoney::amount($value);
        if(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)return CommercialMoney::amount((int)trim($value));
        throw new \InvalidArgumentException($message);
    }
    public static function region(string $regionCode):array{
        $region=strtoupper(trim($regionCode));
        $currency=CommercialRule::regionCurrency($region);
        if($currency===null)throw new \InvalidArgumentException('Supported commercial region required');
        return array('region_code'=>$region,'currency'=>$currency);
    }
    public static function obligationReference(string $offerUid,int $sequence):string{
        return CommercialIdempotency::obligationReference($offerUid.':'.$sequence);
    }
    /** Reject anything that cannot be an exact UTC instant in the canonical storage shape. */
    public static function utc(mixed $value,string $message):string{
        $value=is_string($value)?trim($value):'';
        if(!CommercialValidator::utc($value))throw new \InvalidArgumentException($message);
        return $value;
    }
}
