<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Archive;

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Support;

/**
 * Restricted rsync-over-SSH archive adapter for the Tailscale-only M-Box path.
 *
 * It never trusts an rsync exit code as proof. The remote side reports exact size and SHA-256, the
 * file is flushed, renamed atomically and the parent directory is flushed before a receipt is
 * returned. A different existing object is quarantined, never overwritten.
 */
final class RsyncArchiveAdapter implements ArchivePort {
    public function __construct(private Config $config) {}

    public function identity(): array {
        try {
            $root = $this->remoteRoot();
            $marker = $this->config->get('archive_mount_marker');
            $out = $this->sshRun('test -f ' . escapeshellarg($marker) . ' && test -d ' . escapeshellarg($root) . ' && cat ' . escapeshellarg($marker) . ' && printf "\\n" && df -P -B1 ' . escapeshellarg($root) . ' | awk \'NR==2 {print $4}\'');
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($out))), static fn($v) => $v !== ''));
            if (count($lines) < 2) return ['mounted' => false, 'identity' => '', 'free_bytes' => 0, 'reason' => 'archive_mount_absent'];
            return ['mounted' => true, 'identity' => $lines[0], 'free_bytes' => (int) $lines[1], 'reason' => 'mounted'];
        } catch (\Throwable $error) {
            return ['mounted' => false, 'identity' => '', 'free_bytes' => 0, 'reason' => Support::safeReason($error->getMessage())];
        }
    }

    public function objectKey(string $lessonUid, string $recordingUid, int $contentVersion, string $sha256): string {
        foreach ([$lessonUid, $recordingUid] as $uid) if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $uid) !== 1) throw new \RuntimeException('archive_identity_invalid');
        if ($contentVersion < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) throw new \RuntimeException('archive_identity_invalid');
        return $lessonUid . '/' . $recordingUid . '/' . $contentVersion . '/' . $sha256 . '.mp4';
    }

    public function transfer(string $sourcePath, string $objectKey, string $expectedSha256, string $temporaryKey): array {
        $identity = $this->identity();
        if (!$identity['mounted']) throw new \RuntimeException('archive_mount_absent');
        $root = $this->remoteRoot();
        $final = $root . '/' . $objectKey;
        $temporary = $root . '/' . $temporaryKey;
        $probe = $this->sshRun('mkdir -p ' . escapeshellarg(dirname($final)) . ' ' . escapeshellarg(dirname($temporary)) . ' && if [ -f ' . escapeshellarg($final) . ' ]; then sha256sum ' . escapeshellarg($final) . ' | cut -d" " -f1; else echo absent; fi');
        $probe = trim($probe);
        if ($probe !== 'absent') {
            if (!hash_equals($expectedSha256, $probe)) throw new \RuntimeException('archive_destination_conflict');
            return ['idempotent' => true, 'bytes' => (int) filesize($sourcePath), 'sha256' => $probe, 'receipt' => $this->remoteReceipt($final), 'temp_key' => $temporaryKey];
        }
        $rsync = (string) $this->config->get('archive_rsync_binary');
        $ssh = $this->sshCommand();
        $command = escapeshellarg($rsync) . ' --archive --partial --append-verify --inplace --timeout=120 --rsh ' . escapeshellarg($ssh) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($this->sshTarget() . ':' . $temporary);
        $this->run($command, 'archive_transfer_failed');
        $remote = $this->sshRun('set -e; f=' . escapeshellarg($temporary) . '; d=' . escapeshellarg($final) . '; s=$(sha256sum "$f" | cut -d" " -f1); n=$(stat -c %s "$f"); printf "%s %s\\n" "$s" "$n"; test "$s" = ' . escapeshellarg($expectedSha256) . '; test "$n" = ' . escapeshellarg((string) filesize($sourcePath)) . '; mv -f "$f" "$d"; chmod 600 "$d"; sync "$d"; sync "$(dirname "$d")"');
        [$remoteSha, $remoteBytes] = array_pad(preg_split('/\s+/', trim($remote)) ?: [], 2, '0');
        if (!hash_equals($expectedSha256, (string) $remoteSha) || (int) $remoteBytes !== (int) filesize($sourcePath)) throw new \RuntimeException('archive_destination_checksum_mismatch');
        return ['idempotent' => false, 'bytes' => (int) $remoteBytes, 'sha256' => (string) $remoteSha, 'receipt' => $this->remoteReceipt($final), 'temp_key' => $temporaryKey];
    }

    public function verify(string $objectKey, string $expectedSha256, int $expectedBytes): array {
        $path = $this->remoteRoot() . '/' . $objectKey;
        $out = trim($this->sshRun('f=' . escapeshellarg($path) . '; test -f "$f"; printf "%s %s\\n" "$(sha256sum "$f" | cut -d" " -f1)" "$(stat -c %s "$f")"'));
        [$sha, $bytes] = array_pad(preg_split('/\s+/', $out) ?: [], 2, '0');
        if (!hash_equals($expectedSha256, (string) $sha) || (int) $bytes !== $expectedBytes) throw new \RuntimeException('archive_destination_checksum_mismatch');
        return ['verified' => true, 'bytes' => (int) $bytes, 'sha256' => (string) $sha, 'receipt' => $this->remoteReceipt($path)];
    }

    public function delete(string $objectKey): array {
        if (preg_match('#(^|/)\.\.(/|$)#', $objectKey) === 1 || str_starts_with($objectKey, '/')) throw new \RuntimeException('archive_object_key_invalid');
        $path = $this->remoteRoot() . '/' . $objectKey;
        $receipt = $this->remoteReceipt($path);
        $this->sshRun('f=' . escapeshellarg($path) . '; test -f "$f"; rm -f -- "$f"; sync "$(dirname "$f")"');
        return ['deleted' => true, 'receipt' => $receipt];
    }

    private function remoteRoot(): string {
        $root = rtrim((string) $this->config->get('archive_root'), '/');
        if (!str_starts_with($root, '/')) throw new \RuntimeException('archive_root_invalid');
        return $root;
    }

    private function sshTarget(): string {
        $host = (string) $this->config->get('archive_ssh_host');
        $user = (string) $this->config->get('archive_ssh_user');
        if ($host === '' || $user === '') throw new \RuntimeException('archive_transport_unconfigured');
        return $user . '@' . $host;
    }

    private function sshCommand(): string {
        return 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o ConnectTimeout=15 -i ' . escapeshellarg((string) $this->config->get('archive_ssh_key'));
    }

    private function sshRun(string $script): string {
        $command = $this->sshCommand() . ' ' . escapeshellarg($this->sshTarget()) . ' ' . escapeshellarg('sh -c ' . escapeshellarg($script));
        return $this->run($command, 'archive_transport_failed');
    }

    private function remoteReceipt(string $path): string {
        $out = trim($this->sshRun('f=' . escapeshellarg($path) . '; stat -c "dev=%d;ino=%i;size=%s;mtime=%Y" "$f"'));
        return $out !== '' ? $out : 'unavailable';
    }

    private function run(string $command, string $error): string {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) throw new \RuntimeException($error);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new \RuntimeException($error . ':' . Support::safeReason(trim((string) $stderr)));
        return (string) $stdout;
    }
}
