<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/** Reads host pressure without touching classroom services. */
final class SystemMetrics {
    public function __construct(private Config $config) {}

    public function snapshot(): array {
        $cpus = max(1, (int) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null') ?: 1);
        $load = 0.0;
        $loadRaw = @file_get_contents('/proc/loadavg');
        if (is_string($loadRaw) && preg_match('/^([0-9.]+)/', $loadRaw, $m) === 1) $load = (float) $m[1];
        $loadOverride = $this->config->get('host_load_per_cpu_override');
        $loadPerCpu = $loadOverride !== null && $loadOverride !== '' ? (float) $loadOverride : $load / $cpus;
        $available = 0;
        $mem = @file_get_contents('/proc/meminfo');
        if (is_string($mem) && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $mem, $m) === 1) $available = (int) $m[1] * 1024;
        $memOverride = $this->config->get('host_memory_available_override');
        if ($memOverride !== null && $memOverride !== '') $available = (int) $memOverride;
        return [
            'cpu_count' => $cpus,
            'load1' => $load,
            'load_per_cpu' => round($loadPerCpu, 3),
            'memory_available_bytes' => $available,
        ];
    }

    public function admission(): array {
        $snapshot = $this->snapshot();
        return ResourcePolicy::evaluate(
            (float) $snapshot['load_per_cpu'],
            (int) $snapshot['memory_available_bytes'],
            (int) $this->config->get('min_available_memory_bytes'),
            (float) $this->config->get('max_load_per_cpu')
        ) + $snapshot;
    }
}
