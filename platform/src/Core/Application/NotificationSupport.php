<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Phase 2A.2-S shared support: capability/nonce separation, keyed digests, canonical parameter text and
 * overflow-safe integer-second time arithmetic.
 *
 * Nothing here reads a clock for a derivation input: `now()` exists for audit columns only, and every
 * derivation instant is passed in from a persisted value. The class never touches another module's
 * mutable row, never derives a commercial policy and never resolves a pattern wall-clock.
 */
final class NotificationSupport {
    /** The sub-key used for every S key derivation, so no S digest reuses another domain's salt. */
    public const SALT_DOMAIN='dzn_notification';
    /** The stored `datetime` domain (§6.3): every derived instant must land inside it. */
    public const DATETIME_MIN='1000-01-01 00:00:00';
    public const DATETIME_MAX='9999-12-31 23:59:59';
    public const SECONDS_PER_DAY=86400;

    public static function now():string{return gmdate('Y-m-d H:i:s');}
    public static function salt():string{return wp_salt(self::SALT_DOMAIN);}

    /** Require one explicit capability. Reads and writes never share a capability. */
    public static function requireCapability(string $capability):void{
        if(!NotificationRule::capability($capability)&&$capability!==NotificationRule::READ_CAPABILITY)throw new \InvalidArgumentException('unknown_notification_capability');
        if(!is_user_logged_in()||!current_user_can($capability))throw new \RuntimeException('notification_capability_required');
    }
    public static function actor():int{$actor=get_current_user_id();if($actor<1)throw new \RuntimeException('notification_actor_required');return $actor;}

    /**
     * Validate the shared evidence envelope every S command carries: an allowlisted channel, a
     * non-empty reference (keyed to a digest, never stored raw) and the instant it was recorded.
     */
    public static function evidence(array $input):array{
        $channel=trim((string)($input['evidence_channel']??''));
        $reference=trim((string)($input['evidence_reference']??''));
        $at=trim((string)($input['evidence_at']??''));
        if(!in_array($channel,NotificationRule::EVIDENCE_CHANNELS,true))throw new \InvalidArgumentException('notification_evidence_channel_invalid');
        if($reference==='')throw new \InvalidArgumentException('notification_evidence_reference_required');
        if(self::seconds($at)===null)throw new \InvalidArgumentException('notification_evidence_at_invalid');
        return array('channel'=>$channel,'reference'=>$reference,'at'=>$at,'digest'=>hash_hmac('sha256','evidence:'.$channel.':'.$reference,self::salt()));
    }
    /** A caller-supplied idempotency key, folded to its keyed digest (§9). */
    public static function keyDigest(string $key):string{
        $key=trim($key);
        if($key==='')throw new \InvalidArgumentException('notification_command_key_required');
        return hash_hmac('sha256','notification_command:'.$key,self::salt());
    }
    /** The immutable digest of one command's payload; a same-key/different-payload replay fails closed. */
    public static function payloadDigest(array $payload):string{return hash_hmac('sha256',wp_json_encode(self::canonicalise($payload)),self::salt());}
    /**
     * §6.4 canonical rendered-parameter digest: the key-ordered encoding of the parameter map, so the same
     * parameter set always reproduces one digest however the caller ordered it.
     */
    public static function paramsDigest(array $parameters):string{
        return hash_hmac('sha256',NotificationRule::PARAMS_DIGEST_PREFIX.wp_json_encode(self::canonicalise($parameters)),self::salt());
    }
    /**
     * §6.4 canonical variable contract: the sorted, deduplicated allowlisted codes as comma-separated text,
     * with the digest and the required count derived from exactly that text. A malformed code is a contract
     * failure (`template_variable_mismatch`), never a value to interpret.
     */
    public static function variableContract(array $codes):array{
        $canonical=array();
        foreach($codes as $code){
            if(!is_string($code)&&!is_int($code))throw new \InvalidArgumentException('template_variable_mismatch');
            $code=(string)$code;
            if(preg_match(NotificationRule::VARIABLE_CODE_PATTERN,$code)!==1)throw new \InvalidArgumentException('template_variable_mismatch');
            $canonical[$code]=true;
        }
        $canonical=array_keys($canonical);
        sort($canonical,SORT_STRING);
        $text=implode(',',$canonical);
        if(strlen($text)>NotificationRule::VARIABLE_CONTRACT_MAX)throw new \InvalidArgumentException('template_variable_mismatch');
        return array(
            'variable_contract'=>$text,'variable_codes'=>$canonical,'variable_count'=>count($canonical),
            'variable_contract_digest'=>self::variableContractDigest($text),
        );
    }
    /** The digest of one canonical contract text, and the only place that digest is derived. */
    public static function variableContractDigest(string $text):string{
        return hash_hmac('sha256',NotificationRule::VARIABLE_CONTRACT_PREFIX.$text,self::salt());
    }
    /** Deterministic key ordering so two equal payloads always produce one digest. */
    private static function canonicalise($value){
        if(!is_array($value))return is_bool($value)?($value?'true':'false'):(string)($value??'');
        $out=array();
        $isList=array_keys($value)===range(0,count($value)-1);
        if($isList){foreach($value as $item)$out[]=self::canonicalise($item);return $out;}
        ksort($value);
        foreach($value as $key=>$item)$out[(string)$key]=self::canonicalise($item);
        return $out;
    }

