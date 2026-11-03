<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Delnavazan\Recorder\Archive\LocalArchiveAdapter;
use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Database;
use Delnavazan\Recorder\Media\MediaVerifier;
use Delnavazan\Recorder\Media\Mp4Parser;
use Delnavazan\Recorder\Media\SyntheticMedia;
use Delnavazan\Recorder\Provider\FakeEgress;
use Delnavazan\Recorder\Repository;
use Delnavazan\Recorder\Signer;
use Delnavazan\Recorder\Storage\StorageLayout;
use Delnavazan\Recorder\Support;
use Delnavazan\Recorder\SystemClock;
use Delnavazan\Recorder\Worker;
use Delnavazan\Recorder\WordPressClient;

function dzn_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_cleanup(string $path): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($path);
}
function dzn_tmp(string $label): string {
    $base = rtrim((string) (getenv('DZN_SERVICE_TEST_DIR') ?: sys_get_temp_dir()), '/');
    $path = $base . '/dzn-recorder-' . $label . '-' . bin2hex(random_bytes(4));
    if (!mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('test directory failed');
    return $path;
}

final class TestWordPressClient extends WordPressClient {
    public array $commands = [];
    public array $callbacks = [];
    public array $health = [];
    public bool $online = true;
    public bool $deletionAuthorised = true;
    public string $deletionReason = 'authorised';
    public function configured(): bool { return $this->online; }
    public function pull(int $limit): array { return $this->commands; }
    public function callback(array $payload): void { if (!$this->online) throw new RuntimeException('wordpress_offline'); $this->callbacks[] = $payload; }
    public function health(array $payload): void { if (!$this->online) throw new RuntimeException('wordpress_offline'); $this->health[] = $payload; }
    public function deletionAuthorisation(string $recordingUid, int $contentVersion, string $destinationSha256): array { return ['authorised' => $this->deletionAuthorised, 'reason' => $this->deletionReason, 'recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'destination_sha256' => $destinationSha256, 'legal_hold_state' => $this->deletionAuthorised ? 'none' : 'active']; }
}

function dzn_config(string $root, array $overrides = []): Config {
    $values = array_merge([
        'service_id' => 'recorder-test', 'database_dsn' => 'sqlite:' . $root . '/recorder.sqlite', 'database_user' => '', 'database_password' => '',
        'recording_root' => $root . '/recordings', 'archive_root' => $root . '/mbox', 'archive_mount_marker' => $root . '/mbox/.dzn-mount',
        'archive_node_identity' => 'mbox-test-node', 'archive_transport' => 'local', 'archive_ssh_host' => '', 'archive_ssh_user' => 'delnavazan-archive',
        'archive_ssh_key' => '', 'archive_rsync_binary' => '/usr/bin/rsync', 'wordpress_base_url' => 'http://127.0.0.1:18080',
        'wordpress_service_secret' => str_repeat('s', 64), 'wordpress_service_key_id' => 'test-recorder-v1',
        'archive_signing_secret' => str_repeat('m', 64), 'livekit_api_url' => 'http://127.0.0.1:7880', 'livekit_key' => 'devkey', 'livekit_secret' => 'secret',
        'egress_template_url' => 'http://127.0.0.1:8091/egress/template', 'egress_layout' => 'dzn-two-person',
        'min_free_bytes' => 1048576, 'min_free_percent' => 0.001, 'lease_seconds' => 300, 'max_attempts' => 6,
        'http_bind' => '127.0.0.1:8091', 'tailscale_binary' => '/usr/bin/true', 'egress_cpu_percent' => 1.0, 'egress_memory_bytes' => 1048576,
    ], $overrides);
    return new Config($values);
}

function dzn_environment(string $root, array $overrides = []): array {
    $config = dzn_config($root, $overrides);
    if (!is_dir($root . '/mbox')) mkdir($root . '/mbox', 0700, true);
    file_put_contents($root . '/mbox/.dzn-mount', 'mbox-test-node');
    $db = new Database($config); $db->migrate();
    $repo = new Repository($db); $storage = new StorageLayout($config); $storage->ensure();
    $client = new TestWordPressClient($config);
    return [$config, $db, $repo, $storage, $client];
}

function dzn_command(TestWordPressClient $client, string $type, array $payload, string $key, ?string $expectedState = null): void {
    $command = [
        'command_id' => count($client->commands) + 1, 'command_type' => $type, 'recording_id' => 1,
        'recording_uid' => (string) $payload['recording_uid'], 'content_version' => (int) ($payload['content_version'] ?? 1),
        'expected_state' => $expectedState, 'idempotency_key' => $key,
        'payload_digest' => Support::payloadDigest($payload), 'payload' => $payload,
        'lease_token' => bin2hex(random_bytes(16)),
    ];
    $client->commands[] = Signer::sign($command, str_repeat('s', 64), 'test-recorder-v1', 'recording-service-command', time(), 300);
}

function dzn_start_payload(string $recordingUid, array $overrides = []): array {
    return array_merge([
        'recording_uid' => $recordingUid, 'content_version' => 1, 'lesson_id' => 101, 'lesson_uid' => Support::uid(),
        'schedule_version_id' => 202, 'schedule_version_uid' => Support::uid(), 'room_name' => 'dzn-rec-testroom',
        'teacher_id' => 11, 'teacher_uid' => Support::uid(), 'student_id' => 22, 'student_uid' => Support::uid(),
        'provider' => 'livekit', 'authorisation_evidence_id' => 5, 'authorisation_digest' => hash('sha256', 'evidence'),
        'scheduled_duration_seconds' => 3, 'capture_mode' => 'room_composite', 'layout' => 'dzn-two-person', 'watermark_required' => true,
    ], $overrides);
}

function dzn_worker(Config $config, Repository $repo, StorageLayout $storage, FakeEgress $egress, LocalArchiveAdapter $archive, TestWordPressClient $client): Worker {
    return new Worker($config, $repo, $storage, $egress, $archive, new MediaVerifier(), new SystemClock(), $client);
}
