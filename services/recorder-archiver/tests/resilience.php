<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Delnavazan\Recorder\Archive\ArchivePort;
use Delnavazan\Recorder\Archive\LocalArchiveAdapter;
use Delnavazan\Recorder\Media\MediaVerifier;
use Delnavazan\Recorder\Provider\FakeEgress;
use Delnavazan\Recorder\Repository;
use Delnavazan\Recorder\Signer;
use Delnavazan\Recorder\Storage\StorageLayout;
use Delnavazan\Recorder\Support;
use Delnavazan\Recorder\SystemClock;
use Delnavazan\Recorder\Worker;

final class CorruptingArchive implements ArchivePort {
    public function __construct(private LocalArchiveAdapter $inner, private string $root) {}
    public function identity(): array { return $this->inner->identity(); }
    public function objectKey(string $lessonUid, string $recordingUid, int $contentVersion, string $sha256): string { return $this->inner->objectKey($lessonUid, $recordingUid, $contentVersion, $sha256); }
    public function transfer(string $sourcePath, string $objectKey, string $expectedSha256, string $temporaryKey): array {
        $result = $this->inner->transfer($sourcePath, $objectKey, $expectedSha256, $temporaryKey);
        file_put_contents($this->root . '/' . $objectKey, 'tampered-after-transfer', LOCK_EX);
        return $result;
    }
    public function verify(string $objectKey, string $expectedSha256, int $expectedBytes): array { return $this->inner->verify($objectKey, $expectedSha256, $expectedBytes); }
    public function delete(string $objectKey): array { return $this->inner->delete($objectKey); }
}

function dzn_worker_with_archive($config, $repo, $storage, $egress, ArchivePort $archive, $client): Worker {
    return new Worker($config, $repo, $storage, $egress, $archive, new MediaVerifier(), new SystemClock(), $client);
}
function dzn_unlock(string $table, $db, string $recordingUid): void {
    $db->execute('UPDATE ' . $table . ' SET available_at=?,state=CASE WHEN state=\'leased\' THEN \'pending\' ELSE state END,lease_token_digest=NULL,lease_expires_at=NULL WHERE recording_uid=?', [$db->now(), $recordingUid]);
}
function dzn_assert_true(bool $ok, string $message): void { dzn_assert($ok, $message); }

echo "Recorder/archiver resilience: start\n";

// 1. Duplicate command replay and conflicting digest.
$root = dzn_tmp('duplicate');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid(); $payload = dzn_start_payload($uid);
    dzn_command($client, 'start', $payload, hash('sha256', 'dup-key'), 'recording_requested');
    dzn_command($client, 'start', $payload, hash('sha256', 'dup-key'), 'recording_requested');
    $egress = new FakeEgress(3000);
    $worker = dzn_worker($config, $repo, $storage, $egress, new LocalArchiveAdapter($config), $client);
    dzn_assert_true($worker->pullCommands() === 2, 'both envelopes must be accepted (exact replay is idempotent)');
    dzn_assert_true((int) $db->scalar('SELECT COUNT(*) FROM service_inbox') === 1, 'duplicate command key must not duplicate inbox work');
    $worker->runOnce(false);
    dzn_assert_true($egress->startCalls === 1, 'duplicate command must start Egress once');
    $first = $worker->runOnce(false, true);
    $second = $worker->runOnce(false, true);
    dzn_assert_true((int) $db->scalar('SELECT COUNT(*) FROM recording_jobs') === 1, 'duplicate worker execution must not create a second job');
    dzn_assert_true($second['inbox_completed'] === 0, 'completed inbox work must not run again');

    // Conflicting digest for the same idempotency key is refused.
    $conflictRoot = dzn_tmp('conflict');
    try {
        [$c2, $d2, $r2, $s2, $cl2] = dzn_environment($conflictRoot);
        $uid2 = Support::uid();
        dzn_command($cl2, 'start', dzn_start_payload($uid2), hash('sha256', 'conflict-key'), 'recording_requested');
        dzn_command($cl2, 'start', dzn_start_payload($uid2, ['scheduled_duration_seconds' => 9]), hash('sha256', 'conflict-key'), 'recording_requested');
        $w2 = dzn_worker($c2, $r2, $s2, new FakeEgress(), new LocalArchiveAdapter($c2), $cl2);
        $refused = false;
        try { $w2->pullCommands(); } catch (Throwable $e) { $refused = $e->getMessage() === 'recording_command_payload_digest_conflict'; }
        dzn_assert_true($refused, 'same idempotency key with a different digest must be refused');
    } finally { dzn_cleanup($conflictRoot); }
    echo "Duplicate/conflict: PASS\n";
} finally { dzn_cleanup($root); }

