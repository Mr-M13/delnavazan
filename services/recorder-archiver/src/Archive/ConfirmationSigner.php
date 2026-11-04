<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Archive;

use Delnavazan\Recorder\Support;

/**
 * Archive-node confirmation signature. It binds the exact recording UID, content version, source
 * and destination byte size/digest, archive node identity, object key and durable commit receipt.
 */
final class ConfirmationSigner {
    public function __construct(private string $secret, private string $keyId = 'mbox-archive-v1') {
        if (strlen($secret) < 32) throw new \InvalidArgumentException('archive_confirmation_secret_unavailable');
    }

    public function sign(array $confirmation): string {
        $payload = [
            'recording_uid' => (string) $confirmation['recording_uid'],
            'content_version' => (int) $confirmation['content_version'],
            'source_bytes' => (int) $confirmation['source_bytes'],
            'destination_bytes' => (int) $confirmation['destination_bytes'],
            'source_sha256' => (string) $confirmation['source_sha256'],
            'destination_sha256' => (string) $confirmation['destination_sha256'],
            'archive_node_identity' => (string) $confirmation['archive_node_identity'],
            'destination_object_key' => (string) $confirmation['destination_object_key'],
            'durable_commit_receipt' => (string) $confirmation['durable_commit_receipt'],
            'confirmation_time' => (string) $confirmation['confirmation_time'],
        ];
        return hash_hmac('sha256', $this->keyId . '|' . Support::canonicalJson($payload), $this->secret);
    }

    public function verify(array $confirmation, string $signature): bool {
        return hash_equals($this->sign($confirmation), $signature);
    }
}
