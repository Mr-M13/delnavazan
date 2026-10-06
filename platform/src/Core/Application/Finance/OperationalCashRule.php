<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/** Operational academy cashbook vocabulary. This authority is separate from provider payments. */
final class OperationalCashRule {
    public const DOMAIN='academy_cashbook_v1';
    public const RULE_VERSION='academy_cashbook_v1';
    public const TABLES=array('finance_cash_accounts','finance_cash_transactions','finance_cash_transfers','finance_cash_commands','finance_cash_reconciliations');
    public const ACCOUNTS=array(
        'australia'=>array('label'=>'Australia account','currency'=>'AUD','currency_label'=>'Australian dollars','minor_digits'=>2),
        'iran'=>array('label'=>'Iran account','currency'=>'TOM','currency_label'=>'Tomans','minor_digits'=>0),
    );
    public const TRANSACTION_KINDS=array('income','expense','opening_balance','balance_adjustment','transfer_out','transfer_in','reversal');
    public const EVIDENCE_CHANNELS=array('staff_record','document_reference');
    public const REASON_CODES=array('data_entry_correction','opening_balance','balance_reconciliation','other_reviewed_correction');

    public static function account(string $key):array {
        if(!isset(self::ACCOUNTS[$key])) throw new \InvalidArgumentException('Choose one of the two academy cash accounts');
        return self::ACCOUNTS[$key]+array('key'=>$key);
    }

    /** Parse exact major-unit input into integer cents for AUD or whole tomans for Iran. */
    public static function amount(mixed $input,string $currency,bool $allowZero=false):int {
        $raw=self::normaliseDigits(is_scalar($input)?(string)$input:'');
        $digits=$currency==='AUD'?2:($currency==='TOM'?0:-1);
        if($digits<0) throw new \InvalidArgumentException('Unsupported cashbook currency');
        $hasGrouping=str_contains($raw,',')||str_contains($raw,"\u{066C}");
        if($hasGrouping){$pattern=$digits===2?'/^\d{1,3}(?:[,٬]\d{3})+(?:\.\d{1,2})?$/uD':'/^\d{1,3}(?:[,٬]\d{3})+$/uD';}
        else{$pattern=$digits===2?'/^\d{1,12}(?:\.\d{1,2})?$/D':'/^\d{1,15}$/D';}
        if(preg_match($pattern,$raw)!==1) throw new \InvalidArgumentException($digits===2?'Enter an amount in dollars with up to two decimal places':'Enter a whole toman amount');
        $raw=str_replace(array(',',"\u{066C}"),'',$raw);
        $parts=explode('.',$raw,2);$major=(int)$parts[0];$minor=$digits===2?(int)str_pad($parts[1]??'',2,'0'):0;
        $value=$major*($digits===2?100:1)+$minor;
        if($value<($allowZero?0:1))throw new \InvalidArgumentException($allowZero?'Amount cannot be negative':'Amount must be greater than zero');
        return $value;
    }

    /** Parse a signed external statement balance; ledger entries themselves remain positive-only. */
    public static function statementBalance(mixed $input,string $currency):int {
        $raw=self::normaliseDigits(is_scalar($input)?(string)$input:'');
        $negative=str_starts_with($raw,'-');
        if($negative)$raw=substr($raw,1);
        $value=self::amount($raw,$currency,true);
        return $negative?-$value:$value;
    }

    /** Preserve the operator-entered historical quote as a canonical decimal string; never use live FX. */
    public static function rate(mixed $input):string {
        $raw=self::normaliseDigits(is_scalar($input)?(string)$input:'');
        $grouped=str_contains($raw,',')||str_contains($raw,"\u{066C}");
        $pattern=$grouped?'/^\d{1,3}(?:[,٬]\d{3})+(?:\.\d{1,8})?$/uD':'/^\d{1,10}(?:\.\d{1,8})?$/D';
        if(preg_match($pattern,$raw)!==1)throw new \InvalidArgumentException('Enter the historical TOM per AUD rate');
        $raw=str_replace(array(',',"\u{066C}"),'',$raw);
        if(!preg_match('/[1-9]/',$raw))throw new \InvalidArgumentException('Enter the historical TOM per AUD rate');
        [$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');
        return (string)(int)$whole.($fraction!==''?'.'.rtrim($fraction,'0'):'');
    }

    public static function date(mixed $input):string {
        $value=is_scalar($input)?trim((string)$input):'';
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        if(!$dt||$dt->format('Y-m-d')!==$value||$value>gmdate('Y-m-d'))throw new \InvalidArgumentException('Enter a valid transaction date no later than today');
        return $value;
    }

    public static function text(mixed $input,int $max,string $message):string {
        $value=trim(is_scalar($input)?(string)$input:'');
        if($value===''||strlen($value)>$max)throw new \InvalidArgumentException($message);
        return $value;
    }

    public static function optionalText(mixed $input,int $max):?string {
        $value=trim(is_scalar($input)?(string)$input:'');
        if($value==='' )return null;
        if(strlen($value)>$max)throw new \InvalidArgumentException('Text is too long');
        return $value;
    }

    public static function evidence(array $input,bool $required=false):array {
        $channel=(string)($input['evidence_channel']??'staff_record');
        if(!in_array($channel,self::EVIDENCE_CHANNELS,true))throw new \InvalidArgumentException('Choose a supported evidence type');
        $reference=self::optionalText($input['evidence_reference']??null,191);
        if($required&&$reference===null)throw new \InvalidArgumentException('Add a statement, receipt or other exchange evidence reference');
        if($channel==='document_reference'&&$reference===null)throw new \InvalidArgumentException('Add the document or statement reference');
        return array('channel'=>$channel,'reference'=>$reference,'reference_digest'=>$reference===null?null:FinanceIdempotency::evidence($reference),'at'=>$reference===null?null:gmdate('Y-m-d H:i:s'));
    }

    public static function normaliseDigits(string $value):string {
        return strtr($value,array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.'));
    }

    public static function format(int $minor,string $currency):string {
        $digits=$currency==='AUD'?2:0;$scale=$digits===2?100:1;
        $sign=$minor<0?'−':'';$minor=abs($minor);
        $whole=intdiv($minor,$scale);$fraction=$digits===2?sprintf('.%02d',$minor%$scale):'';
        return $sign.number_format($whole,0,'.',',').$fraction.' '.($currency==='AUD'?'AUD':'TOM');
    }
}
