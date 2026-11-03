<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Storage;

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Support;

/**
 * Private recorder storage layout.
 *
 * tmp and ready live on the same filesystem so finalisation is an atomic rename. Paths are always
 * derived from identifiers, never accepted from a command or browser. The directory is mode 0700
 * and files are mode 0600.
 */
final class StorageLayout {
    public function __construct(private Config $config) {}

    public function root(): string { return rtrim((string) $this->config->get('recording_root'), '/'); }
    public function tmpRoot(): string { return $this->root() . '/tmp'; }
    public function readyRoot(): string { return $this->root() . '/ready'; }

    public function ensure(): void {
        foreach ([$this->root(), $this->tmpRoot(), $this->readyRoot()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('recording_storage_directory_unavailable');
            chmod($dir, 0700);
        }
        $tmp = stat($this->tmpRoot());
        $ready = stat($this->readyRoot());
        if (!is_array($tmp) || !is_array($ready) || $tmp['dev'] !== $ready['dev']) throw new \RuntimeException('recording_storage_not_same_filesystem');
    }

    public function assertIdentifiers(string $recordingUid, int $contentVersion): void {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $recordingUid) !== 1 || $contentVersion < 1) throw new \RuntimeException('recording_identity_invalid');
    }

    public function tmpDirectory(string $recordingUid, int $contentVersion): string {
        $this->assertIdentifiers($recordingUid, $contentVersion);
        return $this->tmpRoot() . '/' . $recordingUid . '/' . $contentVersion;
    }

    public function readyDirectory(string $recordingUid, int $contentVersion): string {
        $this->assertIdentifiers($recordingUid, $contentVersion);
        return $this->readyRoot() . '/' . $recordingUid . '/' . $contentVersion;
    }

    public function tmpCapturePath(string $recordingUid, int $contentVersion): string {
        return $this->tmpDirectory($recordingUid, $contentVersion) . '/capture.mp4';
    }

    public function readyRelativePath(string $recordingUid, int $contentVersion, string $sha256): string {
        $this->assertIdentifiers($recordingUid, $contentVersion);
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) throw new \RuntimeException('recording_digest_invalid');
        return $recordingUid . '/' . $contentVersion . '/' . $sha256 . '.mp4';
    }

    public function readyPath(string $recordingUid, int $contentVersion, string $sha256): string {
        return $this->readyRoot() . '/' . $this->readyRelativePath($recordingUid, $contentVersion, $sha256);
    }

    public function ensureDirectory(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('recording_storage_directory_unavailable');
        chmod($path, 0700);
    }

    /** Reject new capture before writing when either absolute or percentage headroom is low. */
    public function canAccept(): array {
        $free = $this->freeBytes();
        $percent = $this->freePercent();
        $minBytes = (int) $this->config->get('min_free_bytes');
        $minPercent = (float) $this->config->get('min_free_percent');
        $ok = $free >= $minBytes && $percent >= $minPercent;
        return ['ok' => $ok, 'free_bytes' => $free, 'free_percent' => $percent, 'reason' => $ok ? 'ok' : ($free < $minBytes ? 'free_bytes_below_threshold' : 'free_percent_below_threshold')];
    }

    public function freeBytes(): int {
        $this->ensure();
        $free = @disk_free_space($this->root());
        return is_float($free) || is_int($free) ? (int) $free : 0;
    }

    public function freePercent(): float {
        $this->ensure();
        $free = @disk_free_space($this->root());
        $total = @disk_total_space($this->root());
        if (!is_numeric($free) || !is_numeric($total) || (float) $total <= 0) return 0.0;
        return round(((float) $free / (float) $total) * 100, 2);
    }

    /** Finalise the verified temporary file with an atomic rename and durable directory flush. */
    public function promote(string $tmpPath, string $finalPath, string $sha256): string {
        if (!is_file($tmpPath)) throw new \RuntimeException('recording_source_missing');
        if (hash_file('sha256', $tmpPath) !== $sha256) throw new \RuntimeException('recording_source_digest_changed');
        $dir = dirname($finalPath);
        $this->ensureDirectory($dir);
        if (file_exists($finalPath)) {
            if (hash_file('sha256', $finalPath) !== $sha256) throw new \RuntimeException('recording_ready_collision');
            @unlink($tmpPath);
            return $finalPath;
        }
        if (!@rename($tmpPath, $finalPath)) throw new \RuntimeException('recording_atomic_rename_failed');
        chmod($finalPath, 0600);
        $this->fsyncFile($finalPath);
        $this->fsyncDirectory($dir);
        return $finalPath;
    }

    public function fsyncFile(string $path): void {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return;
        @fsync($handle);
        fclose($handle);
    }

    public function fsyncDirectory(string $path): void {
        if (!function_exists('fsync')) return;
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) return;
        @fsync($handle);
        fclose($handle);
    }

    public function sizeAndDigest(string $path): array {
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('recording_source_missing');
        $bytes = filesize($path);
        $digest = hash_file('sha256', $path);
        if (!is_int($bytes) || $bytes < 1 || !is_string($digest)) throw new \RuntimeException('recording_source_unreadable');
        return ['bytes' => $bytes, 'sha256' => $digest];
    }

    public function describe(): array {
        $check = $this->canAccept();
        $tmp = realpath($this->tmpRoot());
        $ready = realpath($this->readyRoot());
        return [
            'root' => $this->root(), 'tmp' => $tmp ?: '', 'ready' => $ready ?: '',
            'free_bytes' => $check['free_bytes'], 'free_percent' => $check['free_percent'],
            'accepting' => $check['ok'], 'reason' => $check['reason'],
        ];
    }
}
