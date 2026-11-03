<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Delnavazan\Recorder\Archive\LocalArchiveAdapter;
use Delnavazan\Recorder\Media\MediaVerifier;
use Delnavazan\Recorder\Media\Mp4Parser;
use Delnavazan\Recorder\Media\SyntheticMedia;
use Delnavazan\Recorder\Provider\FakeEgress;
use Delnavazan\Recorder\Support;

echo "Recorder/archiver integration: start\n";
$root = dzn_tmp('main');
try {
    [$config, $db, $repo, $storage, $client] = dzn_environment($root);
    $recordingUid = Support::uid();
    $start = dzn_start_payload($recordingUid);
    dzn_command($client, 'start', $start, hash('sha256', 'start-command-1'), 'recording_requested');
    $egress = new FakeEgress(3000);
    $archive = new LocalArchiveAdapter($config);
    $worker = dzn_worker($config, $repo, $storage, $egress, $archive, $client);
    $stats = $worker->runOnce(true);
    dzn_assert($stats['pulled'] === 1, 'signed command must be pulled');
    dzn_assert($stats['transfers'] === 1, 'verified local asset must transfer once');
    dzn_assert($stats['deletions'] === 1, 'verified archive must delete the VPS source');
    $job = $repo->job($recordingUid);
    dzn_assert($job && $job['state'] === 'archived_vps_deleted', 'recording job must reach archived_vps_deleted');
    $confirmation = $repo->confirmationFor($recordingUid, 1);
    dzn_assert($confirmation !== null, 'archive confirmation must be durable');
    dzn_assert((string) $confirmation['source_sha256'] === (string) $confirmation['destination_sha256'], 'source and destination digests must match');
    $final = $config->get('archive_root') . '/' . $confirmation['destination_object_key'];
    dzn_assert(is_file($final), 'final archive object must exist');
    dzn_assert((int) filesize($final) === (int) $confirmation['destination_bytes'], 'archive size must match confirmation');
    dzn_assert(!is_file($storage->readyPath($recordingUid, 1, (string) $confirmation['source_sha256'])), 'verified VPS source must be deleted');
    dzn_assert(glob($storage->readyRoot() . '/*/*/*.mp4') === [], 'no VPS media may remain after verified deletion');
    $types = array_column($client->callbacks, 'event');
    foreach (['egress_started', 'egress_completed', 'media_verified', 'transfer_started', 'transfer_verified', 'archive_confirmed', 'source_deleted', 'command_completed'] as $event) {
        dzn_assert(in_array($event, $types, true), 'missing callback event ' . $event);
    }
    $media = null;
    foreach ($client->callbacks as $callback) if (($callback['event'] ?? '') === 'media_verified') $media = $callback['media'];
    dzn_assert(is_array($media) && $media['video_streams'] === 1 && $media['audio_streams'] === 1, 'synthetic composite must carry video and audio');
    dzn_assert($media['video_codec'] === 'h264' && $media['audio_codec'] === 'aac', 'recording codecs must be H.264/AAC');
    dzn_assert($media['duration_ms'] === 3000 && $media['size_stable'] === true, 'duration and closed-file evidence must be declared');

    // Direct container/media proof (the same file shape Egress produces in this synthetic run).
    $fixture = $root . '/fixture.mp4';
    SyntheticMedia::writeMp4($fixture, 3000, 12);
    $parsed = (new Mp4Parser())->parse($fixture);
    dzn_assert($parsed['video_streams'] === 1 && $parsed['audio_streams'] === 1, 'MP4 parser must find both streams');
    dzn_assert($parsed['video_codec'] === 'h264' && $parsed['audio_codec'] === 'aac', 'MP4 sample entries must be H.264/AAC-compatible');
    dzn_assert($parsed['sync_offset_ms'] === 12, 'A/V sync marker must round-trip');
    dzn_assert(($parsed['metadata']['participants'] ?? 0) === 2, 'composite layout must declare both participants');
    $template = (string) file_get_contents(__DIR__ . '/../bin/router.php');
    foreach (['dzn-two-person', 'data-participant-region="a"', 'data-participant-region="b"', 'Delnavazan', 'watermark'] as $needle) dzn_assert(str_contains($template, $needle), 'recording layout missing ' . $needle);
    dzn_assert(!str_contains($template, 'LIVEKIT_API_SECRET') && !str_contains($template, 'livekit_secret'), 'layout template must never expose provider secrets');
    $verified = (new MediaVerifier())->verify($fixture, 3);
    dzn_assert($verified['verified'] === true && $verified['file_closed'] === true, 'media verification must prove closed final file');

    $wav = $root . '/music.wav';
    SyntheticMedia::writeWav($wav, 2.0);
    $measured = SyntheticMedia::measureWav($wav);
    dzn_assert($measured['channels'] === 2 && $measured['sample_rate'] === 48000, 'music fixture must be stereo 48 kHz');
    dzn_assert($measured['bitrate_kbps'] === 1536.0, 'stereo 48 kHz 16-bit bitrate must be measured');
    dzn_assert($measured['stereo_mean_abs_difference'] > 0, 'stereo behaviour must be non-mono');
    dzn_assert($measured['transients'] > 0, 'instrument transients must be present in the fixture');
    dzn_assert($measured['duration_seconds'] === 2.0, 'audio duration must be measured');
    echo 'Synthetic media: ' . json_encode(['mp4' => $parsed, 'audio' => $measured], JSON_UNESCAPED_SLASHES) . "\n";
    echo "Recorder/archiver integration: PASS\n";
} finally { dzn_cleanup($root); }
