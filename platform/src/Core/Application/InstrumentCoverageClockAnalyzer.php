<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure 24-hour instrument-coverage projection.
 *
 * The analyzer receives already-resolved, authoritative availability and occupancy
 * facts. It never reads the database and never decides whether a booking is valid:
 * it only counts Teachers whose complete lesson-plus-buffer interval is still
 * available. The booking-time services remain the scheduling authority.
 *
 * A 15-minute segment is therefore "covered" only when at least one eligible
 * Teacher can accept the *entire* required interval starting at that segment.
 * A free 15-minute hole is never presented as a bookable class.
 */
final class InstrumentCoverageClockAnalyzer {
    public const SLOT_MINUTES = 15;
    public const SEGMENTS_PER_DAY = 96;
    public const VISUAL_SLOT_MINUTES = 30;
    public const VISUAL_SEGMENTS_PER_DAY = 48;

    public const MODE_WEEKLY = 'weekly';
    public const MODE_DATE = 'date';

    public const STATE_UNCOVERED = 'uncovered';
    public const STATE_LIMITED = 'limited';
    public const STATE_COVERED = 'covered';
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_PARTIAL = 'partial';

    public const REASON_NONE = '';
    public const REASON_NO_AVAILABILITY = 'no_availability';
    public const REASON_FULLY_BOOKED = 'fully_booked';
    public const REASON_FULLY_HELD = 'fully_held';
    public const REASON_ONE_TEACHER = 'one_teacher';
    public const REASON_PARTIAL_15_MINUTE = 'partial_15_minute';
    public const REASON_NONEXISTENT_LOCAL_TIME = 'nonexistent_local_time';
    public const REASON_UNKNOWN_DATA = 'unknown_data';

