<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceSettingsRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Consolidates finance presentation defaults and the Toman teacher-rate default.
 *
 * The teacher global rate is a default, not a second compensation model. Applying it
 * materialises effective-dated per-Teacher rate rows through the existing
 * TeacherRateService; an explicit teacher/course rate is recorded separately and marks
 * that Teacher as overridden, so a later global default change does not overwrite it.
 */
final class FinanceSettingsService {
    public const TEMPLATE_KEY = 'STATEMENT_TEMPLATE_V1';
    public const TEACHER_GLOBAL_RATE_KEY = 'TEACHER_GLOBAL_RATE_TOMAN_V1';
    public const TEACHER_OVERRIDES_KEY = 'TEACHER_RATE_OVERRIDE_IDS_V1';
    public const MANAGE_TEMPLATE_CAPABILITY = 'dzn_manage_finance_statements';
    public const MANAGE_TEACHER_RATE_CAPABILITY = 'dzn_manage_teacher_rates';
    public const TEACHER_CURRENCY = 'TOM';

    public function __construct(
        private ?FinanceSettingsRepository $repository = null,
        private ?TeacherRateService $rates = null
    ) {
        $this->repository ??= new FinanceSettingsRepository();
        $this->rates ??= new TeacherRateService();
    }

    public function template(): array {
        $row = $this->repository->active(self::TEMPLATE_KEY);
        if (!$row) return StatementTemplateRule::defaultTemplate();
        $decoded = json_decode((string) $row->setting_value, true);
        $template = is_array($decoded) ? StatementTemplateRule::sanitizeTemplate($decoded) : StatementTemplateRule::defaultTemplate();
        $template['template_version'] = (int) $row->setting_version;
        return $template;
    }

    public function saveTemplate(array $input): array {
        FinanceSupport::requireCapability(self::MANAGE_TEMPLATE_CAPABILITY);
        $template = StatementTemplateRule::sanitizeTemplate($input);
        return $this->store(self::TEMPLATE_KEY, 'statement_template', $template);
    }

    public function resetTemplate(): array {
        FinanceSupport::requireCapability(self::MANAGE_TEMPLATE_CAPABILITY);
        return $this->store(self::TEMPLATE_KEY, 'statement_template', StatementTemplateRule::defaultTemplate());
    }

    public function globalTeacherRate(): ?array {
        $row = $this->repository->active(self::TEACHER_GLOBAL_RATE_KEY);
        if (!$row) return null;
        $decoded = json_decode((string) $row->setting_value, true);
        if (!is_array($decoded)) return null;
        $amount = (int) ($decoded['amount_minor'] ?? 0);
        return array(
            'amount_minor' => $amount,
            'currency' => self::TEACHER_CURRENCY,
            'display' => PricingMoney::fromMinor($amount, self::TEACHER_CURRENCY),
            'effective_from' => (string) ($decoded['effective_from'] ?? ''),
            'applied_teachers' => (int) ($decoded['applied_teachers'] ?? 0),
            'errors' => (array) ($decoded['errors'] ?? array()),
        );
    }

