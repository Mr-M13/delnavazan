<?php
namespace Delnavazan\Platform\Core\Application;

/** Pure seven-day arithmetic for the coverage clock. */
final class InstrumentCoverageWeek {
    /** Saturday, Sunday, Monday, Tuesday, Wednesday, Thursday, Friday as ISO weekdays. */
    public const ORDER = array(6, 7, 1, 2, 3, 4, 5);

    public static function start(string $date): string {
        $day = new \DateTimeImmutable(AvailabilityLocalTime::date($date) . ' 00:00:00', new \DateTimeZone('UTC'));
        $offset = ((int) $day->format('N') + 1) % 7;
        return $day->modify('-' . $offset . ' days')->format('Y-m-d');
    }

    /** @return array<int,string> ISO weekday => date, in display order. */
    public static function dates(string $start): array {
        $out = array();
        $day = new \DateTimeImmutable(AvailabilityLocalTime::date($start) . ' 00:00:00', new \DateTimeZone('UTC'));
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $day->modify('+' . $offset . ' days');
            $out[(int) $date->format('N')] = $date->format('Y-m-d');
        }
        return $out;
    }

    public static function end(string $start): string {
        return (new \DateTimeImmutable(AvailabilityLocalTime::date($start) . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
    }

    public static function shift(string $start, int $weeks): string {
        $day = new \DateTimeImmutable(AvailabilityLocalTime::date($start) . ' 00:00:00', new \DateTimeZone('UTC'));
        return $day->modify(($weeks >= 0 ? '+' : '') . ($weeks * 7) . ' days')->format('Y-m-d');
    }
}
