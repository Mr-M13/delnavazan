<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;

/**
 * B2b: the administrator-configurable deadline for the second instalment.
 *
 * The approved policy is exactly 168 hours (one week / 604800 seconds) before Lesson 7.
 * It is deliberately unset by default: no deadline is invented, and an existing offer is never
 * rewritten. Only an active policy version whose value is the approved lead time is interpreted;
 * any other explicit value fails closed instead of silently inventing a different deadline.
 *
 * The due instant is derived from the canonical pattern's seventh occurrence, resolved in the
 * pattern timezone, so a daylight-saving transition cannot move the agreed local Lesson time.
 */
final class InstalmentDeadlinePolicy {
    public const POLICY_KEY = 'INSTALMENT_DUE_DATE_POLICY';
    public const LESSON_SEQUENCE = 7;
    public const LEAD_SECONDS = 604800;
    public const APPROVED_WEEKS = 1;

    public function __construct(
        private ?CommercialAuthorityRepository $repository = null,
        private ?CommercialPatternService $patterns = null
    ) {
        $this->repository ??= new CommercialAuthorityRepository();
        $this->patterns ??= new CommercialPatternService();
    }

    /**
     * Resolve the deadline for one canonical pattern.
     *
     * @return array{configured:bool,policy_version:?int,due_at:?string,lesson_starts_at:?string}
     */
    public function forPattern(object $pattern, ?string $now = null): array {
        $now ??= gmdate('Y-m-d H:i:s');
        $row = $this->repository->latestEffectivePolicy(self::POLICY_KEY, $now);
        if ($row === null) return self::unset();
        if (!CommercialValidator::policyValid($row)) throw new \InvalidArgumentException('instalment_due_date_policy_invalid');
        if ($row->policy_value === null) return self::unset();

        $lead = $this->leadSeconds((string) $row->value_type, (string) $row->policy_value);
        if ($lead !== self::LEAD_SECONDS) throw new \InvalidArgumentException('instalment_due_date_policy_invalid');

        try {
            $intervals = $this->patterns->intervalsFor($pattern, self::LESSON_SEQUENCE);
        } catch (\Throwable $error) {
            if (in_array($error->getMessage(), array('continuation_slot_wall_clock_invalid', 'continuation_slot_wall_clock_ambiguous'), true)) {
                throw new \InvalidArgumentException('instalment_due_date_schedule_unavailable');
            }
            throw $error;
        }
        $lesson = $intervals[self::LESSON_SEQUENCE - 1] ?? null;
        if (!is_array($lesson) || trim((string) ($lesson['starts_at_utc'] ?? '')) === '') {
            throw new \InvalidArgumentException('instalment_due_date_schedule_unavailable');
        }
        $startsAt = (string) $lesson['starts_at_utc'];
        $startsTimestamp = strtotime($startsAt . ' UTC');
        if ($startsTimestamp === false) throw new \InvalidArgumentException('instalment_due_date_schedule_unavailable');
        $dueTimestamp = $startsTimestamp - $lead;
        if ($dueTimestamp < 1) throw new \InvalidArgumentException('instalment_due_date_schedule_unavailable');

        return array(
            'configured' => true,
            'policy_version' => (int) $row->policy_version,
            'due_at' => gmdate('Y-m-d H:i:s', $dueTimestamp),
            'lesson_starts_at' => $startsAt,
        );
    }

    /** @return array{configured:bool,policy_version:?int,due_at:?string,lesson_starts_at:?string} */
    private static function unset(): array {
        return array('configured' => false, 'policy_version' => null, 'due_at' => null, 'lesson_starts_at' => null);
    }

    private function leadSeconds(string $valueType, string $value): int {
        if ($valueType === 'duration') {
            if (preg_match('/^\d{1,6}$/D', $value) !== 1) throw new \InvalidArgumentException('instalment_due_date_policy_invalid');
            return (int) $value;
        }
        if ($valueType === 'weeks') {
            if (preg_match('/^\d{1,3}$/D', $value) !== 1) throw new \InvalidArgumentException('instalment_due_date_policy_invalid');
            return (int) $value * 7 * 86400;
        }
        throw new \InvalidArgumentException('instalment_due_date_policy_invalid');
    }
}
