<?php
namespace Delnavazan\Platform\Core\Application;

/** Acceptance-specific digests. Raw operator keys never enter persistence. */
final class AcceptanceIdempotency {
    public static function keyDigest(string $raw): string { $raw=trim($raw); if(strlen($raw)<24||strlen($raw)>255||!preg_match('/^[A-Za-z0-9._~-]+$/D',$raw)) throw new \InvalidArgumentException('Valid Acceptance idempotency key required'); return hash_hmac('sha256',$raw,wp_salt('dzn_provisional_acceptance')); }
    public static function payloadDigest(array $command): string { return hash('sha256',wp_json_encode(self::canonical($command),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
    private static function canonical(mixed $v): mixed { if(!is_array($v))return $v; if(array_is_list($v))return array_map(array(self::class,'canonical'),$v); ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::canonical($x);return $v; }
}
