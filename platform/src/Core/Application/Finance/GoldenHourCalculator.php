<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Pure golden-hour interval and bonus calculation.
 *
 * The lesson interval is canonical UTC. Each window is a wall-clock definition in the service
 * area's IANA timezone, so daylight-saving transitions shift the UTC window exactly as they shift
 * the coverage dashboard. Boundaries are half-open (`[start, end)`); overlapping windows are merged
 * before money is calculated and the highest percentage wins each minute, so bonuses never stack.
 */
final class GoldenHourCalculator {
    /**
     * @param list<array{weekdays:list<int>,start:string,end:string,bonus_basis_points:int}> $windows
     * @return array{total_seconds:int,eligible_seconds:int,eligible_minutes:int,seconds_by_basis_points:array<int,int>,highest_basis_points:int}
     */
    public static function eligibility(string $startsAtUtc, string $endsAtUtc, array $windows, string $timezone): array {
        if (!GoldenHourRule::utc($startsAtUtc) || !GoldenHourRule::utc($endsAtUtc) || $endsAtUtc <= $startsAtUtc) {
            throw new \InvalidArgumentException('golden_hour_interval_invalid');
        }
        $utc = new \DateTimeZone('UTC');
        $lessonStart = (new \DateTimeImmutable($startsAtUtc, $utc))->getTimestamp();
        $lessonEnd = (new \DateTimeImmutable($endsAtUtc, $utc))->getTimestamp();
        $totalSeconds = max(1, $lessonEnd - $lessonStart);
        if ($windows === array()) {
            return array('total_seconds'=>$totalSeconds,'eligible_seconds'=>0,'eligible_minutes'=>0,'seconds_by_basis_points'=>array(),'highest_basis_points'=>0);
        }
        $intervals = array();
        // Each window carries its own service-area or global timezone. Iterating them separately is
        // required when an enrolment-area window and the global Iran window use different zones.
        foreach ($windows as $window) {
            $windowTimezone = (string) ($window['timezone'] ?? $timezone);
            if ($windowTimezone === '' || !in_array($windowTimezone, timezone_identifiers_list(), true)) {
                throw new \InvalidArgumentException('golden_hour_timezone_invalid');
            }
            $basisPoints = (int) ($window['bonus_basis_points'] ?? 0);
            if ($basisPoints <= 0) continue;
            $zone = new \DateTimeZone($windowTimezone);
            $firstLocalDate = (new \DateTimeImmutable('@'.$lessonStart))->setTimezone($zone)->setTime(0, 0)->modify('-1 day');
            $lastLocalDate = (new \DateTimeImmutable('@'.$lessonEnd))->setTimezone($zone)->setTime(0, 0)->modify('+1 day');
            for ($date = $firstLocalDate; $date <= $lastLocalDate; $date = $date->modify('+1 day')) {
                $weekday = (int) $date->format('N');
                $weekdays = array_map('intval', (array) ($window['weekdays'] ?? array()));
                if (!in_array($weekday, $weekdays, true)) continue;
                $windowStart = self::wall($date->format('Y-m-d'), (string) $window['start'], $zone);
                $windowEnd = self::wall($date->format('Y-m-d'), (string) $window['end'], $zone);
                $startTs = max($lessonStart, $windowStart->getTimestamp());
                $endTs = min($lessonEnd, $windowEnd->getTimestamp());
                if ($endTs <= $startTs) continue; // Half-open [start, end): exact end is not eligible.
                $intervals[] = array($startTs, $endTs, $basisPoints);
            }
        }
        if ($intervals === array()) {
            return array('total_seconds'=>$totalSeconds,'eligible_seconds'=>0,'eligible_minutes'=>0,'seconds_by_basis_points'=>array(),'highest_basis_points'=>0);
        }
        $boundaries = array();
        foreach ($intervals as $interval) { $boundaries[$interval[0]] = true; $boundaries[$interval[1]] = true; }
        $boundaries = array_keys($boundaries);
        sort($boundaries, SORT_NUMERIC);
        $secondsByBasisPoints = array();
        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            $segmentStart = (int) $boundaries[$index];
            $segmentEnd = (int) $boundaries[$index + 1];
            if ($segmentEnd <= $segmentStart) continue;
            $highest = 0;
            foreach ($intervals as $interval) {
                if ($interval[0] <= $segmentStart && $interval[1] >= $segmentEnd) $highest = max($highest, $interval[2]);
            }
            if ($highest <= 0) continue;
            $secondsByBasisPoints[$highest] = ($secondsByBasisPoints[$highest] ?? 0) + ($segmentEnd - $segmentStart);
        }
        ksort($secondsByBasisPoints, SORT_NUMERIC);
        $eligibleSeconds = array_sum($secondsByBasisPoints);
        return array(
            'total_seconds' => $totalSeconds,
            'eligible_seconds' => $eligibleSeconds,
            'eligible_minutes' => (int) round($eligibleSeconds / 60),
            'seconds_by_basis_points' => $secondsByBasisPoints,
            'highest_basis_points' => $secondsByBasisPoints === array() ? 0 : (int) max(array_keys($secondsByBasisPoints)),
        );
    }

    /**
     * For a fixed per-session amount, the equivalent hourly rule is expressed proportionally:
     * bonus = session base x eligible payable seconds / total payable seconds x percentage.
     *
     * @param list<array{weekdays:list<int>,start:string,end:string,bonus_basis_points:int}> $windows
     * @return array{total_seconds:int,eligible_seconds:int,eligible_minutes:int,seconds_by_basis_points:array<int,int>,highest_basis_points:int,bonus_minor:int,total_minor:int,breakdown:list<array{basis_points:int,seconds:int,minutes:int,bonus_minor:int}>}
     */
    public static function calculate(int $baseAmountMinor, string $startsAtUtc, string $endsAtUtc, array $windows, string $timezone): array {
        if ($baseAmountMinor < 0) throw new \InvalidArgumentException('golden_hour_amount_invalid');
        $eligibility = self::eligibility($startsAtUtc, $endsAtUtc, $windows, $timezone);
        $denominator = $eligibility['total_seconds'] * GoldenHourRule::BASIS_POINTS_SCALE;
        $bonus = 0;
        $breakdown = array();
        foreach ($eligibility['seconds_by_basis_points'] as $basisPoints => $seconds) {
            $groupBonus = GoldenHourRule::mulDivRound($baseAmountMinor, ((int) $seconds) * ((int) $basisPoints), $denominator);
            $bonus += $groupBonus;
            $breakdown[] = array(
                'basis_points' => (int) $basisPoints,
                'seconds' => (int) $seconds,
                'minutes' => (int) round(((int) $seconds) / 60),
                'bonus_minor' => $groupBonus,
            );
        }
        return $eligibility + array('bonus_minor'=>$bonus,'total_minor'=>$baseAmountMinor + $bonus,'breakdown'=>$breakdown);
    }

    private static function wall(string $date, string $time, \DateTimeZone $zone): \DateTimeImmutable {
        if ($time === '24:00') return (new \DateTimeImmutable($date.' 00:00:00', $zone))->modify('+1 day');
        return new \DateTimeImmutable($date.' '.$time.':00', $zone);
    }
}
