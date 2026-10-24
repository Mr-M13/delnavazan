<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Reduces pre-dispatch invitation errors to an operator-safe category.
 *
 * The category intentionally contains neither a recipient, an SQL error, a
 * command key, nor an invitation secret.  Mail dispatch has a separate,
 * durable outcome in platform_outbox and must not be reported through this
 * pre-dispatch path.
 */
final class TeacherInvitationIssuanceFailure {
    private const CATEGORIES = array(
        'invalid_recipient',
        'duplicate_active_teacher_profiles',
        'teacher_already_linked',
        'teacher_not_onboarding_eligible',
        'persistence_failed',
        'transaction_failed',
        'unexpected_failure',
    );

    public static function category(\Throwable $error): string {
        if ($error instanceof \Delnavazan\Platform\Core\Infrastructure\Repository\PersistenceException) return 'persistence_failed';
        return match ($error->getMessage()) {
            // PrincipalInvitationService validates the normalized recipient at
            // the Core boundary. Keep this mapping aligned with that message so
            // a server-side invalid value does not fall through to the generic
            // "no change" path after the browser's required/email checks.
            'Invalid email address', 'Valid recipient email required' => 'invalid_recipient',
            'Multiple active teacher profiles match this email' => 'duplicate_active_teacher_profiles',
            'Teacher already has a linked account' => 'teacher_already_linked',
            'Teacher is not onboarding eligible' => 'teacher_not_onboarding_eligible',
            default => self::infrastructureCategory($error),
        };
    }

    public static function notice(string $category): string {
        return match ($category) {
            'invalid_recipient' => 'Invitation was not created: enter a valid email address.',
            'duplicate_active_teacher_profiles' => 'Invitation was not created: more than one active Teacher profile matches this email. Resolve the duplicate profiles before retrying.',
            'teacher_already_linked' => 'Invitation was not created: this Teacher already has a linked account.',
            'teacher_not_onboarding_eligible' => 'Invitation was not created: the matching Teacher is not eligible for onboarding.',
            'persistence_failed' => 'Invitation was not created because the invitation records could not be saved. Check Core Status before retrying.',
            'transaction_failed' => 'Invitation was not created because the database transaction could not complete. Check Core Status before retrying.',
            default => 'Invitation was not created. Review the privacy-safe failure category in the server log before retrying.',
        };
    }

    /** Log only the fixed category; never pass the caught exception to error_log(). */
    public static function log(string $category): void {
        if (!in_array($category, self::CATEGORIES, true)) $category = 'unexpected_failure';
        error_log('[Delnavazan] teacher_invitation_issuance_failed category=' . $category);
    }

    private static function infrastructureCategory(\Throwable $error): string {
        $message = $error->getMessage();
        if (str_starts_with($message, 'Persistence failed:') || str_starts_with($message, 'Invitation ') || str_starts_with($message, 'Database ')) return 'persistence_failed';
        if (str_starts_with($message, 'Transaction ')) return 'transaction_failed';
        return 'unexpected_failure';
    }
}
