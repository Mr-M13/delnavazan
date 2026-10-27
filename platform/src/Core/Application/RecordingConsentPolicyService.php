<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

final class RecordingConsentPolicyService {
    private const CAPABILITY = 'dzn_manage_recording_consent';

    public function activePolicy(string $purposeCode): ?array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_consent_policies WHERE purpose_code=%s AND status='active' ORDER BY policy_version DESC LIMIT 1", $purposeCode));
        if (!$row) return null;
        return (array) $row;
    }

    public function createPolicyVersion(array $input, int $actor): array {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('capability_denied');
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $purposeCode = (string) ($input['purpose_code'] ?? 'training');
        $policyVersion = (int) ($input['policy_version'] ?? 1);
        $now = gmdate('Y-m-d H:i:s');
        // supersede previous active
        $wpdb->query($wpdb->prepare("UPDATE {$p}recording_consent_policies SET status='superseded', superseded_at=%s WHERE purpose_code=%s AND status='active'", $now, $purposeCode));
        $id = $wpdb->insert("{$p}recording_consent_policies", array(
            'uid' => Identifier::uid(),
            'policy_version' => $policyVersion,
            'purpose_code' => $purposeCode,
            'scope_snapshot' => (string) ($input['scope_snapshot'] ?? 'audio_raw,mixed_recording,metadata,labels'),
            'retention_days_raw_audio' => (int) ($input['retention_days_raw_audio'] ?? 90),
            'retention_days_mixed_recording' => (int) ($input['retention_days_mixed_recording'] ?? 365),
            'retention_days_metadata' => (int) ($input['retention_days_metadata'] ?? 1825),
            'retention_days_labels' => (int) ($input['retention_days_labels'] ?? 1825),
            'retention_days_datasets' => (int) ($input['retention_days_datasets'] ?? 1825),
            'consent_text_version' => (string) ($input['consent_text_version'] ?? 'v1'),
            'status' => 'active',
            'effective_from' => (string) ($input['effective_from'] ?? $now),
            'superseded_at' => null,
            'created_at' => $now,
            'created_by' => $actor,
        ));
        if ($id === false) throw new \RuntimeException($wpdb->last_error);
        return array('id' => (int) $wpdb->insert_id, 'status' => 'active');
    }
}
