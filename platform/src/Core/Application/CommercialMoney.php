<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Exact commercial money arithmetic.
 *
 * Canonical money is an integer number of minor units plus an explicit ISO-4217 currency. There is
 * no floating-point arithmetic, no implicit conversion, no locale formatting and no second
 * currency: every calculation here is integer-only and deterministic for a given input.
 */
final class CommercialMoney {
    public const BASIS_POINTS_SCALE=10000;
    /** Ten thousand basis points is 100%; a commercial adjustment can never be a surcharge. */
    public const MAX_BASIS_POINTS=10000;

    public static function amount(int $minorUnits):int{
        if($minorUnits<0)throw new \InvalidArgumentException('commercial_amount_invalid');
        return $minorUnits;
    }
    public static function basisPoints(int $basisPoints):int{
        if($basisPoints<0||$basisPoints>self::MAX_BASIS_POINTS)throw new \InvalidArgumentException('commercial_percentage_invalid');
        return $basisPoints;
    }
    /** Percentage of an exact minor-unit amount, rounded half-up exactly once. */
    public static function percentage(int $amountMinor,int $basisPoints):int{
        self::amount($amountMinor);self::basisPoints($basisPoints);
        return intdiv($amountMinor*$basisPoints+intdiv(self::BASIS_POINTS_SCALE,2),self::BASIS_POINTS_SCALE);
    }
    /** A discount can never exceed the amount it applies to. */
    public static function discount(int $amountMinor,int $discountMinor):int{
        self::amount($discountMinor);
        return min($amountMinor,$discountMinor);
    }
    public static function subtract(int $amountMinor,int $discountMinor):int{
        return self::amount($amountMinor-self::discount($amountMinor,$discountMinor));
    }
    /**
     * Deterministic two-part split of an exact amount: the remainder always falls to the first
     * tranche, so `split <= amount` always holds and repeated rounding can never lose or invent a
     * minor unit. Example: 22500 → [11250, 11250]; 22501 → [11251, 11250].
     */
    public static function splitTwo(int $amountMinor):array{
        self::amount($amountMinor);
        $first=intdiv($amountMinor+1,2);
        return array($first,$amountMinor-$first);
    }
}
