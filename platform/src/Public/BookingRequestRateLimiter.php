<?php
namespace Delnavazan\Platform\Public;

/** Best-effort abuse control. A cache failure is deliberately fail-open. */
final class BookingRequestRateLimiter {
    private const LIMIT = 5;
    private const WINDOW = 600;
    public function allow(): bool {
        try {
            $signal = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            if ($signal === '') return true;
            $digest = hash_hmac('sha256', 'rate:' . $signal, wp_salt('dzn_booking_request_rate'));
            $key = 'dzn_br_rate_' . substr($digest, 0, 40);
            $state = get_transient($key); $count = is_array($state) && isset($state['count']) ? (int)$state['count'] : 0;
            if ($count >= self::LIMIT) return false;
            if (!set_transient($key, array('count'=>$count+1), self::WINDOW)) return true;
            return true;
        } catch (\Throwable) { return true; }
    }
}
