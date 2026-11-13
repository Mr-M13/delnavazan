<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityRepository;

/**
 * Read-only projection for the 24-hour instrument coverage clock.
 *
 * This service deliberately composes the existing authorities:
 * - TeacherAvailabilityService::effective() for dated availability, exceptions and holidays;
 * - TeacherAvailabilityService::recurring() for structural weekly coverage;
 * - BookingTeacherOccupancyReadService::range() for canonical lessons, legacy schedules,
 *   pending offers and confirmations;
 * - CanonicalContinuationCapacityAuthority::activeHoldsInRange() for active holds;
 * - GoldenHourPolicyService for the designation ring.
 *
 * It never writes and never becomes a second scheduling authority. All reads are
 * batched per Teacher and per range, not per 15-minute segment.
 */
final class InstrumentCoverageClockReadService {
    /** @var array<int,array<string,mixed>> */
    private array $instrumentCache = array();
    /** @var array<string,array<string,mixed>> */
    private array $factsCache = array();
    /** @var array<string,array<string,mixed>> */
    private array $goldenCache = array();

    private bool $optionReadFailed = false;
    private TeacherAvailabilityService $availability;
    private BookingTeacherOccupancyReadService $occupancy;

    public function __construct(?TeacherAvailabilityService $availability = null, ?BookingTeacherOccupancyReadService $occupancy = null) {
        $this->availability = $availability ?? new TeacherAvailabilityService();
        $this->occupancy = $occupancy ?? new BookingTeacherOccupancyReadService();
    }

