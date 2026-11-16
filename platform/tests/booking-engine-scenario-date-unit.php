<?php
/**
 * Scenario-date regressions for the intro-booking stress audit.
 *
 * Codex review finding 2: scenarios E and F pinned allocation dates to December
 * 2026, so once those dates fell inside the engine's 25-hour minimum notice the
 * suite failed for calendar reasons instead of for an application regression.
 *
 * This runs on a plain PHP 8.3 host with no WordPress and no database. It proves
 * that every derived allocation date clears the real lead-time policy, that the
 * DST cases land on a genuine transition rather than an arbitrary day, and that
 * the day grid, the intake transaction and the allocator agree on one boundary
 * at one instant.
 *
 * php platform/tests/booking-engine-scenario-date-unit.php
 */
namespace {
    require_once __DIR__ . '/booking-engine-stress-fixture.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingLeadTimePolicy.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingLeadTimeException.php';
    require_once dirname(__DIR__) . '/src/Core/Application/AvailabilityLocalTime.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Normalizer.php';
    require_once dirname(__DIR__) . '/src/Core/Application/RequestedTimeNormalizer.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingRequestValidationService.php';

    use Delnavazan\Platform\Core\Application\BookingLeadTimeException;
    use Delnavazan\Platform\Core\Application\BookingLeadTimePolicy;
    use Delnavazan\Platform\Core\Application\BookingRequestValidationService;

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

    // 7. The day grid, the intake transaction and the allocator share one
    //    boundary: the same rule, the same strictness, evaluated at one instant.
    //    A start exactly 24h+1h ahead is unavailable everywhere; one second later
    //    it is requestable everywhere.
    $boundaryNow = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $boundaryStart = static fn(int $seconds): string => (new DateTimeImmutable($boundaryNow, new DateTimeZone('UTC')))
        ->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    $validator = new BookingRequestValidationService();
    $instrument = (object) array('id' => 1, 'status' => 'active', 'archived_at' => null);
    $course = (object) array('id' => 2, 'status' => 'active', 'archived_at' => null, 'course_type' => 'introductory', 'instrument_id' => 1, 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15);
    $boundaryCases = array(
        '24h59m59s' => array($boundaryStart(89999), false),
        'exactly 25h' => array($boundaryStart(90000), false),
        '25h1s' => array($boundaryStart(90001), true),
    );
    foreach ($boundaryCases as $label => $case) {
        [$startUtc, $expectedAllowed] = $case;
        $gridAllows = BookingLeadTimePolicy::isStartAllowed($startUtc, $boundaryNow);
        $intakeAllows = true;
        try {
            $intake = array(
                'course_id' => 2,
                'contact' => array('timezone' => 'UTC'),
                'requested_times' => array(array('local_date' => substr($startUtc, 0, 10), 'local_start_time' => substr($startUtc, 11), 'timezone' => 'UTC')),
            );
            $validator->resolveLockedCatalogue($intake, $instrument, $course, $boundaryNow);
        } catch (BookingLeadTimeException $error) {
            $intakeAllows = false;
        }
        $allocationBlocks = BookingLeadTimePolicy::allStartsInsideMinimumNotice(array($startUtc), $boundaryNow);
        $check("day grid and intake agree at $label", $gridAllows === $intakeAllows && $gridAllows === $expectedAllowed,
            'grid=' . var_export($gridAllows, true) . ' intake=' . var_export($intakeAllows, true) . ' start=' . $startUtc);
        $check("the allocator classifies $label exactly as the grid does", $allocationBlocks === !$gridAllows,
            'blocks=' . var_export($allocationBlocks, true) . ' grid=' . var_export($gridAllows, true));
    }

    // The crossed-boundary classifier is only true when nothing is still
    // requestable, so a request with one valid alternative never gets the
    // retryable lead-time reason.
    $check('the classifier reports a fully crossed request as inside the notice window',
        BookingLeadTimePolicy::allStartsInsideMinimumNotice(array($boundaryStart(89999), $boundaryStart(90000)), $boundaryNow));
    $check('one still-requestable start prevents the crossed-boundary classification',
        !BookingLeadTimePolicy::allStartsInsideMinimumNotice(array($boundaryStart(89999), $boundaryStart(90001)), $boundaryNow));
    $check('an empty request is never classified as a notice-window refusal',
        !BookingLeadTimePolicy::allStartsInsideMinimumNotice(array(), $boundaryNow));

    // The allocator must not re-derive the boundary; it consumes the shared
    // predicate and turns a fully crossed request into its own retryable reason.
    $allocationSource = (string) file_get_contents(dirname(__DIR__) . '/src/Core/Application/BookingAllocationService.php');
    $check('allocation consumes the shared boundary predicate instead of re-deriving it',
        strpos($allocationSource, 'BookingLeadTimePolicy::isStartAllowed') !== false && strpos($allocationSource, '$minStart') === false);
    $check('allocation classifies a crossed boundary with the shared predicate',
        strpos($allocationSource, 'BookingLeadTimePolicy::allStartsInsideMinimumNotice') !== false
        && strpos($allocationSource, 'LEAD_TIME_REFERRAL_REASON') !== false
        && strpos($allocationSource, "'requested_time_inside_lead_time'") !== false);
    $controllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/Controller/BookingRequestController.php');
    $check('the coordination queue explains the crossed-boundary referral instead of showing a missing Teacher',
        strpos($controllerSource, 'BookingAllocationService::LEAD_TIME_REFERRAL_REASON') !== false);

    if ($failures > 0) {
        fwrite(STDERR, "Booking scenario date unit failed: $failures assertion(s)\n");
        exit(1);
    }
    echo "Booking scenario date unit passed\n";
}
