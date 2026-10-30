<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Normalises a stored contact number to the E.164 form the Telnyx Messaging API requires.
 *
 * Delnavazan contact data may contain Iranian local forms (`09…`), international dialling prefixes
 * (`0098…`) or a bare country code. Only unambiguous Iranian forms are rewritten; an unknown number
 * without a country code is refused rather than guessed, so a message is never sent to the wrong
 * destination.
 */
final class PhoneNumberNormalizer {
    public static function toE164(string $value,string $defaultCountry='IR'):string{
        $value=trim($value);
        if($value==='')return '';
        $plus=str_starts_with($value,'+');
        $digits=preg_replace('/[^0-9]/','',$value)??'';
        if($digits==='')return '';
        if(str_starts_with($digits,'00')){$digits=substr($digits,2);$plus=true;}
        if(strtoupper($defaultCountry)==='IR'){
            if(str_starts_with($digits,'0')&&strlen($digits)===11)$digits='98'.substr($digits,1);
            elseif(str_starts_with($digits,'9')&&strlen($digits)===10)$digits='98'.$digits;
        }
        $candidate=($plus?'+':'').$digits;
        if(preg_match('/^\+[1-9][0-9]{7,14}$/',$candidate)===1)return $candidate;
        if(!$plus&&preg_match('/^[1-9][0-9]{7,14}$/',$digits)===1)return '+'.$digits;
        return '';
    }
}