    /** §9 logical identity: one notification per logical event, whatever order a replay arrives in. */
    public static function notificationKeyDigest(string $workflowKey,int $workflowVersion,string $intentKey,string $audience,string $recipientDigest,string $subjectAggregate,int $subjectId,string $coalesceBucket):string{
        return hash_hmac('sha256','notification:'.$workflowKey.':'.$workflowVersion.':'.$intentKey.':'.$audience.':'.$recipientDigest.':'.$subjectAggregate.':'.$subjectId.':'.$coalesceBucket,self::salt());
    }
    /** §6.2.3 bound-evidence digest: proves which immutable fact a dispatch was decided on. */
    public static function boundEvidenceDigest(string $aggregate,int $aggregateId,string $eventType,?string $fromState,string $toState,string $occurredAt):string{
        return hash_hmac('sha256','bound_evidence:'.$aggregate.':'.$aggregateId.':'.$eventType.':'.($fromState??'').':'.$toState.':'.$occurredAt,self::salt());
    }
    /** §6.2.4(c) tier-F digest: binds the frozen announced instant into the dispatch evidence. */
    public static function tierFEvidenceDigest(string $aggregate,int $aggregateId,string $column,string $instant):string{
        return hash_hmac('sha256','tier_f_instant:'.$aggregate.':'.$aggregateId.':'.$column.':'.$instant,self::salt());
    }
    /** §9 retry-evidence digest: the audited proof of one persisted retry schedule, digest-only. */
    public static function retryEvidenceDigest(string $notificationKeyDigest,int $attemptSequence,int $appliedJitterBp,int $backoffSeconds):string{
        return hash_hmac('sha256',NotificationRule::RETRY_EVIDENCE_PREFIX.$notificationKeyDigest.':'.$attemptSequence.':'.$appliedJitterBp.':'.$backoffSeconds,self::salt());
    }
    /** §9 deterministic keyed jitter entropy: a fixed 8-hex-character (uint32) prefix, never an RNG. */
    public static function jitterEntropy(string $notificationKeyDigest,int $workflowVersion,int $attemptSequence):int{
        $digest=hash_hmac('sha256',NotificationRule::RETRY_KEY_PREFIX.$notificationKeyDigest.':'.$workflowVersion.':'.$attemptSequence,self::salt());
        return (int)hexdec(substr($digest,0,8));
    }

