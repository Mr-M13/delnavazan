<?php
namespace Delnavazan\Platform\Public;

/** Lightweight abuse control for anonymous, database-backed availability reads. */
final class BookingAvailabilityPreviewRateLimiter {
    private const LIMIT = 30;
    private const WINDOW = 600;
    public function allow(): bool {
        try {
            $signal = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
            if ( $signal === '' ) return true;
            $digest = hash_hmac( 'sha256', 'preview:' . $signal, wp_salt( 'dzn_booking_availability_rate' ) );
            $key = 'dzn_bap_rate_' . substr( $digest, 0, 40 );
            $state = get_transient( $key );
            $count = is_array( $state ) && isset( $state['count'] ) ? (int) $state['count'] : 0;
            if ( $count >= self::LIMIT ) return false;
            if ( ! set_transient( $key, array( 'count' => $count + 1 ), self::WINDOW ) ) return true;
            return true;
        } catch ( \Throwable ) { return true; }
    }
}