// 2. Egress crash and restart recovery.
$root = dzn_tmp('egress-crash');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'crash-start'), 'recording_requested');
    $crash = new FakeEgress(); $crash->crashOnStart = true;
    $worker = dzn_worker($config, $repo, $storage, $crash, new LocalArchiveAdapter($config), $client);
    $first = $worker->runOnce(true);
    dzn_assert_true($first['inbox_failed'] === 1, 'Egress crash must leave retryable inbox work');
    dzn_assert_true($crash->startCalls >= 1, 'crashed Egress attempt must be recorded');
    $db->execute("UPDATE service_inbox SET available_at=?", [$db->now()]);
    $healthy = new FakeEgress(3000);
    $worker2 = dzn_worker($config, $repo, $storage, $healthy, new LocalArchiveAdapter($config), $client);
    $second = $worker2->runOnce(false);
    dzn_assert_true($second['inbox_completed'] === 1, 'restart must recover the pending command');
    dzn_assert_true(($repo->job($uid)['state'] ?? '') === 'archived_vps_deleted', 'restart must complete verification, archive and deletion');
    echo "Egress crash/restart: PASS\n";
} finally { dzn_cleanup($root); }

// 3. Pi offline keeps the complete VPS copy, then reconnect resumes.
$root = dzn_tmp('offline');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid(); $sha = null;
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'offline-start'), 'recording_requested');
    unlink($config->get('archive_mount_marker'));
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $first = $worker->runOnce(true);
    dzn_assert_true($first['transfer_failures'] >= 1, 'offline Pi must record a transfer failure');
    $asset = $repo->asset($uid, 1);
    dzn_assert_true($asset !== null && (string) $asset['state'] === 'verified', 'source must remain verified while archive is offline');
    $source = $storage->readyPath($uid, 1, (string) $asset['sha256']);
    dzn_assert_true(is_file($source), 'complete VPS copy must be retained');
    dzn_assert_true($repo->deletion($uid, 1) === null, 'VPS deletion must not be queued before archive verification');
    file_put_contents($config->get('archive_mount_marker'), 'mbox-test-node');
    dzn_unlock('transfer_jobs', $db, $uid);
    $worker2 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $second = $worker2->runOnce(false);
    dzn_assert_true($second['transfers'] === 1 && $second['deletions'] === 1, 'reconnect must transfer, confirm and delete');
    dzn_assert_true(!is_file($source), 'source is deleted only after successful reconnect');
    echo "Pi offline/reconnect: PASS\n";
} finally { dzn_cleanup($root); }

// 4. Mid-transfer disconnect and safe resume.
$root = dzn_tmp('resume');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'resume-start'), 'recording_requested');
    $failing = new LocalArchiveAdapter($config, 1024);
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), $failing, $client);
    $first = $worker->runOnce(true);
    dzn_assert_true($first['transfer_failures'] >= 1, 'interrupted transfer must be recorded');
    $asset = $repo->asset($uid, 1);
    dzn_assert_true($asset !== null && is_file($storage->readyPath($uid, 1, (string) $asset['sha256'])), 'source must survive an interrupted transfer');
    $transfer = $repo->transfer($uid, 1);
    $temp = $config->get('archive_root') . '/' . $transfer['destination_temp_key'];
    dzn_assert_true(is_file($temp) && filesize($temp) > 0, 'partial destination must be retained for resume');
    dzn_unlock('transfer_jobs', $db, $uid);
    $worker2 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $second = $worker2->runOnce(false);
    dzn_assert_true($second['transfers'] === 1 && $second['deletions'] === 1, 'safe resume must complete archive and deletion');
    dzn_assert_true((int) $db->scalar('SELECT COUNT(*) FROM transfer_attempts') >= 2, 'both transfer attempts must be recorded');
    echo "Mid-transfer resume: PASS\n";
} finally { dzn_cleanup($root); }

