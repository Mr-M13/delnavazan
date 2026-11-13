<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageWeek;

/**
 * Compact shell for the seven-ring, 24-hour coverage clock.
 *
 * One clock, one outer Golden Hours ring, seven weekday rings and contextual
 * details only when a segment is selected. The compact JSON payload contains 48
 * visual segments per weekday; the analyzer keeps its 15-minute booking-precision
 * facts underneath.
 */
final class InstrumentCoverageClockView {
    private const STATE_CODES = array(
        InstrumentCoverageClockAnalyzer::STATE_UNCOVERED => 0,
        InstrumentCoverageClockAnalyzer::STATE_LIMITED => 1,
        InstrumentCoverageClockAnalyzer::STATE_COVERED => 2,
        InstrumentCoverageClockAnalyzer::STATE_UNKNOWN => 3,
        InstrumentCoverageClockAnalyzer::STATE_PARTIAL => 4,
    );
    private const REASON_CODES = array(
        InstrumentCoverageClockAnalyzer::REASON_NONE => 0,
        InstrumentCoverageClockAnalyzer::REASON_NO_AVAILABILITY => 1,
        InstrumentCoverageClockAnalyzer::REASON_FULLY_BOOKED => 2,
        InstrumentCoverageClockAnalyzer::REASON_NONEXISTENT_LOCAL_TIME => 4,
        InstrumentCoverageClockAnalyzer::REASON_UNKNOWN_DATA => 5,
    );