    /**
     * Canonical unsigned decimal text (§6.3): `0` or `[1-9][0-9]*` — no sign, no leading zero, no
     * whitespace, no fractional part. Anything else is a malformed rule rather than a value to read.
     */
    public static function canonicalUnsigned(string $value):?int{
        if($value===''||preg_match('/^(0|[1-9][0-9]*)$/',$value)!==1)return null;
        $length=strlen($value);
        if($length>18)return null;
        $number=(int)$value;
        return (string)$number===$value?$number:null;
    }
    /** Canonical `HH:MM` 24-hour zero-padded text, with the tolerated range checked by the caller. */
    public static function canonicalTime(string $value):bool{
        if(preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/',$value)!==1)return false;
        return true;
    }
    /** Seconds-of-day of a canonical `HH:MM` value, or null when it is not canonical. */
    public static function timeOfDaySeconds(string $value):?int{
        if(!self::canonicalTime($value))return null;
        return ((int)substr($value,0,2))*3600+((int)substr($value,3,2))*60;
    }

    /**
     * Parse a stored `datetime` into integer UTC seconds, or null when it is not a stored instant.
     *
     * The parse judges a value by the schema's own `datetime` domain (§6.3, `DATETIME_MIN`…`DATETIME_MAX`)
     * and not by the calendar alone: `0999-12-31 23:59:59` and a year above `9999` are shapes the stored
     * column can never hold, so they are refused here and every caller — the evidence envelope, the
     * schedule derivation and `addSeconds()`'s own base — inherits one refusal rather than deriving from an
     * instant the column could not contain. The declared shape fixes a four-digit, zero-padded year, so the
     * domain comparison is exact on the canonical text.
     */
    public static function seconds(string $datetime):?int{
        $datetime=trim($datetime);
        if($datetime===''||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/',$datetime)!==1)return null;
        if($datetime<self::DATETIME_MIN||$datetime>self::DATETIME_MAX)return null;
        $parts=array_map('intval',array(substr($datetime,0,4),substr($datetime,5,2),substr($datetime,8,2),substr($datetime,11,2),substr($datetime,14,2),substr($datetime,17,2)));
        if(!checkdate($parts[1],$parts[2],$parts[0])||$parts[3]>23||$parts[4]>59||$parts[5]>59)return null;
        try{$instant=new \DateTimeImmutable($datetime,new \DateTimeZone('UTC'));}catch(\Throwable $error){return null;}
        if($instant->format('Y-m-d H:i:s')!==$datetime)return null;
        return $instant->getTimestamp();
    }
    /**
     * Format integer UTC seconds as a stored instant, refusing only a result outside the declared
     * `datetime` domain (§6.3). That domain begins at `1000-01-01`, not at the Unix epoch, so a
     * pre-epoch (negative) second count is a valid instant whenever it lands inside the domain; the
     * only refusals are a result below `DATETIME_MIN` and one above `DATETIME_MAX`.
     */
    public static function instant(int $seconds):?string{
        $formatted=gmdate('Y-m-d H:i:s',$seconds);
        if($formatted===false||strlen($formatted)!==19)return null;
        if($formatted<self::DATETIME_MIN||$formatted>self::DATETIME_MAX)return null;
        return $formatted;
    }
    /**
     * Add integer seconds to a stored instant, refusing an out-of-domain base, intermediate or result.
     *
     * The base is parsed by `seconds()`, so a base outside the declared domain is refused here even when
     * the sum would happen to land back inside it: the arithmetic can never launder an instant the stored
     * column could not contain. The sum itself is a 64-bit integer second count judged by the same
     * full-domain rule as `instant()`, so a pre-epoch base and an offset that lands before the epoch
     * derive normally rather than failing closed as a divergence.
     */
    public static function addSeconds(string $datetime,int $seconds):?string{
        $base=self::seconds($datetime);
        if($base===null)return null;
        return self::instant($base+$seconds);
    }
    /** Whole days between two instants (later minus earlier), or null when either is unreadable. */
    public static function dayDifference(string $earlier,string $later):?int{
        $from=self::seconds($earlier);$to=self::seconds($later);
        if($from===null||$to===null)return null;
        return intdiv($to-$from,self::SECONDS_PER_DAY);
    }
    /** The later of two stored instants. */
    public static function later(string $left,string $right):string{return $left>=$right?$left:$right;}
    /** The earlier of two stored instants. */
    public static function earlier(string $left,string $right):string{return $left<=$right?$left:$right;}

    /**
     * Resolve one local wall clock to its UTC instant through the version's single persisted zone.
     *
     * This is S's own conversion for its placement and window steps (§6.3 steps 2–3): it reads no
     * pattern row, no current schedule and no commercial policy, and a zone that cannot resolve is the
     * caller's terminal `schedule_timezone_unresolved` outcome rather than a silent default.
     */
    public static function localToUtc(string $timezone,string $localDate,string $localTime):?string{
        if($timezone===''||!self::canonicalTime($localTime)||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/',$localDate)!==1)return null;
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable $error){return null;}
        try{$instant=new \DateTimeImmutable($localDate.' '.$localTime.':00',$zone);}catch(\Throwable $error){return null;}
        return self::instant($instant->getTimestamp());
    }
    /** The local wall date and time of one UTC instant in the version's single persisted zone. */
    public static function utcToLocal(string $timezone,string $instant):?array{
        $seconds=self::seconds($instant);
        if($seconds===null)return null;
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable $error){return null;}
        $local=(new \DateTimeImmutable('@'.$seconds))->setTimezone($zone);
        return array('date'=>$local->format('Y-m-d'),'time'=>$local->format('H:i'),'weekday'=>(int)$local->format('N'));
    }
    /**
     * §6.3 step 7: the anchor-derived coalesce bucket, or the empty string when no rule is registered.
     * The bucket is `floor(anchor_at / (coalesce_window_minutes * 60))` over the epoch, so a pre-epoch
     * anchor floors to the bucket below zero; `intdiv()` truncates toward zero, so a negative
     * non-multiple is corrected by one step. For every anchor at or after the epoch the two agree.
     */
    public static function coalesceBucket(string $anchorAt,?int $windowMinutes):string{
        if($windowMinutes===null||$windowMinutes<1)return '';
        $seconds=self::seconds($anchorAt);
        if($seconds===null)return '';
        $width=$windowMinutes*60;
        $bucket=intdiv($seconds,$width);
        if($seconds<0&&$seconds%$width!==0)$bucket--;
        return (string)$bucket;
    }
    /** A monotone next sequence number for one append-only history. */
    public static function nextSequence(string $table,string $column,int $id):int{
        global $wpdb;$prefix=$wpdb->prefix.'dzn_';
        return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$prefix}{$table} WHERE {$column}=%d",$id));
    }
    /** A monotone next sequence number for a column not named `event_sequence`. */
    public static function nextColumnSequence(string $table,string $keyColumn,int $key,string $sequenceColumn):int{
        global $wpdb;$prefix=$wpdb->prefix.'dzn_';
        return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX({$sequenceColumn}),0) FROM {$prefix}{$table} WHERE {$keyColumn}=%d",$key));
    }
    public static function uid():string{return Identifier::uid();}
    public static function positiveInt($value,string $error):int{
        if(!is_numeric($value)||(int)$value<1)throw new \InvalidArgumentException($error);
        return (int)$value;
    }
    public static function reason(array $input,string $key):string{
        $reason=trim((string)($input[$key]??''));
        if($reason==='')throw new \InvalidArgumentException('notification_reason_required');
        return substr($reason,0,64);
    }
    /** Fire the phase's audit hook, if any listener is registered. Never a fulfilment or send. */
    public static function hook(string $hook,string $operation,int $id):void{
        if(function_exists('do_action'))do_action($hook,$operation,$id);
    }
    /** The outbox adapter S owns; the read path uses it to compare the mirror row-to-row. */
    public static function outbox():NotificationOutboxRepository{return new NotificationOutboxRepository();}
    /**
     * The S-owned outbox predicate: a row S may mutate. Phase-1 invitation rows and every other writer's
     * row are never claimed; `prepared` is owned by the legacy delivery seam and never selected.
     */
    public static function ownedOutboxSql(string $alias='o'):string{
        return $alias.".notification_id IS NOT NULL AND {$alias}.invitation_id IS NULL AND {$alias}.generation_id IS NULL AND {$alias}.workflow_key IS NOT NULL";
    }
    /**
     * §11 itemised encryption seam: S stores only authenticated ciphertext plus its version. A missing or
     * unusable cipher fails closed with `envelope_decrypt_failure` and the work stays claimable.
     */
    public static function encryptEnvelope(array $params):?array{
        if(!function_exists('sodium_crypto_secretbox'))return null;
        $key=self::envelopeKey();
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=sodium_crypto_secretbox(wp_json_encode(self::canonicalise($params)),$nonce,$key);
        return array('envelope'=>$nonce.$cipher,'cipher_version'=>'sodium_secretbox_v1');
    }
    /** Decrypt one stored envelope; null means the cipher/key is unavailable or the envelope is forged. */
    public static function decryptEnvelope(?string $envelope,?string $cipherVersion):?array{
        if($envelope===null||$envelope===''||$cipherVersion!=='sodium_secretbox_v1'||!function_exists('sodium_crypto_secretbox_open'))return null;
        $nonceLength=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if(strlen($envelope)<=$nonceLength)return null;
        $plain=sodium_crypto_secretbox_open(substr($envelope,$nonceLength),substr($envelope,0,$nonceLength),self::envelopeKey());
        if($plain===false)return null;
        $decoded=json_decode($plain,true);
        return is_array($decoded)?$decoded:null;
    }
    private static function envelopeKey():string{return sodium_crypto_generichash(self::salt(),'notification_envelope',SODIUM_CRYPTO_SECRETBOX_KEYBYTES);}
}
