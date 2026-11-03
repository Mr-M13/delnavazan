<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Provider;

use Delnavazan\Recorder\Config;

/**
 * LiveKit Room Composite Egress adapter.
 *
 * Only this server-side service holds the LiveKit API secret. Participant tokens still carry
 * roomRecord=false; the Egress grant exists only in this process.
 */
final class LiveKitEgressClient implements EgressPort {
    public function __construct(private Config $config, private $transport = null) {}

    public function start(array $job): array {
        $path = (string) ($job['output_path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/')) throw new \RuntimeException('egress_output_path_invalid');
        $body = [
            'room_name' => (string) ($job['room_name'] ?? ''),
            'layout' => (string) ($this->config->get('egress_layout') ?? 'dzn-two-person'),
            'audio_only' => false,
            'video_only' => false,
            'file_outputs' => [[
                'file_type' => 'MP4',
                'filepath' => $path,
                'disable_manifest' => true,
            ]],
        ];
        $template = (string) $this->config->get('egress_template_url');
        if ($template !== '') $body['custom_base_url'] = $template;
        $response = $this->call('StartRoomCompositeEgress', $body, (string) ($job['room_name'] ?? ''));
        $egressId = (string) ($response['egress_id'] ?? '');
        if ($egressId === '') throw new \RuntimeException('egress_identity_missing');
        return ['egress_id' => $egressId, 'status' => $this->mapStatus((string) ($response['status'] ?? 'EGRESS_STARTING')), 'output_path' => $path];
    }

    public function status(string $egressId): array {
        $response = $this->call('ListEgress', ['egress_id' => $egressId], '');
        $items = (array) ($response['items'] ?? []);
        foreach ($items as $item) {
            if ((string) ($item['egress_id'] ?? '') !== $egressId) continue;
            $output = '';
            $results = $item['file_results'] ?? $item['file'] ?? [];
            if (is_array($results) && isset($results['filename'])) $output = (string) $results['filename'];
            elseif (is_array($results) && isset($results['filepath'])) $output = (string) $results['filepath'];
            else foreach ((array) $results as $file) {
                if (is_array($file) && isset($file['filename'])) { $output = (string) $file['filename']; break; }
                if (is_array($file) && isset($file['filepath'])) { $output = (string) $file['filepath']; break; }
            }
            return ['egress_id' => $egressId, 'status' => $this->mapStatus((string) ($item['status'] ?? '')), 'output_path' => $output, 'error' => (string) ($item['error'] ?? '')];
        }
        return ['egress_id' => $egressId, 'status' => 'unknown', 'output_path' => '', 'error' => 'egress_not_listed'];
    }

    public function stop(string $egressId): array {
        $response = $this->call('StopEgress', ['egress_id' => $egressId], '');
        return ['egress_id' => $egressId, 'status' => $this->mapStatus((string) ($response['status'] ?? 'EGRESS_ENDING')), 'output_path' => ''];
    }

    private function call(string $method, array $body, string $room): array {
        $base = (string) $this->config->get('livekit_api_url');
        $key = (string) $this->config->get('livekit_key');
        $secret = (string) $this->config->get('livekit_secret');
        if ($base === '' || $key === '' || strlen($secret) < 16) throw new \RuntimeException('livekit_egress_not_configured');
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $key, 'sub' => 'dzn-recorder', 'nbf' => $now - 5, 'iat' => $now, 'exp' => $now + 120,
            'video' => ['roomRecord' => true, 'roomAdmin' => true, 'room' => $room !== '' ? $room : '*'],
        ];
        $segments = [self::b64(json_encode($header, JSON_THROW_ON_ERROR)), self::b64(json_encode($payload, JSON_THROW_ON_ERROR))];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::b64($signature);
        $url = rtrim($base, '/') . '/twirp/livekit.Egress/' . $method;
        $headers = ['Authorization' => 'Bearer ' . implode('.', $segments), 'Content-Type' => 'application/json'];
        $transport = $this->transport ?? static function (string $url, array $body, array $headers): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
                CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => is_string($response) ? $response : ''];
        };
        $response = $transport($url, $body, $headers);
        if ((int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 300) throw new \RuntimeException('livekit_egress_unavailable');
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) throw new \RuntimeException('livekit_egress_invalid_response');
        return $decoded;
    }

    private function mapStatus(string $status): string {
        return match (strtoupper($status)) {
            'EGRESS_STARTING', 'STARTING' => 'starting',
            'EGRESS_ACTIVE', 'ACTIVE' => 'active',
            'EGRESS_ENDING', 'ENDING' => 'ending',
            'EGRESS_COMPLETE', 'COMPLETE' => 'complete',
            'EGRESS_FAILED', 'FAILED' => 'failed',
            'EGRESS_ABORTED', 'ABORTED' => 'aborted',
            'EGRESS_LIMIT_REACHED', 'LIMIT_REACHED' => 'limit_reached',
            default => 'unknown',
        };
    }

    private static function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
