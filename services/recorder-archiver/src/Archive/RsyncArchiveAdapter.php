<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Archive;

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Support;

/**
 * Restricted rsync-over-SSH archive adapter for the Pi.
 *
 * The archive account's forced command is `pi-archive-shell.py`. It allows only rsync writes
 * under the dedicated archive root and a small JSON operation set for identity, verification,
 * atomic commit and deletion. A remote shell is never available. A different existing object is
 * quarantined and never overwritten.
 */
final class RsyncArchiveAdapter implements ArchivePort {
    public function __construct(private Config $config) {}

    public function identity(): array {
        try {
            $result = $this->helper(['op' => 'identity']);
            return ['mounted' => ($result['mounted'] ?? false) === true, 'identity' => (string) ($result['identity'] ?? ''), 'free_bytes' => (int) ($result['free_bytes'] ?? 0), 'reason' => ($result['mounted'] ?? false) === true ? 'mounted' : 'archive_mount_absent'];
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
        $sourceBytes = @filesize($sourcePath);
        if (!is_int($sourceBytes) || $sourceBytes < 1) throw new \RuntimeException('archive_source_unreadable');
        $probe = $this->helper(['op' => 'probe', 'path' => $objectKey]);
        if (($probe['exists'] ?? false) === true) {
            if (!hash_equals($expectedSha256, (string) $probe['sha256']) || (int) $probe['bytes'] !== $sourceBytes) throw new \RuntimeException('archive_destination_conflict');
            return ['idempotent' => true, 'bytes' => (int) $probe['bytes'], 'sha256' => (string) $probe['sha256'], 'receipt' => 'existing', 'temp_key' => $temporaryKey];
        }
        $this->helper(['op' => 'mkdir', 'path' => dirname($temporaryKey)]);
        $this->helper(['op' => 'mkdir', 'path' => dirname($objectKey)]);
        $rsync = (string) $this->config->get('archive_rsync_binary');
        $command = escapeshellarg($rsync) . ' --archive --partial --append-verify --inplace --timeout=180 --chmod=Dg+rx,Fg+r --rsh ' . escapeshellarg($this->sshCommand()) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($this->sshTarget() . ':' . $temporaryKey);
        $this->run($command, 'archive_transfer_failed');
        $commit = $this->helper(['op' => 'commit', 'temporary' => $temporaryKey, 'final' => $objectKey, 'sha256' => $expectedSha256, 'bytes' => $sourceBytes]);
        return ['idempotent' => false, 'bytes' => (int) $commit['bytes'], 'sha256' => (string) $commit['sha256'], 'receipt' => (string) $commit['receipt'], 'temp_key' => $temporaryKey];
    }

    public function verify(string $objectKey, string $expectedSha256, int $expectedBytes): array {
        $result = $this->helper(['op' => 'verify', 'path' => $objectKey, 'sha256' => $expectedSha256, 'bytes' => $expectedBytes]);
        return ['verified' => true, 'bytes' => (int) $result['bytes'], 'sha256' => (string) $result['sha256'], 'receipt' => (string) $result['receipt']];
    }

    public function delete(string $objectKey): array {
        $result = $this->helper(['op' => 'delete', 'path' => $objectKey]);
        return ['deleted' => true, 'receipt' => (string) ($result['receipt'] ?? '')];
    }

    private function helper(array $payload): array {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $command = $this->sshCommand() . ' ' . escapeshellarg($this->sshTarget()) . ' ' . escapeshellarg('dzn-archive-op ' . $encoded);
        $output = trim($this->run($command, 'archive_operation_failed'));
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) throw new \RuntimeException('archive_operation_invalid_response');
        return $decoded;
    }

    private function sshTarget(): string {
        $host = (string) $this->config->get('archive_ssh_host');
        $user = (string) $this->config->get('archive_ssh_user');
        if ($host === '' || $user === '') throw new \RuntimeException('archive_transport_unconfigured');
        return $user . '@' . $host;
    }

    private function sshCommand(): string {
        $known = (string) $this->config->get('archive_ssh_known_hosts');
        return 'ssh -p ' . (int) $this->config->get('archive_ssh_port', 22) . ' -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o ConnectTimeout=15' . ($known !== '' ? ' -o UserKnownHostsFile=' . escapeshellarg($known) : '') . ' -i ' . escapeshellarg((string) $this->config->get('archive_ssh_key'));
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
