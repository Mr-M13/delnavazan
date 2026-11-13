<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * One metric-period contract for the admin coverage clock.
 *
 * Current weeks are actionable: elapsed slots are excluded from every headline
 * metric. Future and past weeks use the full week and say so explicitly. The
 * same period and source drive day percentages, weekly percentages, uncovered
 * durations, Golden gaps and the priority-gap list.
 */
final class InstrumentCoverageMetrics {
    /** @return array{key:string,source:string,label:string,gap_label:string} */
    public static function period(string $weekStart, string $weekEnd, string $today): array {
        if ($weekStart > $today) return array('key' => 'future', 'source' => 'all', 'label' => 'Upcoming week · full period', 'gap_label' => 'Priority gaps in the upcoming week');
        if ($weekEnd < $today) return array('key' => 'past', 'source' => 'all', 'label' => 'Completed week · full period', 'gap_label' => 'Gaps in the completed week');
        return array('key' => 'current', 'source' => 'actionable', 'label' => 'Remaining this week · elapsed slots excluded', 'gap_label' => 'Priority gaps this week');
    }

    /** @param array<int,array<string,mixed>> $clocks @return array<string,mixed> */
    public static function summarize(array $clocks, string $source = 'actionable'): array {
        $prefix = $source === 'all' ? 'all_' : '';
        $keys = array('measured_segments','covered_segments','limited_segments','uncovered_segments','unknown_segments','golden_segments','golden_covered_segments','golden_uncovered_segments','single_teacher_minutes','uncovered_minutes','golden_uncovered_minutes');
        $sum = array_fill_keys($keys, 0);
        foreach ($clocks as $clock) {
            $summary = (array) ($clock['summary'] ?? array());
            foreach ($keys as $key) $sum[$key] += (int) ($summary[$prefix . $key] ?? 0);
        }
        $sum['coverage_percent'] = $sum['measured_segments'] > 0 ? (int) round(100 * $sum['covered_segments'] / $sum['measured_segments']) : null;
        $sum['golden_coverage_percent'] = $sum['golden_segments'] > 0 ? (int) round(100 * $sum['golden_covered_segments'] / $sum['golden_segments']) : null;
        $sum['metric_source'] = $source;
        return $sum;
    }

    /** @param array<string,mixed> $clock @return array{source:string,percent:?int,label:string} */
    public static function day(array $clock, string $period, string $today): array {
        $date = (string) ($clock['date'] ?? '');
        $summary = (array) ($clock['summary'] ?? array());
        if ($period === 'current') {
            if ($date < $today) return array('source' => 'none', 'percent' => null, 'label' => 'Ended');
            if ($date === $today) return array('source' => 'actionable', 'percent' => $summary['coverage_percent'] ?? null, 'label' => 'Remaining today');
            return array('source' => 'all', 'percent' => $summary['all_coverage_percent'] ?? null, 'label' => 'Full day');
        }
        return array('source' => 'all', 'percent' => $summary['all_coverage_percent'] ?? null, 'label' => $period === 'past' ? 'Historical' : 'Full day');
    }
}
