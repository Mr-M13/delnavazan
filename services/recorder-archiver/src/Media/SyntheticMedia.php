<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Media;

/**
 * Deterministic synthetic MP4 and musical-audio fixtures for tests.
 *
 * This is not a claim that real Egress output was produced. It is a structurally valid, inspectable
 * container with one H.264-compatible video track, one AAC-compatible audio track, two participant
 * regions in the layout contract, and a declared A/V sync marker.
 */
final class SyntheticMedia {
    public static function writeMp4(string $path, int $durationMs = 3000, int $syncOffsetMs = 0, int $width = 1280, int $height = 720): array {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('synthetic_media_directory_unavailable');
        $timescale = 1000;
        $mvhd = self::fullBox('mvhd', 0, 0, pack('NNNN', 0, 0, $timescale, $durationMs) . pack('N', 0x00010000) . pack('n', 0x0100) . str_repeat("\0", 10) . self::matrix() . str_repeat("\0", 24) . pack('N', 3));
        $video = self::track('vide', 'avc1', 1, $durationMs, $timescale, $width, $height);
        $audio = self::track('soun', 'mp4a', 2, $durationMs, $timescale, 0, 0);
        $metadata = self::box('udta', self::box('dzn ', json_encode(['sync_offset_ms' => $syncOffsetMs, 'participants' => 2, 'layout' => 'dzn-two-person', 'watermark' => 'recording-native'], JSON_UNESCAPED_SLASHES)));
        $moov = self::box('moov', $mvhd . $video . $audio . $metadata);
        $ftyp = self::box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41');
        $mdatPayload = str_repeat('D', 16384);
        $mdat = self::box('mdat', $mdatPayload);
        $bytes = $ftyp . $moov . $mdat;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new \RuntimeException('synthetic_media_write_failed');
        chmod($path, 0600);
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => $durationMs, 'width' => $width, 'height' => $height, 'sync_offset_ms' => $syncOffsetMs];
    }

    /** Stereo 48 kHz musical fixture with transients and a deliberate near-clip peak. */
    public static function writeWav(string $path, float $seconds = 3.0, int $sampleRate = 48000): array {
        $frames = (int) round($seconds * $sampleRate);
        $data = '';
        for ($i = 0; $i < $frames; $i++) {
            $t = $i / $sampleRate;
            $envelope = exp(-6.0 * (fmod($t, 0.75)));
            $left = 0.55 * sin(2 * M_PI * 440 * $t) + 0.25 * sin(2 * M_PI * 880 * $t) + 0.10 * $envelope * sin(2 * M_PI * 3000 * $t);
            $right = 0.50 * sin(2 * M_PI * 660 * $t) + 0.30 * sin(2 * M_PI * 990 * $t) + 0.10 * $envelope * sin(2 * M_PI * 3600 * $t);
            if ($i % 12000 === 0) { $left = 0.98; $right = -0.98; }
            $left = max(-1.0, min(1.0, $left));
            $right = max(-1.0, min(1.0, $right));
            $data .= pack('v', (int) round($left * 32767)) . pack('v', (int) round($right * 32767));
        }
        $header = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 2) . pack('V', $sampleRate) . pack('V', $sampleRate * 4) . pack('v', 4) . pack('v', 16) . 'data' . pack('V', strlen($data));
        if (file_put_contents($path, $header . $data, LOCK_EX) === false) throw new \RuntimeException('synthetic_audio_write_failed');
        chmod($path, 0600);
        return ['path' => $path, 'seconds' => $seconds, 'sample_rate' => $sampleRate, 'channels' => 2, 'bits' => 16, 'bytes' => 44 + strlen($data)];
    }

    public static function measureWav(string $path): array {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') throw new \RuntimeException('synthetic_audio_invalid');
        $offset = 12; $fmt = null; $data = null;
        while ($offset + 8 <= strlen($bytes)) {
            $chunk = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $payload = substr($bytes, $offset + 8, $size);
            if ($chunk === 'fmt ') $fmt = $payload;
            if ($chunk === 'data') { $data = $payload; break; }
            $offset += 8 + $size + ($size % 2);
        }
        if ($fmt === null || $data === null) throw new \RuntimeException('synthetic_audio_header_invalid');
        $channels = unpack('v', substr($fmt, 2, 2))[1];
        $rate = unpack('V', substr($fmt, 4, 4))[1];
        $bits = unpack('v', substr($fmt, 14, 2))[1];
        $frames = intdiv(strlen($data), max(1, $channels * intdiv($bits, 8)));
        $peak = 0; $clipped = 0; $diffSum = 0; $transients = 0; $previous = 0;
        for ($i = 0; $i < $frames; $i++) {
            $l = unpack('v', substr($data, $i * 4, 2))[1];
            $r = unpack('v', substr($data, $i * 4 + 2, 2))[1];
            $l = $l > 32767 ? $l - 65536 : $l;
            $r = $r > 32767 ? $r - 65536 : $r;
            $peak = max($peak, abs($l), abs($r));
            if (abs($l) >= 32700 || abs($r) >= 32700) $clipped++;
            $diffSum += abs($l - $r);
            $instant = abs($l) + abs($r);
            if ($instant > ($previous + 12000)) $transients++;
            $previous = $instant;
        }
        return [
            'channels' => $channels, 'sample_rate' => $rate, 'bits' => $bits,
            'duration_seconds' => round($frames / $rate, 3),
            'bitrate_kbps' => round(($rate * $channels * $bits) / 1000, 1),
            'stereo_mean_abs_difference' => $frames > 0 ? round($diffSum / $frames, 2) : 0,
            'clipped_samples' => $clipped, 'transients' => $transients,
            'peak_dbfs' => $peak > 0 ? round(20 * log10($peak / 32768), 2) : -120.0,
        ];
    }

    private static function track(string $handler, string $codec, int $id, int $durationMs, int $timescale, int $width, int $height): string {
        $tkhd = self::fullBox('tkhd', 0, 3, pack('NNNN', 0, 0, $id, 0) . pack('N', $durationMs) . str_repeat("\0", 8) . pack('nnn', 0, 0, $handler === 'soun' ? 0x0100 : 0) . str_repeat("\0", 2) . self::matrix() . pack('NN', $width << 16, $height << 16));
        $mdhd = self::fullBox('mdhd', 0, 0, pack('NNNN', 0, 0, $timescale, $durationMs) . pack('n', 0x55c4) . pack('n', 0));
        $hdlr = self::fullBox('hdlr', 0, 0, pack('N', 0) . $handler . str_repeat("\0", 12) . "Delnavazan\0");
        $sample = self::box($codec, str_repeat("\0", $handler === 'vide' ? 78 : 28));
        $stsd = self::fullBox('stsd', 0, 0, pack('N', 1) . $sample);
        $stbl = self::box('stbl', $stsd);
        $minf = self::box('minf', $stbl);
        $mdia = self::box('mdia', $mdhd . $hdlr . $minf);
        return self::box('trak', $tkhd . $mdia);
    }

    private static function fullBox(string $type, int $version, int $flags, string $payload): string { return self::box($type, chr($version) . substr(pack('N', $flags), 1) . $payload); }
    private static function box(string $type, string $payload): string { return pack('N', 8 + strlen($payload)) . $type . $payload; }
    private static function matrix(): string { return pack('N', 0x00010000) . str_repeat("\0", 12) . pack('N', 0x00010000) . str_repeat("\0", 12) . pack('N', 0x40000000); }
}
