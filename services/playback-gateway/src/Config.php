<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

final class Config {
    public function __construct(private array $values) {}
    public static function fromEnvironment(): self {
        $env = getenv();
        $secret = (string) ($env['DZN_PLAYBACK_SIGNING_SECRET'] ?? '');
        $kid = (string) ($env['DZN_PLAYBACK_KEY_ID'] ?? 'dzn-playback-v1');
        $keysJson = (string) ($env['DZN_PLAYBACK_KEYS'] ?? '');
        if ($keysJson === '' && $secret !== '') $keysJson = json_encode([$kid => $secret], JSON_THROW_ON_ERROR);
        return new self([
            'keys_json' => $keysJson,
            'issuer' => (string) ($env['DZN_PLAYBACK_ISSUER'] ?? ''),
            'archive_root' => (string) ($env['DZN_ARCHIVE_ROOT'] ?? '/mnt/mbox/DelnavazanRecordings'),
            'archive_mount_marker' => (string) ($env['DZN_ARCHIVE_MOUNT_MARKER'] ?? '/mnt/mbox/.delnavazan-mount'),
            'runtime_dir' => (string) ($env['DZN_PLAYBACK_RUNTIME_DIR'] ?? '/run/delnavazan-playback'),
            'access_log' => (string) ($env['DZN_PLAYBACK_ACCESS_LOG'] ?? '/var/log/delnavazan/playback-access.jsonl'),
            'bind' => (string) ($env['DZN_PLAYBACK_BIND'] ?? '127.0.0.1:8092'),
            'admin_token' => (string) ($env['DZN_PLAYBACK_ADMIN_TOKEN'] ?? ''),
            'global_concurrency' => (int) ($env['DZN_PLAYBACK_GLOBAL_CONCURRENCY'] ?? 8),
            'user_concurrency' => (int) ($env['DZN_PLAYBACK_USER_CONCURRENCY'] ?? 2),
            'bandwidth_bytes_per_second' => (int) ($env['DZN_PLAYBACK_BANDWIDTH_BPS'] ?? 0),
            'ticket_ttl_seconds' => (int) ($env['DZN_PLAYBACK_TICKET_TTL'] ?? 120),
            'chunk_bytes' => (int) ($env['DZN_PLAYBACK_CHUNK_BYTES'] ?? 262144),
        ]);
    }
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
}
