<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Single pure eligibility rule for administrator classroom recovery.
 *
 * The dashboard flag, the read-only discovery report and the mutating retry all use this
 * calculation so the UI can never offer an action that the authority will reject.
 */
final class BookingProvisioningRecoveryRule {
    public const ELIGIBLE = 'eligible';
    public const NOT_FAILED = 'confirmation_not_failed';
    public const DELIVERY_MISSING = 'delivery_missing';
    public const OWNERSHIP_MISMATCH = 'delivery_ownership_mismatch';
    public const DELIVERY_NOT_RECOVERABLE = 'delivery_not_recoverable';
    public const REQUEST_NOT_ACTIVE = 'request_not_active';
    public const BOOKING_ENDED = 'booking_ended';
    public const ALLOCATION_SUPERSEDED = 'allocation_superseded';

    /**
     * @param array{state?:string,teacher_id?:int,requested_time_id?:int,booking_request_id?:int,delivery_id?:int,starts_at_utc?:string} $confirmation
     * @param array{id?:int,booking_request_id?:int,teacher_id?:int,requested_time_id?:int,state?:string,lifecycle_status?:string,resolution_state?:string,privacy_erased_at?:mixed}|null $delivery
     * @return array{eligible:bool,reason:string}
     */
    public static function assess(array $confirmation, ?array $delivery, bool $liveOffer, ?string $now = null): array {
        $now ??= gmdate('Y-m-d H:i:s');
        if ((string) ($confirmation['state'] ?? '') !== 'failed') {
            return self::result(false, self::NOT_FAILED);
        }
        if ($delivery === null) {
            return self::result(false, self::DELIVERY_MISSING);
        }
        if ((int) ($delivery['id'] ?? 0) !== (int) ($confirmation['delivery_id'] ?? 0)
            || (int) ($delivery['booking_request_id'] ?? 0) !== (int) ($confirmation['booking_request_id'] ?? 0)
            || (int) ($delivery['teacher_id'] ?? 0) !== (int) ($confirmation['teacher_id'] ?? 0)
            || (int) ($delivery['requested_time_id'] ?? 0) !== (int) ($confirmation['requested_time_id'] ?? 0)) {
            return self::result(false, self::OWNERSHIP_MISMATCH);
        }
        if (!in_array((string) ($delivery['state'] ?? ''), array('accepting', 'pending_request'), true)) {
            return self::result(false, self::DELIVERY_NOT_RECOVERABLE);
        }
        if ((string) ($delivery['lifecycle_status'] ?? '') !== 'submitted'
            || (string) ($delivery['resolution_state'] ?? '') !== 'unresolved'
            || ($delivery['privacy_erased_at'] ?? null) !== null) {
            return self::result(false, self::REQUEST_NOT_ACTIVE);
        }
        if ((string) ($confirmation['starts_at_utc'] ?? '') <= $now) {
            return self::result(false, self::BOOKING_ENDED);
        }
        if ($liveOffer) {
            return self::result(false, self::ALLOCATION_SUPERSEDED);
        }
        return self::result(true, self::ELIGIBLE);
    }

    /** Stable operator-facing exception text for a rejected recovery. */
    public static function message(string $reason): string {
        return match ($reason) {
            self::NOT_FAILED => 'Booking confirmation is not failed',
            self::DELIVERY_MISSING => 'Booking delivery not found',
            self::OWNERSHIP_MISMATCH => 'Booking delivery no longer owns this confirmation',
            self::DELIVERY_NOT_RECOVERABLE => 'Booking delivery is not recoverable',
            self::REQUEST_NOT_ACTIVE => 'Booking request is no longer active',
            self::BOOKING_ENDED => 'meet_booking_ended',
            self::ALLOCATION_SUPERSEDED => 'Booking allocation has been superseded',
            default => 'Booking confirmation is not recoverable',
        };
    }

    /** @return array{eligible:bool,reason:string} */
    private static function result(bool $eligible, string $reason): array {
        return array('eligible' => $eligible, 'reason' => $reason);
    }
}
