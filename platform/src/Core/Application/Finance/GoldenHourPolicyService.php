<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceSettingsRepository;

/**
 * Versioned golden-hour window and bonus policy.
 *
 * It deliberately reuses `finance_settings` for configuration and policy history only. Committed
 * per-Lesson details live in the dedicated immutable snapshot-detail table. Installing the feature
 * writes no policy row, so the default remains disabled at 0%; no migration backfills a historical
 * Lesson.
 */
final class GoldenHourPolicyService {
    private const CAPABILITY = 'dzn_manage_teacher_rates';

    public function __construct(private ?FinanceSettingsRepository $settings = null) {
        $this->settings ??= new FinanceSettingsRepository();
    }

    /** The structural defaults, shared with the coverage dashboard. @return array<string,array<string,mixed>> */
    public function defaults(): array {
        return GoldenHourRule::defaults();
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array {
        $out = GoldenHourRule::defaults();
        $out[GoldenHourRule::GLOBAL_IRAN_KEY] = GoldenHourRule::globalDefinition();
        return $out;
    }

    /** The latest recorded policy for display, or the disabled structural default. */
    public function latest(string $area): array {
        $defaults = $this->definitions();
        if (!isset($defaults[$area])) throw new \InvalidArgumentException('golden_hour_area_invalid');
        $history = $this->settings->history($this->key($area));
        if ($history === array()) return $defaults[$area];
        $row = $history[0];
        return $this->decode($row, $defaults[$area]);
    }

    /**
     * Resolve the policy version that covers the Lesson occurrence instant.
     * The occurrence date, not the date an administrator opens a page, decides effectiveness.
     */
    public function resolveAt(string $area, string $occurrenceStartsAtUtc): array {
        $defaults = $this->definitions();
        if (!isset($defaults[$area])) throw new \InvalidArgumentException('golden_hour_area_invalid');
        $versions = array();
        foreach ($this->settings->history($this->key($area)) as $row) {
            $versions[] = $this->decode($row, $defaults[$area]);
        }
        $resolved = GoldenHourRule::resolveVersion($versions, $occurrenceStartsAtUtc);
        if ($resolved !== null) return $resolved;
        return $defaults[$area];
    }

    /**
     * The shared window definitions used by coverage. A configured window describes coverage even
     * when the bonus is disabled, so disabling pay never hides the operational coverage window.
     *
     * @return array<string,array<string,mixed>>
     */
    public function coverageWindows(): array {
        $out = array();
        $now = gmdate('Y-m-d H:i:s');
        foreach (GoldenHourRule::defaults() as $area => $default) $out[$area] = $this->resolveAt($area, $now);
        return $out;
    }

    /** Whether any area has an effective enabled bonus at an occurrence instant. */
    public function anyEnabledAt(string $occurrenceStartsAtUtc): bool {
        foreach ($this->definitions() as $area => $default) {
            $policy = $this->resolveAt($area, $occurrenceStartsAtUtc);
            if (!empty($policy['enabled']) && (int) $policy['bonus_basis_points'] > 0) return true;
        }
        return false;
    }

    /** Save one explicit, effective-dated policy version. */
    public function save(string $area, array $input): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        $defaults = $this->definitions();
        if (!isset($defaults[$area])) throw new \InvalidArgumentException('golden_hour_area_invalid');
        $actor = FinanceSupport::actor();
        $now = FinanceSupport::now();
        $normalized = GoldenHourRule::normalizePolicy($area, $input, $defaults[$area]);
        $current = $this->latest($area);
        if ($current['configured'] && $normalized['effective_from'] < (string) $current['effective_from']) {
            throw new \InvalidArgumentException('golden_hour_effective_from_before_current');
        }
        $same = $this->comparable($current);
        $next = $this->comparable($normalized);
        if ($current['configured'] && $same === $next) {
            return array('area'=>$area,'setting_id'=>(int) $current['setting_id'],'setting_version'=>(int) $current['setting_version'],'saved'=>true,'idempotent'=>true);
        }
        if ($current['configured'] && $normalized['effective_from'] === (string) $current['effective_from']) {
            throw new \InvalidArgumentException('golden_hour_effective_from_conflict');
        }
        $this->settings->begin();
        try {
            $existing = $this->settings->active($this->key($area), true);
            if ($existing) $this->settings->supersede((int) $existing->id, $now, $actor);
            $version = $this->settings->nextVersion($this->key($area));
            $normalized['setting_version'] = $version;
            $id = $this->settings->insert(array(
                'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
                'setting_key' => $this->key($area),
                'setting_type' => 'golden_hour_policy',
                'setting_value' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'setting_version' => $version,
                'status' => 'active',
                'active_slot' => 1,
                'created_at' => $now,
                'created_by' => $actor,
                'updated_at' => $now,
                'updated_by' => $actor,
            ));
            $this->settings->audit($id, $this->key($area), 'golden_hour_policy_saved', $actor, $now, 'area=' . $area . ';new=' . $this->auditShape($normalized));
            $this->settings->audit($id, $this->key($area), 'golden_hour_policy_previous', $actor, $now, 'area=' . $area . ';prev=' . $this->auditShape($current));
            $this->settings->commit();
            $normalized['setting_id'] = $id;
            return array('area'=>$area,'setting_id'=>$id,'setting_version'=>$version,'saved'=>true,'idempotent'=>false) + $normalized;
        } catch (\Throwable $error) {
            $this->settings->rollback();
            throw $error;
        }
    }

