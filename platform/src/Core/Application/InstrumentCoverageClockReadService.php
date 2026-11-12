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
        $mode = self::mode($input['mode'] ?? null, InstrumentCoverageClockAnalyzer::MODE_DATE);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $date = self::date($input['date'] ?? null, $today);
        $weekday = $mode === InstrumentCoverageClockAnalyzer::MODE_DATE
            ? self::weekdayOf($date)
            : self::weekday($input['weekday'] ?? null, self::weekdayOf($date));
        $weekStart = self::weekStart($date, $weekday);
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
        $mode = self::mode($input['mode'] ?? null, InstrumentCoverageClockAnalyzer::MODE_WEEKLY);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $date = self::date($input['date'] ?? null, $today);
        $weekday = $mode === InstrumentCoverageClockAnalyzer::MODE_DATE
            ? self::weekdayOf($date)
            : self::weekday($input['weekday'] ?? null, self::weekdayOf($date));
        $weekStart = self::weekStart($date, $weekday);
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
        $teacherIds = array();
        foreach ($eligible as $map) foreach (array_keys($map) as $teacherId) $teacherIds[(int) $teacherId] = true;
        $teacherIds = array_keys($teacherIds);
        try {
            $facts = $this->teacherFacts($teacherIds, (string) $context['mode'], (string) $context['date'], (string) $context['timezone'], (string) $context['week_start']);
        } catch (\Throwable $error) {
            return self::unavailable('Teacher availability or capacity could not be read, so coverage is not shown.');
        }
        $selected = (int) ($context['instrument_id'] ?? 0);
        $mode = (string) $context['mode'];
        $timezone = (string) $context['timezone'];
        $weekStart = (string) $context['week_start'];
        $date = (string) $context['date'];
        $weekday = (int) $context['weekday'];
        $now = gmdate('Y-m-d H:i:s');

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

            $dates = array();
            if ($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY) {
                for ($day = 1; $day <= 7; $day++) $dates[$day] = (new \DateTimeImmutable($weekStart, new \DateTimeZone('UTC')))->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
            } else {
                $dates[$weekday] = $date;
            }
            $clocks = array();
            foreach ($dates as $day => $dayDate) {
                $clocks[$day] = $this->day(
                    $mode,
                    $dayDate,
                    $day,
                    $timezone,
                    (int) $row['lesson_minutes'],
                    (int) $row['buffer_minutes'],
                    $teacherFacts,
                    $unknown,
                    $now
                );
            }
            $row['clocks'] = $clocks;
            if ($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY) {
                $row['clock'] = $clocks[$weekday] ?? reset($clocks);
            } else {
                $row['clock'] = $clocks[$weekday] ?? reset($clocks);
            }
            $row['metrics'] = $row['clock']['summary'] ?? array();
            if ($selected < 1 && isset($row['clock'])) $selected = $id;
            $instruments[] = $row;
        }

        usort($instruments, static fn(array $a, array $b): int => ((int) ($b['metrics']['uncovered_minutes'] ?? 0)) <=> ((int) ($a['metrics']['uncovered_minutes'] ?? 0)) ?: strnatcasecmp($a['name'], $b['name']));
        $selectedRow = null;
        foreach ($instruments as $row) if ((int) $row['id'] === $selected && isset($row['clock'])) { $selectedRow = $row; break; }
        if ($selectedRow === null) foreach ($instruments as $row) if (isset($row['clock'])) { $selectedRow = $row; break; }
        if ($selectedRow === null && $instruments) $selectedRow = $instruments[0];

        return array(
            'status' => 'ok',
            'scope' => (string) $context['scope'],
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'week_start' => $weekStart,
            'display_timezone' => $timezone,
            'timezone_label' => str_replace('_', ' ', $timezone),
            'calendar' => (string) $context['calendar'],
            'selected_instrument' => $selectedRow ? (int) $selectedRow['id'] : 0,
            'instruments' => $instruments,
            'message' => '',
        );
    }

    /** @param list<array<string,mixed>> $teachers */
    private function day(string $mode, string $date, int $weekday, string $timezone, int $lesson, int $buffer, array $teachers, int $unknown, string $now): array {
        $key = $date . '|' . $timezone . '|' . $mode;
        $golden = $this->goldenOccurrences($key, $date, $mode);
        return InstrumentCoverageClockAnalyzer::build(array(
            'mode' => $mode,
            'date' => $date,
            'weekday' => $weekday,
            'display_timezone' => $timezone,
            'lesson_minutes' => $lesson,
            'buffer_minutes' => $buffer,
            'golden' => $golden,
            'teachers' => $teachers,
            'unknown_teacher_count' => $unknown,
            'now_utc' => $now,
        ));
    }

    /** @return list<array{starts_at_utc:string,ends_at_utc:string,start:string,end:string,date:string,timezone:string}> */
    private function goldenOccurrences(string $key, string $date, string $mode): array {
        $cacheKey = $key . '|' . $mode;
        if (isset($this->goldenCache[$cacheKey])) return $this->goldenCache[$cacheKey];
        $policy = $this->goldenPolicy($date);
        // Adjacent policy dates are always included: a Golden Hour window can cross
        // midnight or a display-timezone boundary. The analyzer marks by overlap.
        $occurrences = GoldenHourClockProjection::aroundDate($policy, $date);
        $this->goldenCache[$cacheKey] = $occurrences;
        return $occurrences;
    }

    /** @return array<string,mixed> */
    private function goldenPolicy(string $date): array {
        $cacheKey = 'policy|' . $date;
        if (isset($this->goldenCache[$cacheKey])) return $this->goldenCache[$cacheKey];
        try {
            $policy = (new GoldenHourPolicyService())->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, $date . ' 12:00:00');
        } catch (\Throwable) {
            $definitions = (new GoldenHourPolicyService())->definitions();
            $policy = $definitions[GoldenHourRule::GLOBAL_IRAN_KEY] ?? GoldenHourRule::globalDefinition();
        }
        $this->goldenCache[$cacheKey] = $policy;
        return $policy;
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
     * the whole range. Instruments then share the resolved facts.
     *
     * @param list<int> $teacherIds
     * @return array<int,array<string,mixed>>
     */
    private function teacherFacts(array $teacherIds, string $mode, string $date, string $timezone, string $weekStart): array {
        $teacherIds = array_values(array_unique(array_filter(array_map('intval', $teacherIds), static fn(int $id): bool => $id > 0)));
        if (!$teacherIds) return array();
        $zone = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');
        if ($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY) {
            $from = (new \DateTimeImmutable($weekStart . ' 00:00:00', $zone))->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = (new \DateTimeImmutable($weekStart . ' 00:00:00', $zone))->modify('+8 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        } else {
            $from = (new \DateTimeImmutable($date . ' 00:00:00', $zone))->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = (new \DateTimeImmutable($date . ' 00:00:00', $zone))->modify('+2 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        $cacheKey = $mode . '|' . $from . '|' . $until;
        if (isset($this->factsCache[$cacheKey])) return $this->factsCache[$cacheKey];
        $out = array();
        foreach ($teacherIds as $teacherId) {
            $fact = array('available' => array(), 'occupied' => array(), 'holds' => array(), 'unknown' => false);
            try {
                $segments = $mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY
                    ? $this->availability->recurring($teacherId, $from, $until)
                    : $this->availability->effective($teacherId, $from, $until);
                foreach ($segments as $segment) {
                    if (!isset($segment['state'], $segment['starts_at_utc'], $segment['ends_at_utc'])) continue;
                    $fact['available'][] = array('state' => (string) $segment['state'], 'starts_at_utc' => (string) $segment['starts_at_utc'], 'ends_at_utc' => (string) $segment['ends_at_utc']);
                }
                if ($mode === InstrumentCoverageClockAnalyzer::MODE_DATE) {
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

    private static function weekStart(string $date, int $weekday): string {
        $day = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        return $day->modify('-' . ($weekday - 1) . ' days')->format('Y-m-d');
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
        return array('status' => 'unavailable', 'scope' => '', 'mode' => '', 'date' => '', 'weekday' => 0, 'week_start' => '', 'display_timezone' => '', 'timezone_label' => '', 'calendar' => 'gregorian', 'selected_instrument' => 0, 'instruments' => array(), 'message' => $message);
    }

    /** @return array<string,mixed> */
    private static function empty(string $message): array {
        $result = self::unavailable($message);
        $result['status'] = 'empty';
        return $result;
    }
}
