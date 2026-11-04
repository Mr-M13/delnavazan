<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

final class KeyStore {
    private array $keys;
    public function __construct(string $json) {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) throw new \RuntimeException('playback_keys_unavailable');
        $this->keys = [];
        foreach ($decoded as $kid => $secret) {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $kid) === 1 && is_string($secret) && strlen($secret) >= 32) $this->keys[(string) $kid] = $secret;
        }
        if (!$this->keys) throw new \RuntimeException('playback_keys_unavailable');
    }
    public function secret(string $kid): string {
        if (!isset($this->keys[$kid])) throw new \RuntimeException('playback_key_unknown');
        return $this->keys[$kid];
    }
}
