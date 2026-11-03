<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/** Pure host admission policy. Classroom reliability takes priority over recording. */
final class ResourcePolicy {
    public const REASONS = ['allowed', 'host_load_above_threshold', 'host_memory_below_threshold'];

    public static function evaluate(float $loadPerCpu, int $availableMemoryBytes, int $minAvailableMemoryBytes, float $maxLoadPerCpu): array {
        if ($loadPerCpu > $maxLoadPerCpu) return ['allowed' => false, 'reason' => 'host_load_above_threshold'];
        if ($availableMemoryBytes < $minAvailableMemoryBytes) return ['allowed' => false, 'reason' => 'host_memory_below_threshold'];
        return ['allowed' => true, 'reason' => 'allowed'];
    }
}
