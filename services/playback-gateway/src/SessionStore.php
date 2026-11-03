<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** Short-lived, one-grant stream ticket. The raw playback grant never appears in a URL. */
final class SessionStore {
    private string $dir;
    public function __construct(private Config $config) {
        $this->dir = rtrim((string) $config->get('runtime_dir'), '/') . '/sessions';
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) throw new \RuntimeException('playback_session_store_unavailable');
        chmod($this->dir, 0700);
    }
    public function create(array $payload): string {
        $id = bin2hex(random_bytes(24));
        $record = ['payload' => $payload, 'expires_at' => time() + (int) $this->config->get('ticket_ttl_seconds')];
        $path = $this->dir . '/' . $id . '.json';
        if (@file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('playback_session_store_unavailable');
        chmod($path, 0600);
        return $id;
    }
    /** Reject replay of the same signed grant jti. */
    public function recordJti(string $jti): void {
        $dir = $this->dir . '/jti';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('playback_session_store_unavailable');
        $path = $dir . '/' . hash('sha256', $jti) . '.json';
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) throw new \RuntimeException('playback_grant_replayed');
        fwrite($handle, json_encode(['created_at' => time()], JSON_THROW_ON_ERROR));
        fclose($handle);
        chmod($path, 0600);
    }

    public function get(string $id): array {
        if (preg_match('/^[a-f0-9]{48}$/D', $id) !== 1) throw new \RuntimeException('playback_ticket_invalid');
        $path = $this->dir . '/' . $id . '.json';
        if (!is_file($path)) throw new \RuntimeException('playback_ticket_invalid');
        $record = json_decode((string) @file_get_contents($path), true);
        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= time() || !is_array($record['payload'] ?? null)) {
            @unlink($path);
            throw new \RuntimeException('playback_ticket_expired');
        }
        return $record['payload'];
    }
    public function activeCount(): int {
        $count = 0;
        foreach (@glob($this->dir . '/*.json') ?: [] as $path) {
            $record = json_decode((string) @file_get_contents($path), true);
            if (is_array($record) && (int) ($record['expires_at'] ?? 0) > time()) $count++;
        }
        return $count;
    }

    public function sweep(): void {
        foreach (@glob($this->dir . '/*.json') ?: [] as $path) {
            $record = json_decode((string) @file_get_contents($path), true);
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= time()) @unlink($path);
        }
    }
}
