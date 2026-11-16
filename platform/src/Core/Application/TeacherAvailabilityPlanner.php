<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure weekly-schedule arithmetic for the Teacher availability planner.
 *
 * The planner is the only place where a Teacher draws weekly ranges. This class
 * owns validation and the overview formulas so the service, the portal model and
 * the tests cannot drift apart. It never reads or writes the database.
 */
final class TeacherAvailabilityPlanner {
    public const STEP_MINUTES = 15;

    /**
     * Validate and normalise ranges from the planner.
     *
     * @param list<array<string,mixed>> $ranges
     * @return list<array{weekday:int,start:int,end:int}>
     */
    public static function normalizeRanges(array $ranges): array {
        $normalized = array();
        foreach ($ranges as $range) {
            if (!is_array($range)) throw new \InvalidArgumentException('Availability range must be an object');
            $weekday = (int) ($range['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7) throw new \InvalidArgumentException('Availability weekday must be between 1 and 7');
            $start = self::minute($range['start'] ?? $range['local_start_time'] ?? '');
            $end = self::minute($range['end'] ?? $range['local_end_time'] ?? '');
            if ($start === null || $end === null) throw new \InvalidArgumentException('Availability time must be a local wall-clock time');
            if ($start % self::STEP_MINUTES !== 0 || $end % self::STEP_MINUTES !== 0) throw new \InvalidArgumentException('Availability time must align to 15 minutes');
            if ($end <= $start) throw new \InvalidArgumentException('Availability end must follow start on the same weekday');
            $normalized[] = array('weekday' => $weekday, 'start' => $start, 'end' => $end);
        }
        usort($normalized, static fn(array $a, array $b): int => ($a['weekday'] <=> $b['weekday']) ?: ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
        $lastByDay = array();
        foreach ($normalized as $range) {
            $weekday = (int) $range['weekday'];
            if (isset($lastByDay[$weekday]) && $range['start'] < $lastByDay[$weekday]) {
                throw new \InvalidArgumentException('Availability ranges must not overlap on the same weekday');
            }
            $lastByDay[$weekday] = (int) $range['end'];
        }
        return $normalized;
    }

    /** @return list<array{weekday:int,start:int,end:int}> */
    public static function fromRules(array $rules): array {
        $ranges = array();
        foreach ($rules as $rule) {
            $row = is_object($rule) ? get_object_vars($rule) : (array) $rule;
            $start = self::minute((string) ($row['local_start_time'] ?? $row['start'] ?? ''));
            $end = self::minute((string) ($row['local_end_time'] ?? $row['end'] ?? ''));
            $weekday = (int) ($row['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7 || $start === null || $end === null || $end <= $start) continue;
            $ranges[] = array('weekday' => $weekday, 'start' => $start, 'end' => $end);
        }
        usort($ranges, static fn(array $a, array $b): int => ($a['weekday'] <=> $b['weekday']) ?: ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
        return $ranges;
    }

    /** @param list<array{start:int,end:int}> $intervals @return list<array{start:int,end:int}> */
    public static function mergeIntervals(array $intervals): array {
        $intervals = array_values(array_filter($intervals, static fn(array $interval): bool => (int) ($interval['end'] ?? 0) > (int) ($interval['start'] ?? 0)));
        usort($intervals, static fn(array $a, array $b): int => ((int) $a['start'] <=> (int) $b['start']) ?: ((int) $a['end'] <=> (int) $b['end']));
        $merged = array();
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && (int) $interval['start'] <= (int) $merged[$last]['end']) {
                $merged[$last]['end'] = max((int) $merged[$last]['end'], (int) $interval['end']);
            } else {
                $merged[] = array('start' => (int) $interval['start'], 'end' => (int) $interval['end']);
            }
        }
        return $merged;
    }

    /**
     * Union of one or more instrument schedules, grouped by weekday. Overlapping
     * hours across instruments count once because a Teacher can only teach one
     * class at a time.
     *
     * @param array<int,array<int,list<array{start:int,end:int}>>> $schedules instrument id => weekday => intervals
     * @return array<int,list<array{start:int,end:int}>>
     */
    public static function unionByWeekday(array $schedules): array {
        $byDay = array();
        foreach ($schedules as $days) {
            foreach ((array) $days as $weekday => $intervals) {
                $weekday = (int) $weekday;
                if ($weekday < 1 || $weekday > 7) continue;
                foreach ((array) $intervals as $interval) {
                    if (!is_array($interval)) continue;
                    $start = (int) ($interval['start'] ?? -1);
                    $end = (int) ($interval['end'] ?? -1);
                    if ($start < 0 || $end <= $start) continue;
                    $byDay[$weekday][] = array('start' => $start, 'end' => $end);
                }
            }
        }
        ksort($byDay);
        foreach ($byDay as $weekday => $intervals) $byDay[$weekday] = self::mergeIntervals($intervals);
        return $byDay;
    }

    /** @param array<int,list<array{start:int,end:int}>> $byWeekday */
    public static function totalMinutes(array $byWeekday): int {
        $total = 0;
        foreach ($byWeekday as $intervals) foreach (self::mergeIntervals((array) $intervals) as $interval) $total += (int) $interval['end'] - (int) $interval['start'];
        return $total;
    }

    /**
     * @param array<int,list<array{start:int,end:int}>> $allocation
     * @param array<int,list<array{start:int,end:int}>> $windows
     */
    public static function overlapMinutes(array $allocation, array $windows): int {
        $total = 0;
        foreach ($allocation as $weekday => $intervals) {
            foreach (self::mergeIntervals((array) $intervals) as $a) {
                foreach (self::mergeIntervals((array) ($windows[(int) $weekday] ?? array())) as $b) {
                    $start = max((int) $a['start'], (int) $b['start']);
                    $end = min((int) $a['end'], (int) $b['end']);
                    if ($end > $start) $total += $end - $start;
                }
            }
        }
        return $total;
    }

    /**
     * Overview metrics. `allocated` is the union across instruments; `intake` is
     * already-clocked Teacher occupancy for the representative week.
     *
     * @param array<int,array<int,list<array{start:int,end:int}>>> $schedules
     * @param array<int,list<array{start:int,end:int}>> $goldenWindows
     * @return array{allocated_minutes:int,student_intake_minutes:int,available_minutes:int,golden_minutes:int}
     */
    public static function overview(array $schedules, array $goldenWindows, int $studentIntakeMinutes): array {
        $allocation = self::unionByWeekday($schedules);
        $allocated = self::totalMinutes($allocation);
        $intake = max(0, $studentIntakeMinutes);
        return array(
            'allocated_minutes' => $allocated,
            'student_intake_minutes' => $intake,
            'available_minutes' => max(0, $allocated - $intake),
            'golden_minutes' => self::overlapMinutes($allocation, $goldenWindows),
        );
    }

    public static function minute(string $value): ?int {
        $value = trim($value);
        if ($value === '24:00' || $value === '24:00:00') return 1440;
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $value) !== 1) return null;
        return ((int) substr($value, 0, 2)) * 60 + (int) substr($value, 3, 2);
    }

    public static function clock(int $minute): string {
        if ($minute >= 1440) return '24:00';
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /** @param list<array{start:int,end:int}> $intervals @return list<array{start:string,end:string}> */
    public static function clockIntervals(array $intervals): array {
        $out = array();
        foreach (self::mergeIntervals($intervals) as $interval) $out[] = array('start' => self::clock((int) $interval['start']), 'end' => self::clock((int) $interval['end']));
        return $out;
    }

    /**
     * Half-open interval overlap: two same-weekday windows conflict only when
     * each starts strictly before the other ends. Adjacent windows (end == next
     * start) never conflict, and a reversed or zero-length window never does.
     *
     * @param int $startA Minutes-from-midnight start of the first window.
     * @param int $endA   Minutes-from-midnight end of the first window.
     * @param int $startB Minutes-from-midnight start of the second window.
     * @param int $endB   Minutes-from-midnight end of the second window.
     */
    public static function overlaps(int $startA, int $endA, int $startB, int $endB): bool {
        if ($endA <= $startA || $endB <= $startB) return false;
        return $startA < $endB && $startB < $endA;
    }

    /**
     * Whether a newly submitted weekly window conflicts with one existing active
     * window on the same weekday.
     *
     * The comparison is idempotent (a window re-submitted against the same
     * logical rule never conflicts), timezone-normalised (both windows are
     * projected onto a fixed representative Monday so a timezone change can
     * never turn two genuinely distinct windows into a false overlap), and
     * half-open (adjacency is allowed).
     *
     * @param array{local_start_time:string,local_end_time:string,timezone:string} $submitted
     * @param array{local_start_time:string,local_end_time:string,timezone:string,state:string} $existing
     * @param bool $sameRule Whether $existing is the same logical rule as $submitted.
     */
    public static function recurringWindowConflict(array $submitted, array $existing, bool $sameRule): bool {
        if ($sameRule) return false;
        $a = self::windowMinutes((string) ($submitted['local_start_time'] ?? ''), (string) ($submitted['local_end_time'] ?? ''), (string) ($submitted['timezone'] ?? ''));
        $b = self::windowMinutes((string) ($existing['local_start_time'] ?? ''), (string) ($existing['local_end_time'] ?? ''), (string) ($existing['timezone'] ?? ''));
        // A reversed, zero-length or nonexistent local time is never an overlap;
        // the submitted window is rejected by the range validator before this point.
        if ($a === null || $b === null) return false;
        return self::overlaps($a['start'], $a['end'], $b['start'], $b['end']);
    }

    /**
     * Project one recurring weekly window onto a fixed representative Monday and
     * return its span as minutes since that Monday's UTC midnight. The shared
     * basis makes windows expressed in different IANA timezones comparable.
     *
     * @return array{start:int,end:int}|null
     */
    private static function windowMinutes(string $start, string $end, string $timezone): ?array {
        try {
            $interval = AvailabilityLocalTime::interval('2026-01-05', $start, $end, $timezone);
        } catch (\Throwable) {
            return null;
        }
        $origin = (new \DateTimeImmutable('2026-01-05 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $toMinute = static function (string $utc) use ($origin): int {
            return (int) (((new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() - $origin) / 60);
        };
        return array('start' => $toMinute($interval['starts_at_utc']), 'end' => $toMinute($interval['ends_at_utc']));
    }
}
