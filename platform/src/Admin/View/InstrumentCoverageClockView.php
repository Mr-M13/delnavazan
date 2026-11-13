<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;

/**
 * Server-rendered shell for the interactive 24-hour instrument coverage clock.
 *
 * The data payload is deliberately compact (one small tuple per 15-minute
 * segment). The shared coverage-clock JavaScript turns it into an accessible SVG
 * clock, handles roving keyboard focus, segment selection and the details panel.
 * Without JavaScript the view still states the measured coverage in text.
 */
final class InstrumentCoverageClockView {
    private const STATE_CODES = array(
        InstrumentCoverageClockAnalyzer::STATE_UNCOVERED => 0,
        InstrumentCoverageClockAnalyzer::STATE_LIMITED => 1,
        InstrumentCoverageClockAnalyzer::STATE_COVERED => 2,
        InstrumentCoverageClockAnalyzer::STATE_UNKNOWN => 3,
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
        $status = (string) ($data['status'] ?? 'unavailable');
        $page = (string) ($options['page'] ?? 'dzn-academy-dashboard');
        $mode = (string) ($data['mode'] ?? InstrumentCoverageClockAnalyzer::MODE_DATE);
        $calendar = (string) ($data['calendar'] ?? 'gregorian');
        echo '<section class="dzn-clock" id="dzn-instrument-coverage" dir="ltr" aria-labelledby="dzn-clock-title" data-dzn-clock-root>';
        self::header($data, $page, $mode, $calendar);
        if ($status === 'unavailable' || $status === 'empty') {
            echo '<div class="notice ' . ($status === 'unavailable' ? 'notice-error' : 'notice-info') . ' inline"><p>' . esc_html((string) ($data['message'] ?? 'Coverage is not available.')) . '</p></div></section>';
            return;
        }
        $selected = self::selectedInstrument($data);
        if ($selected === null) {
            echo '<p class="dzn-clock__empty">' . esc_html((string) ($data['message'] ?? 'No instrument coverage is available.')) . '</p></section>';
            return;
        }
        if (empty($selected['clock']['segments'])) {
            self::instrumentCards($data, $selected);
            echo '<div class="notice notice-info inline"><p>' . esc_html((string) ($selected['message'] ?? 'No instrument with an active introductory course is available for this coverage view.')) . '</p></div></section>';
            return;
        }
        self::metrics($selected);
        self::instrumentCards($data, $selected);
        self::clockStage($selected);
        self::legend();
        self::footnote($selected);
        $payload = self::payload($data);
        echo '<script type="application/json" data-dzn-clock-data>' . self::json($payload) . '</script>';
        echo '</section>';
    }

    /** @param array<string,mixed> $data */
    private static function header(array $data, string $page, string $mode, string $calendar): void {
        $date = (string) ($data['date'] ?? '');
        $weekday = (int) ($data['weekday'] ?? 0);
        $weekLabels = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');
        $selected = (int) ($data['selected_instrument'] ?? 0);
        echo '<header class="dzn-clock__head"><div class="dzn-clock__intro"><p class="dzn-clock__eyebrow">Instrument capacity</p><h2 id="dzn-clock-title">24-hour coverage clock</h2><p class="dzn-clock__meta">Midnight at the top · clockwise · 15-minute segments · <bdi>' . esc_html((string) ($data['timezone_label'] ?? '')) . '</bdi></p><p class="dzn-clock__date-line"><strong>' . esc_html($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY ? 'Structural weekly coverage' : 'Actual bookable coverage') . '</strong></p></div>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-clock__filters" data-dzn-clock-filters>';
        echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        foreach (array('tab', 'view', 'stage') as $preserve) {
            if (isset($_GET[$preserve]) && is_scalar($_GET[$preserve]) && (string) $_GET[$preserve] !== '') {
                echo '<input type="hidden" name="' . esc_attr($preserve) . '" value="' . esc_attr(sanitize_key((string) $_GET[$preserve])) . '">';
            }
        }
        echo '<label for="dzn-clock-mode">View<select id="dzn-clock-mode" name="coverage_mode"><option value="weekly"' . selected($mode, 'weekly', false) . '>Weekly coverage</option><option value="date"' . selected($mode, 'date', false) . '>Actual date</option></select></label>';
        echo '<label for="dzn-clock-weekday">Weekday<select id="dzn-clock-weekday" name="coverage_weekday" data-dzn-clock-weekday-select' . ($mode === 'date' ? ' disabled' : '') . '>';
        foreach ($weekLabels as $value => $label) echo '<option value="' . $value . '"' . selected($weekday, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label>';
        echo '<label for="dzn-clock-date">Date<input id="dzn-clock-date" type="date" name="coverage_date" value="' . esc_attr($date) . '"></label>';
        echo '<label for="dzn-clock-instrument">Instrument<select id="dzn-clock-instrument" name="coverage_instrument">';
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            echo '<option value="' . (int) $instrument['id'] . '"' . selected($selected, (int) $instrument['id'], false) . '>' . esc_html(self::instrumentName($instrument)) . '</option>';
        }
        echo '</select></label>';
        echo '<label for="dzn-clock-calendar">Calendar<select id="dzn-clock-calendar" name="coverage_calendar"><option value="gregorian"' . selected($calendar, 'gregorian', false) . '>Gregorian</option><option value="persian"' . selected($calendar, 'persian', false) . '>Persian</option></select></label>';
        echo '<button class="button button-primary" type="submit">Show coverage</button></form></header>';
        $dateLabel = $calendar === 'persian' ? self::persianLabel($date) : self::dateLabel($data);
        if ($dateLabel === '') $dateLabel = self::dateLabel($data);
        echo '<div class="dzn-clock__period"><span class="dzn-clock__period-label" data-dzn-clock-period-label>' . esc_html($mode === InstrumentCoverageClockAnalyzer::MODE_WEEKLY ? (string) ($weekLabels[$weekday] ?? '') : $dateLabel) . '</span><span class="dzn-clock__period-sub" data-dzn-clock-period-sub>' . esc_html($dateLabel . ' · ' . (string) ($data['timezone_label'] ?? '')) . '</span></div>';
    }

