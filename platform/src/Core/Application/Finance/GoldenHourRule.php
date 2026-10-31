<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/**
 * Locked vocabulary and validation for golden-hour teacher pay.
 *
 * The existing AcademyDemandWindowRule remains the structural source of the serviced areas. This
 * class adds the pay rule around those same windows. A policy stores an exact integer percentage in
 * basis points (10,000 = 100%), never a floating-point amount, and every window is interpreted in
 * the configured area timezone rather than the Teacher's or the browser's timezone.
 */
final class GoldenHourRule {
    public const RULE_VERSION = 'golden_hour_pay_v2';
    public const BASIS_POINTS_SCALE = 10000;
    public const MAX_BONUS_BASIS_POINTS = 10000;
    public const POLICY_KEY_PREFIX = 'GOLDEN_HOUR_POLICY_';
    public const GLOBAL_IRAN_KEY = 'GLOBAL_IRAN';
    public const GLOBAL_IRAN_TIMEZONE = 'Asia/Tehran';
    public const DEFAULT_EFFECTIVE_FROM = '1970-01-01 00:00:00';

    /** The one canonical Enrolment service-area vocabulary. */
    public const SERVICE_AREA_KEYS = array('AU','EU','GB','NA_EAST','NA_WEST');
    public const REVIEW_SERVICE_AREA_REQUIRED = 'service_area_required';
    public const REVIEW_TEXT = 'Golden-hour bonus requires service-area review.';
    public const OUTCOME_APPLIED = 'bonus_applied';
    public const OUTCOME_NO_OVERLAP = 'no_eligible_overlap';
    public const OUTCOME_DISABLED = 'policy_disabled';
    public const OUTCOME_ZERO = 'policy_zero_percent';
    public const OUTCOME_AREA_REQUIRED = 'service_area_required';
    public const OUTCOME_LEGACY = 'legacy_migrated';

    /** The global Iran-time window is an additional policy, not an Enrolment service area. */
    public static function globalDefinition(): array {
        return array(
            'area' => self::GLOBAL_IRAN_KEY,
            'label' => 'Iran global golden hours',
            'enabled' => false,
            'timezone' => self::GLOBAL_IRAN_TIMEZONE,
            'weekdays' => array(1,2,3,4,5,6,7),
            'start' => '16:00',
            'end' => '21:00',
            'bonus_basis_points' => 0,
            'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
            'effective_until' => null,
            'configured' => false,
            'setting_id' => null,
            'setting_version' => 0,
            'global' => true,
        );
    }

    /** @return array<string,array<string,mixed>> */
    public static function defaults(): array {
        $out = array();
        foreach (AcademyDemandWindowRule::regions() as $area => $region) {
            $out[$area] = array(
                'area' => (string) $area,
                'label' => (string) $region['label'],
                'enabled' => false,
                'timezone' => (string) $region['timezone'],
                'weekdays' => array(1,2,3,4,5,6,7),
                'start' => (string) $region['start'],
                'end' => (string) $region['end'],
                'bonus_basis_points' => 0,
                'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
                'effective_until' => null,
                'configured' => false,
                'setting_id' => null,
                'setting_version' => 0,
            );
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    public static function areas(): array {
        return self::defaults();
    }

    /** Validate the explicit canonical Enrolment service-area key. A blank value is allowed but unresolved. */
    public static function serviceAreaKey(mixed $value): ?string {
        $key = strtoupper(trim((string) $value));
        if ($key === '') return null;
        if (!in_array($key, self::SERVICE_AREA_KEYS, true)) throw new \InvalidArgumentException('service_area_key_invalid');
        return $key;
    }

    /** @return array{area:?string,review_reason:?string} */
    public static function serviceAreaResolution(?string $serviceAreaKey): array {
        $key = self::serviceAreaKey($serviceAreaKey);
        if ($key === null) return array('area'=>null,'review_reason'=>self::REVIEW_SERVICE_AREA_REQUIRED);
        return array('area'=>$key,'review_reason'=>null);
    }

    /** Parse a finite, non-negative decimal percentage into exact basis points. */
    public static function percentageToBasisPoints(mixed $value): int {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $raw, $matches) !== 1) {
            throw new \InvalidArgumentException('golden_hour_percentage_invalid');
        }
        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? str_pad($matches[2], 2, '0') : '00';
        $basisPoints = $whole * 100 + (int) $fraction;
        if ($basisPoints < 0 || $basisPoints > self::MAX_BONUS_BASIS_POINTS) {
            throw new \InvalidArgumentException('golden_hour_percentage_invalid');
        }
        return $basisPoints;
    }

