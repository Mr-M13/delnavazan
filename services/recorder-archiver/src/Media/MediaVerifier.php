<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Media;

/**
 * Prove that the Egress output is closed, parseable, has the expected streams and a digest taken
 * from the final file. A provider "complete" event alone is never sufficient.
 */
final class MediaVerifier {
    private Mp4Parser $parser;

    public function __construct(?Mp4Parser $parser = null) { $this->parser = $parser ?? new Mp4Parser(); }

    public function verify(string $path, int $scheduledDurationSeconds): array {
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('media_file_missing');
        $locked = $this->assertClosed($path);
        clearstatcache(true, $path);
        $before = filesize($path);
        if (!is_int($before) || $before < 1024) throw new \RuntimeException('media_size_missing');
        try {
            $parsed = $this->parser->parse($path);
        } catch (\Throwable $error) {
            throw new \RuntimeException('media_parse_failed:' . $error->getMessage());
        }
        clearstatcache(true, $path);
        $after = filesize($path);
        if (!is_int($after) || $after !== $before) throw new \RuntimeException('media_size_unstable');
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) throw new \RuntimeException('media_digest_invalid');
        if (($parsed['video_streams'] ?? 0) < 1) throw new \RuntimeException('media_video_stream_missing');
        if (($parsed['audio_streams'] ?? 0) < 1) throw new \RuntimeException('media_audio_stream_missing');
        if (strtolower((string) $parsed['video_codec']) !== 'h264') throw new \RuntimeException('media_video_codec_unsupported');
        if (strtolower((string) $parsed['audio_codec']) !== 'aac') throw new \RuntimeException('media_audio_codec_unsupported');
        $duration = (int) $parsed['duration_ms'];
        if ($duration < 1) throw new \RuntimeException('media_duration_missing');
        if ($scheduledDurationSeconds > 0) {
            $scheduled = $scheduledDurationSeconds * 1000;
            if ($duration < (int) floor($scheduled * 0.90) || $duration > (int) ceil($scheduled * 1.10) + 5000) throw new \RuntimeException('media_duration_implausible');
        }
        return [
            'verified' => true, 'path' => $path, 'bytes' => $after, 'sha256' => $digest,
            'duration_ms' => $duration, 'width' => (int) ($parsed['width'] ?? 0), 'height' => (int) ($parsed['height'] ?? 0),
            'video_codec' => 'h264', 'audio_codec' => 'aac',
            'video_streams' => (int) $parsed['video_streams'], 'audio_streams' => (int) $parsed['audio_streams'],
            'sync_offset_ms' => (int) ($parsed['sync_offset_ms'] ?? 0),
            'size_stable' => true, 'file_closed' => $locked, 'format' => 'mp4',
        ];
    }

    private function assertClosed(string $path): bool {
        $handle = @fopen($path, 'rb+');
        if (!is_resource($handle)) return false;
        $wouldBlock = 0;
        $ok = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        if ($ok) @flock($handle, LOCK_UN);
        fclose($handle);
        if (!$ok && $wouldBlock) throw new \RuntimeException('media_file_not_closed');
        return true;
    }
}
