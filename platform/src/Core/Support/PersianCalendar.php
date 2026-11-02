<?php
namespace Delnavazan\Platform\Core\Support;

/**
 * Pure Gregorian/Jalali conversion and Asia/Tehran month boundaries.
 *
 * The conversion is the well-known 33-year-cycle algorithm used by the Persian calendar and is
 * deliberately dependency-free so month boundaries can be proven without WordPress, ICU or a
 * database. Callers that need a presentation label should still use the theme's ICU formatter;
 * this class owns only arithmetic and instant boundaries.
 */
final class PersianCalendar {
    public const DEFAULT_TIMEZONE = 'Asia/Tehran';
    public const PERSIAN_MONTHS = array(
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    );

    /** Gregorian date to Jalali [year, month, day]. */
    public static function toJalali(int $gy, int $gm, int $gd): array {
        self::assertGregorian($gy, $gm, $gd);
        $gDaysInMonth = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $gDaysInMonth[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
        return array($jy, $jm, $jd);
    }

    /** Jalali date to Gregorian [year, month, day]. */
    public static function toGregorian(int $jy, int $jm, int $jd): array {
        self::assertJalali($jy, $jm, $jd);
        if ($jy > 979) { $gy = 1600; $jy -= 979; } else { $gy = 621; }
        $days = (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + 78 + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy += 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $days--;
            $gy += intdiv($days, 365);
            $days %= 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $monthDays = array(1 => 31, 2 => $leap ? 29 : 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31);
        for ($gm = 1; $gm <= 12; $gm++) {
            if ($gd <= $monthDays[$gm]) break;
            $gd -= $monthDays[$gm];
        }
        return array($gy, $gm, $gd);
    }

    /** The 33-year-cycle leap set used by the conversion above. */
    public static function isLeap(int $jy): bool {
        if ($jy < 1) return false;
        $remainder = (($jy % 33) + 33) % 33;
        return in_array($remainder, array(1, 5, 9, 13, 17, 22, 26, 30), true);
    }

    public static function monthLength(int $jy, int $jm): int {
        if ($jm < 1 || $jm > 12) throw new \InvalidArgumentException('persian_month_invalid');
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;
        return self::isLeap($jy) ? 30 : 29;
    }

    public static function monthLabel(int $jy, int $jm, bool $persian = true): string {
        if ($jm < 1 || $jm > 12) throw new \InvalidArgumentException('persian_month_invalid');
        if ($persian) return self::PERSIAN_MONTHS[$jm] . ' ' . $jy;
        return sprintf('%04d-%02d', $jy, $jm);
    }

    /**
     * Half-open Asia/Tehran month range for a Jalali `YYYY-MM` key.
     *
     * The local start is 00:00:00 on the first day and the local end is 00:00:00 on the first day
     * of the following month, converted to UTC. This is the boundary the statement scheduler and
     * the teacher summary must use; a UTC calendar month is never relabelled as Persian.
     *
     * @return array{key:string,year:int,month:int,start_local:string,end_local:string,start_utc:string,end_utc:string,label:string,label_fa:string,timezone:string}
     */
    public static function monthRange(string $yearMonth, string $timezone = self::DEFAULT_TIMEZONE): array {
        if (preg_match('/^(\d{3,4})-(0[1-9]|1[0-2])$/D', trim($yearMonth), $matches) !== 1) {
            throw new \InvalidArgumentException('persian_month_invalid');
        }
        $jy = (int) $matches[1];
        $jm = (int) $matches[2];
        $zone = self::zone($timezone);
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, 1);
        $startLocal = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $gy, $gm, $gd), $zone);
        $nextYear = $jy;
        $nextMonth = $jm + 1;
        if ($nextMonth > 12) { $nextYear++; $nextMonth = 1; }
        [$ngy, $ngm, $ngd] = self::toGregorian($nextYear, $nextMonth, 1);
        $endLocal = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $ngy, $ngm, $ngd), $zone);
        return self::shape($jy, $jm, $startLocal, $endLocal, $zone);
    }

    /**
     * The Jalali month containing a UTC instant, with its complete half-open range.
     *
     * @return array{key:string,year:int,month:int,start_local:string,end_local:string,start_utc:string,end_utc:string,label:string,label_fa:string,timezone:string}
     */
    public static function monthForInstant(string $utc, string $timezone = self::DEFAULT_TIMEZONE): array {
        $zone = self::zone($timezone);
        $instant = self::utcInstant($utc)->setTimezone($zone);
        [$jy, $jm] = self::toJalali((int) $instant->format('Y'), (int) $instant->format('n'), (int) $instant->format('j'));
        return self::monthRange(sprintf('%04d-%02d', $jy, $jm), $timezone);
    }

    /**
     * The previous completed Jalali month for a UTC instant.
     *
     * @return array{key:string,year:int,month:int,start_local:string,end_local:string,start_utc:string,end_utc:string,label:string,label_fa:string,timezone:string}
     */
    public static function previousMonthForInstant(string $utc, string $timezone = self::DEFAULT_TIMEZONE): array {
        $current = self::monthForInstant($utc, $timezone);
        $year = (int) $current['year'];
        $month = (int) $current['month'] - 1;
        if ($month < 1) { $year--; $month = 12; }
        return self::monthRange(sprintf('%04d-%02d', $year, $month), $timezone);
    }

    /**
     * A completed custom inclusive local date range as a half-open UTC range.
     *
     * @return array{key:string,start_local:string,end_local:string,start_utc:string,end_utc:string,label:string,label_fa:string,timezone:string}
     */
    public static function customRange(string $startDate, string $endDate, string $timezone = self::DEFAULT_TIMEZONE): array {
        foreach (array($startDate, $endDate) as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) throw new \InvalidArgumentException('report_date_invalid');
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('report_date_invalid');
        }
        if ($endDate < $startDate) throw new \InvalidArgumentException('report_period_invalid');
        $zone = self::zone($timezone);
        $start = new \DateTimeImmutable($startDate . ' 00:00:00', $zone);
        $end = (new \DateTimeImmutable($endDate . ' 00:00:00', $zone))->modify('+1 day');
        if ($start->diff($end)->days > 366) throw new \InvalidArgumentException('report_period_too_long');
        return self::shape(0, 0, $start, $end, $zone, $startDate . ' – ' . $endDate, $startDate . ' – ' . $endDate);
    }

    /** True when the instant is the first local day of a Jalali month in the timezone. */
    public static function isFirstDayOfMonth(string $utc, string $timezone = self::DEFAULT_TIMEZONE): bool {
        $local = self::utcInstant($utc)->setTimezone(self::zone($timezone));
        $jalali = self::toJalali((int)$local->format('Y'), (int)$local->format('n'), (int)$local->format('j'));
        return (int)$jalali[2] === 1;
    }

    /**
     * @param \DateTimeImmutable $startLocal
     * @param \DateTimeImmutable $endLocal
     * @return array<string,mixed>
     */
    private static function shape(int $jy, int $jm, \DateTimeImmutable $startLocal, \DateTimeImmutable $endLocal, \DateTimeZone $zone, ?string $label = null, ?string $labelFa = null): array {
        $utc = new \DateTimeZone('UTC');
        $key = $jy > 0 ? sprintf('%04d-%02d', $jy, $jm) : $startLocal->format('Y-m-d') . '..' . $endLocal->modify('-1 day')->format('Y-m-d');
        return array(
            'key' => $key,
            'year' => $jy,
            'month' => $jm,
            'start_local' => $startLocal->format('Y-m-d H:i:s'),
            'end_local' => $endLocal->format('Y-m-d H:i:s'),
            'start_utc' => $startLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc' => $endLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            'label' => $label ?? ($jy > 0 ? sprintf('%04d-%02d', $jy, $jm) : ''),
            'label_fa' => $labelFa ?? ($jy > 0 ? self::monthLabel($jy, $jm, true) : ''),
            'timezone' => $zone->getName(),
        );
    }

    private static function zone(string $timezone): \DateTimeZone {
        try { return new \DateTimeZone($timezone); } catch (\Throwable) { throw new \InvalidArgumentException('persian_timezone_invalid'); }
    }

    private static function utcInstant(string $value): \DateTimeImmutable {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) throw new \InvalidArgumentException('persian_instant_invalid');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) throw new \InvalidArgumentException('persian_instant_invalid');
        return $parsed;
    }

    private static function assertJalali(int $jy, int $jm, int $jd): void {
        if ($jy < 1 || $jy > 9999 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > self::monthLength($jy, $jm)) {
            throw new \InvalidArgumentException('persian_date_invalid');
        }
    }

    private static function assertGregorian(int $gy, int $gm, int $gd): void {
        if ($gy < 1 || $gy > 9999 || $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) throw new \InvalidArgumentException('gregorian_date_invalid');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-n-j', $gy . '-' . $gm . '-' . $gd, new \DateTimeZone('UTC'));
        if (!$parsed || (int) $parsed->format('Y') !== $gy || (int) $parsed->format('n') !== $gm || (int) $parsed->format('j') !== $gd) {
            throw new \InvalidArgumentException('gregorian_date_invalid');
        }
    }
}