    /**
     * Administrator overview. Defaults to actual bookable coverage for a date.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function admin(array $input = array()): array {
        $academyTimezone = self::safeTimezone((string) (BookingAvailabilityPolicy::current()['academy_timezone'] ?? 'Asia/Tehran'));
        $timezone = self::requestTimezone($input['timezone'] ?? null, $academyTimezone);
        $mode = 'week';
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $weekStart = self::date($input['week_start'] ?? null, $today);
        $date = self::date($input['date'] ?? null, $weekStart);
        if ($date < $weekStart || $date > InstrumentCoverageWeek::end($weekStart)) $weekStart = $date;
        $weekday = self::weekdayOf($date);
        return $this->projection(array(
            'scope' => 'admin',
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'week_start' => $weekStart,
            'timezone' => $timezone,
            'academy_timezone' => $academyTimezone,
            'instrument_id' => isset($input['instrument_id']) ? max(0, (int) $input['instrument_id']) : 0,
            'teacher_id' => 0,
            'calendar' => self::calendar($input['calendar'] ?? null),
        ));
    }

    /**
     * Teacher portal projection. Only instruments the Teacher is authorised to
     * teach are exposed, and only aggregate capacity is returned.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function teacher(int $teacherId, array $input = array()): array {
        if ($teacherId < 1) return self::unavailable('Teacher coverage is not available for this account.');
        $profile = null;
        try {
            $repository = new TeacherAvailabilityRepository();
            if (function_exists('get_current_user_id')) {
                $userId = (int) get_current_user_id();
                if ($userId < 1) return self::unavailable('Teacher coverage requires an authenticated account.');
                $linked = $repository->activeTeacherIdForUser($userId) ?? $repository->onboardingTeacherIdForUser($userId);
                if ($linked !== null && (int) $linked !== $teacherId) return self::unavailable('Teacher coverage is limited to the signed-in Teacher.');
            }
            $profile = $repository->profile($teacherId);
        }
        catch (\Throwable) { return self::unavailable('Teacher coverage could not read the availability profile.'); }
        $timezone = self::safeTimezone((string) ($profile->timezone ?? (BookingAvailabilityPolicy::current()['academy_timezone'] ?? 'Asia/Tehran')));
        $mode = 'week';
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $weekStart = self::date($input['week_start'] ?? null, $today);
        $date = self::date($input['date'] ?? null, $weekStart);
        if ($date < $weekStart || $date > InstrumentCoverageWeek::end($weekStart)) $weekStart = $date;
        $weekday = self::weekdayOf($date);
        $academyTimezone = self::safeTimezone((string) (BookingAvailabilityPolicy::current()['academy_timezone'] ?? 'Asia/Tehran'));
        try {
            $authorized = $this->authorizedInstrumentIds($teacherId);
        } catch (\Throwable) {
            return self::unavailable('Teacher instrument eligibility could not be read.');
        }
        if (!$authorized) return self::empty('No instrument is currently assigned to this Teacher.');
        return $this->projection(array(
            'scope' => 'teacher',
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'week_start' => $weekStart,
            'timezone' => $timezone,
            'academy_timezone' => $academyTimezone,
            'instrument_id' => 0,
            'teacher_id' => $teacherId,
            'calendar' => self::calendar($input['calendar'] ?? null),
            'authorized_instruments' => $authorized,
        ));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function projection(array $context): array {
        try {
            $universe = $this->instruments();
        } catch (\Throwable) {
            return self::unavailable('Instrument coverage could not read the active catalogue.');
        }
        $authorized = array_fill_keys(array_map('intval', (array) ($context['authorized_instruments'] ?? array())), true);
        if ($context['scope'] === 'teacher') {
            $universe = array_values(array_filter($universe, static fn(array $instrument): bool => isset($authorized[(int) $instrument['id']])));
        }
        if (!$universe) return self::empty('No active instrument is available for this coverage view.');

        $courseIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['course_id'] ?? 0), $universe)));
        try {
            $eligible = $this->eligibleTeachers($courseIds);
        } catch (\Throwable) {
            return self::unavailable('Teacher eligibility could not be read, so coverage is not shown.');
        }
        $selected = (int) ($context['instrument_id'] ?? 0);
        $mode = (string) $context['mode'];
        $timezone = (string) $context['timezone'];
        $weekStart = (string) $context['week_start'];
        $date = (string) $context['date'];
        $weekday = (int) $context['weekday'];
        $now = gmdate('Y-m-d H:i:s');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $period = InstrumentCoverageMetrics::period($weekStart, InstrumentCoverageWeek::end($weekStart), $today);
        $asOfLocal = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('j M Y H:i');

        $instruments = array();
        foreach ($universe as $instrument) {
            $id = (int) $instrument['id'];
            $row = array(
                'id' => $id,
                'slug' => (string) ($instrument['slug'] ?? ''),
                'name' => (string) $instrument['name'],
                'name_en' => (string) ($instrument['name_en'] ?? ''),
                'status' => 'ok',
                'lesson_minutes' => (int) ($instrument['lesson_minutes'] ?? 0),
                'buffer_minutes' => (int) ($instrument['buffer_minutes'] ?? 0),
                'course_id' => (int) ($instrument['course_id'] ?? 0),
                'eligible_teacher_count' => count($eligible[$id] ?? array()),
                'unknown_teacher_count' => 0,
                'clocks' => array(),
                'golden_ring' => array(),
                'metrics' => InstrumentCoverageMetrics::summarize(array(), 'actionable'),
            );
            if (($instrument['course_id'] ?? 0) < 1) {
                $row['status'] = $this->optionReadFailed ? 'unavailable' : 'not_bookable';
                $row['message'] = $this->optionReadFailed
                    ? 'The introductory catalogue could not be read, so capacity is not shown.'
                    : 'No active introductory course is configured for this instrument.';
                $instruments[] = $row;
                continue;
            }
            $eligibleIds = array_keys($eligible[$id] ?? array());
            try {
                // Availability is instrument-scoped. Read facts for this card's
                // own eligible Teachers and instrument, whether or not another
                // instrument is selected in the overview filter.
                $facts = $this->teacherFacts($eligibleIds, $mode, $date, $timezone, $weekStart, $id);
            } catch (\Throwable $error) {
                $row['status'] = 'unavailable';
                $row['message'] = 'Teacher availability or capacity could not be read, so coverage is not shown.';
                $instruments[] = $row;
                continue;
            }
            $unknown = 0;
            $teacherFacts = array();
            foreach ($eligibleIds as $teacherId) {
                $fact = $facts[(int) $teacherId] ?? null;
                if (!is_array($fact)) continue;
                if (!empty($fact['unknown'])) { $unknown++; continue; }
                $teacherFacts[] = array(
                    'id' => (int) $teacherId,
                    'available' => (array) ($fact['available'] ?? array()),
                    'occupied' => (array) ($fact['occupied'] ?? array()),
                    'holds' => (array) ($fact['holds'] ?? array()),
                );
            }
            $row['unknown_teacher_count'] = $unknown;
            if (!$eligibleIds) $row['status'] = 'no_eligible_teachers';
            elseif ($unknown > 0) $row['status'] = 'partial';

            $clocks = array();
            foreach (InstrumentCoverageWeek::dates($weekStart) as $day => $dayDate) {
                $clock = $this->day(
                    $dayDate,
                    $day,
                    $timezone,
                    (int) $row['lesson_minutes'],
                    (int) $row['buffer_minutes'],
                    $teacherFacts,
                    $unknown,
                    $now
                );
                $clock['is_today'] = $dayDate === (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
                $clocks[$day] = $clock;
            }
            foreach ($clocks as &$dayClock) $dayClock['metric'] = InstrumentCoverageMetrics::day($dayClock, $period['key'], $today);
            unset($dayClock);
            $row['clocks'] = $clocks;
            $row['clock'] = $clocks[$weekday] ?? reset($clocks);
            $row['metrics'] = InstrumentCoverageMetrics::summarize($clocks, $period['source']);
            $row['priority_gaps'] = InstrumentCoverageGapList::build($clocks, $period['source'], 10);
            $row['unknown_data'] = InstrumentCoverageUnknownData::build($clocks, $period['source']);
            $row['golden_ring'] = $this->goldenRing($clocks, $weekStart, $timezone);
            if ($selected < 1 && isset($row['clock'])) $selected = $id;
            $instruments[] = $row;
        }

        usort($instruments, static fn(array $a, array $b): int => ((int) ($b['metrics']['uncovered_minutes'] ?? 0)) <=> ((int) ($a['metrics']['uncovered_minutes'] ?? 0)) ?: strnatcasecmp($a['name'], $b['name']));
        $selectedRow = null;
        foreach ($instruments as $row) if ((int) $row['id'] === $selected && isset($row['clock'])) { $selectedRow = $row; break; }
        if ($selectedRow === null) foreach ($instruments as $row) if (isset($row['clock'])) { $selectedRow = $row; break; }
        if ($selectedRow === null && $instruments) $selectedRow = $instruments[0];

        $priority = array();
        foreach ($instruments as $row) {
            foreach ((array) ($row['priority_gaps'] ?? array()) as $gap) {
                $gap['instrument_id'] = (int) $row['id'];
                $gap['instrument_name'] = trim((string) ($row['name_en'] ?? '')) !== '' ? (string) $row['name_en'] : (string) $row['name'];
                $priority[] = $gap;
            }
        }
        usort($priority, static fn(array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: ($b['duration_minutes'] <=> $a['duration_minutes']) ?: ($a['date'] <=> $b['date']) ?: ($a['start_minute'] <=> $b['start_minute']));
        $priority = array_slice($priority, 0, 12);

        $unknownRanges = array();
        $unknownInstruments = array();
        $unknownDates = array();
        $unknownSegments = 0;
        $unknownMinutes = 0;
        foreach ($instruments as $row) {
            $data = (array) ($row['unknown_data'] ?? array());
            $instrumentName = trim((string) ($row['name_en'] ?? '')) !== '' ? (string) $row['name_en'] : (string) $row['name'];
            foreach ((array) ($data['ranges'] ?? array()) as $range) {
                $range['instrument_id'] = (int) $row['id'];
                $range['instrument_name'] = $instrumentName;
                $unknownRanges[] = $range;
                $unknownInstruments[(int) $row['id']] = $instrumentName;
                $unknownDates[(string) ($range['date'] ?? '')] = (string) ($range['date_label'] ?? '');
            }
            $unknownSegments += (int) ($data['segment_count'] ?? 0);
            $unknownMinutes += (int) ($data['duration_minutes'] ?? 0);
        }
        usort($unknownRanges, static fn(array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['start_minute'] <=> $b['start_minute']) ?: ($a['instrument_id'] <=> $b['instrument_id']));
        ksort($unknownDates);
        $unknownData = array(
            'interval_count' => count($unknownRanges),
            'segment_count' => $unknownSegments,
            'duration_minutes' => $unknownMinutes,
            'instruments' => array_values($unknownInstruments),
            'dates' => array_values($unknownDates),
            'ranges' => $unknownRanges,
        );

        return array(
            'status' => 'ok',
            'scope' => (string) $context['scope'],
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'week_start' => $weekStart,
            'week_end' => InstrumentCoverageWeek::end($weekStart),
            'week_order' => array_keys(InstrumentCoverageWeek::dates($weekStart)),
            'period_key' => $period['key'],
            'period_label' => $period['label'],
            'gap_period_label' => $period['gap_label'],
            'metric_source' => $period['source'],
            'as_of_local' => $asOfLocal,
            'priority_gaps' => $priority,
            'unknown_data' => $unknownData,
            'golden_bonus' => $this->goldenBonus(),
            'display_timezone' => $timezone,
            'timezone_label' => str_replace('_', ' ', $timezone),
            'calendar' => (string) $context['calendar'],
            'selected_instrument' => $selectedRow ? (int) $selectedRow['id'] : 0,
            'instruments' => $instruments,
            'message' => '',
        );
    }

    /** @param list<array<string,mixed>> $teachers */
    private function day(string $date, int $weekday, string $timezone, int $lesson, int $buffer, array $teachers, int $unknown, string $now): array {
        $occurrences = $this->regionalGoldenOccurrences($date);
        $clock = InstrumentCoverageClockAnalyzer::build(array(
            'mode' => InstrumentCoverageClockAnalyzer::MODE_DATE,
            'date' => $date,
            'weekday' => $weekday,
            'display_timezone' => $timezone,
            'lesson_minutes' => $lesson,
            'buffer_minutes' => $buffer,
            'golden' => $occurrences,
            'teachers' => $teachers,
            'unknown_teacher_count' => $unknown,
            'now_utc' => $now,
        ));
        $clock['visual_segments'] = InstrumentCoverageClockAnalyzer::visualize($clock);
        $pay = $this->payOccurrences($date);
        foreach ($clock['visual_segments'] as &$visual) {
            $visual['bonus_applies'] = false;
            foreach ($pay as $occurrence) {
                if ($occurrence['starts_at_utc'] < $visual['ends_at_utc'] && $occurrence['ends_at_utc'] > $visual['starts_at_utc']) { $visual['bonus_applies'] = true; break; }
            }
        }
        unset($visual);
        $clock['golden_details'] = $this->goldenDetails($clock['visual_segments'], $occurrences, $date, $weekday);
        return $clock;
    }

