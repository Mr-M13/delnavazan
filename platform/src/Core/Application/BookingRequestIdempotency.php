<?php
namespace Delnavazan\Platform\Core\Application;

/** HMAC-only idempotency material; raw client keys and request facts never leave memory. */
final class BookingRequestIdempotency {
    public const WINDOW_SECONDS = 86400;
    public static function keyDigest(string $key): string { self::assertKey($key); return self::digest('key:' . $key); }
    public static function payloadDigest(array $normalized): string { return self::digest('payload:' . self::canonicalJson($normalized)); }
    public static function privateDigest(string $value): ?string { return $value === '' ? null : self::digest('contact:' . $value); }
    public static function tombstoneDigest(int $requestId, string $now): string { return self::digest('tombstone:' . $requestId . ':' . $now); }
    private static function assertKey(string $key): void { if ( strlen($key) < 24 || strlen($key) > 255 || ! preg_match('/^[A-Za-z0-9._~-]+$/D', $key) ) throw new \InvalidArgumentException('Invalid idempotency key'); }
    private static function digest(string $value): string { return hash_hmac('sha256', $value, wp_salt('dzn_booking_request_intake')); }
    /** Assoc-object keys are canonicalized; list order (notably requested_times) is intentionally retained. */
    private static function canonicalJson(mixed $value): string { return wp_json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private static function canonicalize(mixed $value): mixed { if ( ! is_array($value) ) return $value; if ( array_is_list($value) ) return array_map(array(__CLASS__, 'canonicalize'), $value); ksort($value, SORT_STRING); foreach($value as $key=>$item)$value[$key]=self::canonicalize($item); return $value; }
}
