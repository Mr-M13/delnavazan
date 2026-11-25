<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Identity authority for the single-operator follow-up screen.
 *
 * The screen exists for one named account (the co-founder who coordinates the academy day to
 * day). The identity is compared against the WordPress login and email; everyone else is denied,
 * including other administrators, because the navigation capability only maps for this account.
 *
 * Set the operator's WordPress login or email in OPERATOR (or override it with the
 * `dzn_owner_followup_operator` filter). An empty value disables the screen everywhere.
 */
final class OwnerFollowupAuthority {
    /** Navigation + read capability; mapped to manage_options only for the operator. */
    public const CAPABILITY = 'dzn_access_owner_followups';

    /** WordPress login or email of the operator. */
    private const OPERATOR = 'Nimasepandar@gmail.com';

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
        return false;
    }
}