// 5. Checksum mismatch prevents archival confirmation and deletion.
$root = dzn_tmp('mismatch');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'mismatch-start'), 'recording_requested');
    $worker = dzn_worker_with_archive($config, $repo, $storage, new FakeEgress(3000), new CorruptingArchive(new LocalArchiveAdapter($config), $config->get('archive_root')), $client);
    $stats = $worker->runOnce(true);
    dzn_assert_true($stats['transfer_failures'] >= 1, 'corrupt destination must fail verification');
    dzn_assert_true($repo->confirmationFor($uid, 1) === null, 'no confirmation may be written for a corrupt archive');
    dzn_assert_true($repo->deletion($uid, 1) === null, 'corrupt archive must not queue VPS deletion');
    $asset = $repo->asset($uid, 1);
    dzn_assert_true($asset !== null && is_file($storage->readyPath($uid, 1, (string) $asset['sha256'])), 'VPS source must be retained on checksum mismatch');
    echo "Checksum mismatch blocks deletion: PASS\n";
} finally { dzn_cleanup($root); }

// 6. Legal hold blocks deletion until released; request path cannot influence it.
$root = dzn_tmp('hold');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'hold-start'), 'recording_requested');
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $worker->runOnce(true, true);
    $asset = $repo->asset($uid, 1);
    $source = $storage->readyPath($uid, 1, (string) $asset['sha256']);
    dzn_assert_true(is_file($source), 'source must exist before legal-hold test');
    $job = $repo->job($uid);
    $repo->jobUpdate($uid, (int) $job['version'], ['legal_hold_state' => 'active']);
    $malicious = $config->get('recording_root') . '/outside-target.mp4';
    file_put_contents($malicious, 'must-not-be-deleted');
    // A hostile path in a signed command is ignored; the worker derives the source path from IDs.
    $command = ['command_id' => 9, 'command_type' => 'source_deletion', 'recording_id' => 1, 'recording_uid' => $uid, 'content_version' => 1, 'expected_state' => null, 'idempotency_key' => hash('sha256', 'hold-delete'), 'payload_digest' => Support::payloadDigest(['recording_uid' => $uid, 'content_version' => 1, 'path' => '../../outside-target.mp4']), 'lease_token' => bin2hex(random_bytes(16)), 'payload' => ['recording_uid' => $uid, 'content_version' => 1, 'path' => '../../outside-target.mp4']];
    $client->commands = [Signer::sign($command, str_repeat('s', 64), 'test-recorder-v1', 'recording-service-command', time(), 300)];
    $worker->runOnce(true, false);
    dzn_assert_true(is_file($source), 'legal hold must block VPS deletion');
    dzn_assert_true(is_file($malicious), 'request-supplied path must never influence deletion');
    $job = $repo->job($uid);
    $repo->jobUpdate($uid, (int) $job['version'], ['legal_hold_state' => 'none']);
    dzn_unlock('deletion_jobs', $db, $uid);
    // WordPress independently refuses deletion while its own legal hold is active.
    $client->deletionAuthorised = false; $client->deletionReason = 'legal_hold_active';
    $worker2 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $worker2->runOnce(false, false);
    dzn_assert_true(is_file($source), 'WordPress legal-hold refusal must retain the source even after the local hold clears');
    $client->deletionAuthorised = true; $client->deletionReason = 'authorised';
    dzn_unlock('deletion_jobs', $db, $uid);
    $worker3 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $worker3->runOnce(false, false);
    dzn_assert_true(!is_file($source), 'after both holds release the derived source is deleted');
    dzn_assert_true(is_file($malicious), 'the unrelated path remains untouched');
    echo "Legal hold/path injection: PASS\n";
} finally { dzn_cleanup($root); }

