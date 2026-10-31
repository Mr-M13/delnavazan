<?php
/**
 * Pure Golden-hour teacher pay calculation and policy tests.
 *
 * No WordPress, database, provider, network or clock-dependent fixture is used.
 */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AcademyDemandWindowRule.php';
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/GoldenHourRule.php';
require_once $root . '/src/Core/Application/Finance/GoldenHourCalculator.php';
require_once $root . '/src/Core/Application/Finance/GoldenHourAreaResolver.php';

use Delnavazan\Platform\Core\Application\Finance\GoldenHourAreaResolver;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourCalculator;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;

$failures = array();
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$window = static function (string $start, string $end, int $basisPoints, array $days = array(1,2,3,4,5,6,7)): array {
    return array('weekdays'=>$days,'start'=>$start,'end'=>$end,'bonus_basis_points'=>$basisPoints);
};

// Disabled and 0% policies increase no liability.
$zero = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array(), 'Australia/Brisbane');
$check($zero['bonus_minor'] === 0 && $zero['total_minor'] === 4000, 'A disabled policy must leave base pay unchanged.');
$zeroPercent = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array($window('16:00','21:00',0)), 'Australia/Brisbane');
$check($zeroPercent['bonus_minor'] === 0 && $zeroPercent['total_minor'] === 4000, 'A 0% policy must leave base pay unchanged.');

// Full overlap: 06:00–07:00 UTC is 16:00–17:00 in Australia/Brisbane.
$full = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$check($full['eligible_minutes'] === 60 && $full['bonus_minor'] === 800 && $full['total_minor'] === 4800, 'Full overlap must earn base 40.00 + bonus 8.00.');

