<?php
namespace Delnavazan\Platform\Core\Application\Finance\Pricing;

/**
 * Structural vocabulary for the single regional student-price authority.
 *
 * A region maps to exactly one currency. The amount is administrator data, never a
 * per-instrument or per-course value. Teacher compensation is a separate Toman authority.
 */
final class RegionalPricingRule {
    public const INTRO_FREE = 'free';
    public const INTRO_PAID = 'paid';
    public const INTRO_MODES = array(self::INTRO_FREE, self::INTRO_PAID);

    /** Preserved Delnavazan region → currency mapping. */
    public const REGIONS = array(
        'AU' => 'AUD', 'NZ' => 'NZD', 'US' => 'USD', 'CA' => 'CAD', 'EU' => 'EUR', 'GB' => 'GBP',
    );

    public const CURRENCIES = array('AUD', 'NZD', 'USD', 'CAD', 'EUR', 'GBP', 'TOM');

    public const REGION_LABELS = array(
        'AU' => array('fa' => 'استرالیا', 'en' => 'Australia'),
        'NZ' => array('fa' => 'نیوزیلند', 'en' => 'New Zealand'),
        'US' => array('fa' => 'ایالات متحده', 'en' => 'United States'),
        'CA' => array('fa' => 'کانادا', 'en' => 'Canada'),
        'EU' => array('fa' => 'منطقهٔ یورو', 'en' => 'Euro area'),
        'GB' => array('fa' => 'بریتانیا', 'en' => 'United Kingdom'),
    );

    /** Preserved country-to-region mapping; country is only an input to region selection. */
    public const COUNTRY_TO_REGION = array(
        'AU' => 'AU', 'NZ' => 'NZ', 'US' => 'US', 'CA' => 'CA', 'GB' => 'GB',
        'DE' => 'EU', 'FR' => 'EU', 'NL' => 'EU', 'ES' => 'EU', 'IT' => 'EU',
        'AT' => 'EU', 'BE' => 'EU', 'IE' => 'EU', 'PT' => 'EU', 'FI' => 'EU',
        'GR' => 'EU', 'LU' => 'EU', 'CY' => 'EU', 'SK' => 'EU', 'SI' => 'EU',
        'EE' => 'EU', 'LV' => 'EU', 'LT' => 'EU',
    );

    public static function region(string $regionCode): string {
        $region = strtoupper(trim($regionCode));
        if (!isset(self::REGIONS[$region])) throw new \InvalidArgumentException('unsupported_region');
        return $region;
    }

    public static function currencyForRegion(string $regionCode): string {
        return self::REGIONS[self::region($regionCode)];
    }

    public static function currency(string $currency): string {
        $currency = strtoupper(trim($currency));
        if (!in_array($currency, self::CURRENCIES, true)) throw new \InvalidArgumentException('unsupported_currency');
        return $currency;
    }

    /**
     * Validate one administrator-entered standard price.
     *
     * @return array{region_code:string,currency:string,standard_amount_minor:int,introductory_mode:string,introductory_amount_minor:?int}
     */
    public static function standardPrice(array $input): array {
        $region = self::region((string) ($input['region_code'] ?? ''));
        $currency = self::currency((string) ($input['currency'] ?? self::currencyForRegion($region)));
        if ($currency !== self::currencyForRegion($region)) throw new \InvalidArgumentException('currency_region_mismatch');
        $amountMinor = PricingMoney::toMinor((string) ($input['standard_price'] ?? ''), $currency);
        if ($amountMinor < 1) throw new \InvalidArgumentException('standard_price_required');
        $mode = strtolower(trim((string) ($input['introductory_mode'] ?? self::INTRO_FREE)));
        if (!in_array($mode, self::INTRO_MODES, true)) throw new \InvalidArgumentException('invalid_introductory_mode');
        $introMinor = null;
        if ($mode === self::INTRO_PAID) {
            $introMinor = PricingMoney::toMinor((string) ($input['introductory_price'] ?? ''), $currency);
            if ($introMinor < 1) throw new \InvalidArgumentException('introductory_price_required');
        }
        return array(
            'region_code' => $region,
            'currency' => $currency,
            'standard_amount_minor' => $amountMinor,
            'introductory_mode' => $mode,
            'introductory_amount_minor' => $introMinor,
        );
    }

    /** The intro amount is always zero for a free introduction, never a stale paid amount. */
    public static function introductoryAmountMinor(string $mode, ?int $amountMinor): int {
        return $mode === self::INTRO_PAID ? max(0, (int) $amountMinor) : 0;
    }

    public static function introLabel(string $mode, ?int $amountMinor, string $currency, bool $persian = false): string {
        if ($mode !== self::INTRO_PAID) return $persian ? 'کلاس آشنایی رایگان' : 'Free introductory lesson';
        $amount = PricingMoney::format((int) $amountMinor, $currency, $persian);
        return $persian ? 'کلاس آشنایی ' . $amount : 'Introductory lesson ' . $amount;
    }
}
