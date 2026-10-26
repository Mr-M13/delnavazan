<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Applies the current global Toman teacher-rate default when a Teacher becomes eligible.
 *
 * Explicit teacher or teacher/course rates are never overwritten. Repeated activation is
 * idempotent because the effective instant and global amount form a stable command key.
 */
final class TeacherRateInheritanceService {
    public function __construct(
        private ?FinanceSettingsService $settings = null,
        private ?TeacherRateService $rates = null
    ) {
        $this->settings ??= new FinanceSettingsService();
        $this->rates ??= new TeacherRateService();
    }

    /** @return array{applied:bool,reason:string,rate_id:?int} */
    public function applyForTeacher(int $teacherId): array {
        if ($teacherId < 1) throw new \InvalidArgumentException('teacher_required');
        $global = $this->settings->globalTeacherRate();
        if (!$global || (int) $global['amount_minor'] < 1) return array('applied' => false, 'reason' => 'global_teacher_rate_unset', 'rate_id' => null);
        if (in_array($teacherId, $this->settings->overrides(), true)) return array('applied' => false, 'reason' => 'teacher_rate_override', 'rate_id' => null);
        $existing = $this->liveRates($teacherId);
        if ($existing !== array()) {
            $matchesGlobal = count($existing) === 1
                && (string) $existing[0]->scope_kind === 'teacher'
                && (int) $existing[0]->amount_minor === (int) $global['amount_minor']
                && (string) $existing[0]->currency === FinanceSettingsService::TEACHER_CURRENCY
                && (string) $existing[0]->effective_from === (string) $global['effective_from'];
            if ($matchesGlobal) return array('applied' => false, 'reason' => 'already_inherited', 'rate_id' => (int) $existing[0]->id);
            // A legacy or explicitly recorded rate is not silently replaced by the default.
            $this->settings->markOverride($teacherId);
            return array('applied' => false, 'reason' => 'existing_rate_preserved', 'rate_id' => (int) $existing[0]->id);
        }
        $effectiveFrom = (string) $global['effective_from'];
        if (!FinanceRule::utc($effectiveFrom)) $effectiveFrom = gmdate('Y-m-d H:i:s');
        $result = $this->rates->record($teacherId, array(
            'scope_kind' => 'teacher', 'course_scope_id' => 0,
            'amount_minor' => (int) $global['amount_minor'], 'currency' => FinanceSettingsService::TEACHER_CURRENCY,
            'effective_from' => $effectiveFrom, 'compensation_basis' => 'per_session',
            'evidence_channel' => 'staff_record',
            'evidence_reference' => 'finance-settings:inherit-global-rate:' . $teacherId,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), 'finance-settings-inherit-global-' . $teacherId . '-' . $effectiveFrom . '-' . (int) $global['amount_minor']);
        return array('applied' => true, 'reason' => 'global_default_applied', 'rate_id' => (int) ($result['rate_id'] ?? 0));
    }

    /** @return list<object> */
    private function liveRates(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,scope_kind,amount_minor,currency,effective_from FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND status='active' AND effective_until IS NULL ORDER BY id",
            $teacherId
        )) ?: array();
    }
}
