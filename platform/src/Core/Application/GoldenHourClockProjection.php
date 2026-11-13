<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;

/**
 * Convert finance-owned Golden Hour policy periods into UTC occurrences for the
 * coverage clock. No region or country label crosses this boundary: the coin is
 * a 24-hour clock in one display timezone.
 *
 * The structural bonus policy remains the sole owner of start/end/weekdays and
 * timezone. A disabled bonus is still shown as a Golden Hour designation, exactly
 * as the existing coverage projection does.
 */
final class GoldenHourClockProjection {
    /**
     * @param array<string,mixed> $policy Resolved golden-hour policy (global Iran key by default).
     * @return list<array{starts_at_utc:string,ends_at_utc:string,start:string,end:string,date:string,timezone:string}>
     */
    public static function forDate(array $policy, string $date): array {
        $timezone = self::timezone((string) ($policy['timezone'] ?? GoldenHourRule::GLOBAL_IRAN_TIMEZONE));
        $weekday = (int) (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->format('N');
        $rules = is_array($policy['rules'] ?? null) ? array_values(array_filter($policy['rules'], 'is_array')) : array();
        if ($rules) {
            $periods = array();
            foreach ($rules as $rule) {
                $ruleWeekdays = array_values(array_unique(array_map('intval', (array) ($rule['weekdays'] ?? array()))));
                if ($ruleWeekdays && !in_array($weekday, $ruleWeekdays, true)) continue;
                if (!isset($rule['start'], $rule['end'])) continue;
                $periods[] = array('start' => (string) $rule['start'], 'end' => (string) $rule['end']);
            }
            if (!$periods) return array();
        } else {
            if (!empty($policy['rules_configured'])) return array();
            $weekdays = array_values(array_unique(array_map('intval', (array) ($policy['weekdays'] ?? array(1,2,3,4,5,6,7)))));
            if ($weekdays && !in_array($weekday, $weekdays, true)) return array();
            $periods = self::periods($policy);
        }
        $out = array();
        foreach ($periods as $period) {
            try {
                $start = AvailabilityLocalTime::wall($date, self::wall($period['start']), $timezone);
                $endMinute = self::minute($period['end']);
                $startMinute = self::minute($period['start']);
                if ($endMinute <= $startMinute) {
                    $endDate = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
                    $end = $endMinute === 1440
                        ? AvailabilityLocalTime::wall($endDate, '00:00:00', $timezone)
                        : AvailabilityLocalTime::wall($endDate, self::wall($period['end']), $timezone);
                } else {
                    $end = AvailabilityLocalTime::wall($date, self::wall($period['end']), $timezone);
                }
            } catch (\Throwable) {
                // A DST gap or fold is not a schedulable designation. Do not invent an instant.
                continue;
            }
            if ($end <= $start) continue;
            $utc = new \DateTimeZone('UTC');
            $out[] = array(
                'starts_at_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                'ends_at_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
                'start' => (string) $period['start'],
                'end' => (string) $period['end'],
                'date' => $date,
                'timezone' => $timezone,
            );
        }
        return $out;
    }

    /**
     * Occurrences for a display date. Adjacent policy dates are included because a
     * window can cross midnight or a display-timezone boundary.
     *
     * @param array<string,mixed> $policy
     * @return list<array{starts_at_utc:string,ends_at_utc:string,start:string,end:string,date:string,timezone:string}>
     */
    public static function aroundDate(array $policy, string $date): array {
        $day = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        $out = array();
        for ($offset = -1; $offset <= 1; $offset++) {
            $candidate = $day->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
            foreach (self::forDate($policy, $candidate) as $occurrence) $out[] = $occurrence;
        }
        usort($out, static fn(array $a, array $b): int => $a['starts_at_utc'] <=> $b['starts_at_utc']);
        return $out;
    }

    /** @param array<string,mixed> $policy @return list<array{start:string,end:string}> */
    private static function periods(array $policy): array {
        $periods = array();
        foreach ((array) ($policy['periods'] ?? array()) as $period) {
            if (is_array($period) && isset($period['start'], $period['end'])) $periods[] = array('start' => (string) $period['start'], 'end' => (string) $period['end']);
        }
        if (!$periods) $periods[] = array('start' => (string) ($policy['start'] ?? '16:00'), 'end' => (string) ($policy['end'] ?? '21:00'));
        return array_values(array_filter($periods, static function (array $period): bool {
            try { self::minute($period['start']); self::minute($period['end']); return true; } catch (\Throwable) { return false; }
        }));
    }

    private static function minute(string $time): int {
        $time = trim($time);
        if ($time === '24:00' || $time === '24:00:00') return 1440;
        if (preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?$/D', $time, $matches) !== 1) throw new \InvalidArgumentException('golden_hour_window_invalid');
        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function wall(string $time): string {
        $minute = self::minute($time);
        if ($minute >= 1440) return '24:00:00';
        return sprintf('%02d:%02d:00', intdiv($minute, 60), $minute % 60);
    }

    private static function timezone(string $timezone): string {
        try { if ($timezone === '') throw new \Throwable(); new \DateTimeZone($timezone); return $timezone; }
        catch (\Throwable) { return GoldenHourRule::GLOBAL_IRAN_TIMEZONE; }
    }
}