    /** @param array<string,mixed> $instrument */
    private static function metrics(array $instrument): void {
        $metrics = (array) ($instrument['metrics'] ?? array());
        $coverage = $metrics['coverage_percent'] ?? null;
        $golden = $metrics['golden_coverage_percent'] ?? null;
        echo '<div class="dzn-clock__metrics" role="list" aria-label="Coverage summary">';
        self::metric('Coverage', $coverage === null ? '—' : (string) (int) $coverage . '%', 'of measured 24-hour segments', 'coverage');
        self::metric('Golden coverage', $golden === null ? 'No window' : (string) (int) $golden . '%', 'of Golden Hour segments', 'golden');
        self::metric('Uncovered', self::duration((int) ($metrics['uncovered_minutes'] ?? 0)), 'total measured gap time', 'uncovered');
        self::metric('One teacher', self::duration((int) ($metrics['single_teacher_minutes'] ?? 0)), 'single-teacher dependency', 'single');
        self::metric('Eligible teachers', (string) (int) ($instrument['eligible_teacher_count'] ?? 0), 'with active eligibility', 'teachers');
        self::metric('Golden gaps', self::duration((int) ($metrics['golden_uncovered_minutes'] ?? 0)), 'uncovered Golden Hours', 'golden-gap');
        echo '</div>';
        echo '<p class="dzn-clock__assumption">Capacity assumes <span data-dzn-clock-assumption>' . esc_html((int) ($instrument['lesson_minutes'] ?? 0) . '-minute class + ' . (int) ($instrument['buffer_minutes'] ?? 0) . '-minute buffer') . '</span> for this instrument.</p>';
    }

