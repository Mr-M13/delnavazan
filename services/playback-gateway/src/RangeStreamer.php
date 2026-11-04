<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** HTTP Range/HEAD streamer with bounded bandwidth and truthful abort/complete evidence. */
final class RangeStreamer {
    public function __construct(private Config $config, private AccessLog $log) {}

    public function serve(array $file, string $method, string $rangeHeader, array $principal, string $ticket): void {
        $size = (int) $file['size'];
        $start = 0; $end = $size - 1; $partial = false;
        if ($rangeHeader !== '') {
            $range = $this->parseRange($rangeHeader, $size);
            if ($range === null) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'range_not_satisfiable']);
                return;
            }
            [$start, $end] = $range; $partial = true;
        }
        $length = $end - $start + 1;
        http_response_code($partial ? 206 : 200);
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('ETag: "' . $file['etag'] . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if ($partial) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        if (strtoupper($method) === 'HEAD') return;

        $handle = fopen($file['path'], 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('archive_temporarily_unavailable');
        fseek($handle, $start);
        $remaining = $length;
        $chunkSize = max(8192, min(1048576, (int) $this->config->get('chunk_bytes')));
        $bandwidth = max(0, (int) $this->config->get('bandwidth_bytes_per_second'));
        $started = microtime(true);
        $sent = 0;
        $this->log->write('start', $this->context($principal, $ticket, $file, $start, $end, 0));
        try {
            while ($remaining > 0 && !connection_aborted()) {
                $chunk = fread($handle, min($chunkSize, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                flush();
                $sent += strlen($chunk);
                $remaining -= strlen($chunk);
                if ($bandwidth > 0) {
                    $expected = $sent / $bandwidth;
                    $elapsed = microtime(true) - $started;
                    if ($expected > $elapsed) usleep((int) (($expected - $elapsed) * 1000000));
                }
            }
        } finally { fclose($handle); }
        $event = connection_aborted() || $remaining > 0 ? 'abort' : 'complete';
        $this->log->write($event, $this->context($principal, $ticket, $file, $start, $end, $sent));
    }

    /** @return array{0:int,1:int}|null */
    private function parseRange(string $header, int $size): ?array {
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $m) !== 1) return null;
        if ($m[1] === '' && $m[2] === '') return null;
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix < 1) return null;
            $start = max(0, $size - $suffix); return [$start, $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : (int) $m[2];
        if ($end < $start || $start >= $size) return null;
        return [$start, min($end, $size - 1)];
    }

    private function context(array $principal, string $ticket, array $file, int $start, int $end, int $sent): array {
        return [
            'principal_type' => (string) ($principal['principal_type'] ?? ''),
            'principal_id' => (int) ($principal['principal_id'] ?? 0),
            'recording_uid' => (string) ($principal['recording_uid'] ?? ''),
            'content_version' => (int) ($principal['content_version'] ?? 0),
            'jti_digest' => hash('sha256', (string) ($principal['jti'] ?? '')),
            'ticket_digest' => hash('sha256', $ticket),
            'range_start' => $start, 'range_end' => $end, 'bytes_sent' => $sent,
            'object_key' => (string) ($principal['archive_object_key'] ?? ''),
        ];
    }
}