    /** @param array<string,mixed> $data @param array<string,mixed> $options */
    public static function render(array $data, array $options = array()): void {
        $scope = (string) ($options['scope'] ?? 'admin');
        $labels = array_merge(self::defaultLabels($scope), (array) ($options['labels'] ?? array()));
        $calendarValue = (string) ($data['calendar'] ?? 'gregorian');
        $calendar = in_array($calendarValue, array('persian', 'gregorian'), true) ? $calendarValue : 'gregorian';
        $status = (string) ($data['status'] ?? 'unavailable');
        $selected = self::selectedInstrument($data);
        echo '<section class="dzn-clock dzn-clock--' . esc_attr($scope) . '" id="dzn-instrument-coverage" dir="' . ($scope === 'teacher' ? 'rtl' : 'ltr') . '" aria-labelledby="dzn-clock-title" data-dzn-clock-root data-dzn-clock-scope="' . esc_attr($scope) . '"' . ($scope === 'teacher' ? ' data-dzn-clock-teacher' : '') . '>';
        self::header($data, $options, $labels, $calendar, $selected);
        if ($status === 'unavailable' || $status === 'empty') {
            echo '<div class="notice ' . ($status === 'unavailable' ? 'notice-error' : 'notice-info') . ' inline"><p>' . esc_html((string) ($data['message'] ?? $labels['unavailable'])) . '</p></div></section>';
            return;
        }
        if ($selected === null || empty($selected['clocks'])) {
            echo '<p class="dzn-clock__empty">' . esc_html((string) ($selected['message'] ?? $data['message'] ?? $labels['empty'])) . '</p></section>';
            return;
        }
        echo '<div class="dzn-clock__layout">';
        self::ringKey($data, $selected, $labels, $calendar, $scope);
        echo '<div class="dzn-clock__main">';
        self::metrics($selected, $labels);
        self::clockStage($selected, $labels, $scope);
        echo '</div>';
        echo '</div>';
        self::legend($labels);
        if ($scope === 'teacher') self::teacherNote($labels);
        echo '<script type="application/json" data-dzn-clock-data>' . self::json(self::dataPayload($data, $options, $labels, $scope === 'admin')) . '</script>';
        echo '</section>';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $options @param array<string,string|array> $labels */
    private static function header(array $data, array $options, array $labels, string $calendar, ?array $selected): void {
        $scope = (string) ($options['scope'] ?? 'admin');
        $base = (string) ($options['base_url'] ?? admin_url('admin.php'));
        $page = (string) ($options['page'] ?? 'dzn-academy-dashboard');
        $preserve = array_merge((array) ($options['preserve'] ?? array()), self::currentPreserve($scope));
        $date = (string) ($data['date'] ?? '');
        $weekStart = (string) ($data['week_start'] ?? '');
        $selectedId = $selected ? (int) $selected['id'] : (int) ($data['selected_instrument'] ?? 0);
        echo '<header class="dzn-clock__head"><div class="dzn-clock__intro"><h2 id="dzn-clock-title">' . esc_html((string) ($options['title'] ?? $labels['title'])) . '</h2><p>' . esc_html((string) ($options['description'] ?? $labels['description'])) . '</p></div>';
        echo '<form method="get" action="' . esc_url($base) . '" class="dzn-clock__controls">';
        if ($scope === 'admin') echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        foreach ($preserve as $key => $value) if (is_string($key) && is_scalar($value) && (string) $value !== '') echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
        echo '<label>' . esc_html($labels['instrument']) . '<select name="coverage_instrument" data-dzn-clock-instrument>';
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            echo '<option value="' . (int) $instrument['id'] . '"' . selected($selectedId, (int) $instrument['id'], false) . '>' . esc_html(self::instrumentName($instrument, $scope === 'admin')) . '</option>';
        }
        echo '</select></label>';
        echo '<label>' . esc_html($labels['weekOf']) . '<input type="date" name="coverage_date" value="' . esc_attr($date) . '"></label>';
        echo '<label>' . esc_html($labels['calendar']) . '<select name="coverage_calendar"><option value="gregorian"' . selected($calendar, 'gregorian', false) . '>' . esc_html($labels['gregorian']) . '</option><option value="persian"' . selected($calendar, 'persian', false) . '>' . esc_html($labels['persian']) . '</option></select></label>';
        echo '<button class="button button-primary" type="submit">' . esc_html($labels['apply']) . '</button>';
        echo '</form></header>';
        $prev = $weekStart !== '' ? InstrumentCoverageWeek::shift($weekStart, -1) : '';
        $next = $weekStart !== '' ? InstrumentCoverageWeek::shift($weekStart, 1) : '';
        echo '<div class="dzn-clock__weekline"><div class="dzn-clock__week-nav">';
        if ($prev !== '') echo '<a href="' . esc_url(self::url($base, $scope, $page, $preserve, $selectedId, $calendar, $prev)) . '" aria-label="' . esc_attr($labels['prevWeek']) . '">‹</a>';
        $weekLabel = self::weekLabel($data, $calendar, $scope);
        echo '<strong>' . ($calendar === 'gregorian' && $scope === 'admin' ? '<bdi dir="ltr">' . esc_html($weekLabel) . '</bdi>' : esc_html($weekLabel)) . '</strong>';
        if ($next !== '') echo '<a href="' . esc_url(self::url($base, $scope, $page, $preserve, $selectedId, $calendar, $next)) . '" aria-label="' . esc_attr($labels['nextWeek']) . '">›</a>';
        echo '</div><span class="dzn-clock__tz">' . esc_html((string) ($data['timezone_label'] ?? '')) . '</span></div>';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $selected @param array<string,string|array> $labels */
    private static function ringKey(array $data, array $selected, array $labels, string $calendar, string $scope): void {
        echo '<div class="dzn-clock__ring-key" role="list" aria-label="' . esc_attr($labels['rings']) . '">';
        $ringIndex = 0;
        foreach (InstrumentCoverageWeek::ORDER as $weekday) {
            $clock = (array) ($selected['clocks'][$weekday] ?? array());
            if (!$clock) continue;
            $isToday = !empty($clock['is_today']);
            $coverage = $clock['summary']['coverage_percent'] ?? null;
            $dateLabel = $calendar === 'persian' ? (string) ($clock['date_label_fa'] ?? '') : ($scope === 'teacher' ? self::gregorianFa((string) ($clock['date'] ?? '')) : (string) ($clock['date_label'] ?? ''));
            echo '<button type="button" class="dzn-clock__ring-row dzn-clock__ring-row--' . (int) $weekday . ($isToday ? ' is-today' : '') . '" role="listitem" data-dzn-clock-ring="' . (int) $ringIndex . '" data-dzn-clock-weekday="' . (int) $weekday . '" aria-pressed="false" title="' . esc_attr((string) ($labels['weekdays'][$weekday] ?? '')) . '">';
            $dateHtml = $calendar === 'gregorian' && $scope === 'admin' ? '<bdi dir="ltr">' . esc_html($dateLabel) . '</bdi>' : esc_html($dateLabel);
            echo '<i class="dzn-clock__ring-dot" aria-hidden="true"></i><span class="dzn-clock__ring-name">' . esc_html((string) ($labels['weekdays'][$weekday] ?? '')) . '</span><span class="dzn-clock__ring-date">' . $dateHtml . '</span><span class="dzn-clock__ring-pct">' . ($coverage === null ? '—' : esc_html((string) (int) $coverage . '%')) . '</span>';
            if ($isToday) echo '<span class="dzn-clock__today">' . esc_html($labels['today']) . '</span>';
            echo '</button>';
            $ringIndex++;
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $selected @param array<string,string|array> $labels */
    private static function metrics(array $selected, array $labels): void {
        $m = (array) ($selected['metrics'] ?? array());
        echo '<div class="dzn-clock__metrics" role="list">';
        self::metric($labels['coverage'], ($m['coverage_percent'] ?? null) === null ? '—' : (string) (int) $m['coverage_percent'] . '%', 'coverage');
        self::metric($labels['goldenCoverage'], ($m['golden_coverage_percent'] ?? null) === null ? '—' : (string) (int) $m['golden_coverage_percent'] . '%', 'golden');
        self::metric($labels['goldenGaps'], self::duration((int) ($m['golden_uncovered_minutes'] ?? 0)), 'golden-gap');
        self::metric($labels['uncovered'], self::duration((int) ($m['uncovered_minutes'] ?? 0)), 'uncovered');
        self::metric($labels['eligibleTeachers'], (string) (int) ($selected['eligible_teacher_count'] ?? 0), 'teachers');
        echo '</div>';
    }

    private static function metric(string $label, string $value, string $key): void {
        echo '<div class="dzn-clock__metric dzn-clock__metric--' . esc_attr($key) . '" role="listitem"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $selected @param array<string,string|array> $labels */
    private static function clockStage(array $selected, array $labels, string $scope): void {
        echo '<div class="dzn-clock__stage"><div class="dzn-clock__canvas" data-dzn-clock-canvas role="group" aria-label="' . esc_attr(self::instrumentName($selected, true)) . '"><noscript><p>' . esc_html($labels['noscript']) . '</p></noscript></div>';
        echo '<aside class="dzn-clock__details" data-dzn-clock-details aria-live="polite" aria-label="' . esc_attr($labels['details']) . '"><div data-dzn-clock-details-body><p class="dzn-clock__details-empty">' . esc_html($labels['select']) . '</p></div>';
        if ($scope === 'teacher') echo '<button type="button" class="dzn-clock__use" data-dzn-clock-use disabled>' . esc_html($labels['useTime']) . '</button>';
        echo '</aside></div>';
    }

    /** @param array<string,string|array> $labels */
    private static function legend(array $labels): void {
        echo '<div class="dzn-clock__legend" role="group" aria-label="' . esc_attr($labels['legend']) . '">';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--covered"></i>' . esc_html($labels['covered']) . '</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--limited"></i>' . esc_html($labels['limited']) . '</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--partial"></i>' . esc_html($labels['partial']) . '</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--uncovered"></i>' . esc_html($labels['uncoveredState']) . '</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--golden"></i>' . esc_html($labels['golden']) . '</span>';
        echo '</div>';
    }

    /** @param array<string,string|array> $labels */
    private static function teacherNote(array $labels): void {
        echo '<p class="dzn-clock__note">' . esc_html($labels['teacherNote']) . '</p>';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $options @param array<string,string|array> $labels @return array<string,mixed> */
    public static function dataPayload(array $data, array $options = array(), array $labels = array(), bool $preferEnglishNames = true): array {
        $labels = $labels ?: self::defaultLabels((string) ($options['scope'] ?? 'admin'));
        $selected = self::selectedInstrument($data);
        if ($selected === null) return array('week' => array(), 'golden_ring' => array(), 'labels' => $labels);
        $week = array();
        foreach (InstrumentCoverageWeek::ORDER as $weekday) {
            $clock = (array) ($selected['clocks'][$weekday] ?? array());
            if (!$clock) continue;
            $week[] = self::compactDay($clock);
        }
        return array(
            'scope' => (string) ($options['scope'] ?? 'admin'),
            'timezone' => (string) ($data['display_timezone'] ?? ''),
            'timezone_label' => (string) ($data['timezone_label'] ?? ''),
            'calendar' => (string) ($data['calendar'] ?? 'gregorian'),
            'week_start' => (string) ($data['week_start'] ?? ''),
            'week_end' => (string) ($data['week_end'] ?? ''),
            'week_order' => InstrumentCoverageWeek::ORDER,
            'selected_instrument' => (int) $selected['id'],
            'instrument_name' => self::instrumentName($selected, $preferEnglishNames),
            'lesson_minutes' => (int) ($selected['lesson_minutes'] ?? 0),
            'buffer_minutes' => (int) ($selected['buffer_minutes'] ?? 0),
            'eligible_teacher_count' => (int) ($selected['eligible_teacher_count'] ?? 0),
            'golden_bonus' => (array) ($data['golden_bonus'] ?? array()),
            'golden_ring' => (array) ($selected['golden_ring'] ?? array()),
            'week' => $week,
            'labels' => $labels,
        );
    }

    /** @param array<string,mixed> $clock @return array<string,mixed> */
    private static function compactDay(array $clock): array {
        $segments = array();
        foreach ((array) ($clock['visual_segments'] ?? array()) as $segment) {
            $segments[] = array(
                (int) (self::STATE_CODES[(string) $segment['state']] ?? 0),
                (int) ($segment['min_teacher_count'] ?? 0),
                (int) ($segment['max_teacher_count'] ?? 0),
                !empty($segment['golden']) ? 1 : 0,
                !empty($segment['golden_partial']) ? 1 : 0,
                !empty($segment['past']) ? 1 : 0,
                (int) (self::STATE_CODES[(string) ($segment['first_state'] ?? '')] ?? 0),
                (int) (self::STATE_CODES[(string) ($segment['second_state'] ?? '')] ?? 0),
                (int) ($segment['first_teacher_count'] ?? 0),
                (int) ($segment['second_teacher_count'] ?? 0),
                !empty($segment['first_golden']) ? 1 : 0,
                !empty($segment['second_golden']) ? 1 : 0,
                !empty($segment['bonus_applies']) ? 1 : 0,
            );
        }
        return array(
            'date' => (string) ($clock['date'] ?? ''),
            'weekday' => (int) ($clock['weekday'] ?? 1),
            'date_label' => (string) ($clock['date_label'] ?? ''),
            'date_label_fa' => (string) ($clock['date_label_fa'] ?? ''),
            'is_today' => !empty($clock['is_today']),
            'summary' => (array) ($clock['summary'] ?? array()),
            'segments' => $segments,
            'golden_details' => (array) ($clock['golden_details'] ?? array()),
        );
    }

    /** @param array<string,mixed> $data */
    private static function selectedInstrument(array $data): ?array {
        $selected = (int) ($data['selected_instrument'] ?? 0);
        $fallback = null;
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            if ($fallback === null) $fallback = $instrument;
            if ((int) $instrument['id'] === $selected && !empty($instrument['clocks'])) return $instrument;
        }
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) if (!empty($instrument['clocks'])) return $instrument;
        return $fallback;
    }

    /** @param array<string,mixed> $instrument */
    private static function instrumentName(array $instrument, bool $english = true): string {
        $name = (string) ($instrument['name'] ?? 'Instrument');
        $en = trim((string) ($instrument['name_en'] ?? ''));
        return $english && $en !== '' ? $en : $name;
    }

    /** @param array<string,mixed> $data */
    private static function weekLabel(array $data, string $calendar, string $scope = 'admin'): string {
        $start = (string) ($data['week_start'] ?? '');
        $end = (string) ($data['week_end'] ?? '');
        if ($start === '' || $end === '') return '';
        if ($calendar === 'persian' && class_exists('\\Delnavazan\\Platform\\Core\\Support\\PersianCalendar')) {
            [$sy,$sm,$sd] = \Delnavazan\Platform\Core\Support\PersianCalendar::toJalali((int) substr($start,0,4), (int) substr($start,5,2), (int) substr($start,8,2));
            [$ey,$em,$ed] = \Delnavazan\Platform\Core\Support\PersianCalendar::toJalali((int) substr($end,0,4), (int) substr($end,5,2), (int) substr($end,8,2));
            $months = \Delnavazan\Platform\Core\Support\PersianCalendar::PERSIAN_MONTHS;
            $fa = static fn(int $n): string => str_replace(range(0,9), array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'), (string) $n);
            return $sm === $em ? $fa($sd) . '–' . $fa($ed) . ' ' . $months[$sm] . ' ' . $fa($sy) : $fa($sd) . ' ' . $months[$sm] . ' – ' . $fa($ed) . ' ' . $months[$em] . ' ' . $fa($ey);
        }
        if ($scope === 'teacher') {
            $s = self::gregorianFa($start);
            $e = self::gregorianFa($end);
            $sParts = explode(' ', $s); $eParts = explode(' ', $e);
            if (($sParts[1] ?? '') === ($eParts[1] ?? '') && ($sParts[2] ?? '') === ($eParts[2] ?? '')) return $sParts[0] . '–' . $eParts[0] . ' ' . $sParts[1] . ' ' . $sParts[2];
            return $s . ' – ' . $e;
        }
        $s = new \DateTimeImmutable($start . ' 00:00:00', new \DateTimeZone('UTC'));
        $e = new \DateTimeImmutable($end . ' 00:00:00', new \DateTimeZone('UTC'));
        return $s->format('j M') . ' – ' . $e->format('j M Y');
    }

    /** @return array<string,string> */
    private static function currentPreserve(string $scope): array {
        if ($scope !== 'admin') return array();
        $out = array();
        foreach (array('tab', 'view', 'stage') as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string) $_GET[$key] !== '') $out[$key] = sanitize_key((string) $_GET[$key]);
        }
        return $out;
    }

    private static function url(string $base, string $scope, string $page, array $preserve, int $instrument, string $calendar, string $date): string {
        $args = $preserve;
        if ($scope === 'admin') $args['page'] = $page;
        $args['coverage_instrument'] = $instrument;
        $args['coverage_calendar'] = $calendar;
        $args['coverage_date'] = $date;
        return add_query_arg($args, $base);
    }

    /** @return array<string,string|array> */
    public static function defaultLabels(string $scope = 'admin'): array {
        if ($scope === 'teacher') {
            return array(
                'title' => 'ساعت پوشش هفتگی',
                'description' => 'هفت روز، یک ساعت؛ برای جزئیات روی هر بخش کلیک کنید.',
                'instrument' => 'ساز',
                'weekOf' => 'هفتهٔ',
                'calendar' => 'تقویم',
                'gregorian' => 'میلادی',
                'persian' => 'شمسی',
                'apply' => 'نمایش',
                'prevWeek' => 'هفتهٔ قبل',
                'nextWeek' => 'هفتهٔ بعد',
                'rings' => 'روزهای هفته',
                'weekdays' => array(6=>'شنبه',7=>'یکشنبه',1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه'),
                'today' => 'امروز',
                'coverage' => 'پوشش',
                'goldenCoverage' => 'پوشش ساعت طلایی',
                'goldenGaps' => 'ساعت‌های طلایی بدون پوشش',
                'uncovered' => 'بدون پوشش',
                'eligibleTeachers' => 'مدرسان واجد شرایط',
                'covered' => 'ظرفیت کافی',
                'limited' => 'یک مدرس',
                'partial' => 'پوشش جزئی',
                'uncoveredState' => 'بدون ظرفیت',
                'golden' => 'ساعت طلایی',
                'legend' => 'راهنمای ساعت',
                'details' => 'جزئیات بخش',
                'select' => 'برای دیدن جزئیات یک بخش را انتخاب کنید.',
                'useTime' => 'استفاده از این زمان',
                'teacherNote' => 'کلیک روی بخش‌ها فقط زمان را در فرم انتخاب می‌کند و چیزی ذخیره نمی‌شود. حلقهٔ طلایی فرصت تقاضاست و پاداش تابع قواعد مالی است.',
                'noscript' => 'برای رسم ساعت تعاملی، JavaScript لازم است.',
                'capacity' => 'ظرفیت',
                'teachers' => 'مدرس واجد شرایط',
                'teacher' => 'مدرس واجد شرایط',
                'goldenTitle' => 'ساعت طلایی',
                'demandWindow' => 'فرصت تقاضا',
                'regions' => 'مناطق',
                'localTime' => 'زمان محلی',
                'weekday' => 'روز',
                'date' => 'تاریخ',
                'bonus' => 'پاداش',
                'bonusOff' => 'پاداش فعال نیست',
                'bonusConditional' => 'در بازهٔ پاداش سراسری',
                'noDemandWindow' => 'در این بازه فرصت تقاضای منطقه‌ای ثبت نشده است.',
                'partialFirst' => 'نیمهٔ اول',
                'partialSecond' => 'نیمهٔ دوم',
                'noCapacity' => 'هیچ مدرس واجد شرایطی برای کل کلاس آزاد نیست',
                'booked' => 'کاملاً رزرو شده',
                'past' => 'گذشته',
                'unknown' => 'نامشخص',
                'selected' => 'انتخاب‌شده',
                'aggregateGolden' => 'این حلقه تقاضای تجمیعی هفته است؛ وضعیت دقیق هر روز در حلقهٔ همان روز دیده می‌شود.',
                'assumption' => 'بر اساس کلاس کامل و فاصلهٔ آن',
                'unavailable' => 'اطلاعات پوشش در دسترس نیست.',
                'empty' => 'پوششی برای نمایش وجود ندارد.',
            );
        }
        return array(
            'title' => 'Weekly coverage clock',
            'description' => 'Seven days, one clock. Select a segment for details.',
            'instrument' => 'Instrument',
            'weekOf' => 'Week of',
            'calendar' => 'Calendar',
            'gregorian' => 'Gregorian',
            'persian' => 'Persian',
            'apply' => 'Show',
            'prevWeek' => 'Previous week',
            'nextWeek' => 'Next week',
            'rings' => 'Weekday rings',
            'weekdays' => array(6=>'Saturday',7=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'),
            'today' => 'Today',
            'coverage' => 'Coverage',
            'goldenCoverage' => 'Golden coverage',
            'goldenGaps' => 'Golden gaps',
            'uncovered' => 'Uncovered',
            'eligibleTeachers' => 'Eligible teachers',
            'covered' => 'Capacity',
            'limited' => 'One teacher',
            'partial' => 'Partial',
            'uncoveredState' => 'No capacity',
            'golden' => 'Golden Hours',
            'legend' => 'Clock legend',
            'details' => 'Segment details',
            'select' => 'Select a segment for details.',
            'useTime' => 'Use this time',
            'teacherNote' => '',
            'noscript' => 'JavaScript is required to draw the interactive clock.',
            'capacity' => 'Capacity',
            'teachers' => 'eligible teachers',
            'teacher' => 'eligible teacher',
            'goldenTitle' => 'Golden Hours',
            'demandWindow' => 'Demand window',
            'regions' => 'Regions',
            'localTime' => 'Local time',
            'weekday' => 'Day',
            'date' => 'Date',
            'bonus' => 'Bonus',
            'bonusOff' => 'Bonus is not active',
            'bonusConditional' => 'within the global bonus window',
            'noDemandWindow' => 'No regional demand window is recorded for this segment.',
            'partialFirst' => 'First half',
            'partialSecond' => 'Second half',
            'noCapacity' => 'No eligible teacher is available for the full class',
            'booked' => 'Fully booked',
            'past' => 'Already passed',
            'unknown' => 'Unknown',
            'selected' => 'Selected',
            'aggregateGolden' => 'This ring aggregates week demand; the exact day eligibility is shown in each weekday ring.',
            'assumption' => 'Assumes the complete class plus buffer',
            'unavailable' => 'Coverage is not available.',
            'empty' => 'No coverage is available to display.',
        );
    }

    private static function gregorianFa(string $date): string {
        if ($date === '') return '';
        $months = array(1=>'ژانویه',2=>'فوریه',3=>'مارس',4=>'آوریل',5=>'مه',6=>'ژوئن',7=>'ژوئیه',8=>'اوت',9=>'سپتامبر',10=>'اکتبر',11=>'نوامبر',12=>'دسامبر');
        $value = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        $digits = static fn(int $n): string => str_replace(range(0,9), array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'), (string) $n);
        return $digits((int) $value->format('j')) . ' ' . $months[(int) $value->format('n')] . ' ' . $digits((int) $value->format('Y'));
    }

    private static function duration(int $minutes): string {
        $minutes = max(0, $minutes);
        if ($minutes === 0) return '0m';
        $hours = intdiv($minutes, 60);
        return ($hours > 0 ? $hours . 'h ' : '') . ($minutes % 60) . 'm';
    }

    /** @param mixed $value */
    public static function json($value): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_HEX_TAG')) $flags |= JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (function_exists('wp_json_encode')) return (string) wp_json_encode($value, $flags);
        return (string) json_encode($value, $flags);
    }
}
