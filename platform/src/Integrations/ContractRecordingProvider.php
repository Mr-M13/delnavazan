<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\Port\RecordingProviderPort;

/**
 * Deterministic no-I/O adapter for tests.
 */
final class ContractRecordingProvider implements RecordingProviderPort {
    public function capture(array $command): array {
        return array(
            'object_key_raw' => 'contract://raw/' . ($command['uid'] ?? 'x'),
            'object_key_mixed' => 'contract://mixed/' . ($command['uid'] ?? 'x'),
            'quality_metrics' => array('snr_db' => 30.0, 'duration_seconds' => 60),
            'digest' => hash('sha256', (string) wp_json_encode($command)),
        );
    }
    public function purge(string $objectKey): bool {
        return true;
    }
}