    /** A normalised, validated policy value, still unpersisted. */
    public static function normalizePolicy(string $area, array $input, array $defaults): array {
        $definitions = self::defaults();
        $definitions[self::GLOBAL_IRAN_KEY] = self::globalDefinition();
        if (!isset($definitions[$area])) throw new \InvalidArgumentException('golden_hour_area_invalid');
        $timezone = trim((string) ($input['timezone'] ?? $defaults['timezone']));
        if ($timezone === '' || strlen($timezone) > 64 || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException('golden_hour_timezone_invalid');
        }
        $weekdays = $input['weekdays'] ?? $defaults['weekdays'];
        if (is_string($weekdays)) $weekdays = preg_split('/[^0-9]+/', $weekdays, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        if (!is_array($weekdays)) throw new \InvalidArgumentException('golden_hour_weekdays_invalid');
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays, SORT_NUMERIC);
        if ($weekdays === array()) throw new \InvalidArgumentException('golden_hour_weekdays_invalid');
        foreach ($weekdays as $weekday) if ($weekday < 1 || $weekday > 7) throw new \InvalidArgumentException('golden_hour_weekdays_invalid');
        $start = self::normalizeTime((string) ($input['start'] ?? $defaults['start']));
        $end = self::normalizeTime((string) ($input['end'] ?? $defaults['end']), true);
        if (self::minutes($end) <= self::minutes($start)) throw new \InvalidArgumentException('golden_hour_window_overnight_not_supported');
        $effectiveFrom = trim((string) ($input['effective_from'] ?? $defaults['effective_from']));
        if (!self::utc($effectiveFrom)) throw new \InvalidArgumentException('golden_hour_effective_from_invalid');
        $effectiveUntilRaw = trim((string) ($input['effective_until'] ?? ''));
        $effectiveUntil = null;
        if ($effectiveUntilRaw !== '') {
            if (!self::utc($effectiveUntilRaw) || $effectiveUntilRaw <= $effectiveFrom) throw new \InvalidArgumentException('golden_hour_effective_until_invalid');
            $effectiveUntil = $effectiveUntilRaw;
        }
        return array(
            'area' => $area,
            'label' => (string) ($defaults['label'] ?? $area),
            'enabled' => !empty($input['enabled']),
            'timezone' => $timezone,
            'weekdays' => $weekdays,
            'start' => $start,
            'end' => $end,
            'bonus_basis_points' => self::percentageToBasisPoints($input['bonus_percentage'] ?? 0),
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'configured' => true,
            'setting_id' => null,
            'setting_version' => 0,
        );
    }

    /**
     * Resolve the version whose half-open effective interval covers an occurrence instant.
     * Versions are ordered by effective date and then by recorded setting version.
     */
    public static function resolveVersion(array $versions, string $atUtc): ?array {
        if (!self::utc($atUtc)) throw new \InvalidArgumentException('golden_hour_occurrence_instant_invalid');
        $eligible = array();
        foreach ($versions as $version) {
            $from = (string) ($version['effective_from'] ?? '');
            $until = $version['effective_until'] ?? null;
            if ($from === '' || $from > $atUtc) continue;
            if ($until !== null && (string) $until <= $atUtc) continue;
            $eligible[] = $version;
        }
        if ($eligible === array()) return null;
        usort($eligible, static function (array $left, array $right): int {
            $cmp = strcmp((string) $right['effective_from'], (string) $left['effective_from']);
            if ($cmp !== 0) return $cmp;
            return (int) ($right['setting_version'] ?? 0) <=> (int) ($left['setting_version'] ?? 0);
        });
        return $eligible[0];
    }

    /** The plain-language administrator preview, using the actual configured currency. */
    public static function previewText(int $baseMinor, string $currency, int $basisPoints, string $timezone): string {
        $bonus = self::percentageAmount($baseMinor, $basisPoints);
        $total = $baseMinor + $bonus;
        $base = PricingMoney::format($baseMinor, $currency);
        $totalText = PricingMoney::format($total, $currency);
        $bonusText = PricingMoney::format($bonus, $currency);
        $percent = rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
        return sprintf(
            'A base rate of %s/hour becomes %s/hour during this window (%s%% · %s). A 60-minute lesson entirely inside the window earns a %s bonus.',
            $base,
            $totalText,
            $percent,
            $timezone,
            $bonusText
        );
    }

    /** Exact percentage of an exact minor-unit amount, rounded half-up once. */
    public static function percentageAmount(int $amountMinor, int $basisPoints): int {
        if ($amountMinor < 0) throw new \InvalidArgumentException('golden_hour_amount_invalid');
        if ($basisPoints < 0 || $basisPoints > self::MAX_BONUS_BASIS_POINTS) throw new \InvalidArgumentException('golden_hour_percentage_invalid');
        return self::mulDivRound($amountMinor, $basisPoints, self::BASIS_POINTS_SCALE);
    }

    /** Exact non-negative round-half-up multiplication without overflowing on large minor amounts. */
    public static function mulDivRound(int $amount, int $multiplier, int $divisor): int {
        if ($amount < 0 || $multiplier < 0 || $divisor < 1) throw new \InvalidArgumentException('golden_hour_amount_invalid');
        $quotient = intdiv($amount, $divisor);
        $remainder = $amount % $divisor;
        return $quotient * $multiplier + intdiv($remainder * $multiplier + intdiv($divisor, 2), $divisor);
    }

    public static function utc(string $value): bool {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) return false;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }

    public static function minutes(string $time): int {
        $parts = array_map('intval', explode(':', $time));
        return (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0);
    }

    private static function normalizeTime(string $value, bool $allowDayEnd = false): string {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/D', trim($value), $matches) !== 1) {
            throw new \InvalidArgumentException('golden_hour_window_invalid');
        }
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($allowDayEnd && $hour === 24 && $minute === 0) return '24:00';
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) throw new \InvalidArgumentException('golden_hour_window_invalid');
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
