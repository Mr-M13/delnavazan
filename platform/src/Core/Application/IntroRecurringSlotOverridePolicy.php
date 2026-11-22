<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * A Teacher may expressly move a confirmed introductory booking outside their
 * published hours. That action is narrowly authoritative for the same recurring
 * wall-clock slot; it is not a general availability bypass.
 */
final class IntroRecurringSlotOverridePolicy {
    public const BASIS = 'teacher_rescheduled_outside_availability';
    public const ADMIN_BASIS = 'administrator_attested_outside_availability';

    public static function requiresRecord(string $actorType, bool $coveredByPublishedAvailability): bool {
        return ! $coveredByPublishedAvailability && in_array($actorType, array('teacher', 'administrator'), true);
    }

    /** @param array<string,mixed> $override @param array<string,mixed> $confirmation */
    public static function matches(array $override, array $confirmation): bool {
        $basis = (string) ($override['authority_basis'] ?? '');
        $actor = (string) ($override['actor_type'] ?? '');
        $authority = ($basis === self::BASIS && $actor === 'teacher')
            || ($basis === self::ADMIN_BASIS && $actor === 'administrator');
        return $authority
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($override['evidence_reference_digest'] ?? '')) === 1
            && (int) ($override['confirmation_id'] ?? 0) === (int) ($confirmation['id'] ?? 0)
            && (int) ($override['teacher_id'] ?? 0) === (int) ($confirmation['teacher_id'] ?? 0)
            && (string) ($override['starts_at_utc'] ?? '') === (string) ($confirmation['starts_at_utc'] ?? '')
            && (string) ($override['ends_at_utc'] ?? '') === (string) ($confirmation['ends_at_utc'] ?? '')
            && (string) ($override['schedule_timezone'] ?? '') === (string) ($confirmation['schedule_timezone'] ?? '')
            && (string) ($override['local_wall_date'] ?? '') === (string) ($confirmation['local_wall_date'] ?? '')
            && (string) ($override['local_wall_time'] ?? '') === (string) ($confirmation['local_wall_time'] ?? '');
    }
}
