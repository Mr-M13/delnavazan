<?php
/**
 * Pure proof of the editable Iran golden-hour row contract: per-row percentage/effective date,
 * overnight windows and overlap refusal.
 */
require __DIR__ . '/../src/Core/Application/AcademyDemandWindowRule.php';
require __DIR__ . '/../src/Core/Application/AvailabilityLocalTime.php';
require __DIR__ . '/../src/Core/Application/Finance/Pricing/PricingMoney.php';
require __DIR__ . '/../src/Core/Application/Finance/GoldenHourRule.php';
require __DIR__ . '/../src/Core/Application/GoldenHourClockProjection.php';
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Application\GoldenHourClockProjection;

$fail = array();
$check = static function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };
$defaults = GoldenHourRule::globalDefinition();
$check($defaults['rules_configured'] === true, 'A fresh global policy must not present a default golden-hour rule');

$policy = GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE,
    'effective_from' => '2026-11-01 00:00:00',
    'rules' => array(
        array('weekdays' => array(1, 3, 5), 'start' => '06:00', 'end' => '10:00', 'bonus_percentage' => '20', 'effective_from' => '2026-11-01 00:00:00'),
        array('weekdays' => array(2), 'start' => '22:00', 'end' => '02:00', 'bonus_percentage' => '35.5', 'effective_from' => '2026-11-15 00:00:00'),
    ),
), $defaults);
$check(count($policy['rules']) === 2, 'Two rows must normalise');
$check($policy['rules'][0]['bonus_basis_points'] === 2000, '20% must become 2000 basis points');
$check($policy['rules'][1]['bonus_basis_points'] === 3550, '35.5% must become 3550 basis points');
$check($policy['rules'][0]['effective_from'] === '2026-11-01 00:00:00' && $policy['rules'][1]['effective_from'] === '2026-11-15 00:00:00', 'Each row must retain its effective date');
$check($policy['start'] === '06:00' && $policy['end'] === '10:00', 'Legacy fields must remain populated from the first row for compatibility');

$overlap = false;
try {
    GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
        'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE, 'effective_from' => '2026-11-01 00:00:00',
        'rules' => array(
            array('weekdays' => array(1), 'start' => '06:00', 'end' => '10:00', 'bonus_percentage' => '20'),
            array('weekdays' => array(1), 'start' => '09:00', 'end' => '12:00', 'bonus_percentage' => '25'),
        ),
    ), $defaults);
} catch (Throwable $error) { $overlap = $error->getMessage() === 'golden_hour_period_overlap'; }
$check($overlap, 'Overlapping rows on the same day must be refused');

$overnightOverlap = false;
try {
    GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
        'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE, 'effective_from' => '2026-11-01 00:00:00',
        'rules' => array(
            array('weekdays' => array(1), 'start' => '22:00', 'end' => '02:00', 'bonus_percentage' => '20'),
            array('weekdays' => array(2), 'start' => '01:00', 'end' => '03:00', 'bonus_percentage' => '25'),
        ),
    ), $defaults);
} catch (Throwable $error) { $overnightOverlap = $error->getMessage() === 'golden_hour_period_overlap'; }
$check($overnightOverlap, 'An overnight row must overlap the following day and be refused');

$legacy = GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE, 'weekdays' => array(1, 2, 3, 4, 5, 6, 7),
    'periods' => array(array('start' => '16:00', 'end' => '21:00')),
    'bonus_percentage' => '12.5', 'effective_from' => '2026-11-01 00:00:00',
), $defaults);
$check($legacy['rules'] === array() && $legacy['bonus_basis_points'] === 1250, 'A legacy period submission must remain accepted without inventing rule rows');

$empty = GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE,
    'effective_from' => '2026-11-20 00:00:00', 'rules' => array(),
), $defaults);
$check($empty['rules'] === array() && $empty['rules_configured'] === true, 'An explicit empty row list must remain an empty rule set');
$check($empty['periods'] === array() && $empty['weekdays'] === array() && $empty['enabled'] === false, 'An empty rule set must disable the bonus without recreating a default window');
$check(GoldenHourClockProjection::forDate($empty, '2026-11-20') === array(), 'An explicit empty rule set must not project a fallback coverage window');

$bad = false;
try {
    GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, array(
        'enabled' => '1', 'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE, 'effective_from' => '2026-11-01 00:00:00',
        'rules' => array(array('weekdays' => array(1), 'start' => '06:00', 'end' => '06:00', 'bonus_percentage' => '20')),
    ), $defaults);
} catch (Throwable $error) { $bad = $error->getMessage() === 'golden_hour_window_invalid'; }
$check($bad, 'A zero-length row must be refused');

if ($fail !== array()) {
    fwrite(STDERR, "golden-hour-rules-unit failures:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "golden-hour-rules-unit: OK\n";
