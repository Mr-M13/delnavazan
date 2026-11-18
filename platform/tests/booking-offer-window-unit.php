<?php
/** Pure unit coverage for the time-budgeted introductory offer window. */
namespace {
    require_once dirname(__DIR__) . '/src/Core/Application/BookingLeadTimePolicy.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingAllocationService.php';

    use Delnavazan\Platform\Core\Application\BookingAllocationService;
    use Delnavazan\Platform\Core\Application\BookingLeadTimePolicy;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $now = '2026-11-19 00:00:00';

    $assert(BookingLeadTimePolicy::MIN_WINDOW_SECONDS === 14400, 'The minimum offer window must be four hours.');
    $assert(BookingLeadTimePolicy::TERMINAL_ESCALATION_SECONDS === 43200, 'The terminal stop must be T−12h.');
    $assert(BookingLeadTimePolicy::RESPONSE_WINDOW_SECONDS === 86400, 'The 24-hour window must remain the maximum.');

    // A request 36h away splits the 24h budget across three Teachers: 6h each.
    $assert(BookingAllocationService::deadlineFor('2026-11-20 12:00:00', $now, 3) === '2026-11-19 06:00:00', 'A 36h request with three Teachers must get a 6h first window.');
    $assert(BookingAllocationService::deadlineFor('2026-11-20 12:00:00', $now, 2) === '2026-11-19 08:00:00', 'The same 24h budget across two Teachers must get an 8h window.');

    // A request 26h away produces three four-hour windows, then the terminal gate.
    $assert(BookingAllocationService::deadlineFor('2026-11-20 02:00:00', $now, 3) === '2026-11-19 04:00:00', 'A 26h request with three Teachers must get a 4h first window.');
    $assert(BookingAllocationService::deadlineFor('2026-11-20 02:00:00', '2026-11-19 04:00:00', 3) === '2026-11-19 08:00:00', 'The second 4h window must start at the first deadline.');
    $assert(BookingAllocationService::deadlineFor('2026-11-20 02:00:00', '2026-11-19 08:00:00', 3) === '2026-11-19 12:00:00', 'The third 4h window must end before the terminal stop.');
    $assert(BookingAllocationService::deadlineFor('2026-11-20 02:00:00', '2026-11-19 12:00:00', 3) === null, 'A fourth window inside T−12h must be refused.');

    // The terminal stop wins over the formula, including the exact boundary.
    $assert(BookingAllocationService::deadlineFor('2026-11-19 15:59:59', $now, 0) === null, 'A start one second inside the offer lead must not be offered.');
    $assert(BookingAllocationService::deadlineFor('2026-11-19 16:00:00', $now, 0) === '2026-11-19 04:00:00', 'Exactly 4h before T−12h is the last offerable instant.');
    $assert(!BookingLeadTimePolicy::isStartOfferable('2026-11-19 16:00:00', $now), 'The offer boundary is strict.');
    $assert(BookingLeadTimePolicy::isStartOfferable('2026-11-19 16:00:01', $now), 'One second past the offer boundary is offerable.');

    // The maximum window still applies to a request several days away.
    $assert(BookingAllocationService::deadlineFor('2026-11-21 00:00:00', $now, 0) === '2026-11-20 00:00:00', 'A distant request must keep the 24h maximum, not an unbounded window.');

    // The sole-Teacher hold extends the same offer past T−12h, capped at T−1h.
    $assert(BookingAllocationService::deadlineFor('2026-11-19 14:00:00', '2026-11-19 00:00:00', 1, false) === '2026-11-19 04:00:00', 'A hold may extend past T−12h up to T−1h.');
    $assert(BookingAllocationService::deadlineFor('2026-11-19 02:00:00', '2026-11-19 00:00:00', 1, false) === '2026-11-19 01:00:00', 'A hold deadline must never pass T−1h.');
    $assert(BookingAllocationService::deadlineFor('2026-11-19 00:30:00', '2026-11-19 00:00:00', 1, false) === null, 'A request already inside the projection buffer cannot be held.');

    // A multi-time request uses the chosen time for the offer and the earliest for the request.
    $assert(BookingAllocationService::deadlineFor('2026-11-20 12:00:00', $now, 3) !== BookingAllocationService::deadlineFor('2026-11-22 12:00:00', $now, 3), 'The window must follow the offered start, not a fixed request age.');

    echo "Booking offer window unit passed\n";
}