    private static function metric(string $label, string $value, string $help, string $key): void {
        echo '<div class="dzn-clock__metric dzn-clock__metric--' . esc_attr($key) . '" role="listitem" data-dzn-metric="' . esc_attr($key) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong><small>' . esc_html($help) . '</small></div>';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $selected */
    private static function instrumentCards(array $data, array $selected): void {
        echo '<div class="dzn-clock__instruments" role="list" aria-label="Instrument coverage overview">';
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            $id = (int) $instrument['id'];
            $metrics = (array) ($instrument['metrics'] ?? array());
            $status = (string) ($instrument['status'] ?? 'ok');
            $isSelected = $id === (int) $selected['id'];
            $coverage = $metrics['coverage_percent'] ?? null;
            echo '<button type="button" class="dzn-clock__instrument' . ($isSelected ? ' is-selected' : '') . ' dzn-clock__instrument--' . esc_attr($status) . '" role="listitem" data-dzn-clock-instrument="' . $id . '" aria-pressed="' . ($isSelected ? 'true' : 'false') . '">';
            echo '<span class="dzn-clock__instrument-name">' . esc_html(self::instrumentName($instrument)) . '</span>';
            echo '<span class="dzn-clock__instrument-value">' . ($coverage === null ? '—' : esc_html((string) (int) $coverage . '%')) . '</span>';
            echo '<span class="dzn-clock__instrument-meta">' . esc_html((function_exists('number_format_i18n') ? number_format_i18n((int) ($instrument['eligible_teacher_count'] ?? 0)) : number_format((int) ($instrument['eligible_teacher_count'] ?? 0))) . ' eligible') . '</span>';
            if ($status === 'not_bookable') echo '<span class="dzn-clock__badge dzn-clock__badge--warning">No intro course</span>';
            elseif ($status === 'unavailable') echo '<span class="dzn-clock__badge dzn-clock__badge--warning">Catalogue unavailable</span>';
            elseif ($status === 'no_eligible_teachers') echo '<span class="dzn-clock__badge dzn-clock__badge--warning">No eligible teacher</span>';
            elseif ($status === 'partial') echo '<span class="dzn-clock__badge dzn-clock__badge--warning">Partial data</span>';
            echo '</button>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $selected */
    private static function clockStage(array $selected): void {
        echo '<div class="dzn-clock__stage">';
        echo '<div class="dzn-clock__clock-wrap"><div class="dzn-clock__canvas" data-dzn-clock-canvas role="group" aria-label="24-hour coverage clock for ' . esc_attr(self::instrumentName($selected)) . '"><noscript><p>JavaScript is required to draw the interactive clock. ' . esc_html(self::noscriptSummary($selected)) . '</p></noscript></div></div>';
        echo '<aside class="dzn-clock__details" data-dzn-clock-details aria-live="polite" aria-label="Selected time segment"><p class="dzn-clock__details-empty">Select a segment to inspect remaining capacity. Use the arrow keys to move around the clock.</p></aside>';
        echo '</div>';
    }

    private static function legend(): void {
        echo '<div class="dzn-clock__legend" role="group" aria-label="Clock legend">';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--covered" aria-hidden="true"></i>Covered · 2+ teachers</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--limited" aria-hidden="true"></i>One teacher</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--uncovered" aria-hidden="true"></i>No remaining capacity</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--golden" aria-hidden="true"></i>Golden Hour · covered</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--golden-gap" aria-hidden="true"></i>Golden Hour · no capacity</span>';
        echo '<span><i class="dzn-clock__swatch dzn-clock__swatch--booked" aria-hidden="true"></i>Fully booked</span>';
        echo '</div>';
    }

    /** @param array<string,mixed> $selected */
    private static function footnote(array $selected): void {
        echo '<div class="dzn-clock__footnote"><p><strong>How to read this:</strong> a segment is coloured only when an eligible Teacher can accept the complete ' . (int) $selected['lesson_minutes'] . '-minute class plus ' . (int) $selected['buffer_minutes'] . '-minute buffer starting at that time. A free 15-minute hole is not a bookable class.</p><p>Actual-date mode includes confirmed classes, introductory booking offers and confirmations, active holds, dated exceptions and holidays. Weekly mode uses recurring availability only, so one date’s bookings are never treated as a structural loss. The booking-time scheduling authority remains the decision maker.</p></div>';
    }

    /** @param array<string,mixed> $selected */
    private static function noscriptSummary(array $selected): string {
        $metrics = (array) ($selected['metrics'] ?? array());
        $coverage = $metrics['coverage_percent'] ?? null;
        return (self::instrumentName($selected)) . ': ' . ($coverage === null ? 'coverage unavailable' : $coverage . '% of measured segments covered') . ', ' . (int) ($metrics['uncovered_minutes'] ?? 0) . ' uncovered minutes, ' . (int) ($selected['eligible_teacher_count'] ?? 0) . ' eligible teachers.';
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function dataPayload(array $data, array $labels = array(), bool $preferEnglishNames = true): array {
        $instruments = array();
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            $clocks = array();
            foreach ((array) ($instrument['clocks'] ?? array()) as $weekday => $clock) {
                $clocks[(string) $weekday] = self::compactClock((array) $clock);
            }
            $name = (string) $instrument['name'];
            $english = trim((string) ($instrument['name_en'] ?? ''));
            if ($preferEnglishNames && $english !== '') $name = $english;
            $instruments[] = array(
                'id' => (int) $instrument['id'],
                'name' => $name,
                'name_en' => (string) ($instrument['name_en'] ?? ''),
                'status' => (string) ($instrument['status'] ?? 'ok'),
                'lesson_minutes' => (int) ($instrument['lesson_minutes'] ?? 0),
                'buffer_minutes' => (int) ($instrument['buffer_minutes'] ?? 0),
                'eligible_teacher_count' => (int) ($instrument['eligible_teacher_count'] ?? 0),
                'unknown_teacher_count' => (int) ($instrument['unknown_teacher_count'] ?? 0),
                'clocks' => $clocks,
            );
        }
        return array(
            'mode' => (string) ($data['mode'] ?? 'date'),
            'timezone' => (string) ($data['display_timezone'] ?? ''),
            'timezone_label' => (string) ($data['timezone_label'] ?? ''),
            'calendar' => (string) ($data['calendar'] ?? 'gregorian'),
            'weekday' => (int) ($data['weekday'] ?? 1),
            'selected_instrument' => (int) ($data['selected_instrument'] ?? 0),
            'instruments' => $instruments,
            'labels' => array_merge(self::defaultLabels(), $labels),
        );
    }

    /** @return array<string,string|int> */
    public static function defaultLabels(): array {
        return array(
            'covered' => 'Covered',
            'limited' => 'One teacher',
            'uncovered' => 'No remaining capacity',
            'unknown' => 'Capacity unknown',
            'golden' => 'Golden Hour',
            'booked' => 'Fully booked',
            'past' => 'Already passed',
            'nonExistent' => 'Local time does not exist at this DST transition',
            'noAvailability' => 'No eligible teacher is available for the full class',
            'teachers' => 'eligible teachers',
            'teacher' => 'eligible teacher',
            'select' => 'Select a segment to inspect remaining capacity.',
            'selected' => 'Selected',
            'assumedLesson' => 'Assumes a complete class plus buffer',
            'clock' => '24-hour coverage clock',
            'capacity' => 'Remaining capacity',
            'declared' => 'Declared availability',
            'reason' => 'Note',
            'yes' => 'Yes',
            'no' => 'No',
            'noWindow' => 'No window',
            'proposed' => 'Proposed availability',
            'assumptionTemplate' => '{lesson}-minute class + {buffer}-minute buffer',
        );
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function payload(array $data): array {
        return self::dataPayload($data);
    }

    /** @param array<string,mixed> $clock @return array<string,mixed> */
    private static function compactClock(array $clock): array {
        $segments = array();
        foreach ((array) ($clock['segments'] ?? array()) as $segment) {
            $segments[] = array(
                (int) (self::STATE_CODES[(string) $segment['state']] ?? 0),
                (int) $segment['teacher_count'],
                (int) $segment['declared_teacher_count'],
                !empty($segment['golden']) ? 1 : 0,
                !empty($segment['past']) ? 1 : 0,
                (int) (self::REASON_CODES[(string) $segment['reason']] ?? 0),
            );
        }
        return array(
            'date' => (string) ($clock['date'] ?? ''),
            'weekday' => (int) ($clock['weekday'] ?? 1),
            'date_label' => (string) ($clock['date_label'] ?? ''),
            'date_label_fa' => (string) ($clock['date_label_fa'] ?? ''),
            'segments' => $segments,
            'summary' => (array) ($clock['summary'] ?? array()),
            'gaps' => array_map(static fn(array $gap): array => array((int) $gap['start_minute'], (int) $gap['end_minute'], (int) $gap['minutes']), (array) ($clock['gaps'] ?? array())),
            'golden_gaps' => array_map(static fn(array $gap): array => array((int) $gap['start_minute'], (int) $gap['end_minute'], (int) $gap['minutes']), (array) ($clock['golden_gaps'] ?? array())),
        );
    }

    /** @param array<string,mixed> $data */
    private static function selectedInstrument(array $data): ?array {
        $selected = (int) ($data['selected_instrument'] ?? 0);
        $fallback = null;
        $fallback = null;
        $firstWithClock = null;
        foreach ((array) ($data['instruments'] ?? array()) as $instrument) {
            if ($fallback === null) $fallback = $instrument;
            if (isset($instrument['clock']) && $firstWithClock === null) $firstWithClock = $instrument;
            if ((int) $instrument['id'] === $selected && isset($instrument['clock'])) return $instrument;
        }
        return $firstWithClock ?? $fallback;
    }

    /** @param array<string,mixed> $instrument */
    private static function instrumentName(array $instrument): string {
        $english = trim((string) ($instrument['name_en'] ?? ''));
        return $english !== '' ? $english : (string) ($instrument['name'] ?? 'Instrument');
    }

    /** @param array<string,mixed> $data */
    private static function dateLabel(array $data): string {        $date = (string) ($data['date'] ?? '');
        if ($date === '') return '';
        $value = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        return $value->format('j M Y');
    }

    private static function persianLabel(string $date): string {
        if ($date === '' || !class_exists('\\Delnavazan\\Platform\\Core\\Support\\PersianCalendar')) return '';
        $value = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        [$year, $month, $day] = \Delnavazan\Platform\Core\Support\PersianCalendar::toJalali((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));
        return $day . ' ' . \Delnavazan\Platform\Core\Support\PersianCalendar::PERSIAN_MONTHS[$month] . ' ' . $year;
    }

    private static function duration(int $minutes): string {
        $minutes = max(0, $minutes);
        if ($minutes === 0) return '0m';
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return ($hours > 0 ? $hours . 'h ' : '') . $rest . 'm';
    }

    /** @param mixed $value */
    public static function json($value): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_HEX_TAG')) $flags |= JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (function_exists('wp_json_encode')) return (string) wp_json_encode($value, $flags);
        return (string) json_encode($value, $flags);
    }
}