    public function preview(int $baseMinor, string $currency, string $area, array $input): string {
        $normalized = GoldenHourRule::normalizePolicy($area, $input, $this->definitions()[$area]);
        return GoldenHourRule::previewText($baseMinor, $currency, (int) $normalized['bonus_basis_points'], (string) $normalized['timezone']);
    }

    private function key(string $area): string {
        return GoldenHourRule::POLICY_KEY_PREFIX . strtoupper($area);
    }

    private function decode(object $row, array $fallback): array {
        $decoded = json_decode((string) $row->setting_value, true);
        if (!is_array($decoded)) return $fallback;
        $decoded['area'] = (string) ($decoded['area'] ?? $fallback['area']);
        $decoded['label'] = (string) ($decoded['label'] ?? $fallback['label']);
        $decoded['enabled'] = !empty($decoded['enabled']);
        $decoded['timezone'] = (string) ($decoded['timezone'] ?? $fallback['timezone']);
        $decoded['weekdays'] = array_values(array_map('intval', (array) ($decoded['weekdays'] ?? $fallback['weekdays'])));
        $decoded['start'] = (string) ($decoded['start'] ?? $fallback['start']);
        $decoded['end'] = (string) ($decoded['end'] ?? $fallback['end']);
        $decoded['bonus_basis_points'] = (int) ($decoded['bonus_basis_points'] ?? 0);
        $decoded['effective_from'] = (string) ($decoded['effective_from'] ?? $fallback['effective_from']);
        $decoded['effective_until'] = isset($decoded['effective_until']) && $decoded['effective_until'] !== null ? (string) $decoded['effective_until'] : null;
        $decoded['configured'] = true;
        $decoded['setting_id'] = (int) ($row->id ?? 0);
        $decoded['setting_version'] = (int) ($row->setting_version ?? 0);
        $decoded['global'] = !empty($fallback['global']);
        return $decoded;
    }

    private function comparable(array $policy): array {
        return array(
            'enabled' => !empty($policy['enabled']),
            'timezone' => (string) $policy['timezone'],
            'weekdays' => array_values(array_map('intval', (array) $policy['weekdays'])),
            'start' => (string) $policy['start'],
            'end' => (string) $policy['end'],
            'bonus_basis_points' => (int) $policy['bonus_basis_points'],
            'effective_from' => (string) $policy['effective_from'],
            'effective_until' => $policy['effective_until'] === null ? null : (string) $policy['effective_until'],
        );
    }

    private function auditShape(array $policy): string {
        return 'enabled=' . (!empty($policy['enabled']) ? '1' : '0')
            . ',pct=' . number_format(((int) $policy['bonus_basis_points']) / 100, 2, '.', '')
            . ',tz=' . (string) $policy['timezone']
            . ',days=' . implode('', array_map('intval', (array) $policy['weekdays']))
            . ',start=' . (string) $policy['start']
            . ',end=' . (string) $policy['end']
            . ',from=' . (string) $policy['effective_from']
            . ',until=' . ($policy['effective_until'] === null ? '' : (string) $policy['effective_until']);
    }
}
