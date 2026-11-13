<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Priority gaps derived from the same authoritative 15-minute clock facts.
 * Nothing here changes availability; it only ranks and groups gaps for the
 * administrator's next action.
 */
final class InstrumentCoverageGapList {
    /** @param array<int,array<string,mixed>> $clocks @return list<array<string,mixed>> */
    public static function build(array $clocks, string $source = 'actionable', int $limit = 20): array {
        $gaps = array();
        foreach (InstrumentCoverageWeek::ORDER as $weekday) {
            $clock = $clocks[$weekday] ?? null;
            if (!is_array($clock)) continue;
            $date = (string) ($clock['date'] ?? '');
            $segments = array_values((array) ($clock['segments'] ?? array()));
            $visuals = array_values((array) ($clock['visual_segments'] ?? array()));
            $details = (array) ($clock['golden_details'] ?? array());
            $open = null;
            foreach ($segments as $segment) {
                if (!is_array($segment)) continue;
                $state = (string) ($segment['state'] ?? '');
                if ($source === 'actionable' && !empty($segment['past'])) { self::close($gaps, $open); $open = null; continue; }
                $rank = $state === InstrumentCoverageClockAnalyzer::STATE_UNCOVERED && !empty($segment['golden']) ? 0
                    : ($state === InstrumentCoverageClockAnalyzer::STATE_UNCOVERED ? 1
                    : ($state === InstrumentCoverageClockAnalyzer::STATE_LIMITED ? 2 : -1));
                if ($rank < 0) { self::close($gaps, $open); $open = null; continue; }
                $visualIndex = intdiv((int) ($segment['index'] ?? 0), 2);
                $visual = $visuals[$visualIndex] ?? array();
                $regions = array();
                foreach ((array) ($details[$visualIndex] ?? $details[(string) $visualIndex] ?? array()) as $entry) {
                    if (!is_array($entry)) continue;
                    $key = (string) ($entry['region'] ?? '');
                    if ($key === '') continue;
                    $regions[$key] = array('key' => $key, 'label' => (string) ($entry['label'] ?? $key));
                }
                ksort($regions);
                $regionKey = implode('|', array_keys($regions));
                $partial = (string) ($visual['state'] ?? '') === InstrumentCoverageClockAnalyzer::STATE_PARTIAL;
                $reason = (string) ($segment['reason'] ?? '');
                if ($partial && (string) ($visual['reason'] ?? '') === InstrumentCoverageClockAnalyzer::REASON_PARTIAL_15_MINUTE) {
                    $reason = InstrumentCoverageClockAnalyzer::REASON_PARTIAL_15_MINUTE;
                }
                $signature = $rank . '|' . $reason . '|' . (!empty($segment['golden']) ? '1' : '0') . '|' . $regionKey . '|' . ($partial ? '1' : '0');
                $minute = (int) ($segment['minute'] ?? 0);
                if ($open !== null && $open['signature'] === $signature && $open['end_minute'] === $minute) {
                    $open['end_minute'] = $minute + InstrumentCoverageClockAnalyzer::SLOT_MINUTES;
                    $open['duration_minutes'] += InstrumentCoverageClockAnalyzer::SLOT_MINUTES;
                    $open['visual_indexes'][$visualIndex] = $visualIndex;
                    $open['teacher_count'] = max($open['teacher_count'], (int) ($segment['teacher_count'] ?? 0));
                    $open['declared_teacher_count'] = max($open['declared_teacher_count'], (int) ($segment['declared_teacher_count'] ?? 0));
                    $open['booked_teacher_count'] = max($open['booked_teacher_count'], (int) ($segment['booked_teacher_count'] ?? 0));
                    $open['held_teacher_count'] = max($open['held_teacher_count'], (int) ($segment['held_teacher_count'] ?? 0));
                    continue;
                }
                self::close($gaps, $open);
                $open = array(
                    'signature' => $signature,
                    'rank' => $rank,
                    'date' => $date,
                    'weekday' => (int) $weekday,
                    'date_label' => (string) ($clock['date_label'] ?? ''),
                    'date_label_fa' => (string) ($clock['date_label_fa'] ?? ''),
                    'start_minute' => $minute,
                    'end_minute' => $minute + InstrumentCoverageClockAnalyzer::SLOT_MINUTES,
                    'duration_minutes' => InstrumentCoverageClockAnalyzer::SLOT_MINUTES,
                    'state' => $state,
                    'reason' => $reason,
                    'golden' => !empty($segment['golden']),
                    'bonus_applies' => !empty($visual['bonus_applies']),
                    'partial' => $partial,
                    'regions' => array_values($regions),
                    'visual_indexes' => array($visualIndex => $visualIndex),
                    'teacher_count' => (int) ($segment['teacher_count'] ?? 0),
                    'declared_teacher_count' => (int) ($segment['declared_teacher_count'] ?? 0),
                    'booked_teacher_count' => (int) ($segment['booked_teacher_count'] ?? 0),
                    'held_teacher_count' => (int) ($segment['held_teacher_count'] ?? 0),
                );
            }
            self::close($gaps, $open);
        }
        usort($gaps, static fn(array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: ($a['date'] <=> $b['date']) ?: ($a['start_minute'] <=> $b['start_minute']));
        foreach ($gaps as &$gap) {
            unset($gap['signature']);
            $gap['start_time'] = InstrumentCoverageClockAnalyzer::clock((int) $gap['start_minute']);
            $gap['end_time'] = InstrumentCoverageClockAnalyzer::clock((int) $gap['end_minute']);
            $gap['time_label'] = $gap['start_time'] . '–' . $gap['end_time'];
            $gap['visual_indexes'] = array_values($gap['visual_indexes']);
            $gap['visual_start_index'] = $gap['visual_indexes'][0] ?? 0;
        }
        unset($gap);
        return array_slice($gaps, 0, max(1, $limit));
    }

    private static function close(array &$gaps, ?array &$open): void {
        if ($open === null) return;
        $gaps[] = $open;
        $open = null;
    }
}
