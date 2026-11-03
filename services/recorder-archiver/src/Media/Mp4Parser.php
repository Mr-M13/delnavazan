<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Media;

/**
 * Minimal MP4 container inspector used to prove the final Egress file is closed and parseable.
 * It reads box structure rather than trusting the provider's completion flag.
 */
final class Mp4Parser {
    private array $tracks = [];
    private int $timescale = 0;
    private int $durationUnits = 0;
    private int $width = 0;
    private int $height = 0;
    private array $metadata = [];

    public function parse(string $path): array {
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('media_file_missing');
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('media_file_unreadable');
        try {
            $this->walk($handle, filesize($path), 0);
        } finally {
            fclose($handle);
        }
        if ($this->timescale < 1) throw new \RuntimeException('media_movie_header_missing');
        $video = 0; $audio = 0; $videoCodec = ''; $audioCodec = '';
        foreach ($this->tracks as $track) {
            if ($track['handler'] === 'vide') { $video++; $videoCodec = $videoCodec ?: $track['codec']; }
            if ($track['handler'] === 'soun') { $audio++; $audioCodec = $audioCodec ?: $track['codec']; }
        }
        $videoCodec = in_array(strtolower($videoCodec), ['avc1', 'avc3'], true) ? 'h264' : strtolower($videoCodec);
        $audioCodec = strtolower($audioCodec) === 'mp4a' ? 'aac' : strtolower($audioCodec);
        return [
            'format' => 'mp4', 'video_streams' => $video, 'audio_streams' => $audio,
            'video_codec' => $videoCodec, 'audio_codec' => $audioCodec,
            'duration_ms' => (int) round($this->durationUnits / $this->timescale * 1000),
            'width' => $this->width, 'height' => $this->height, 'tracks' => $this->tracks,
            'sync_offset_ms' => (int) ($this->metadata['sync_offset_ms'] ?? 0),
            'metadata' => $this->metadata,
        ];
    }

    private function walk($handle, int $limit, int $depth, string $parent = ''): void {
        if ($depth > 12) return;
        $offset = ftell($handle);
        while ($offset < $limit) {
            fseek($handle, $offset);
            $header = fread($handle, 8);
            if (strlen($header) < 8) break;
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $large = fread($handle, 8);
                if (strlen($large) < 8) break;
                $size = unpack('J', $large)[1];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $limit - $offset;
            }
            if ($size < $headerSize || $offset + $size > $limit) break;
            $payloadStart = $offset + $headerSize;
            $payloadSize = $size - $headerSize;
            if (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'udta', 'dinf'], true)) {
                $this->walk($handle, $offset + $size, $depth + 1, $type);
            } elseif ($type === 'mvhd') {
                $this->readMvhd($handle, $payloadStart);
            } elseif ($type === 'tkhd') {
                $this->readTkhd($handle, $payloadStart, $payloadSize);
            } elseif ($type === 'hdlr') {
                $this->readHdlr($handle, $payloadStart);
            } elseif ($type === 'stsd') {
                $this->readStsd($handle, $payloadStart, $payloadSize);
            } elseif ($type === 'dzn ') {
                fseek($handle, $payloadStart);
                $json = fread($handle, $payloadSize);
                $decoded = json_decode((string) $json, true);
                if (is_array($decoded)) $this->metadata = $decoded;
            }
            $offset += $size;
        }
    }

    private function readMvhd($handle, int $payloadStart): void {
        fseek($handle, $payloadStart);
        $data = fread($handle, 32);
        if (strlen($data) < 20) return;
        $version = ord($data[0]);
        if ($version === 1) {
            if (strlen($data) < 28) return;
            $this->timescale = unpack('N', substr($data, 20, 4))[1];
            $duration = unpack('J', substr($data, 24, 8))[1];
        } else {
            $this->timescale = unpack('N', substr($data, 12, 4))[1];
            $duration = unpack('N', substr($data, 16, 4))[1];
        }
        $this->durationUnits = (int) $duration;
    }

    private function readTkhd($handle, int $payloadStart, int $payloadSize): void {
        if ($payloadSize < 8) return;
        fseek($handle, $payloadStart + $payloadSize - 8);
        $data = fread($handle, 8);
        if (strlen($data) < 8) return;
        $w = unpack('N', substr($data, 0, 4))[1] >> 16;
        $h = unpack('N', substr($data, 4, 4))[1] >> 16;
        if ($w > $this->width) $this->width = $w;
        if ($h > $this->height) $this->height = $h;
    }

    private function readHdlr($handle, int $payloadStart): void {
        fseek($handle, $payloadStart);
        $data = fread($handle, 12);
        if (strlen($data) < 12) return;
        $handler = substr($data, 8, 4);
        if (in_array($handler, ['vide', 'soun'], true)) $this->tracks[] = ['handler' => $handler, 'codec' => ''];
    }

    private function readStsd($handle, int $payloadStart, int $payloadSize): void {
        if (!$this->tracks) return;
        fseek($handle, $payloadStart);
        $data = fread($handle, max(16, min(8192, $payloadSize)));
        if (strlen($data) < 16) return;
        $offset = 8;
        $entries = (int) unpack('N', substr($data, 4, 4))[1];
        for ($i = 0; $i < $entries && $offset + 8 <= strlen($data); $i++) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size < 8) break;
            $last = count($this->tracks) - 1;
            if ($last >= 0 && $this->tracks[$last]['codec'] === '') $this->tracks[$last]['codec'] = $type;
            $offset += $size;
        }
    }
}
