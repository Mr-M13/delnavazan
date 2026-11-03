<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Provider\LiveKitEgressClient;

function dzn_egress_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$captured = [];
$transport = static function (string $url, array $body, array $headers) use (&$captured): array {
    $captured[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
    if (str_contains($url, 'StartRoomCompositeEgress')) return ['status' => 200, 'body' => json_encode(['egress_id' => 'EG_TEST', 'status' => 'EGRESS_STARTING'])];
    if (str_contains($url, 'ListEgress')) return ['status' => 200, 'body' => json_encode(['items' => [['egress_id' => 'EG_TEST', 'status' => 'EGRESS_COMPLETE', 'file' => ['filename' => '/srv/out.mp4']]]])];
    if (str_contains($url, 'StopEgress')) return ['status' => 200, 'body' => json_encode(['status' => 'EGRESS_ENDING'])];
    return ['status' => 500, 'body' => '{}'];
};
$config = new Config([
    'livekit_api_url' => 'https://rtc.example.invalid', 'livekit_key' => 'key', 'livekit_secret' => str_repeat('s', 32),
    'egress_layout' => 'dzn-two-person', 'egress_template_url' => 'http://127.0.0.1:8091/egress/template',
]);
$client = new LiveKitEgressClient($config, $transport);
$started = $client->start(['recording_uid' => 'R', 'content_version' => 1, 'room_name' => 'dzn-room', 'output_path' => '/srv/delnavazan-recordings/tmp/R/1/capture.mp4']);
dzn_egress_assert($started['egress_id'] === 'EG_TEST' && $started['status'] === 'starting', 'Egress start mapping');
$first = $captured[0];
dzn_egress_assert(str_contains($first['url'], '/twirp/livekit.Egress/StartRoomCompositeEgress'), 'Twirp Egress endpoint');
dzn_egress_assert($first['body']['layout'] === 'dzn-two-person', 'deterministic two-person layout');
dzn_egress_assert($first['body']['file_outputs'][0]['file_type'] === 'MP4', 'MP4 output');
dzn_egress_assert($first['body']['file_outputs'][0]['filepath'] === '/srv/delnavazan-recordings/tmp/R/1/capture.mp4', 'recording-specific private output path');
dzn_egress_assert($first['body']['custom_base_url'] === 'http://127.0.0.1:8091/egress/template', 'custom watermark layout base');
dzn_egress_assert(!str_contains(json_encode($first['body']), 'secret'), 'request body must not contain provider secret');
$auth = $first['headers']['Authorization'] ?? '';
$jwt = substr($auth, 7);
$parts = explode('.', $jwt);
dzn_egress_assert(count($parts) === 3, 'Egress request must be signed');
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
dzn_egress_assert(($payload['video']['roomRecord'] ?? false) === true, 'server Egress grant must carry roomRecord=true');
dzn_egress_assert(($payload['video']['room'] ?? '') === 'dzn-room', 'Egress grant must be room-scoped');
dzn_egress_assert(!str_contains($auth, str_repeat('s', 32)), 'secret must not be embedded in the token');
$status = $client->status('EG_TEST');
dzn_egress_assert($status['status'] === 'complete' && $status['output_path'] === '/srv/out.mp4', 'Egress completion mapping');
dzn_egress_assert($client->stop('EG_TEST')['status'] === 'ending', 'Egress stop mapping');
echo "LiveKit Egress adapter unit: PASS\n";
