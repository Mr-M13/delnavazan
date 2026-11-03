<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** Append-only playback access evidence. Never stores a raw grant or token. */
final class AccessLog {
    public function __construct(private Config $config) {}
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
