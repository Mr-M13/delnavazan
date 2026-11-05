<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Archive;

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Support;

/**
 * Filesystem archive adapter used for synthetic staging/integration proof.
 *
 * The production path uses RsyncArchiveAdapter over Tailscale SSH. Both implement the same
 * verification and idempotency rules: never overwrite different content, resume a partial file,
 * verify size and SHA-256, flush, rename atomically and flush the parent directory.
 */
final class LocalArchiveAdapter implements ArchivePort {
    private ?int $failAfterBytes;

    public function __construct(private Config $config, ?int $failAfterBytes = null) {
        $this->failAfterBytes = $failAfterBytes;
    }

    public function identity(): array {
        $root = $this->root();
        $marker = (string) $this->config->get('archive_mount_marker');
        if (!is_dir($root) || !is_file($marker)) return ['mounted' => false, 'identity' => '', 'free_bytes' => 0, 'reason' => 'archive_mount_absent'];
        $identity = trim((string) file_get_contents($marker));
        if ($identity === '') $identity = (string) $this->config->get('archive_node_identity');
        if ($identity === '') return ['mounted' => false, 'identity' => '', 'free_bytes' => 0, 'reason' => 'archive_identity_unverified'];
        $free = @disk_free_space($root);
        return ['mounted' => true, 'identity' => $identity, 'free_bytes' => is_numeric($free) ? (int) $free : 0, 'reason' => 'mounted'];
    }

    public function objectKey(string $lessonUid, string $recordingUid, int $contentVersion, string $sha256): string {
        foreach ([$lessonUid, $recordingUid] as $uid) if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $uid) !== 1) throw new \RuntimeException('archive_identity_invalid');
        if ($contentVersion < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) throw new \RuntimeException('archive_identity_invalid');
        return $lessonUid . '/' . $recordingUid . '/' . $contentVersion . '/' . $sha256 . '.mp4';
    }

    public function transfer(string $sourcePath, string $objectKey, string $expectedSha256, string $temporaryKey): array {
        $identity = $this->identity();
        if (!$identity['mounted']) throw new \RuntimeException('archive_mount_absent');
        if (!is_file($sourcePath) || is_link($sourcePath)) throw new \RuntimeException('archive_source_missing');
        $final = $this->path($objectKey);
        $temporary = $this->path($temporaryKey);
        $this->ensureDir(dirname($final));
        $this->ensureDir(dirname($temporary));
        if (is_file($final)) {
            $digest = hash_file('sha256', $final);
            if (!hash_equals($expectedSha256, (string) $digest)) throw new \RuntimeException('archive_destination_conflict');
            return ['idempotent' => true, 'bytes' => (int) filesize($final), 'sha256' => (string) $digest, 'receipt' => $this->receipt($final), 'temp_key' => $temporaryKey];
        }
        $sourceBytes = filesize($sourcePath);
        if (!is_int($sourceBytes)) throw new \RuntimeException('archive_source_unreadable');
        $written = is_file($temporary) ? (int) filesize($temporary) : 0;
        if ($written > $sourceBytes) { @unlink($temporary); $written = 0; }
        $in = fopen($sourcePath, 'rb');
        $out = fopen($temporary, $written > 0 ? 'ab' : 'wb');
        if (!is_resource($in) || !is_resource($out)) throw new \RuntimeException('archive_transfer_open_failed');
        try {
            if ($written > 0) fseek($in, $written);
            $transferred = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) throw new \RuntimeException('archive_transfer_read_failed');
                if ($chunk === '') break;
                if (fwrite($out, $chunk) === false) throw new \RuntimeException('archive_transfer_write_failed');
                $transferred += strlen($chunk);
                if ($this->failAfterBytes !== null && $written + $transferred >= $this->failAfterBytes) {
                    fflush($out); @fsync($out);
                    throw new \RuntimeException('archive_transfer_interrupted');
                }
            }
            fflush($out); @fsync($out);
        } finally {
            fclose($in); fclose($out);
        }
        $digest = hash_file('sha256', $temporary);
        $size = filesize($temporary);
        if (!hash_equals($expectedSha256, (string) $digest) || $size !== $sourceBytes) throw new \RuntimeException('archive_destination_checksum_mismatch');
        if (!@rename($temporary, $final)) throw new \RuntimeException('archive_final_rename_failed');
        chmod($final, 0600);
        $this->fsyncFile($final);
        $this->fsyncDir(dirname($final));
        return ['idempotent' => false, 'bytes' => (int) $size, 'sha256' => (string) $digest, 'receipt' => $this->receipt($final), 'temp_key' => $temporaryKey];
    }

    public function verify(string $objectKey, string $expectedSha256, int $expectedBytes): array {
        $path = $this->path($objectKey);
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('archive_destination_missing');
        $bytes = (int) filesize($path);
        $digest = (string) hash_file('sha256', $path);
        if ($bytes !== $expectedBytes || !hash_equals($expectedSha256, $digest)) throw new \RuntimeException('archive_destination_checksum_mismatch');
        return ['verified' => true, 'bytes' => $bytes, 'sha256' => $digest, 'receipt' => $this->receipt($path)];
    }

    public function delete(string $objectKey): array {
        $path = $this->path($objectKey);
        $root = realpath($this->root());
        $real = realpath($path);
        if (!$root || !$real || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || is_link($path)) throw new \RuntimeException('archive_deletion_path_invalid');
        $receipt = $this->receipt($path);
        if (!@unlink($path)) throw new \RuntimeException('archive_deletion_failed');
        $this->fsyncDir(dirname($path));
        return ['deleted' => true, 'receipt' => $receipt];
    }

    private function root(): string { return rtrim((string) $this->config->get('archive_root'), '/'); }

    /** Derive a path only from a validated object key; never accept an absolute caller path. */
    private function path(string $objectKey): string {
        if ($objectKey === '' || str_contains($objectKey, "\0") || str_starts_with($objectKey, '/') || preg_match('#(^|/)\.\.(/|$)#', $objectKey) === 1) throw new \RuntimeException('archive_object_key_invalid');
        return $this->root() . '/' . $objectKey;
    }

    private function ensureDir(string $dir): void {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('archive_directory_unavailable');
        chmod($dir, 0700);
    }

    private function receipt(string $path): string {
        $stat = stat($path);
        if (!is_array($stat)) throw new \RuntimeException('archive_receipt_unavailable');
        return 'dev=' . $stat['dev'] . ';ino=' . $stat['ino'] . ';size=' . $stat['size'] . ';mtime=' . $stat['mtime'];
    }

    private function fsyncFile(string $path): void {
        if (!function_exists('fsync')) return;
        $handle = @fopen($path, 'rb');
        if (is_resource($handle)) { @fsync($handle); fclose($handle); }
    }

    private function fsyncDir(string $dir): void {
        if (!function_exists('fsync')) return;
        $handle = @fopen($dir, 'r');
        if (is_resource($handle)) { @fsync($handle); fclose($handle); }
    }
}
