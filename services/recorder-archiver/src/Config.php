<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/**
 * Service configuration with fail-closed required values. Secrets are read from the process
 * environment or a root-only file; none is persisted in the database or exposed by HTTP.
 */
final class Config {
    public function __construct(private array $values) {}

    public static function fromEnvironment(): self {
        $env = getenv();
        $root = (string) ($env['DZN_RECORDING_ROOT'] ?? '/srv/delnavazan-recordings');
        return new self([
            'service_id' => (string) ($env['DZN_RECORDER_SERVICE_ID'] ?? 'ovh-recorder-1'),
            'database_dsn' => (string) ($env['DZN_RECORDER_DATABASE_DSN'] ?? 'sqlite:' . $root . '/recorder.sqlite'),
            'database_user' => (string) ($env['DZN_RECORDER_DATABASE_USER'] ?? ''),
            'database_password' => (string) ($env['DZN_RECORDER_DATABASE_PASSWORD'] ?? ''),
            'recording_root' => $root,
            'archive_root' => (string) ($env['DZN_ARCHIVE_ROOT'] ?? '/mnt/mbox/DelnavazanRecordings'),
            'archive_mount_marker' => (string) ($env['DZN_ARCHIVE_MOUNT_MARKER'] ?? '/mnt/mbox/.delnavazan-mount'),
            'archive_node_identity' => (string) ($env['DZN_ARCHIVE_NODE_IDENTITY'] ?? ''),
            'archive_transport' => (string) ($env['DZN_ARCHIVE_TRANSPORT'] ?? 'rsync'),
            'archive_ssh_host' => (string) ($env['DZN_ARCHIVE_SSH_HOST'] ?? ''),
            'archive_ssh_user' => (string) ($env['DZN_ARCHIVE_SSH_USER'] ?? 'delnavazan-archive'),
            'archive_ssh_key' => (string) ($env['DZN_ARCHIVE_SSH_KEY'] ?? ''),
            'archive_rsync_binary' => (string) ($env['DZN_ARCHIVE_RSYNC_BINARY'] ?? '/usr/bin/rsync'),
            'wordpress_base_url' => rtrim((string) ($env['DZN_WORDPRESS_BASE_URL'] ?? ''), '/'),
            'wordpress_service_secret' => (string) ($env['DZN_RECORDING_SERVICE_SECRET'] ?? ''),
            'wordpress_service_key_id' => (string) ($env['DZN_RECORDING_SERVICE_KEY_ID'] ?? 'dzn-recorder-v1'),
            'archive_signing_secret' => (string) ($env['DZN_MBOX_CONFIRMATION_SECRET'] ?? ''),
            'livekit_api_url' => rtrim((string) ($env['DZN_LIVEKIT_API_URL'] ?? ''), '/'),
            'livekit_key' => (string) ($env['DZN_LIVEKIT_API_KEY'] ?? ''),
            'livekit_secret' => (string) ($env['DZN_LIVEKIT_API_SECRET'] ?? ''),
            'egress_template_url' => (string) ($env['DZN_EGRESS_TEMPLATE_URL'] ?? ''),
            'egress_layout' => (string) ($env['DZN_EGRESS_LAYOUT'] ?? 'dzn-two-person'),
            'min_free_bytes' => (int) ($env['DZN_RECORDER_MIN_FREE_BYTES'] ?? 5368709120),
            'min_free_percent' => (float) ($env['DZN_RECORDER_MIN_FREE_PERCENT'] ?? 10.0),
            'archive_min_free_bytes' => (int) ($env['DZN_ARCHIVE_MIN_FREE_BYTES'] ?? 1073741824),
            'lease_seconds' => (int) ($env['DZN_RECORDER_LEASE_SECONDS'] ?? 300),
            'max_attempts' => (int) ($env['DZN_RECORDER_MAX_ATTEMPTS'] ?? 8),
            'http_bind' => (string) ($env['DZN_RECORDER_HTTP_BIND'] ?? '127.0.0.1:8091'),
            'tailscale_binary' => (string) ($env['DZN_TAILSCALE_BINARY'] ?? '/usr/bin/tailscale'),
            'egress_cpu_percent' => (float) ($env['DZN_EGRESS_CPU_PERCENT'] ?? 0.0),
            'egress_memory_bytes' => (int) ($env['DZN_EGRESS_MEMORY_BYTES'] ?? 0),
            'transient_prefix' => (string) ($env['DZN_RECORDER_TRANSIENT_PREFIX'] ?? ''),
        ]);
    }

    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }

    public function requireSecret(string $key): string {
        $value = (string) ($this->values[$key] ?? '');
        if (strlen($value) < 32) throw new \RuntimeException($key . '_unavailable');
        return $value;
    }

    public function requireValue(string $key): string {
        $value = (string) ($this->values[$key] ?? '');
        if ($value === '') throw new \RuntimeException($key . '_unavailable');
        return $value;
    }

    public function with(array $values): self { return new self(array_merge($this->values, $values)); }
}