    /**
     * Regional structural demand windows, not the global Iran pay policy. This is
     * the shared finance-owned coverage window authority, converted per occurrence.
     *
     * @return list<array<string,mixed>>
     */
    private function regionalGoldenOccurrences(string $date): array {
        $cacheKey = 'regions|' . $date;
        if (isset($this->goldenCache[$cacheKey])) return $this->goldenCache[$cacheKey];
        $out = array();
        try {
            $windows = (new GoldenHourPolicyService())->coverageWindows();
        } catch (\Throwable) {
            $windows = GoldenHourRule::defaults();
        }
        foreach ($windows as $region => $window) {
            if (!is_array($window)) continue;
            $policy = array(
                'timezone' => (string) ($window['timezone'] ?? 'UTC'),
                'weekdays' => (array) ($window['weekdays'] ?? array(1,2,3,4,5,6,7)),
                'start' => (string) ($window['start'] ?? '16:00'),
                'end' => (string) ($window['end'] ?? '21:00'),
                'periods' => (array) ($window['periods'] ?? array(array('start' => (string) ($window['start'] ?? '16:00'), 'end' => (string) ($window['end'] ?? '21:00')))),
            );
            foreach (GoldenHourClockProjection::aroundDate($policy, $date) as $occurrence) {
                $out[] = $occurrence + array(
                    'region' => (string) $region,
                    'label' => (string) ($window['label'] ?? $region),
                );
            }
        }
        usort($out, static fn(array $a, array $b): int => $a['starts_at_utc'] <=> $b['starts_at_utc'] ?: strcmp((string) $a['region'], (string) $b['region']));
        $this->goldenCache[$cacheKey] = $out;
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $visualSegments
     * @param list<array<string,mixed>> $occurrences
     * @return array<int,list<array<string,mixed>>>
     */
    private function goldenDetails(array $visualSegments, array $occurrences, string $date, int $weekday): array {
        $details = array();
        foreach ($visualSegments as $segment) {
            $start = (string) ($segment['starts_at_utc'] ?? '');
            $end = (string) ($segment['ends_at_utc'] ?? '');
            if ($start === '' || $end === '') continue;
            foreach ($occurrences as $occurrence) {
                if ($occurrence['starts_at_utc'] < $end && $occurrence['ends_at_utc'] > $start) {
                    $details[(int) $segment['index']][] = array(
                        'region' => (string) $occurrence['region'],
                        'label' => (string) $occurrence['label'],
                        'timezone' => (string) $occurrence['timezone'],
                        'local_start' => (string) $occurrence['start'],
                        'local_end' => (string) $occurrence['end'],
                        'date' => $date,
                        'weekday' => $weekday,
                    );
                }
            }
        }
        return $details;
    }

    /**
     * One aggregated outer ring across the seven displayed dates. A segment is
     * golden when any regional demand window on any weekday overlaps it.
     *
     * @param array<int,array<string,mixed>> $clocks
     * @return list<array<string,mixed>>
     */
    private function goldenRing(array $clocks, string $weekStart, string $timezone): array {
        $ring = array();
        for ($index = 0; $index < InstrumentCoverageClockAnalyzer::VISUAL_SEGMENTS_PER_DAY; $index++) $ring[$index] = array('golden' => false, 'intensity' => 0, 'regions' => array());
        $seen = array();
        foreach ($clocks as $weekday => $clock) {
            foreach ((array) ($clock['golden_details'] ?? array()) as $index => $entries) {
                foreach ($entries as $entry) {
                    $key = $index . '|' . $entry['region'] . '|' . $weekday;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $ring[$index]['golden'] = true;
                    $ring[$index]['regions'][] = $entry + array('weekday' => (int) $weekday);
                }
            }
        }
        $max = 1;
        foreach ($ring as $segment) $max = max($max, count($segment['regions']));
        foreach ($ring as &$segment) {
            $segment['intensity'] = $segment['golden'] ? min(1, count($segment['regions']) / max(1, $max)) : 0;
            usort($segment['regions'], static fn(array $a, array $b): int => ($a['weekday'] <=> $b['weekday']) ?: strcmp((string) $a['region'], (string) $b['region']));
        }
        unset($segment);
        return array_values($ring);
    }

    /** @return list<array<string,mixed>> */
    private function payOccurrences(string $date): array {
        $cacheKey = 'pay|' . $date;
        if (isset($this->goldenCache[$cacheKey])) return $this->goldenCache[$cacheKey];
        try {
            $policy = (new GoldenHourPolicyService())->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, $date . ' 12:00:00');
        } catch (\Throwable) {
            $definitions = (new GoldenHourPolicyService())->definitions();
            $policy = $definitions[GoldenHourRule::GLOBAL_IRAN_KEY] ?? GoldenHourRule::globalDefinition();
        }
        $occurrences = !empty($policy['enabled']) && (int) ($policy['bonus_basis_points'] ?? 0) > 0
            ? GoldenHourClockProjection::aroundDate($policy, $date)
            : array();
        $this->goldenCache[$cacheKey] = $occurrences;
        return $occurrences;
    }