// Partial overlap: local 15:30–16:30 means only 30 of 60 minutes are inside.
$partial = GoldenHourCalculator::calculate(4000, '2026-10-05 05:30:00', '2026-10-05 06:30:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$check($partial['eligible_minutes'] === 30 && $partial['bonus_minor'] === 400 && $partial['total_minor'] === 4400, 'Partial overlap must apply 20% to half the payable session.');

// Zero overlap and exact half-open boundaries.
$before = GoldenHourCalculator::calculate(4000, '2026-10-05 04:00:00', '2026-10-05 05:00:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$atStart = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$atEnd = GoldenHourCalculator::calculate(4000, '2026-10-05 11:00:00', '2026-10-05 12:00:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$check($before['bonus_minor'] === 0, 'No overlap must pay zero bonus.');
$check($atStart['bonus_minor'] === 800, 'A lesson starting exactly at the window start is eligible.');
$check($atEnd['bonus_minor'] === 0, 'A lesson starting exactly at the window end is not eligible for that window.');

// The area timezone, not the Teacher/browser timezone, determines the result.
$au = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array($window('16:00','21:00',2000)), 'Australia/Brisbane');
$gb = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 07:00:00', array($window('16:00','21:00',2000)), 'Europe/London');
$check($au['bonus_minor'] === 800 && $gb['bonus_minor'] === 0, 'The same UTC lesson must differ by configured service-area timezone.');

// Daylight-saving transitions: local 16:00–21:00 moves in UTC but remains the same local window.
$dstSummer = GoldenHourCalculator::calculate(4000, '2026-10-24 15:00:00', '2026-10-24 20:00:00', array($window('16:00','21:00',2000)), 'Europe/London');
$dstWinter = GoldenHourCalculator::calculate(4000, '2026-10-25 16:00:00', '2026-10-25 21:00:00', array($window('16:00','21:00',2000)), 'Europe/London');
$dstSpring = GoldenHourCalculator::calculate(4000, '2026-03-29 15:00:00', '2026-03-29 20:00:00', array($window('16:00','21:00',2000)), 'Europe/London');
$check($dstSummer['bonus_minor'] === 800 && $dstWinter['bonus_minor'] === 800 && $dstSpring['bonus_minor'] === 800, 'A local window must survive both daylight-saving transitions.');

// Overlapping windows do not stack: the highest percentage applies once to each minute.
$overlap = GoldenHourCalculator::calculate(4000, '2026-10-05 06:00:00', '2026-10-05 11:00:00', array($window('16:00','19:00',2000), $window('18:00','21:00',3000)), 'Australia/Brisbane');
$check($overlap['eligible_minutes'] === 300 && $overlap['bonus_minor'] === 1040 && $overlap['total_minor'] === 5040, 'Overlapping windows must apply the highest percentage once per minute (26% weighted, not 50%).');
$breakdownSum = array_sum(array_column($overlap['breakdown'], 'bonus_minor'));
$check($breakdownSum === $overlap['bonus_minor'], 'The applied window breakdown must sum exactly to the total bonus.');

// Exact integer rounding.
$check(GoldenHourRule::percentageAmount(4005, 2000) === 801, 'Currency rounding must be exact half-up.');
$check(GoldenHourRule::percentageAmount(4004, 2000) === 801, 'Currency rounding must be applied once.');
$check(GoldenHourRule::percentageAmount(4002, 2000) === 800, 'Currency rounding must not round up below half a minor unit.');

// Percentage validation is finite, non-negative and bounded by the documented 100% limit.
foreach (array('0', '15', '20.5', '100', '100.00') as $valid) {
    try { GoldenHourRule::percentageToBasisPoints($valid); } catch (Throwable $error) { $failures[] = 'Valid percentage rejected: ' . $valid; }
}
foreach (array('-1', '100.01', '101', '1e2', 'NaN', 'INF', '', '20.555') as $invalid) {
    try { GoldenHourRule::percentageToBasisPoints($invalid); $failures[] = 'Invalid percentage accepted: ' . $invalid; } catch (InvalidArgumentException $expected) {}
}

// Missing and group-conflicting area mappings require review rather than guessing.
$missing = GoldenHourAreaResolver::resolve(null);
$check($missing['area'] === null && $missing['review_reason'] === GoldenHourRule::REVIEW_SERVICE_AREA_REQUIRED, 'A blank Enrolment service area must require explicit review.');
$explicit = GoldenHourAreaResolver::resolve('NA_EAST');
$check($explicit['area'] === 'NA_EAST' && $explicit['review_reason'] === null, 'North America East must come only from the explicit Enrolment key.');
foreach (array('US','CA','AUSTRALIA','Europe/London','golden') as $invalid) {
    try { GoldenHourAreaResolver::resolve($invalid); $failures[] = 'Non-canonical service area accepted: ' . $invalid; } catch (InvalidArgumentException $expected) {}
}

// Configuration validation rejects invalid values instead of silently changing them.
$defaults = GoldenHourRule::defaults();
$normalized = GoldenHourRule::normalizePolicy('AU', array(
    'enabled' => '1', 'timezone' => 'Australia/Brisbane', 'weekdays' => array(1,2,3,4,5),
    'start' => '16:00', 'end' => '21:00', 'bonus_percentage' => '20',
    'effective_from' => '2026-10-01 00:00:00', 'effective_until' => '2026-12-31 00:00:00',
), $defaults['AU']);
$check($normalized['bonus_basis_points'] === 2000 && $normalized['weekdays'] === array(1,2,3,4,5), 'A valid policy must normalise to exact basis points and weekdays.');
$check(!$defaults['AU']['enabled'] && $defaults['AU']['bonus_basis_points'] === 0, 'The structural default must be disabled at 0%.');
foreach (array(
    array('timezone' => 'Not/AZone', 'message' => 'invalid timezone'),
    array('start' => '21:00', 'end' => '16:00', 'message' => 'overnight window'),
    array('weekdays' => array(), 'message' => 'empty weekdays'),
    array('bonus_percentage' => '-5', 'message' => 'negative percentage'),
    array('effective_from' => 'not-a-date', 'message' => 'invalid effective date'),
    array('effective_until' => '2026-09-01 00:00:00', 'message' => 'effective-until before effective-from'),
) as $probe) {
    $input = array('enabled' => '1','timezone' => 'Australia/Brisbane','weekdays' => array(1,2,3,4,5),'start' => '16:00','end' => '21:00','bonus_percentage' => '20','effective_from' => '2026-10-01 00:00:00','effective_until' => '');
    $input = array_merge($input, $probe);
    unset($input['message']);
    try { GoldenHourRule::normalizePolicy('AU', $input, $defaults['AU']); $failures[] = 'Invalid policy accepted: ' . $probe['message']; } catch (InvalidArgumentException $expected) {}
}

// Versioned effective dates choose the version covering the occurrence, not today's page view.
$v1 = array('enabled'=>true,'timezone'=>'Australia/Brisbane','weekdays'=>array(1,2,3,4,5),'start'=>'16:00','end'=>'21:00','bonus_basis_points'=>2000,'effective_from'=>'2026-01-01 00:00:00','effective_until'=>null,'setting_version'=>1);
$v2 = array('enabled'=>true,'timezone'=>'Australia/Brisbane','weekdays'=>array(1,2,3,4,5),'start'=>'16:00','end'=>'21:00','bonus_basis_points'=>0,'effective_from'=>'2026-06-01 00:00:00','effective_until'=>null,'setting_version'=>2);
$check((int) GoldenHourRule::resolveVersion(array($v1,$v2), '2026-05-10 00:00:00')['bonus_basis_points'] === 2000, 'An occurrence before the new effective date must use the prior version.');
$check((int) GoldenHourRule::resolveVersion(array($v1,$v2), '2026-06-01 00:00:00')['bonus_basis_points'] === 0, 'An occurrence on the new effective date must use the new version.');

// The preview uses the actual configured currency, never a hard-coded pound sign.
$preview = GoldenHourRule::previewText(4000, 'AUD', 2000, 'Australia/Brisbane');
$check(str_contains($preview, 'A$40.00') && str_contains($preview, 'A$48.00') && str_contains($preview, 'A$8.00'), 'The preview must use the configured currency and actual amounts.');
$check(!str_contains($preview, '£'), 'The preview must not hard-code pounds.');

if ($failures !== array()) {
    fwrite(STDERR, "Golden-hour pay unit failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS golden-hour-pay-unit\n";