// 6a. Withdrawal disposition can delete the archive after the VPS source is already gone.
$root = dzn_tmp('withdraw-archive');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'withdraw-archive-start'), 'recording_requested');
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $worker->runOnce(true, true);
    $asset = $repo->asset($uid, 1);
    $source = $storage->readyPath($uid, 1, (string) $asset['sha256']);
    $confirmation = $repo->confirmationFor($uid, 1);
    $archivePath = $config->get('archive_root') . '/' . $confirmation['destination_object_key'];
    dzn_assert_true(is_file($archivePath), 'archive object must exist before withdrawal');
    @unlink($source);
    $repo->deletionCreate($uid, 1, true);
    $worker2 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $stats = $worker2->runOnce(false, false);
    dzn_assert_true($stats['deletions'] === 1, 'archived-only withdrawal deletion must complete');
    dzn_assert_true(!is_file($archivePath), 'withdrawal must delete the archived object');
    dzn_assert_true(($repo->job($uid)['state'] ?? '') === 'archived_vps_deleted', 'withdrawal deletion must reach archived_vps_deleted');
    echo "Withdrawal archive deletion: PASS\n";
} finally { dzn_cleanup($root); }

// 6b. Pi capacity threshold refuses transfer without deleting the VPS source.
$root = dzn_tmp('mbox-full');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root, ['archive_min_free_bytes' => PHP_INT_MAX]);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'mbox-full-start'), 'recording_requested');
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $stats = $worker->runOnce(true);
    dzn_assert_true($stats['transfer_failures'] >= 1, 'Pi capacity threshold must refuse transfer');
    $asset = $repo->asset($uid, 1);
    dzn_assert_true($asset !== null && is_file($storage->readyPath($uid, 1, (string) $asset['sha256'])), 'VPS source must be retained when Pi is full');
    dzn_assert_true($repo->confirmationFor($uid, 1) === null && $repo->deletion($uid, 1) === null, 'full Pi must not produce a confirmation or deletion job');
    echo "Pi capacity threshold: PASS\n";
} finally { dzn_cleanup($root); }

// 6c. Host pressure admission refuses capture before Egress starts.
$root = dzn_tmp('host-pressure');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root, ['max_load_per_cpu' => 0.05]);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'host-pressure-start'), 'recording_requested');
    $egress = new FakeEgress();
    $worker = dzn_worker($config, $repo, $storage, $egress, new LocalArchiveAdapter($config), $client);
    $stats = $worker->runOnce(true);
    dzn_assert_true($stats['inbox_failed'] === 1 && $egress->startCalls === 0, 'host pressure must refuse capture before Egress');
    dzn_assert_true($repo->job($uid) === null, 'host-pressure refusal must not create a job');
    echo "Host pressure admission: PASS\n";
} finally { dzn_cleanup($root); }

// 7. Storage threshold refuses new capture.
$root = dzn_tmp('threshold');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root, ['min_free_bytes' => PHP_INT_MAX]);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'threshold-start'), 'recording_requested');
    $egress = new FakeEgress();
    $worker = dzn_worker($config, $repo, $storage, $egress, new LocalArchiveAdapter($config), $client);
    $stats = $worker->runOnce(true);
    dzn_assert_true($stats['inbox_failed'] === 1, 'storage threshold must refuse the job');
    dzn_assert_true($egress->startCalls === 0, 'refused job must never start Egress');
    dzn_assert_true($repo->job($uid) === null, 'refused job must not create a recording job');
    echo "Storage threshold: PASS\n";
} finally { dzn_cleanup($root); }

// 8. Duplicate provider events and outbox digest conflicts.
$root = dzn_tmp('provider-events');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    $first = $repo->providerEventStore($uid, 'EG_1', 'egress_completed', hash('sha256', 'event-key'), ['status' => 'complete']);
    $second = $repo->providerEventStore($uid, 'EG_1', 'egress_completed', hash('sha256', 'event-key'), ['status' => 'complete']);
    dzn_assert_true((int) $first['id'] === (int) $second['id'], 'exact provider replay must be idempotent');
    $refused = false;
    try { $repo->providerEventStore($uid, 'EG_1', 'egress_completed', hash('sha256', 'event-key'), ['status' => 'failed']); } catch (Throwable $e) { $refused = $e->getMessage() === 'provider_event_payload_digest_conflict'; }
    dzn_assert_true($refused, 'provider replay with different payload must be refused');
    echo "Provider event replay/conflict: PASS\n";
} finally { dzn_cleanup($root); }

