<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/**
 * Signed WordPress transport. The service signs a command pull and every callback/health snapshot.
 * It never sends a raw media path, recording URL, token or secret.
 */
class WordPressClient {
    public function __construct(private Config $config, private Signer $signer = new Signer()) {}

    public function pull(int $limit): array {
        $payload = ['service_id' => (string) $this->config->get('service_id'), 'limit' => max(1, min(100, $limit)), 'requested_at' => time()];
        $envelope = Signer::sign($payload, $this->config->requireSecret('wordpress_service_secret'), (string) $this->config->get('wordpress_service_key_id'), 'recording-service-command-pull', time(), 120);
        $response = $this->post('/wp-json/delnavazan-platform/v1/recording-service/pull/', $envelope);
        return (array) ($response['commands'] ?? []);
    }

    public function callback(array $payload): void {
        $envelope = Signer::sign($payload, $this->config->requireSecret('wordpress_service_secret'), (string) $this->config->get('wordpress_service_key_id'), 'wordpress-recording-callback', time(), 300);
        $this->post('/wp-json/delnavazan-platform/v1/recording-service/callback/', $envelope);
    }

    public function health(array $payload): void {
        $envelope = Signer::sign($payload, $this->config->requireSecret('wordpress_service_secret'), (string) $this->config->get('wordpress_service_key_id'), 'wordpress-recording-callback', time(), 300);
        $this->post('/wp-json/delnavazan-platform/v1/recording-service/health/', $envelope);
    }

    /** Fresh signed authorisation obtained immediately before VPS source deletion. */
    public function deletionAuthorisation(string $recordingUid, int $contentVersion, string $destinationSha256): array {
        $payload = ['recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'destination_sha256' => $destinationSha256, 'requested_at' => time()];
        $envelope = Signer::sign($payload, $this->config->requireSecret('wordpress_service_secret'), (string) $this->config->get('wordpress_service_key_id'), 'recording-deletion-authorisation', time(), 120);
        $response = $this->post('/wp-json/delnavazan-platform/v1/recording-service/deletion-authorisation/', $envelope);
        $verified = Signer::verify($response, $this->config->requireSecret('wordpress_service_secret'), 'recording-deletion-authorisation-response', time());
        $payload = $verified['payload'];
        if ((string) ($payload['recording_uid'] ?? '') !== $recordingUid || (int) ($payload['content_version'] ?? 0) !== $contentVersion || !hash_equals($destinationSha256, (string) ($payload['destination_sha256'] ?? ''))) {
            throw new \RuntimeException('recording_deletion_authorisation_mismatch');
        }
        return $payload;
    }

    public function configured(): bool {
        return (string) $this->config->get('wordpress_base_url') !== '' && strlen((string) $this->config->get('wordpress_service_secret')) >= 32;
    }

    private function post(string $path, array $envelope): array {
        $base = rtrim((string) $this->config->get('wordpress_base_url'), '/');
        if ($base === '') throw new \RuntimeException('wordpress_transport_unconfigured');
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $header = rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-DZN-Signature: ' . $header],
            CURLOPT_POSTFIELDS => $body, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($status < 200 || $status >= 300) throw new \RuntimeException('wordpress_transport_failed:' . Support::safeReason($error !== '' ? $error : 'http_' . $status));
        $decoded = json_decode(is_string($response) ? $response : '', true);
        if (!is_array($decoded)) throw new \RuntimeException('wordpress_transport_invalid_response');
        return $decoded;
    }
}
