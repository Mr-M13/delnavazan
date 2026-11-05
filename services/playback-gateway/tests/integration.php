<?php
declare(strict_types=1);

require __DIR__ . '/../../recorder-archiver/src/autoload.php';
require __DIR__ . '/../src/autoload.php';

use Delnavazan\Gateway\ConcurrencyLimiter;
use Delnavazan\Gateway\Config as GatewayConfig;
use Delnavazan\Recorder\Media\SyntheticMedia;
use Delnavazan\Recorder\Signer;
use Delnavazan\Recorder\Support;

function dzn_gw_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_gw_cleanup(string $path): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($path);
}
function dzn_gw_tmp(): string {
    $path = rtrim((string) (getenv('DZN_SERVICE_TEST_DIR') ?: sys_get_temp_dir()), '/') . '/dzn-gateway-' . bin2hex(random_bytes(4));
    if (!mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('gateway test dir failed');
    return $path;
}
function dzn_gw_curl(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
}
function dzn_gw_grant(string $secret, string $kid, array $payload, int $ttl = 300, string $audience = 'recording-playback-gateway'): array {
    return Signer::sign($payload, $secret, $kid, $audience, time(), $ttl);
}
function dzn_gw_wait(string $url, array $headers = []): bool {
    for ($i = 0; $i < 40; $i++) {
        $r = dzn_gw_curl('GET', $url, $headers);
        if ($r['status'] === 200) return true;
        usleep(100000);
    }
    return false;
}

echo "Playback gateway integration: start\n";
$root = dzn_gw_tmp();
$server = null;
try {
    $mbox = $root . '/mbox'; $lesson = Support::uid(); $recording = Support::uid(); $sha = hash('sha256', 'placeholder');
    mkdir($mbox, 0700, true);
    file_put_contents($mbox . '/.dzn-mount', 'mbox-test-node');
    $fixture = $root . '/fixture.mp4';
    $media = SyntheticMedia::writeMp4($fixture, 3000, 7);
    $sha = $media['sha256'];
    $objectKey = $lesson . '/' . $recording . '/1/' . $sha . '.mp4';
    $final = $mbox . '/' . $objectKey;
    mkdir(dirname($final), 0700, true);
    copy($fixture, $final); chmod($final, 0600);
    $secret = str_repeat('p', 64); $kid = 'test-playback-v1'; $adminToken = str_repeat('a', 48);
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($probe)) throw new RuntimeException('free port probe failed');
    $socketName = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr((string) $socketName, strrpos((string) $socketName, ':') + 1);
    $env = array_merge(getenv(), [
        'DZN_PLAYBACK_SIGNING_SECRET' => $secret, 'DZN_PLAYBACK_KEY_ID' => $kid, 'DZN_PLAYBACK_ISSUER' => 'https://delnavazan.test/',
        'DZN_ARCHIVE_ROOT' => $mbox, 'DZN_ARCHIVE_MOUNT_MARKER' => $mbox . '/.dzn-mount',
        'DZN_PLAYBACK_RUNTIME_DIR' => $root . '/run', 'DZN_PLAYBACK_ACCESS_LOG' => $root . '/access.jsonl',
        'DZN_PLAYBACK_GLOBAL_CONCURRENCY' => '1', 'DZN_PLAYBACK_USER_CONCURRENCY' => '1',
        'DZN_PLAYBACK_BANDWIDTH_BPS' => '0', 'DZN_PLAYBACK_CHUNK_BYTES' => '32768', 'DZN_PLAYBACK_ADMIN_TOKEN' => $adminToken,
    ]);
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg(__DIR__ . '/../bin/router.php');
    $server = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    $base = 'http://127.0.0.1:' . $port;
    dzn_gw_assert(dzn_gw_wait($base . '/health', ['Authorization: Bearer ' . $adminToken]), 'gateway must start');
    dzn_gw_assert(dzn_gw_curl('GET', $base . '/health')['status'] === 404, 'health must be hidden without the admin token');
    $health = dzn_gw_curl('GET', $base . '/health', ['Authorization: Bearer ' . $adminToken]);
    dzn_gw_assert($health['status'] === 200, 'health must accept the admin token');
    $metrics = dzn_gw_curl('GET', $base . '/metrics', ['Authorization: Bearer ' . $adminToken]);
    dzn_gw_assert($metrics['status'] === 200 && str_contains($metrics['body'], 'dzn_playback_service_up 1'), 'metrics must accept the admin token');
    $payload = [
        'iss' => 'https://delnavazan.test/', 'aud' => 'recording-playback-gateway', 'sub' => '42',
        'principal_type' => 'student', 'principal_id' => 22, 'recording_uid' => $recording,
        'content_version' => 1, 'lesson_id' => 101, 'scope' => 'stream', 'jti' => bin2hex(random_bytes(16)),
        'nbf' => time(), 'iat' => time(), 'exp' => time() + 300, 'archive_object_key' => $objectKey,
    ];
    $grant = dzn_gw_grant($secret, $kid, $payload);
    $session = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $grant]));
    dzn_gw_assert($session['status'] === 200, 'authorised grant must create a session');
    $sessionBody = json_decode($session['body'], true);
    dzn_gw_assert(is_array($sessionBody) && str_starts_with((string) $sessionBody['stream_url'], '/stream/'), 'session must return an opaque stream path');
    dzn_gw_assert(!str_contains((string) $sessionBody['stream_url'], $recording) && !str_contains((string) $sessionBody['stream_url'], 'grant'), 'stream URL must not contain recording or grant');
    $replayed = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $grant]));
    dzn_gw_assert($replayed['status'] === 403, 'replayed grant jti must be refused');
    $streamUrl = $base . $sessionBody['stream_url'];
    $full = dzn_gw_curl('GET', $streamUrl);
    dzn_gw_assert($full['status'] === 200 && strlen($full['body']) === filesize($final), 'full GET must stream the complete MP4');
    $range = dzn_gw_curl('GET', $streamUrl, ['Range: bytes=0-99']);
    dzn_gw_assert($range['status'] === 206 && strlen($range['body']) === 100, 'Range request must return 206 with exactly 100 bytes');
    dzn_gw_assert(str_contains($range['headers'], 'Content-Range: bytes 0-99/'), 'Range response must declare Content-Range');
    $head = dzn_gw_curl('HEAD', $streamUrl);
    dzn_gw_assert($head['status'] === 200 && $head['body'] === '' && stripos($head['headers'], 'Content-Length: ' . filesize($final)) !== false, 'HEAD must return metadata only');
    $midStart = 500; $midEnd = 1499;
    $middle = dzn_gw_curl('GET', $streamUrl, ['Range: bytes=' . $midStart . '-' . $midEnd]);
    dzn_gw_assert($middle['status'] === 206 && strlen($middle['body']) === 1000, 'seeking must return the requested middle range');
    dzn_gw_assert(substr($middle['body'], 0, 32) === substr(file_get_contents($final), $midStart, 32), 'range content must match the archive bytes');
    $suffix = dzn_gw_curl('GET', $streamUrl, ['Range: bytes=-64']);
    dzn_gw_assert($suffix['status'] === 206 && strlen($suffix['body']) === 64, 'suffix range must be supported');
    $invalid = dzn_gw_curl('GET', $streamUrl, ['Range: bytes=999999999-']);
    dzn_gw_assert($invalid['status'] === 416, 'unsatisfiable range must return 416');

    // Unauthorised grants.
    $tampered = $grant; $tampered['payload']['principal_id'] = 99;
    $r = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $tampered]));
    dzn_gw_assert($r['status'] === 403, 'tampered grant must be refused');
    $wrongAudience = dzn_gw_grant($secret, $kid, array_merge($payload, ['aud' => 'other']), 300, 'other');
    $r = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $wrongAudience]));
    dzn_gw_assert($r['status'] === 403, 'wrong audience must be refused');
    $expired = dzn_gw_grant($secret, $kid, array_merge($payload, ['jti' => bin2hex(random_bytes(16)), 'exp' => time() - 10]), 60);
    // Signature validity is short; expire the envelope metadata as well.
    $expired['meta']['issued_at'] = time() - 200; $expired['meta']['expires_at'] = time() - 100;
    $r = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $expired]));
    dzn_gw_assert($r['status'] === 403, 'expired grant must be refused');

    // M-Box offline response.
    unlink($mbox . '/.dzn-mount');
    $r = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => dzn_gw_grant($secret, $kid, array_merge($payload, ['jti' => bin2hex(random_bytes(16))]))]));
    dzn_gw_assert($r['status'] === 503, 'offline M-Box must return an accurate temporary-unavailability response');
    file_put_contents($mbox . '/.dzn-mount', 'mbox-test-node');

    // Concurrency limit via the same state file used by the gateway.
    $limiter = new ConcurrencyLimiter(new GatewayConfig(['runtime_dir' => $root . '/run', 'global_concurrency' => 1, 'user_concurrency' => 1]));
    $limiter->acquire('student:22');
    $limitedGrant = dzn_gw_grant($secret, $kid, array_merge($payload, ['jti' => bin2hex(random_bytes(16))]));
    $limitedSession = dzn_gw_curl('POST', $base . '/session', ['Content-Type: application/json'], json_encode(['grant' => $limitedGrant]));
    dzn_gw_assert($limitedSession['status'] === 200, 'session may be created before a stream is opened');
    $r = dzn_gw_curl('GET', $base . (json_decode($limitedSession['body'], true)['stream_url'] ?? '/missing'));
    dzn_gw_assert($r['status'] === 429, 'concurrency limit must refuse a new stream');
    $limiter->release('student:22');

    $log = (string) file_get_contents($root . '/access.jsonl');
    foreach (['allow', 'start', 'complete', 'refuse'] as $event) dzn_gw_assert(str_contains($log, '"event":"' . $event . '"'), 'access log must contain ' . $event);
    dzn_gw_assert(!str_contains($log, $secret) && !str_contains($log, $grant['signature']), 'access log must never contain a raw secret or grant signature');
    $page = dzn_gw_curl('GET', $base . '/');
    dzn_gw_assert($page['status'] === 200 && !str_contains($page['body'], $objectKey), 'gateway page must not disclose the archive object key');
    echo "Playback gateway integration: PASS\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    dzn_gw_cleanup($root);
}
