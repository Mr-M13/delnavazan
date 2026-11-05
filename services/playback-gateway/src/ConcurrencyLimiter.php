<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** Per-user and global concurrency limits backed by a lock-protected state file. */
final class ConcurrencyLimiter {
    private string $stateFile;
    private string $lockFile;
    public function __construct(private Config $config) {
        $dir = rtrim((string) $config->get('runtime_dir'), '/');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('playback_runtime_dir_unavailable');
        $this->stateFile = $dir . '/concurrency.json';
        $this->lockFile = $dir . '/concurrency.lock';
    }
    /** @return array{ok:bool,reason:string} */
    public function acquire(string $principal): array {
        return $this->withLock(function (array $state) use ($principal): array {
            $global = (int) ($state['global'] ?? 0);
            $users = (array) ($state['users'] ?? []);
            $mine = (int) ($users[$principal] ?? 0);
            if ($global >= (int) $this->config->get('global_concurrency')) return [['ok' => false, 'reason' => 'global_concurrency_limit'], $state];
            if ($mine >= (int) $this->config->get('user_concurrency')) return [['ok' => false, 'reason' => 'user_concurrency_limit'], $state];
            $state['global'] = $global + 1; $users[$principal] = $mine + 1; $state['users'] = $users;
            return [['ok' => true, 'reason' => 'ok'], $state];
        });
    }
    public function release(string $principal): void {
        $this->withLock(function (array $state) use ($principal): array {
            $state['global'] = max(0, (int) ($state['global'] ?? 0) - 1);
            $users = (array) ($state['users'] ?? []);
            $users[$principal] = max(0, (int) ($users[$principal] ?? 0) - 1);
            if ($users[$principal] === 0) unset($users[$principal]);
            $state['users'] = $users;
            return [[], $state];
        });
    }
    private function withLock(callable $work): mixed {
        $handle = fopen($this->lockFile, 'c+');
        if (!is_resource($handle)) throw new \RuntimeException('playback_limiter_unavailable');
        chmod($this->lockFile, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('playback_limiter_unavailable');
            $raw = is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : '';
            $state = json_decode($raw, true);
            if (!is_array($state)) $state = ['global' => 0, 'users' => []];
            [$result, $state] = $work($state);
            file_put_contents($this->stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($this->stateFile, 0600);
            flock($handle, LOCK_UN);
            return $result;
        } finally { fclose($handle); }
    }
}
