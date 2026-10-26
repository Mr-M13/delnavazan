<?php
namespace Delnavazan\Platform\Core\Application\Finance\Pricing;

/**
 * Exact money conversion for administrator-entered finance values.
 *
 * Delnavazan stores money as integer minor units. Administrators type normal currency
 * units ("40.00", "۲۵۰", "1,200,000"). This class is deliberately dependency-free so the
 * same conversion is used by the public pricing surface, checkout and administration.
 */
final class PricingMoney {
    /** Currencies whose minor unit is not two decimal places. */
    private const ZERO_DIGIT_CURRENCIES = array('TOM', 'IRR', 'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF');

    public static function minorDigits(string $currency): int {
        $currency = strtoupper(trim($currency));
        if (in_array($currency, self::ZERO_DIGIT_CURRENCIES, true)) return 0;
        return 2;
    }

    /** Normalise Persian/Arabic digits and common thousands/decimal separators. */
    public static function normalizeNumber(string $value): string {
        $value = trim($value);
        $value = strtr($value, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', '،' => '', ',' => '', ' ' => '', "\u{200f}" => '', "\u{200e}" => '',
        ));
        return $value;
    }

    /**
     * Convert a human-entered amount to exact minor units.
     *
     * @throws \InvalidArgumentException when the amount is not exact for the currency.
     */
    public static function toMinor(string $value, string $currency): int {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new \InvalidArgumentException('invalid_currency');
        $number = self::normalizeNumber($value);
        if ($number === '' || preg_match('/^-?\d+(?:\.\d+)?$/D', $number) !== 1) throw new \InvalidArgumentException('invalid_amount');
        $negative = str_starts_with($number, '-');
        if ($negative) $number = substr($number, 1);
        $digits = self::minorDigits($currency);
        $parts = explode('.', $number, 2);
        $whole = ltrim($parts[0], '0');
        if ($whole === '') $whole = '0';
        $fraction = $parts[1] ?? '';
        if ($digits === 0) {
            if ($fraction !== '' && trim($fraction, '0') !== '') throw new \InvalidArgumentException('amount_requires_whole_units');
            $fraction = '';
        } else {
            if (strlen($fraction) > $digits) throw new \InvalidArgumentException('amount_has_too_many_decimals');
            $fraction = str_pad($fraction, $digits, '0');
        }
        $minor = $whole . $fraction;
        if (strlen($minor) > 18) throw new \InvalidArgumentException('amount_out_of_range');
        $amount = (int) $minor;
        return $negative ? -$amount : $amount;
    }

    /** Present an exact minor-unit amount in normal currency units. */
    public static function fromMinor(int $minor, string $currency): string {
        $digits = self::minorDigits($currency);
        $negative = $minor < 0;
        $minor = abs($minor);
        if ($digits === 0) return ($negative ? '-' : '') . (string) $minor;
        $divisor = 10 ** $digits;
        $whole = intdiv($minor, $divisor);
        $fraction = str_pad((string) ($minor % $divisor), $digits, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    public static function format(int $minor, string $currency, bool $persian = false): string {
        $amount = self::fromMinor($minor, $currency);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $grouped = number_format((float) $whole, 0, '.', ',');
        if ($fraction !== '') $grouped .= '.' . $fraction;
        $symbols = array('AUD' => 'A$', 'NZD' => 'NZ$', 'USD' => 'US$', 'CAD' => 'C$', 'EUR' => '€', 'GBP' => '£', 'TOM' => 'تومان');
        $symbol = $symbols[strtoupper($currency)] ?? strtoupper($currency);
        if ($persian) {
            $persianDigits = array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹');
            $grouped = strtr($grouped, $persianDigits);
            return strtoupper($currency) === 'TOM' ? $grouped . ' تومان' : $symbol . $grouped;
        }
        return strtoupper($currency) === 'TOM' ? $grouped . ' Toman' : $symbol . $grouped;
    }
}
