<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Provider;

use Delnavazan\Recorder\Media\SyntheticMedia;

/**
 * Deterministic Egress double for synthetic tests. It never contacts LiveKit and is not registered
 * by the production bootstrap; it exists to prove lifecycle, verification and crash recovery.
 */
final class FakeEgress implements EgressPort {
    private array $jobs = [];
    public int $startCalls = 0;
    public bool $crashOnStart = false;
    public bool $crashOnStatus = false;
    public int $pollsBeforeComplete = 1;

    public function __construct(private int $durationMs = 3000) {}

    public function start(array $job): array {
        $this->startCalls++;
        if ($this->crashOnStart) throw new \RuntimeException('fake_egress_crash');
        $id = 'EG_' . substr(hash('sha256', (string) $job['recording_uid'] . '|' . (int) $job['content_version']), 0, 24);
        $this->jobs[$id] = ['path' => (string) $job['output_path'], 'polls' => 0, 'stopped' => false];
        return ['egress_id' => $id, 'status' => 'starting', 'output_path' => (string) $job['output_path']];
    }

    public function status(string $egressId): array {
        if ($this->crashOnStatus) throw new \RuntimeException('fake_egress_status_crash');
        if (!isset($this->jobs[$egressId])) return ['egress_id' => $egressId, 'status' => 'unknown', 'output_path' => '', 'error' => 'not_found'];
        $job = &$this->jobs[$egressId];
        $job['polls']++;
        if ($job['stopped'] || $job['polls'] >= $this->pollsBeforeComplete) {
            if (!is_file($job['path'])) {
                SyntheticMedia::writeMp4($job['path'], $this->durationMs);
            }
            return ['egress_id' => $egressId, 'status' => 'complete', 'output_path' => $job['path'], 'error' => ''];
        }
        return ['egress_id' => $egressId, 'status' => 'active', 'output_path' => $job['path'], 'error' => ''];
    }

    public function stop(string $egressId): array {
        if (!isset($this->jobs[$egressId])) throw new \RuntimeException('fake_egress_not_found');
        $this->jobs[$egressId]['stopped'] = true;
        return ['egress_id' => $egressId, 'status' => 'ending', 'output_path' => $this->jobs[$egressId]['path']];
    }
}
