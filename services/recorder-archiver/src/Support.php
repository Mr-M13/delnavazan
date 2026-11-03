<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

final class Support {
    public const UID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function uid(): string {
        $bytes = random_bytes(16);
        $value = '';
        // A Crockford-style 26-character identifier, matching the WordPress identity shape.
        $n = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $n = ($n << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $value .= self::UID_ALPHABET[($n >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $value .= self::UID_ALPHABET[($n << (5 - $bits)) & 31];
        }
        return substr(str_pad($value, 26, '0'), 0, 26);
    }

    public static function digest(string $value): string { return hash('sha256', $value); }

    public static function canonicalJson(array $value): string {
        self::sortKeys($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function payloadDigest(array $payload): string { return self::digest(self::canonicalJson($payload)); }

    public static function safeReason(string $reason): string {
        return preg_match('/^[a-z0-9_.:-]{3,96}$/D', $reason) === 1 ? $reason : 'recording_service_error';
    }

    public static function humanBytes(int $bytes): string {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GiB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MiB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KiB';
        return $bytes . ' B';
    }

    private static function sortKeys(array &$value): void {
        foreach ($value as &$item) if (is_array($item)) self::sortKeys($item);
        unset($item);
        if (array_is_list($value)) return;
        ksort($value, SORT_STRING);
    }
}