    /**
     * Save the Toman global teacher-rate default and apply it to every active Teacher that
     * has not been explicitly overridden. Failures are reported per Teacher and never
     * silently rewrite a rate a snapshot already consumed.
     */
    public function saveGlobalTeacherRate(array $input): array {
        FinanceSupport::requireCapability(self::MANAGE_TEACHER_RATE_CAPABILITY);
        $amountMinor = PricingMoney::toMinor((string) ($input['amount'] ?? ''), self::TEACHER_CURRENCY);
        if ($amountMinor < 1) throw new \InvalidArgumentException('teacher_global_rate_required');
        $effectiveFrom = trim((string) ($input['effective_from'] ?? ''));
        if ($effectiveFrom === '') $effectiveFrom = gmdate('Y-m-d H:i:s');
        if (!FinanceRule::utc($effectiveFrom)) throw new \InvalidArgumentException('invalid_effective_from');
        $apply = (string) ($input['apply_to_teachers'] ?? '1') === '1';
        $previousGlobal = $this->globalTeacherRate();
        $applied = 0;
        $errors = array();
        if ($apply) {
            $overrides = $this->overrides();
            foreach ($this->activeTeachers() as $teacher) {
                $teacherId = (int) $teacher->id;
                if (in_array($teacherId, $overrides, true)) continue;
                if ($this->inheritedCurrent($teacherId, $amountMinor, $effectiveFrom)) continue;
                $live = $this->liveTeacherRate($teacherId);
                if ($live !== null && !$this->matchesGlobal($live, $previousGlobal)) {
                    $this->markOverride($teacherId);
                    continue;
                }
                try {
                    $this->rates->record($teacherId, array(
                        'scope_kind' => 'teacher', 'course_scope_id' => 0,
                        'amount_minor' => $amountMinor, 'currency' => self::TEACHER_CURRENCY,
                        'effective_from' => $effectiveFrom, 'compensation_basis' => 'per_session',
                        'evidence_channel' => 'staff_record',
                        'evidence_reference' => 'finance-settings:global-teacher-rate:' . $effectiveFrom,
                        'evidence_at' => gmdate('Y-m-d H:i:s'),
                    ), 'finance-settings-global-rate-' . $teacherId . '-' . $effectiveFrom . '-' . $amountMinor);
                    $applied++;
                } catch (\Throwable $error) {
                    $errors[] = array('teacher_id' => $teacherId, 'reason' => $this->safeReason($error));
                }
            }
        }
        $stored = $this->store(self::TEACHER_GLOBAL_RATE_KEY, 'teacher_rate', array(
            'amount_minor' => $amountMinor, 'currency' => self::TEACHER_CURRENCY,
            'effective_from' => $effectiveFrom, 'applied_teachers' => $applied, 'errors' => $errors,
        ));
        return $stored + array('applied_teachers' => $applied, 'errors' => $errors);
    }

    /** Record one explicit teacher or teacher/course rate in Toman and mark the Teacher overridden. */
    public function saveTeacherRate(int $teacherId, array $input): array {
        FinanceSupport::requireCapability(self::MANAGE_TEACHER_RATE_CAPABILITY);
        if ($teacherId < 1) throw new \InvalidArgumentException('teacher_required');
        $amountMinor = PricingMoney::toMinor((string) ($input['amount'] ?? ''), self::TEACHER_CURRENCY);
        if ($amountMinor < 1) throw new \InvalidArgumentException('teacher_rate_required');
        $scope = (string) ($input['scope_kind'] ?? 'teacher');
        if (!in_array($scope, array('teacher', 'teacher_course'), true)) throw new \InvalidArgumentException('invalid_rate_scope');
        $courseId = $scope === 'teacher_course' ? max(0, (int) ($input['course_scope_id'] ?? 0)) : 0;
        $effectiveFrom = trim((string) ($input['effective_from'] ?? ''));
        if ($effectiveFrom === '') $effectiveFrom = gmdate('Y-m-d H:i:s');
        $rawKey = 'finance-settings-teacher-rate-' . $teacherId . '-' . $scope . '-' . $courseId . '-' . $effectiveFrom . '-' . $amountMinor;
        $result = $this->rates->record($teacherId, array(
            'scope_kind' => $scope, 'course_scope_id' => $courseId,
            'amount_minor' => $amountMinor, 'currency' => self::TEACHER_CURRENCY,
            'effective_from' => $effectiveFrom, 'compensation_basis' => 'per_session',
            'evidence_channel' => 'staff_record',
            'evidence_reference' => (string) ($input['evidence_reference'] ?? ('finance-settings:teacher-rate:' . $teacherId)),
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), $rawKey);
        if ($scope === 'teacher') $this->markOverride($teacherId);
        return $result;
    }

