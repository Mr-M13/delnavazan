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

    /** Golden-hour pay now has one policy: the global Iran/Asia-Tehran window. */
    public function definitions(): array {
        return array(GoldenHourRule::GLOBAL_IRAN_KEY => GoldenHourRule::globalDefinition());
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
     * Structural coverage windows. The coverage dashboard remains independent of Iran pay policy.
     *
     * @return array<string,array<string,mixed>>
     */
    public function coverageWindows(): array {
        return GoldenHourRule::defaults();
    }

    /** Whether the global Iran policy has an effective enabled bonus at an occurrence instant. */
    public function anyEnabledAt(string $occurrenceStartsAtUtc): bool {
        $policy = $this->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, $occurrenceStartsAtUtc);
        return !empty($policy['enabled']) && (int) $policy['bonus_basis_points'] > 0;
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

    /** Edit one recorded policy version in place, with explicit audit evidence. */
    public function editVersion(int $settingId, array $input): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor = FinanceSupport::actor(); $now = FinanceSupport::now();
        $row = $this->settings->byId($settingId, true);
        if (!$row || (string) $row->setting_key !== $this->key(GoldenHourRule::GLOBAL_IRAN_KEY) || (string) $row->setting_type !== 'golden_hour_policy') {
            throw new \InvalidArgumentException('golden_hour_policy_version_not_found');
        }
        $default = $this->definitions()[GoldenHourRule::GLOBAL_IRAN_KEY];
        $old = $this->decode($row, $default);
        $normalized = GoldenHourRule::normalizePolicy(GoldenHourRule::GLOBAL_IRAN_KEY, $input, $default);
        foreach ($this->settings->history($this->key(GoldenHourRule::GLOBAL_IRAN_KEY)) as $other) {
            if ((int) $other->id === $settingId) continue;
            $decoded = $this->decode($other, $default);
            if ((string) $decoded['effective_from'] === (string) $normalized['effective_from']) {
                throw new \InvalidArgumentException('golden_hour_effective_from_conflict');
            }
        }
        $normalized['setting_version'] = (int) $row->setting_version;
        $this->settings->begin();
        try {
            $this->settings->updateValue($settingId, array(
                'setting_value' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'updated_at' => $now,
                'updated_by' => $actor,
            ));
            $this->settings->audit($settingId, $this->key(GoldenHourRule::GLOBAL_IRAN_KEY), 'golden_hour_policy_version_edited', $actor, $now, 'prev=' . $this->auditShape($old) . ';new=' . $this->auditShape($normalized));
            $this->settings->commit();
            $normalized['setting_id'] = $settingId;
            return $normalized;
        } catch (\Throwable $error) {
            $this->settings->rollback();
            throw $error;
        }
    }

    /** Remove one previous non-active policy version. Committed lesson details are untouched. */
    public function removeVersion(int $settingId): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor = FinanceSupport::actor(); $now = FinanceSupport::now();
        $row = $this->settings->byId($settingId, true);
        if (!$row || (string) $row->setting_key !== $this->key(GoldenHourRule::GLOBAL_IRAN_KEY) || (string) $row->setting_type !== 'golden_hour_policy') {
            throw new \InvalidArgumentException('golden_hour_policy_version_not_found');
        }
        if ((string) $row->status === 'active' || (int) $row->active_slot === 1) {
            throw new \InvalidArgumentException('golden_hour_active_version_requires_replacement');
        }
        $old = $this->decode($row, $this->definitions()[GoldenHourRule::GLOBAL_IRAN_KEY]);
        $this->settings->begin();
        try {
            $this->settings->deleteById($settingId);
            $this->settings->audit($settingId, $this->key(GoldenHourRule::GLOBAL_IRAN_KEY), 'golden_hour_policy_version_removed', $actor, $now, 'removed=' . $this->auditShape($old));
            $this->settings->commit();
            return array('setting_id' => $settingId, 'removed' => true, 'setting_version' => (int) $row->setting_version);
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
        $periods = $decoded['periods'] ?? ($fallback['periods'] ?? array());
        if (!is_array($periods) || $periods === array()) {
            $periods = array(array('start' => (string) ($decoded['start'] ?? $fallback['start']), 'end' => (string) ($decoded['end'] ?? $fallback['end'])));
        }
        $decoded['periods'] = array_values(array_map(static fn(array $period): array => array('start' => (string) $period['start'], 'end' => (string) $period['end']), $periods));
        $decoded['start'] = (string) ($decoded['periods'][0]['start'] ?? $fallback['start']);
        $decoded['end'] = (string) ($decoded['periods'][0]['end'] ?? $fallback['end']);
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
            'periods' => array_values(array_map(static fn(array $period): array => array('start' => (string) $period['start'], 'end' => (string) $period['end']), (array) ($policy['periods'] ?? array()))),
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
            . ',periods=' . implode('|', array_map(static fn(array $period): string => $period['start'] . '-' . $period['end'], (array) ($policy['periods'] ?? array())))
            . ',from=' . (string) $policy['effective_from']
            . ',until=' . ($policy['effective_until'] === null ? '' : (string) $policy['effective_until']);
    }
}
