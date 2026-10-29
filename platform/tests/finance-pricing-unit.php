<?php
/** Pure unit coverage for the shared regional pricing rules. */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/Pricing/RegionalPricingRule.php';

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Pricing\RegionalPricingRule;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$throws = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
};

$assert(PricingMoney::toMinor('40.00', 'AUD') === 4000, 'AUD 40.00 must be 4000 minor units');
$assert(PricingMoney::toMinor('40', 'AUD') === 4000, 'AUD 40 must be 4000 minor units');
$assert(PricingMoney::toMinor('۱۲۰٫۵۰', 'EUR') === 12050, 'Persian digits and decimal separator must convert exactly');
$assert(PricingMoney::toMinor('1,200,000', 'TOM') === 1200000, 'Toman accepts grouped whole units');
$assert(PricingMoney::fromMinor(4000, 'AUD') === '40.00', 'AUD minor units must present two decimals');
$assert(PricingMoney::fromMinor(4500000, 'TOM') === '4500000', 'Toman must present whole units');
$throws(static fn() => PricingMoney::toMinor('40.001', 'AUD'), 'Sub-minor precision must be refused, never rounded');
$throws(static fn() => PricingMoney::toMinor('40.5', 'TOM'), 'Toman must refuse fractional units');
$throws(static fn() => PricingMoney::toMinor('', 'AUD'), 'Empty price must be refused');

$assert(RegionalPricingRule::currencyForRegion('AU') === 'AUD', 'Preserved AU→AUD mapping');
$assert(RegionalPricingRule::currencyForRegion('GB') === 'GBP', 'Preserved GB→GBP mapping');
$assert(RegionalPricingRule::COUNTRY_TO_REGION['DE'] === 'EU', 'Country-to-region mapping must be preserved');

$free = RegionalPricingRule::standardPrice(array('region_code' => 'AU', 'currency' => 'AUD', 'standard_price' => '40.00', 'introductory_mode' => 'free'));
$assert($free['standard_amount_minor'] === 4000 && $free['introductory_amount_minor'] === null, 'Free introduction carries no amount');
$assert(RegionalPricingRule::introductoryAmountMinor('free', 9999) === 0, 'Free introduction is always zero even if a stale amount is supplied');
$paid = RegionalPricingRule::standardPrice(array('region_code' => 'GB', 'currency' => 'GBP', 'standard_price' => '60.00', 'introductory_mode' => 'paid', 'introductory_price' => '15.00'));
$assert($paid['introductory_amount_minor'] === 1500, 'Paid introduction keeps its exact configured amount');
$throws(static fn() => RegionalPricingRule::standardPrice(array('region_code' => 'AU', 'currency' => 'USD', 'standard_price' => '40.00')), 'Currency must match the region');
$throws(static fn() => RegionalPricingRule::standardPrice(array('region_code' => 'GB', 'currency' => 'GBP', 'standard_price' => '60.00', 'introductory_mode' => 'paid', 'introductory_price' => '')), 'Paid introduction requires an amount');

// The same standard amount is selected for every Course in a region because the rule has no
// instrument/course parameter at all. Different regions may configure different amounts.
$au = RegionalPricingRule::standardPrice(array('region_code' => 'AU', 'currency' => 'AUD', 'standard_price' => '40.00'));
$us = RegionalPricingRule::standardPrice(array('region_code' => 'US', 'currency' => 'USD', 'standard_price' => '55.00'));
$assert($au['standard_amount_minor'] !== $us['standard_amount_minor'], 'Different manually configured regions may use different prices');
$assert(!array_key_exists('course_id', $au) && !array_key_exists('instrument_id', $au), 'Standard pricing must not be keyed by Course or Instrument');

echo "finance-pricing-unit: OK\n";
