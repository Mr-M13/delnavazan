<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Data-quality projection for intervals the clock cannot classify.
 *
 * Unknown intervals are deliberately kept out of confirmed priority gaps. They
 * are still shown to the administrator so incomplete data never disappears: the
 * clock neither counts them as available capacity nor as an uncovered gap.
 */
final class InstrumentCoverageUnknownData {
    /** @param array<int,array<string,mixed>> $clocks @return array<string,mixed> */
    public static function build(array $clocks, string $source = 'actionable'): array {
        $ranges = array();
        $segments = 0;
        foreach (InstrumentCoverageWeek::ORDER as $weekday) {
            $clock = $clocks[$weekday] ?? null;
            if (!is_array($clock)) continue;
            $date = (string) ($clock['date'] ?? '');
            $open = null;
            foreach (array_values((array) ($clock['segments'] ?? array())) as $segment) {
                if (!is_array($segment)) continue;
                if ($source === 'actionable' && !empty($segment['past'])) { self::close($ranges, $open); $open = null; continue; }
                if ((string) ($segment['state'] ?? '') !== InstrumentCoverageClockAnalyzer::STATE_UNKNOWN) {
                    self::close($ranges, $open);
                    $open = null;
                    continue;
                }
                $reason = (string) ($segment['reason'] ?? '');
                $minute = (int) ($segment['minute'] ?? 0);
                $visualIndex = intdiv((int) ($segment['index'] ?? 0), 2);
                if ($open !== null && $open['reason'] === $reason && $open['end_minute'] === $minute) {
                    $open['end_minute'] = $minute + InstrumentCoverageClockAnalyzer::SLOT_MINUTES;
                    $open['duration_minutes'] += InstrumentCoverageClockAnalyzer::SLOT_MINUTES;
                    $open['visual_indexes'][$visualIndex] = $visualIndex;
                    $segments++;
                    continue;
                }
                self::close($ranges, $open);
                $open = array(
                    'date' => $date,
                    'weekday' => (int) $weekday,
                    'date_label' => (string) ($clock['date_label'] ?? ''),
                    'date_label_fa' => (string) ($clock['date_label_fa'] ?? ''),
                    'start_minute' => $minute,
                    'end_minute' => $minute + InstrumentCoverageClockAnalyzer::SLOT_MINUTES,
                    'duration_minutes' => InstrumentCoverageClockAnalyzer::SLOT_MINUTES,
                    'reason' => $reason,
                    'golden' => !empty($segment['golden']),
                    'visual_indexes' => array($visualIndex => $visualIndex),
                );
                $segments++;
            }
            self::close($ranges, $open);
        }

        usort($ranges, static fn(array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['start_minute'] <=> $b['start_minute']));
        $duration = 0;
        foreach ($ranges as &$range) {
            $range['start_time'] = InstrumentCoverageClockAnalyzer::clock((int) $range['start_minute']);
            $range['end_time'] = InstrumentCoverageClockAnalyzer::clock((int) $range['end_minute']);
            $range['time_label'] = $range['start_time'] . '–' . $range['end_time'];
            $range['visual_indexes'] = array_values($range['visual_indexes']);
            $range['visual_start_index'] = $range['visual_indexes'][0] ?? 0;
            $duration += (int) $range['duration_minutes'];
        }
        unset($range);

        return array(
            'interval_count' => count($ranges),
            'segment_count' => $segments,
            'duration_minutes' => $duration,
            'ranges' => $ranges,
        );
    }

    private static function close(array &$ranges, ?array &$open): void {
        if ($open === null) return;
        $ranges[] = $open;
        $open = null;
    }
}
