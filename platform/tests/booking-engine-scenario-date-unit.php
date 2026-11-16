<?php
/**
 * Scenario-date regressions for the intro-booking stress audit.
 *
 * Codex review finding 2: scenarios E and F pinned allocation dates to December
 * 2026, so once those dates fell inside the engine's 25-hour minimum notice the
 * suite failed for calendar reasons instead of for an application regression.
 *
 * This runs on a plain PHP 8.3 host with no WordPress and no database. It proves
 * that every derived allocation date clears the real lead-time policy, and that
 * the DST cases land on a genuine transition rather than an arbitrary day.
 *
 * php platform/tests/booking-engine-scenario-date-unit.php
 */
namespace Delnavazan\Platform\Core\Application {
    final class BookingLeadTimePolicy {
        public const RESPONSE_WINDOW_SECONDS = 86400;
        public const PROJECTION_BUFFER_SECONDS = 3600;
        public const MINIMUM_LEAD_SECONDS = self::RESPONSE_WINDOW_SECONDS + self::PROJECTION_BUFFER_SECONDS;
        public static function minimumStartUtc(string $nowUtc, bool $includeResponseWindow = true): string {
            $offset = self::PROJECTION_BUFFER_SECONDS + ($includeResponseWindow ? self::RESPONSE_WINDOW_SECONDS : 0);
            return (new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))
                ->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds')->format('Y-m-d H:i:s');
        }
        public static function isStartAllowed(string $startUtc, string $nowUtc, bool $includeResponseWindow = true): bool {
            return $startUtc > self::minimumStartUtc($nowUtc, $includeResponseWindow);
        }
    }
}

namespace {
    require_once __DIR__ . '/booking-engine-stress-fixture.php';

    $failures = 0;
    $check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        if ($ok) { echo "ok   $label\n"; return; }
        $failures++;
        echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    };

    $nowUtc = gmdate('Y-m-d H:i:s');
    $Zones = array('UTC', 'Asia/Tehran', 'America/New_York', 'Australia/Brisbane', 'Pacific/Auckland', 'Europe/London');

    // 1. Every derived allocation date is beyond the 25-hour lead time at the
    //    earliest wall time it could ever be used with (00:00 local).
    $minMargin = PHP_INT_MAX;
    $minCase = '';
    $allAllowed = true;
    foreach (array(3, 4, 5, 6, 7, 8, 9, 10) as $offset) {
        foreach ($Zones as $zone) {
            $zoneObject = new DateTimeZone($zone);
            $date = dzn_stress_allocation_date($offset, $zone);
            $earliest = (new DateTimeImmutable($date . ' 00:00:00', $zoneObject))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if (!Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed($earliest, $nowUtc)) $allAllowed = false;
            $margin = strtotime($earliest . ' UTC') - strtotime($nowUtc . ' UTC');
            if ($margin < $minMargin) { $minMargin = $margin; $minCase = "+$offset days in $zone at $earliest"; }
        }
    }
    $check('every derived allocation date clears the 25-hour lead time in every tested zone',
        $allAllowed && $minMargin > 25 * 3600, 'smallest gap ' . round($minMargin / 3600, 2) . 'h at ' . $minCase);

    // 2. The guard is necessary: a two-day date is genuinely unsafe, and the
    //    helper refuses to produce one instead of silently returning it.
    $refused = false;
    try { dzn_stress_allocation_date(2, 'UTC'); } catch (Throwable $error) { $refused = true; }
    $check('the helper refuses a +2 day date as unsafe for allocation', $refused);

    $midnight = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $lateNowUtc = $midnight->modify('+23 hours 30 minutes')->format('Y-m-d H:i:s');
    $tooSoon = $midnight->modify('+2 days')->format('Y-m-d') . ' 00:00:00';
    $check('a +2 day date can fall inside the notice window, so the 3-day guard is required',
        !Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed($tooSoon, $lateNowUtc),
        "$tooSoon from $lateNowUtc is only 24.5 hours ahead");

    // 3. Derived dates are always in the future relative to today.
    foreach ($Zones as $zone) {
        $date = dzn_stress_allocation_date(4, $zone);
        $today = (new DateTimeImmutable('today', new DateTimeZone($zone)))->format('Y-m-d');
        $check("a derived date in $zone is in the future", $date > $today, "$date > $today");
    }

    // 4. The DST cases land on a real transition, in the right direction.
    foreach (array('America/New_York', 'Europe/London', 'Pacific/Auckland') as $zone) {
        $zoneObject = new DateTimeZone($zone);

        $spring = dzn_stress_dst_transition_date($zone, true);
        $springBefore = (new DateTimeImmutable($spring . ' 00:00:00', $zoneObject))->getOffset();
        $springAfter = (new DateTimeImmutable($spring . ' 23:59:59', $zoneObject))->getOffset();
        $check("the derived spring-forward date in $zone moves the clock forward one hour",
            $springAfter - $springBefore === 3600, "$spring: $springBefore -> $springAfter");
        $check("the derived spring-forward date in $zone is in the future",
            $spring >= (new DateTimeImmutable('today', $zoneObject))->format('Y-m-d'), $spring);

        $fall = dzn_stress_dst_transition_date($zone, false);
        $fallBefore = (new DateTimeImmutable($fall . ' 00:00:00', $zoneObject))->getOffset();
        $fallAfter = (new DateTimeImmutable($fall . ' 23:59:59', $zoneObject))->getOffset();
        $check("the derived fall-back date in $zone moves the clock back one hour",
            $fallBefore - $fallAfter === 3600, "$fall: $fallBefore -> $fallAfter");
        $check("the derived fall-back date in $zone is in the future",
            $fall >= (new DateTimeImmutable('today', $zoneObject))->format('Y-m-d'), $fall);
    }

    // 5. The DST dates are far enough ahead that the allocation path is never
    //    rejected by the lead time before the DST behaviour is even exercised.
    foreach (array(true, false) as $springForward) {
        $date = dzn_stress_dst_transition_date('America/New_York', $springForward);
        $start = (new DateTimeImmutable($date . ' 03:00:00', new DateTimeZone('America/New_York')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $check('the derived ' . ($springForward ? 'spring-forward' : 'fall-back') . ' DST case clears the lead time',
            Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed($start, $nowUtc), $start);
    }

    // 6. No allocation-path date literal is left behind in the scenario sources.
    foreach (array('e', 'f') as $scenario) {
        $source = (string) file_get_contents(__DIR__ . '/booking-engine-scenario-' . $scenario . '.php');
        $literal = preg_match("/dzn_stress_(?:time|request|confirmation_blocker)\([^;]*'20[0-9]{2}-[0-9]{2}-[0-9]{2} /s", $source) === 1;
        $check("scenario " . strtoupper($scenario) . ' passes no fixed calendar date through allocation', !$literal);
    }

    if ($failures > 0) {
        fwrite(STDERR, "Booking scenario date unit failed: $failures assertion(s)\n");
        exit(1);
    }
    echo "Booking scenario date unit passed\n";
}