    /**
     * @param array{
     *   mode:string,date:string,weekday:int,display_timezone:string,
     *   lesson_minutes:int,buffer_minutes:int,
     *   golden?:list<array{starts_at_utc:string,ends_at_utc:string}>,
     *   teachers?:list<array{id:int,available?:list<array{state:string,starts_at_utc:string,ends_at_utc:string}>,occupied?:list<array{starts_at_utc:string,occupied_ends_at_utc:string}>,holds?:list<array{starts_at_utc:string,ends_at_utc:string}>}>,
     *   unknown_teacher_count?:int,now_utc?:string
     * } $day
     * @return array<string,mixed>
     */
    public static function build(array $day): array {
        $mode = (string) ($day['mode'] ?? self::MODE_WEEKLY);
        if (!in_array($mode, array(self::MODE_WEEKLY, self::MODE_DATE), true)) throw new \InvalidArgumentException('coverage_clock_mode_invalid');
        $date = AvailabilityLocalTime::date((string) ($day['date'] ?? ''));
        $weekday = (int) ($day['weekday'] ?? 0);
        if ($weekday < 1 || $weekday > 7) throw new \InvalidArgumentException('coverage_clock_weekday_invalid');
        $timezone = self::timezone((string) ($day['display_timezone'] ?? ''));
        $lesson = (int) ($day['lesson_minutes'] ?? 0);
        $buffer = (int) ($day['buffer_minutes'] ?? 0);
        if ($lesson < 5 || $lesson > 480 || $buffer < 0 || $buffer > 240) throw new \InvalidArgumentException('coverage_clock_lesson_invalid');
        $unknown = max(0, (int) ($day['unknown_teacher_count'] ?? 0));
        $now = self::utc((string) ($day['now_utc'] ?? gmdate('Y-m-d H:i:s'))) ?? gmdate('Y-m-d H:i:s');
        $golden = self::goldenOccurrences((array) ($day['golden'] ?? array()));
        $teachers = self::teachers((array) ($day['teachers'] ?? array()));

        $segments = array();
        $measured = 0;
        $covered = 0;
        $limited = 0;
        $uncovered = 0;
        $unknownSegments = 0;
        $goldenCount = 0;
        $goldenCovered = 0;
        $goldenUncovered = 0;
        $singleMinutes = 0;
        $uncoveredMinutes = 0;
        $goldenUncoveredMinutes = 0;
        $activeTeachers = array();
        $allMeasured = 0; $allCovered = 0; $allLimited = 0; $allUncovered = 0; $allUnknownSegments = 0;
        $allGoldenCount = 0; $allGoldenCovered = 0; $allGoldenUncovered = 0;
        $allSingleMinutes = 0; $allUncoveredMinutes = 0; $allGoldenUncoveredMinutes = 0;

        for ($index = 0; $index < self::SEGMENTS_PER_DAY; $index++) {
            $minute = $index * self::SLOT_MINUTES;
            $time = self::clock($minute);
            $startWall = null;
            try {
                $startWall = AvailabilityLocalTime::wall($date, $time . ':00', $timezone);
            } catch (\Throwable) {
                // A nonexistent or ambiguous local wall time cannot identify one UTC
                // interval. Never guess an occurrence across a DST transition.
            }
            $startUtc = $startWall?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $segmentEndUtc = $startWall?->modify('+' . self::SLOT_MINUTES . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $occupiedEndUtc = $startWall?->modify('+' . ($lesson + $buffer) . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $isGolden = $startUtc !== null && self::overlapsGolden($golden, $startUtc, $segmentEndUtc);
            $isPast = $mode === self::MODE_DATE && $segmentEndUtc !== null && $segmentEndUtc <= $now;

            $declared = 0;
            $available = 0;
            $booked = 0;
            $held = 0;
            if ($startUtc !== null && $occupiedEndUtc !== null) {
                foreach ($teachers as $teacher) {
                    if (!self::covers($teacher['available'], $startUtc, $occupiedEndUtc)) continue;
                    $declared++;
                    if ($mode === self::MODE_DATE) {
                        if (self::overlaps($teacher['occupied'], $startUtc, $occupiedEndUtc, 'occupied_ends_at_utc')) { $booked++; continue; }
                        if (self::overlaps($teacher['holds'], $startUtc, $occupiedEndUtc, 'ends_at_utc')) { $held++; continue; }
                    }
                    $available++;
                    $activeTeachers[$teacher['id']] = true;
                }
            }

            $reason = self::REASON_NONE;
            if ($startUtc === null) {
                $state = self::STATE_UNKNOWN;
                $reason = self::REASON_NONEXISTENT_LOCAL_TIME;
            } elseif ($available >= 2) {
                $state = self::STATE_COVERED;
            } elseif ($available === 1) {
                $state = self::STATE_LIMITED;
                $reason = self::REASON_ONE_TEACHER;
            } elseif ($declared > 0) {
                $state = self::STATE_UNCOVERED;
                $reason = $held > 0 && $booked === 0 ? self::REASON_FULLY_HELD : self::REASON_FULLY_BOOKED;
            } elseif ($unknown > 0) {
                $state = self::STATE_UNKNOWN;
                $reason = self::REASON_UNKNOWN_DATA;
            } else {
                $state = self::STATE_UNCOVERED;
                $reason = self::REASON_NO_AVAILABILITY;
            }

            if (!$isPast) {
                $measured++;
                if ($state === self::STATE_COVERED) $covered++;
                elseif ($state === self::STATE_LIMITED) { $covered++; $limited++; $singleMinutes += self::SLOT_MINUTES; }
                elseif ($state === self::STATE_UNKNOWN) $unknownSegments++;
                else $uncovered++;
            }
            if ($state === self::STATE_UNCOVERED) {
                if (!$isPast) {
                    $uncoveredMinutes += self::SLOT_MINUTES;
                    if ($isGolden) $goldenUncoveredMinutes += self::SLOT_MINUTES;
                }
            }
            if ($isGolden && !$isPast) {
                $goldenCount++;
                if ($state === self::STATE_COVERED || $state === self::STATE_LIMITED) $goldenCovered++;
                elseif ($state === self::STATE_UNCOVERED) $goldenUncovered++;
            }
            $allMeasured++;
            if ($state === self::STATE_COVERED) $allCovered++;
            elseif ($state === self::STATE_LIMITED) { $allCovered++; $allLimited++; $allSingleMinutes += self::SLOT_MINUTES; }
            elseif ($state === self::STATE_UNKNOWN) $allUnknownSegments++;
            else $allUncovered++;
            if ($state === self::STATE_UNCOVERED) {
                $allUncoveredMinutes += self::SLOT_MINUTES;
                if ($isGolden) $allGoldenUncoveredMinutes += self::SLOT_MINUTES;
            }
            if ($isGolden) {
                $allGoldenCount++;
                if ($state === self::STATE_COVERED || $state === self::STATE_LIMITED) $allGoldenCovered++;
                elseif ($state === self::STATE_UNCOVERED) $allGoldenUncovered++;
            }

            $segments[] = array(
                'index' => $index,
                'minute' => $minute,
                'time' => $time,
                'end_time' => self::clock($minute + self::SLOT_MINUTES),
                'starts_at_utc' => $startUtc,
                'ends_at_utc' => $segmentEndUtc,
                'occupied_ends_at_utc' => $occupiedEndUtc,
                'state' => $state,
                'golden' => $isGolden,
                'past' => $isPast,
                'teacher_count' => $available,
                'declared_teacher_count' => $declared,
                'booked_teacher_count' => $booked,
                'held_teacher_count' => $held,
                'bookable' => $state === self::STATE_COVERED || $state === self::STATE_LIMITED,
                'reason' => $reason,
            );
        }

        return array(
            'status' => $unknown > 0 ? 'partial' : 'ok',
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'display_timezone' => $timezone,
            'lesson_minutes' => $lesson,
            'buffer_minutes' => $buffer,
            'slot_minutes' => self::SLOT_MINUTES,
            'timezone_label' => self::timezoneLabel($timezone),
            'date_label' => self::gregorianLabel($date),
            'date_label_fa' => self::persianLabel($date),
            'segments' => $segments,
            'summary' => array(
                'measured_segments' => $measured,
                'covered_segments' => $covered,
                'limited_segments' => $limited,
                'uncovered_segments' => $uncovered,
                'unknown_segments' => $unknownSegments,
                'coverage_percent' => $measured > 0 ? (int) round(100 * $covered / $measured) : null,
                'golden_segments' => $goldenCount,
                'golden_covered_segments' => $goldenCovered,
                'golden_uncovered_segments' => $goldenUncovered,
                'golden_coverage_percent' => $goldenCount > 0 ? (int) round(100 * $goldenCovered / $goldenCount) : null,
                'single_teacher_minutes' => $singleMinutes,
                'uncovered_minutes' => $uncoveredMinutes,
                'golden_uncovered_minutes' => $goldenUncoveredMinutes,
                'active_teacher_count' => count($activeTeachers),
                'unknown_teacher_count' => $unknown,
                'all_measured_segments' => $allMeasured,
                'all_covered_segments' => $allCovered,
                'all_limited_segments' => $allLimited,
                'all_uncovered_segments' => $allUncovered,
                'all_unknown_segments' => $allUnknownSegments,
                'all_coverage_percent' => $allMeasured > 0 ? (int) round(100 * $allCovered / $allMeasured) : null,
                'all_golden_segments' => $allGoldenCount,
                'all_golden_covered_segments' => $allGoldenCovered,
                'all_golden_uncovered_segments' => $allGoldenUncovered,
                'all_golden_coverage_percent' => $allGoldenCount > 0 ? (int) round(100 * $allGoldenCovered / $allGoldenCount) : null,
                'all_single_teacher_minutes' => $allSingleMinutes,
                'all_uncovered_minutes' => $allUncoveredMinutes,
                'all_golden_uncovered_minutes' => $allGoldenUncoveredMinutes,
            ),
            'gaps' => self::ranges($segments, static fn(array $segment): bool => $segment['state'] === self::STATE_UNCOVERED),
            'golden_gaps' => self::ranges($segments, static fn(array $segment): bool => $segment['state'] === self::STATE_UNCOVERED && $segment['golden']),
            'selected_segment' => null,
        );
    }

    /**
     * Aggregate the authoritative 15-minute analysis into 48 visual 30-minute
     * segments. No booking precision is lost: each visual segment carries both
     * halves and a partial state when the halves differ. The ring visualisation is
     * presentation only; the full interval validation remains in `build()`.
     *
     * @param array<string,mixed> $clock
     * @return list<array<string,mixed>>
     */
    public static function visualize(array $clock): array {
        $source = array_values((array) ($clock['segments'] ?? array()));
        $visual = array();
        for ($index = 0; $index < self::VISUAL_SEGMENTS_PER_DAY; $index++) {
            $first = $source[$index * 2] ?? null;
            $second = $source[$index * 2 + 1] ?? null;
            if (!is_array($first) || !is_array($second)) continue;
            $firstBookable = in_array($first['state'], array(self::STATE_COVERED, self::STATE_LIMITED), true);
            $secondBookable = in_array($second['state'], array(self::STATE_COVERED, self::STATE_LIMITED), true);
            $firstCount = (int) ($first['teacher_count'] ?? 0);
            $secondCount = (int) ($second['teacher_count'] ?? 0);
            $firstState = (string) $first['state'];
            $secondState = (string) $second['state'];
            $unknown = $firstState === self::STATE_UNKNOWN || $secondState === self::STATE_UNKNOWN;
            if ($unknown) {
                $state = self::STATE_UNKNOWN;
            } elseif ($firstBookable && $secondBookable) {
                $state = min($firstCount, $secondCount) >= 2 ? self::STATE_COVERED : self::STATE_LIMITED;
            } elseif ($firstBookable || $secondBookable) {
                $state = self::STATE_PARTIAL;
            } else {
                $state = self::STATE_UNCOVERED;
            }
            $partial = $firstState !== $secondState || $firstCount !== $secondCount || ($firstBookable xor $secondBookable);
            $firstReason = (string) ($first['reason'] ?? '');
            $secondReason = (string) ($second['reason'] ?? '');
            if ($state === self::STATE_LIMITED) $reason = self::REASON_ONE_TEACHER;
            elseif ($state === self::STATE_PARTIAL) $reason = self::REASON_PARTIAL_15_MINUTE;
            elseif ($state === self::STATE_UNKNOWN) {
                $reason = in_array(self::REASON_UNKNOWN_DATA, array($firstReason, $secondReason), true)
                    ? self::REASON_UNKNOWN_DATA
                    : (in_array(self::REASON_NONEXISTENT_LOCAL_TIME, array($firstReason, $secondReason), true) ? self::REASON_NONEXISTENT_LOCAL_TIME : self::REASON_UNKNOWN_DATA);
            } elseif (in_array(self::REASON_FULLY_HELD, array($firstReason, $secondReason), true)) {
                $reason = self::REASON_FULLY_HELD;
            } elseif (in_array(self::REASON_FULLY_BOOKED, array($firstReason, $secondReason), true)) {
                $reason = self::REASON_FULLY_BOOKED;
            } elseif ($state === self::STATE_UNCOVERED) {
                $reason = self::REASON_NO_AVAILABILITY;
            } else {
                $reason = self::REASON_NONE;
            }
            $visual[] = array(
                'index' => $index,
                'minute' => $index * self::VISUAL_SLOT_MINUTES,
                'time' => self::clock($index * self::VISUAL_SLOT_MINUTES),
                'end_time' => self::clock(($index + 1) * self::VISUAL_SLOT_MINUTES),
                'starts_at_utc' => $first['starts_at_utc'],
                'ends_at_utc' => $second['ends_at_utc'],
                'state' => $state,
                'partial' => $partial,
                'golden' => !empty($first['golden']) || !empty($second['golden']),
                'golden_partial' => !empty($first['golden']) !== !empty($second['golden']),
                'past' => !empty($first['past']) && !empty($second['past']),
                'bookable' => in_array($state, array(self::STATE_COVERED, self::STATE_LIMITED, self::STATE_PARTIAL), true),
                'teacher_count' => max($firstCount, $secondCount),
                'min_teacher_count' => min($firstCount, $secondCount),
                'max_teacher_count' => max($firstCount, $secondCount),
                'declared_teacher_count' => max((int) ($first['declared_teacher_count'] ?? 0), (int) ($second['declared_teacher_count'] ?? 0)),
                'booked_teacher_count' => max((int) ($first['booked_teacher_count'] ?? 0), (int) ($second['booked_teacher_count'] ?? 0)),
                'held_teacher_count' => max((int) ($first['held_teacher_count'] ?? 0), (int) ($second['held_teacher_count'] ?? 0)),
                'reason' => $reason,
                'first_reason' => $firstReason,
                'second_reason' => $secondReason,
                'first_state' => $firstState,
                'second_state' => $secondState,
                'first_teacher_count' => $firstCount,
                'second_teacher_count' => $secondCount,
                'first_booked_count' => (int) ($first['booked_teacher_count'] ?? 0),
                'second_booked_count' => (int) ($second['booked_teacher_count'] ?? 0),
                'first_held_count' => (int) ($first['held_teacher_count'] ?? 0),
                'second_held_count' => (int) ($second['held_teacher_count'] ?? 0),
                'first_golden' => !empty($first['golden']),
                'second_golden' => !empty($second['golden']),
                'first_past' => !empty($first['past']),
                'second_past' => !empty($second['past']),
            );
        }
        return $visual;
    }

    /** @param list<array<string,mixed>> $segments */
    private static function ranges(array $segments, callable $matches): array {
        $ranges = array();
        $open = null;
        foreach ($segments as $segment) {
            if ($matches($segment)) {
                if ($open !== null && $open['end_minute'] === (int) $segment['minute']) {
                    $open['end_minute'] = (int) $segment['minute'] + self::SLOT_MINUTES;
                    $open['minutes'] += self::SLOT_MINUTES;
                } else {
                    if ($open !== null) $ranges[] = $open;
                    $open = array(
                        'start_minute' => (int) $segment['minute'],
                        'end_minute' => (int) $segment['minute'] + self::SLOT_MINUTES,
                        'minutes' => self::SLOT_MINUTES,
                    );
                }
            } elseif ($open !== null) {
                $ranges[] = $open;
                $open = null;
            }
        }
        if ($open !== null) $ranges[] = $open;
        foreach ($ranges as &$range) {
            $range['start'] = self::clock((int) $range['start_minute']);
            $range['end'] = self::clock((int) $range['end_minute']);
            $range['label'] = $range['start'] . '–' . $range['end'];
        }
        unset($range);
        usort($ranges, static fn(array $a, array $b): int => $b['minutes'] <=> $a['minutes'] ?: $a['start_minute'] <=> $b['start_minute']);
        return $ranges;
    }

    /** @param list<array<string,mixed>> $teachers */
    private static function teachers(array $teachers): array {
        $out = array();
        foreach ($teachers as $teacher) {
            if (!is_array($teacher)) continue;
            $id = (int) ($teacher['id'] ?? 0);
            if ($id < 1) continue;
            $available = array();
            foreach ((array) ($teacher['available'] ?? array()) as $segment) {
                if (!is_array($segment)) continue;
                $state = (string) ($segment['state'] ?? '');
                $start = self::utc((string) ($segment['starts_at_utc'] ?? ''));
                $end = self::utc((string) ($segment['ends_at_utc'] ?? ''));
                if ($start === null || $end === null || $end <= $start) continue;
                if (!in_array($state, array('preferred', 'requestable', 'blocked'), true)) continue;
                $available[] = array('state' => $state, 'starts_at_utc' => $start, 'ends_at_utc' => $end);
            }
            usort($available, static fn(array $a, array $b): int => $a['starts_at_utc'] <=> $b['starts_at_utc']);
            $occupied = array();
            foreach ((array) ($teacher['occupied'] ?? array()) as $interval) {
                if (!is_array($interval)) continue;
                $start = self::utc((string) ($interval['starts_at_utc'] ?? ''));
                $end = self::utc((string) ($interval['occupied_ends_at_utc'] ?? $interval['ends_at_utc'] ?? ''));
                if ($start === null || $end === null || $end <= $start) continue;
                $occupied[] = array('starts_at_utc' => $start, 'occupied_ends_at_utc' => $end);
            }
            $holds = array();
            foreach ((array) ($teacher['holds'] ?? array()) as $interval) {
                if (!is_array($interval)) continue;
                $start = self::utc((string) ($interval['starts_at_utc'] ?? ''));
                $end = self::utc((string) ($interval['ends_at_utc'] ?? $interval['occupied_ends_at_utc'] ?? ''));
                if ($start === null || $end === null || $end <= $start) continue;
                $holds[] = array('starts_at_utc' => $start, 'ends_at_utc' => $end);
            }
            $out[] = array('id' => $id, 'available' => $available, 'occupied' => $occupied, 'holds' => $holds);
        }
        return $out;
    }

    /**
     * Whole-interval availability only. A blocked segment, a gap, or a segment that
     * ends before the required occupied interval finishes all mean "not available".
     */
    private static function covers(array $segments, string $start, string $end): bool {
        // Defensive guard against unresolved overlapping facts: a blocked interval
        // anywhere in the candidate range always wins, even if a preferred fact
        // would otherwise appear to cover the range.
        foreach ($segments as $segment) {
            if ($segment['state'] !== 'blocked') continue;
            if ($segment['starts_at_utc'] < $end && $segment['ends_at_utc'] > $start) return false;
        }
        $cursor = $start;
        foreach ($segments as $segment) {
            if ($segment['ends_at_utc'] <= $cursor) continue;
            if ($segment['starts_at_utc'] > $cursor) return false;
            if ($segment['ends_at_utc'] > $cursor) $cursor = min($end, $segment['ends_at_utc']);
            if ($cursor >= $end) return true;
        }
        return false;
    }

    /** @param list<array<string,string>> $intervals */
    private static function overlaps(array $intervals, string $start, string $end, string $endKey): bool {
        foreach ($intervals as $interval) {
            $existingStart = (string) ($interval['starts_at_utc'] ?? '');
            $existingEnd = (string) ($interval[$endKey] ?? '');
            if ($existingStart === '' || $existingEnd === '') continue;
            if ($existingStart < $end && $existingEnd > $start) return true;
        }
        return false;
    }

    /** @param list<array{starts_at_utc:string,ends_at_utc:string}> $golden */
    private static function overlapsGolden(array $golden, ?string $start, ?string $end): bool {
        if ($start === null || $end === null) return false;
        foreach ($golden as $window) {
            if ($window['starts_at_utc'] < $end && $window['ends_at_utc'] > $start) return true;
        }
        return false;
    }

    /** @return list<array{starts_at_utc:string,ends_at_utc:string}> */
    private static function goldenOccurrences(array $windows): array {
        $out = array();
        foreach ($windows as $window) {
            if (!is_array($window)) continue;
            $start = self::utc((string) ($window['starts_at_utc'] ?? ''));
            $end = self::utc((string) ($window['ends_at_utc'] ?? ''));
            if ($start === null || $end === null || $end <= $start) continue;
            $out[] = array('starts_at_utc' => $start, 'ends_at_utc' => $end);
        }
        usort($out, static fn(array $a, array $b): int => $a['starts_at_utc'] <=> $b['starts_at_utc']);
        return $out;
    }

    private static function timezone(string $timezone): string {
        try {
            if ($timezone === '') throw new \Throwable();
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('coverage_clock_timezone_invalid');
        }
    }

    private static function utc(string $value): ?string {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    public static function clock(int $minute): string {
        if ($minute >= 1440) return '24:00';
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private static function timezoneLabel(string $timezone): string {
        return str_replace('_', ' ', $timezone);
    }

    private static function gregorianLabel(string $date): string {
        $value = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        return $value->format('j M Y');
    }

    private static function persianLabel(string $date): string {
        if (!class_exists('\\Delnavazan\\Platform\\Core\\Support\\PersianCalendar')) return '';
        $value = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        [$year, $month, $day] = \Delnavazan\Platform\Core\Support\PersianCalendar::toJalali((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));
        return \Delnavazan\Platform\Core\Support\PersianCalendar::PERSIAN_MONTHS[$month] . ' ' . $year;
    }
}
