<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Applies the current global Toman teacher-rate default when a Teacher becomes eligible.
 *
 * A new inherited rate is never effective before the Teacher's recorded activation instant.
 * Rates carrying `rate_origin = global_inherited` are updated by a later global default;
 * explicit or unknown historical rates are preserved and marked as overrides.
 */
final class TeacherRateInheritanceService {
    public function __construct(
        private ?FinanceSettingsService $settings = null,
        private ?TeacherRateService $rates = null
    ) {
        $this->settings ??= new FinanceSettingsService();
        $this->rates ??= new TeacherRateService();
    }

    /** @return array{applied:bool,reason:string,rate_id:?int,effective_from:?string} */
    public function applyForTeacher(int $teacherId): array {
        if ($teacherId < 1) throw new \InvalidArgumentException('teacher_required');
        $global = $this->settings->globalTeacherRate();
        if (!$global || (int) $global['amount_minor'] < 1) return array('applied' => false, 'reason' => 'global_teacher_rate_unset', 'rate_id' => null, 'effective_from' => null);
        if (in_array($teacherId, $this->settings->overrides(), true)) return array('applied' => false, 'reason' => 'teacher_rate_override', 'rate_id' => null, 'effective_from' => null);
        $activation = $this->activationInstant($teacherId);
        if ($activation === null) return array('applied' => false, 'reason' => 'teacher_not_activated', 'rate_id' => null, 'effective_from' => null);
        $effectiveFrom = $this->later((string) $global['effective_from'], $activation);
        if (!FinanceRule::utc($effectiveFrom)) $effectiveFrom = gmdate('Y-m-d H:i:s');
        $existing = $this->liveTeacherRate($teacherId);
        if ($existing !== null) {
            $origin = (string) ($existing->rate_origin ?? '');
            if ($origin !== 'global_inherited') {
                // Unknown/legacy and explicit rates are never reinterpreted. Preserve them and make the
                // override durable so a future global save cannot overwrite them.
                $this->settings->markOverride($teacherId);
                return array('applied' => false, 'reason' => 'existing_rate_preserved', 'rate_id' => (int) $existing->id, 'effective_from' => (string) $existing->effective_from);
            }
            if ((int) $existing->amount_minor === (int) $global['amount_minor']
                && (string) $existing->currency === FinanceSettingsService::TEACHER_CURRENCY
                && (string) $existing->effective_from === $effectiveFrom) {
                return array('applied' => false, 'reason' => 'already_inherited', 'rate_id' => (int) $existing->id, 'effective_from' => $effectiveFrom);
            }
            if ($effectiveFrom <= (string) $existing->effective_from && (int) $existing->amount_minor !== (int) $global['amount_minor']) {
                // A distinct amount cannot supersede another interval at the same instant.
                throw new \RuntimeException('teacher_rate_effective_instant_conflict');
            }
        }
        $result = $this->rates->record($teacherId, array(
            'scope_kind' => 'teacher', 'course_scope_id' => 0,
            'amount_minor' => (int) $global['amount_minor'], 'currency' => FinanceSettingsService::TEACHER_CURRENCY,
            'effective_from' => $effectiveFrom, 'compensation_basis' => 'per_session',
            'rate_origin' => 'global_inherited', 'origin_setting_version' => (int) ($global['setting_version'] ?? 0) ?: null,
            'evidence_channel' => 'staff_record',
            'evidence_reference' => 'finance-settings:inherit-global-rate:' . $teacherId,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), 'finance-settings-inherit-global-' . $teacherId . '-' . $effectiveFrom . '-' . (int) $global['amount_minor']);
        return array('applied' => true, 'reason' => 'global_default_applied', 'rate_id' => (int) ($result['rate_id'] ?? 0), 'effective_from' => $effectiveFrom);
    }

    /** The recorded activation instant; null when onboarding has not activated the Teacher. */
    private function activationInstant(int $teacherId): ?string {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $state = $wpdb->get_row($wpdb->prepare("SELECT state,activated_at FROM {$p}teacher_onboarding_states WHERE teacher_id=%d", $teacherId));
        if ($state === null) return gmdate('Y-m-d H:i:s'); // Legacy active Teacher without an onboarding row.
        if ((string) $state->state !== 'active') return null;
        return is_string($state->activated_at) && FinanceRule::utc($state->activated_at) ? $state->activated_at : gmdate('Y-m-d H:i:s');
    }

    private function liveTeacherRate(int $teacherId): ?object {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,amount_minor,currency,effective_from,rate_origin,origin_setting_version FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND status='active' AND effective_until IS NULL ORDER BY id DESC",
            $teacherId
        )) ?: array();
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function later(string $left, string $right): string {
        return $left >= $right ? $left : $right;
    }
}