    /** @return array{enabled:bool,percent:float,timezone:string,label:string} */
    private function goldenBonus(): array {
        if (isset($this->goldenCache['bonus'])) return $this->goldenCache['bonus'];
        try {
            $policy = (new GoldenHourPolicyService())->latest(GoldenHourRule::GLOBAL_IRAN_KEY);
        } catch (\Throwable) {
            $definitions = (new GoldenHourPolicyService())->definitions();
            $policy = $definitions[GoldenHourRule::GLOBAL_IRAN_KEY] ?? GoldenHourRule::globalDefinition();
        }
        $bonus = array(
            'enabled' => !empty($policy['enabled']) && (int) ($policy['bonus_basis_points'] ?? 0) > 0,
            'percent' => round(((int) ($policy['bonus_basis_points'] ?? 0)) / 100, 2),
            'timezone' => (string) ($policy['timezone'] ?? GoldenHourRule::GLOBAL_IRAN_TIMEZONE),
            'label' => (string) ($policy['label'] ?? 'Golden Hours'),
        );
        $this->goldenCache['bonus'] = $bonus;
        return $bonus;
    }

    /** @return list<array{id:int,slug:string,name:string,course_id:int,lesson_minutes:int,buffer_minutes:int}> */
    private function instruments(): array {
        if ($this->instrumentCache) return array_values($this->instrumentCache);
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results("SELECT id,slug,name_fa,name_en FROM {$p}instruments WHERE status='active' AND archived_at IS NULL ORDER BY COALESCE(NULLIF(name_fa,''),name_en),id", ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('coverage_clock_instruments_unavailable');
        $options = array();
        try {
            foreach ((new PublicBookingOptionsReadService())->introductoryInstruments() as $option) $options[(int) $option['id']] = $option;
        } catch (\Throwable) {
            // An unreadable introductory catalogue is never treated as free capacity
            // or as a confirmed "no course" fact.
            $this->optionReadFailed = true;
        }
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) continue;
            $option = $options[$id] ?? null;
            $this->instrumentCache[$id] = array(
                'id' => $id,
                'slug' => (string) ($row['slug'] ?? ''),
                'name' => trim((string) ($row['name_fa'] ?? '')) !== '' ? (string) $row['name_fa'] : (string) ($row['name_en'] ?? ('Instrument ' . $id)),
                'name_en' => (string) ($row['name_en'] ?? ''),
                'course_id' => (int) ($option['course_id'] ?? 0),
                'lesson_minutes' => (int) ($option['duration_minutes'] ?? 0),
                'buffer_minutes' => (int) ($option['buffer_minutes'] ?? 0),
            );
        }
        return array_values($this->instrumentCache);
    }

    /**
     * @param list<int> $courseIds
     * @return array<int,array<int,true>> instrument id => teacher ids
     */
    private function eligibleTeachers(array $courseIds): array {
        $courseIds = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn(int $id): bool => $id > 0)));
        if (!$courseIds) return array();
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $placeholders = implode(',', array_fill(0, count($courseIds), '%d'));
        $sql = "SELECT DISTINCT e.teacher_id,c.instrument_id
                FROM {$p}teacher_course_eligibilities e
                INNER JOIN {$p}teachers t ON t.id=e.teacher_id
                INNER JOIN {$p}courses c ON c.id=e.course_id AND c.status='active' AND c.archived_at IS NULL
                INNER JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id
                LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
                WHERE e.course_id IN ({$placeholders})
                  AND e.status='active'
                  AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP())
                  AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP())
                  AND t.status='active' AND t.archived_at IS NULL
                  AND o.state='active' AND o.readiness_state='ready'
                  AND COALESCE(a.state,'accepting') IN ('accepting','limited')
                ORDER BY c.instrument_id,e.teacher_id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $courseIds), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('coverage_clock_eligibility_unavailable');
        $out = array();
        foreach ((array) $rows as $row) {
            $instrument = (int) ($row['instrument_id'] ?? 0);
            $teacher = (int) ($row['teacher_id'] ?? 0);
            if ($instrument < 1 || $teacher < 1) continue;
            $out[$instrument][$teacher] = true;
        }
        return $out;
    }

    /** @return list<int> */
    private function authorizedInstrumentIds(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT c.instrument_id FROM {$p}teacher_course_eligibilities e INNER JOIN {$p}courses c ON c.id=e.course_id AND c.status='active' AND c.archived_at IS NULL WHERE e.teacher_id=%d AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP())", $teacherId));
        if ($wpdb->last_error !== '') throw new \RuntimeException('coverage_clock_teacher_eligibility_unavailable');
        return array_values(array_unique(array_map('intval', (array) $rows)));
    }

    /**
     * One authoritative availability read and one occupancy read per Teacher for
     * this instrument and range. Cached facts are never reused across scopes.
     *
     * @param list<int> $teacherIds
     * @return array<int,array<string,mixed>>
     */
    private function teacherFacts(array $teacherIds, string $mode, string $date, string $timezone, string $weekStart, ?int $instrumentId = null): array {
        $teacherIds = array_values(array_unique(array_filter(array_map('intval', $teacherIds), static fn(int $id): bool => $id > 0)));
        if (!$teacherIds) return array();
        $zone = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');
        if ($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY || $mode === 'week') {
            $from = (new \DateTimeImmutable($weekStart . ' 00:00:00', $zone))->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = (new \DateTimeImmutable($weekStart . ' 00:00:00', $zone))->modify('+8 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        } else {
            $from = (new \DateTimeImmutable($date . ' 00:00:00', $zone))->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = (new \DateTimeImmutable($date . ' 00:00:00', $zone))->modify('+2 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        $cacheKey = $mode . '|' . $from . '|' . $until . '|' . (string)$instrumentId;
        if (isset($this->factsCache[$cacheKey])) return $this->factsCache[$cacheKey];
        $out = array();
        foreach ($teacherIds as $teacherId) {
            $fact = array('available' => array(), 'occupied' => array(), 'holds' => array(), 'unknown' => false);
            try {
                $segments = $mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY
                    ? $this->availability->recurring($teacherId, $from, $until, $instrumentId)
                    : $this->availability->effective($teacherId, $from, $until, $instrumentId);
                foreach ($segments as $segment) {
                    if (!isset($segment['state'], $segment['starts_at_utc'], $segment['ends_at_utc'])) continue;
                    $fact['available'][] = array('state' => (string) $segment['state'], 'starts_at_utc' => (string) $segment['starts_at_utc'], 'ends_at_utc' => (string) $segment['ends_at_utc']);
                }
                if ($mode === InstrumentCoverageClockAnalyzer::MODE_DATE || $mode === 'week') {
                    foreach ($this->occupancy->range($teacherId, $from, $until) as $interval) {
                        $fact['occupied'][] = array('starts_at_utc' => (string) $interval['starts_at_utc'], 'occupied_ends_at_utc' => (string) $interval['occupied_ends_at_utc']);
                    }
                    $fact['holds'] = CanonicalContinuationCapacityAuthority::activeHoldsInRange($teacherId, $from, $until);
                }
            } catch (\Throwable) {
                $fact['unknown'] = true;
            }
            $out[(int) $teacherId] = $fact;
        }
        $this->factsCache[$cacheKey] = $out;
        return $out;
    }

    private static function weekdayOf(string $date): int {
        return (int) (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->format('N');
    }

    private static function mode(mixed $value, string $default): string {
        $mode = (string) $value;
        return in_array($mode, array(InstrumentCoverageClockAnalyzer::MODE_WEEKLY, InstrumentCoverageClockAnalyzer::MODE_DATE), true) ? $mode : $default;
    }

    private static function calendar(mixed $value): string {
        $calendar = (string) $value;
        return in_array($calendar, array('persian', 'gregorian'), true) ? $calendar : 'gregorian';
    }

    private static function date(mixed $value, string $default): string {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $candidate = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if ($candidate && $candidate->format('Y-m-d') === $value) return $value;
        }
        return $default;
    }

    private static function weekday(mixed $value, int $default): int {
        $weekday = (int) $value;
        return $weekday >= 1 && $weekday <= 7 ? $weekday : $default;
    }

    private static function requestTimezone(mixed $value, string $default): string {
        return self::safeTimezone(is_string($value) && $value !== '' ? $value : $default);
    }

    private static function safeTimezone(string $value): string {
        try {
            if ($value === '') throw new \Throwable();
            new \DateTimeZone($value);
            return $value;
        } catch (\Throwable) {
            return 'Asia/Tehran';
        }
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $message): array {
        return array('status' => 'unavailable', 'scope' => '', 'mode' => '', 'date' => '', 'weekday' => 0, 'week_start' => '', 'week_end' => '', 'week_order' => InstrumentCoverageWeek::ORDER, 'period_key' => '', 'period_label' => '', 'gap_period_label' => '', 'metric_source' => '', 'as_of_local' => '', 'priority_gaps' => array(), 'unknown_data' => array('interval_count' => 0, 'segment_count' => 0, 'duration_minutes' => 0, 'instruments' => array(), 'dates' => array(), 'ranges' => array()), 'golden_bonus' => array('enabled' => false, 'percent' => 0, 'timezone' => 'Asia/Tehran', 'label' => 'Golden Hours'), 'display_timezone' => '', 'timezone_label' => '', 'calendar' => 'gregorian', 'selected_instrument' => 0, 'instruments' => array(), 'message' => $message);
    }

    /** @return array<string,mixed> */
    private static function empty(string $message): array {
        $result = self::unavailable($message);
        $result['status'] = 'empty';
        return $result;
    }
}
