<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** Append-only playback access evidence. Never stores a raw grant or token. */
final class AccessLog {
    public function __construct(private Config $config) {}
    /** Coarse counters for monitoring. No principal, grant or token data is returned. */
    public function summary(int $windowSeconds = 3600): array {
        $file = (string) $this->config->get('access_log');
        $out = ['allow' => 0, 'refuse' => 0, 'unavailable' => 0];
        if (!is_file($file)) return $out;
        $cutoff = time() - max(60, $windowSeconds);
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) return $out;
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || (int) strtotime((string) ($entry['at'] ?? '')) < $cutoff) continue;
            $event = (string) ($entry['event'] ?? '');
            if ($event === 'allow') $out['allow']++;
            elseif ($event === 'refuse') {
                $out['refuse']++;
                if (($entry['reason'] ?? '') === 'archive_temporarily_unavailable') $out['unavailable']++;
            }
        }
        fclose($handle);
        return $out;
    }

    public function write(string $event, array $context = []): void {
        $file = (string) $this->config->get('access_log');
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return;
        $entry = array_merge(['at' => gmdate('c'), 'event' => $event], $context);
        unset($entry['grant'], $entry['token'], $entry['secret']);
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
    }
}
