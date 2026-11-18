<?php
/** Pure unit coverage for the shared regional pricing rules. */

namespace Delnavazan\Platform\Core\Application\Finance\Pricing {
    /**
     * Test double for the DB-backed regional authority.
     *
     * `IntroPricingService` accepts the concrete service, so the test declares the double in the
     * same namespace and never loads the real repository reader.
     */
    final class RegionalPricingService {
        public static array $regions = array();
        public function activeForRegion( string $region ): ?array { return self::$regions[ $region ] ?? null; }
    }
}

namespace {
    use Delnavazan\Platform\Core\Application\Finance\Pricing\IntroPricingService;
    use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
    use Delnavazan\Platform\Core\Application\Finance\Pricing\RegionalPricingRule;
    use Delnavazan\Platform\Core\Application\Finance\Pricing\RegionalPricingService;

    $root = dirname(__DIR__);
    require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
    require_once $root . '/src/Core/Application/Finance/Pricing/RegionalPricingRule.php';
    require_once $root . '/src/Core/Application/Finance/Pricing/IntroPricingService.php';

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
    $assert(RegionalPricingRule::DEFAULT_REGION === 'US', 'Unsupported countries must default to the US price region.');
    $assert(RegionalPricingRule::regionForCountry('AU') === 'AU', 'A directly supported country must keep its own region.');
    $assert(RegionalPricingRule::regionForCountry('de') === 'EU', 'A mapped euro country must resolve EU case-insensitively.');
    $assert(RegionalPricingRule::regionForCountry('IR') === 'US', 'A country without a linked price must fall back to US.');
    $assert(RegionalPricingRule::regionForCountry('HR') === 'US', 'A country outside the linked map must fall back to US rather than invent a region.');
    $assert(RegionalPricingRule::regionForCountry('') === 'US', 'An empty country must fall back to US.');

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

    // One booking request's contact country is the only input to region selection.
    final class IntroPricingUnitWpdb {
        public string $prefix = 'wp_';
        public array $countries = array();
        private array $args = array();
        public function prepare( string $sql, ...$args ): string { $this->args = $args; return $sql; }
        public function get_row( string $sql ): ?object {
            $id = (int) ( $this->args[0] ?? 0 );
            return isset( $this->countries[ $id ] ) ? (object) array( 'id' => $id, 'country' => $this->countries[ $id ] ) : null;
        }
    }
    $wpdb = new IntroPricingUnitWpdb();
    $wpdb->countries = array( 101 => 'IR', 102 => 'AU' );
    $GLOBALS['wpdb'] = $wpdb;

    // An unmapped country (IR is not a linked price region) uses the US/USD authority for both
    // presentation and finance. A paid US introduction must therefore create a USD obligation.
    RegionalPricingService::$regions = array(
        'AU' => array('region_code' => 'AU', 'currency' => 'AUD', 'standard_amount_minor' => 25000, 'introductory_mode' => 'paid', 'introductory_amount_minor' => 4000),
        'US' => array('region_code' => 'US', 'currency' => 'USD', 'standard_amount_minor' => 25000, 'introductory_mode' => 'paid', 'introductory_amount_minor' => 5000),
    );
    $unmapped = ( new IntroPricingService() )->forBooking( 101 );
    $assert($unmapped['configured'] === true && $unmapped['mode'] === RegionalPricingRule::INTRO_PAID && $unmapped['amount_minor'] === 5000 && $unmapped['currency'] === 'USD' && $unmapped['region_code'] === 'US', 'An unmapped country must resolve the US/USD price region.');

    // A mapped country still resolves its exact regional authority, including a paid gate.
    $mapped = ( new IntroPricingService() )->forBooking( 102 );
    $assert($mapped['configured'] === true && $mapped['mode'] === RegionalPricingRule::INTRO_PAID && $mapped['amount_minor'] === 4000 && $mapped['currency'] === 'AUD' && $mapped['region_code'] === 'AU', 'A mapped country must resolve its regional introduction.');

    // A missing default authority keeps the existing free fallback and names the default region.
    RegionalPricingService::$regions = array();
    $unconfigured = ( new IntroPricingService() )->forBooking( 101 );
    $assert($unconfigured['mode'] === RegionalPricingRule::INTRO_FREE && $unconfigured['amount_minor'] === 0 && $unconfigured['region_code'] === 'US', 'A missing US authority must keep the free fallback for an unmapped country.');
    $mappedMissing = ( new IntroPricingService() )->forBooking( 102 );
    $assert($mappedMissing['mode'] === RegionalPricingRule::INTRO_FREE && $mappedMissing['region_code'] === 'AU', 'A missing mapped authority must keep its region code and the free fallback.');

    unset($GLOBALS['wpdb']);

    echo "finance-pricing-unit: OK\n";
}
