<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Versioned, append-only configuration storage for presentation and finance defaults.
 *
 * These settings never rewrite a completed transaction, statement or lesson snapshot.
 */
final class FinanceSettingsRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    public function begin(): void { global $wpdb; if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Finance settings transaction start failed'); }
    public function commit(): void { global $wpdb; if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Finance settings transaction commit failed'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public function active(string $key, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}finance_settings WHERE setting_key=%s AND active_slot=1 AND status='active'" . ($lock ? ' FOR UPDATE' : ''), $key));
    }

    /** @return list<object> */
    public function history(string $key): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}finance_settings WHERE setting_key=%s ORDER BY setting_version DESC,id DESC", $key)) ?: array();
    }

    public function insert(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->prefix . 'finance_settings', $data) === false) throw new \RuntimeException('Finance setting persistence failed: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    public function supersede(int $id, string $now, int $actor): void {
        global $wpdb;
        $changed = $wpdb->update(
            $this->prefix . 'finance_settings',
            array('status' => 'superseded', 'active_slot' => null, 'updated_at' => $now, 'updated_by' => $actor),
            array('id' => $id, 'active_slot' => 1, 'status' => 'active')
        );
        if ($changed !== 1) throw new \RuntimeException('Finance setting supersession lost its compare-and-swap');
    }

    public function nextVersion(string $key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(setting_version),0)+1 FROM {$this->prefix}finance_settings WHERE setting_key=%s", $key));
    }

    /**
     * Record an audit event for a versioned settings row.
     *
     * It uses the established infrastructure seam and the same digest-only shape as the rest of
     * Finance. The previous and new values remain available in the versioned setting history; the
     * safe detail here is a short, bounded summary.
     */
    public function audit(int $settingId, string $settingKey, string $eventType, int $actor, string $now, ?string $safeDetail = null): void {
        global $wpdb;
        $key = hash('sha256', 'finance_settings:' . $settingKey . ':' . $eventType . ':' . $settingId . ':' . $now);
        $inserted = $wpdb->insert($this->prefix . 'platform_audit_events', array(
            'aggregate_type' => 'finance_settings',
            'aggregate_id' => $settingId,
            'event_type' => substr($eventType, 0, 64),
            'actor_type' => 'user',
            'actor_id' => $actor,
            'reason_code' => null,
            'safe_detail' => $safeDetail === null ? null : substr($safeDetail, 0, 190),
            'idempotency_key' => $key,
            'occurred_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('Finance setting audit evidence could not be recorded');
    }
}
