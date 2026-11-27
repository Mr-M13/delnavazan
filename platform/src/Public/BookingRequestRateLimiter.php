<?php
namespace Delnavazan\Platform\Public;

/** Best-effort abuse control. A cache failure is deliberately fail-open. */
final class BookingRequestRateLimiter {
    private const LIMIT = 5;
    private const WINDOW = 600;
    /** Existing-account authentication attempts allowed per IP + email before a short cool-down. */
    private const AUTH_LIMIT = 8;
    private const AUTH_WINDOW = 900;

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

    /**
     * Admission for verifying a password against an existing WordPress account.
     *
     * The public booking form authenticates an existing account before the submission gate runs, so
     * failed guesses must be counted here instead of after account creation. The bucket is keyed by
     * the remote address *and* the email digest, so one shared connection cannot lock out everyone.
     */
    public function allowAuth(string $email): bool {
        try {
            return $this->authCount($email) < self::AUTH_LIMIT;
        } catch (\Throwable) { return true; }
    }

    public function recordAuthFailure(string $email): void {
        try {
            $key = $this->authKey($email);
            if ($key === '') return;
            $state = get_transient($key);
            $count = is_array($state) && isset($state['count']) ? (int)$state['count'] : 0;
            set_transient($key, array('count'=>$count+1), self::AUTH_WINDOW);
        } catch (\Throwable) {
            // Fail-open: the credential check itself already failed.
        }
    }

    private function authCount(string $email): int {
        $key = $this->authKey($email);
        if ($key === '') return 0;
        $state = get_transient($key);
        return is_array($state) && isset($state['count']) ? (int)$state['count'] : 0;
    }

    private function authKey(string $email): string {
        $signal = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $identity = strtolower(trim($email));
        if ($signal === '' && $identity === '') return '';
        $digest = hash_hmac('sha256', 'auth:' . $signal . '|' . $identity, wp_salt('dzn_booking_auth_rate'));
        return 'dzn_ba_rate_' . substr($digest, 0, 40);
    }
}
