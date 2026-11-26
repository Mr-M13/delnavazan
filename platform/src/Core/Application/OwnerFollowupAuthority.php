<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Identity authority for the single-operator follow-up screen.
 *
 * The screen exists for one named account (the co-founder who coordinates the academy day to
 * day) inside the Teacher portal. The identity is compared against the WordPress login, email and
 * display name; everyone else is denied, including other administrators.
 *
 * Set the operator's WordPress login or email in OPERATOR (or override it with the
 * `dzn_owner_followup_operator` filter). An empty value disables the screen everywhere.
 */
final class OwnerFollowupAuthority {
    /** WordPress login or email of the operator. */
    private const OPERATOR = 'Nimasepandar@gmail.com';

    /** Display-name tokens accepted for the operator if the profile email differs. */
    private const OPERATOR_NAME_TOKENS = array('نیما', 'nima');

    public static function operator(): string {
        $identity = self::OPERATOR;
        if (function_exists('apply_filters')) {
            $identity = (string) apply_filters('dzn_owner_followup_operator', $identity);
        }
        return strtolower(trim($identity));
    }

    /** True only for the configured operator account. */
    public static function isOperator(?int $userId = null): bool {
        $identity = self::operator();
        if ('' === $identity || !function_exists('get_userdata')) { return false; }
        $userId = $userId ?? (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        if ($userId < 1) { return false; }
        $user = get_userdata($userId);
        if (!$user) { return false; }
        foreach (array((string) ($user->user_login ?? ''), (string) ($user->user_email ?? '')) as $candidate) {
            if (strtolower(trim($candidate)) === $identity) { return true; }
        }
        $display = strtolower(trim((string) ($user->display_name ?? '')));
        foreach (self::OPERATOR_NAME_TOKENS as $token) {
            if ('' !== $token && str_contains($display, strtolower($token))) { return true; }
        }
        return false;
    }
}