// 8b. Expired lease takeover after a simulated worker crash.
$root = dzn_tmp('lease-recovery');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $uid = Support::uid();
    dzn_command($client, 'start', dzn_start_payload($uid), hash('sha256', 'lease-recovery-start'), 'recording_requested');
    $worker = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $worker->pullCommands();
    $claimed = $repo->inboxClaim(1);
    dzn_assert_true(count($claimed) === 1, 'first worker must lease the command');
    $db->execute("UPDATE service_inbox SET lease_expires_at='2000-01-01 00:00:00' WHERE id=?", [(int) $claimed[0]['id']]);
    $worker2 = dzn_worker($config, $repo, $storage, new FakeEgress(3000), new LocalArchiveAdapter($config), $client);
    $stats = $worker2->runOnce(false, true);
    dzn_assert_true((int) ($stats['leases_recovered']['service_inbox'] ?? 0) === 1, 'expired lease must be recovered');
    dzn_assert_true($stats['inbox_completed'] === 1 && $repo->job($uid) !== null, 'restarted worker must continue the leased command');
    echo "Expired lease takeover: PASS\n";
} finally { dzn_cleanup($root); }

// 9. Lease concurrency: parallel claimers must never claim the same row.
$root = dzn_tmp('concurrency');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    for ($i = 0; $i < 20; $i++) {
        $uid = Support::uid();
        $payload = ['command_id' => $i + 1, 'command_type' => 'reconcile', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'lease-' . $i), 'payload_digest' => Support::payloadDigest(['recording_uid' => $uid]), 'payload' => ['recording_uid' => $uid, 'content_version' => 1]];
        $envelope = Signer::sign($payload, str_repeat('s', 64), 'test-recorder-v1', 'recording-service-command', time(), 300);
        $repo->inboxStore($envelope, $payload);
    }
    $outputs = [];
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $out = $root . '/claim-' . $i . '.jsonl';
        $outputs[] = $out;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/claim-worker.php') . ' ' . escapeshellarg($config->get('database_dsn')) . ' ' . escapeshellarg($out) . ' 5';
        $processes[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    }
    foreach ($processes as $process) proc_close($process);
    $ids = [];
    foreach ($outputs as $out) foreach (file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $row = json_decode($line, true); if (is_array($row) && isset($row['id'])) $ids[] = (int) $row['id']; }
    dzn_assert_true(count($ids) === 20, 'four workers must claim exactly twenty leases');
    dzn_assert_true(count(array_unique($ids)) === 20, 'no inbox row may be claimed twice');

    // Concurrent transfer workers must also claim distinct jobs.
    for ($i = 0; $i < 20; $i++) {
        $uid = Support::uid();
        $repo->transferCreate($uid, 1, 'L/' . $uid . '/1/' . hash('sha256', 'k' . $i) . '.mp4', 'L/' . $uid . '/1/part');
    }
    $transferOutputs = []; $transferProcesses = [];
    for ($i = 0; $i < 4; $i++) {
        $out = $root . '/transfer-claim-' . $i . '.jsonl'; $transferOutputs[] = $out;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/claim-worker.php') . ' ' . escapeshellarg($config->get('database_dsn')) . ' ' . escapeshellarg($out) . ' 5 transfer_jobs';
        $transferProcesses[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    }
    foreach ($transferProcesses as $process) proc_close($process);
    $transferIds = [];
    foreach ($transferOutputs as $out) foreach (file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $row = json_decode($line, true); if (is_array($row) && isset($row['id'])) $transferIds[] = (int) $row['id']; }
    dzn_assert_true(count($transferIds) === 20 && count(array_unique($transferIds)) === 20, 'concurrent transfer workers must claim distinct jobs');
    echo "Concurrent lease claim: PASS\n";
} finally { dzn_cleanup($root); }

echo "Recorder/archiver resilience: PASS\n";