    /** Clear an override marker and reapply the current global default (effective now). */
    public function resumeGlobalTeacherRate(int $teacherId): array {
        FinanceSupport::requireCapability(self::MANAGE_TEACHER_RATE_CAPABILITY);
        $global = $this->globalTeacherRate();
        if (!$global || (int) $global['amount_minor'] < 1) throw new \InvalidArgumentException('teacher_global_rate_required');
        $result = $this->rates->record($teacherId, array(
            'scope_kind' => 'teacher', 'course_scope_id' => 0,
            'amount_minor' => (int) $global['amount_minor'], 'currency' => self::TEACHER_CURRENCY,
            'effective_from' => gmdate('Y-m-d H:i:s'), 'compensation_basis' => 'per_session',
            'evidence_channel' => 'staff_record',
            'evidence_reference' => 'finance-settings:resume-global-rate:' . $teacherId,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), 'finance-settings-resume-global-' . $teacherId . '-' . gmdate('YmdHis'));
        $overrides = array_values(array_filter($this->overrides(), static fn(int $id): bool => $id !== $teacherId));
        $this->store(self::TEACHER_OVERRIDES_KEY, 'teacher_ids', $overrides);
        return $result;
    }

    public function overrides(): array {
        $row = $this->repository->active(self::TEACHER_OVERRIDES_KEY);
        if (!$row) return array();
        $decoded = json_decode((string) $row->setting_value, true);
        if (!is_array($decoded)) return array();
        return array_values(array_unique(array_map('intval', array_filter($decoded, 'is_numeric'))));
    }

    public function markOverride(int $teacherId): void {
        if ($teacherId < 1) return;
        $overrides = $this->overrides();
        if (!in_array($teacherId, $overrides, true)) $overrides[] = $teacherId;
        $this->store(self::TEACHER_OVERRIDES_KEY, 'teacher_ids', $overrides);
    }

    public function teacherHistory(int $teacherId): array {
        return (new Read\TeacherRateReadService())->timeline($teacherId);
    }

    private function inheritedCurrent(int $teacherId, int $amountMinor, string $effectiveFrom): bool {
        $row = $this->liveTeacherRate($teacherId);
        return $row !== null
            && (int) $row->amount_minor === $amountMinor
            && (string) $row->currency === self::TEACHER_CURRENCY
            && (string) $row->effective_from === $effectiveFrom;
    }

    private function liveTeacherRate(int $teacherId): ?object {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT amount_minor,currency,effective_from FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND status='active' AND effective_until IS NULL ORDER BY id DESC",
            $teacherId
        )) ?: array();
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function matchesGlobal(?object $row, ?array $global): bool {
        return $row !== null && $global !== null
            && (int) $row->amount_minor === (int) $global['amount_minor']
            && (string) $row->currency === self::TEACHER_CURRENCY
            && (string) $row->effective_from === (string) $global['effective_from'];
    }

    /** @return list<object> */
    private function activeTeachers(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return $wpdb->get_results("SELECT id FROM {$p}teachers WHERE status='active' AND archived_at IS NULL ORDER BY id") ?: array();
    }

    private function store(string $key, string $type, mixed $value): array {
        $actor = FinanceSupport::actor();
        $now = gmdate('Y-m-d H:i:s');
        $this->repository->begin();
        try {
            $existing = $this->repository->active($key, true);
            if ($existing) $this->repository->supersede((int) $existing->id, $now, $actor);
            $version = $this->repository->nextVersion($key);
            $id = $this->repository->insert(array(
                'uid' => Identifier::uid(), 'setting_key' => $key, 'setting_type' => $type,
                'setting_value' => wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'setting_version' => $version,
                'status' => 'active', 'active_slot' => 1, 'created_at' => $now, 'created_by' => $actor,
                'updated_at' => $now, 'updated_by' => $actor,
            ));
            global $wpdb;
            $audited = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
                'aggregate_type' => 'finance_settings', 'aggregate_id' => $id, 'event_type' => 'record',
                'actor_type' => 'user', 'actor_id' => $actor, 'reason_code' => null,
                'safe_detail' => substr($key, 0, 191), 'idempotency_key' => hash('sha256', 'finance_settings:' . $key . ':' . $version . ':' . $now),
                'occurred_at' => $now,
            ));
            if ($audited === false) throw new \RuntimeException('Finance setting audit evidence could not be recorded');
            $this->repository->commit();
            return array('setting_id' => $id, 'setting_version' => $version, 'saved' => true);
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
    }

    private function safeReason(\Throwable $error): string {
        $message = trim($error->getMessage());
        if (preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1) return $message;
        return 'teacher_rate_write_failed';
    }
}
