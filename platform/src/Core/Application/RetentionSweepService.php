<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

final class RetentionSweepService {
    public function run(string $dataClass, int $actor = 0): array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $policyService = new RecordingConsentPolicyService();
        $policy = $policyService->activePolicy('training');
        $days = 90;
        if ($policy) {
            $map = array(
                'raw_audio' => 'retention_days_raw_audio',
                'mixed_recording' => 'retention_days_mixed_recording',
                'metadata' => 'retention_days_metadata',
                'labels' => 'retention_days_labels',
                'dataset_membership' => 'retention_days_datasets',
            );
            $col = $map[$dataClass] ?? 'retention_days_raw_audio';
            $days = (int) $policy[$col];
        }
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recording_provenance WHERE deletion_status='active' AND captured_at < %s LIMIT 100", $cutoff));
        $scanned = count($rows);
        $purged = 0;

        // synthetic deletion request for sweep
        $wpdb->insert("{$p}deletion_requests", array(
            'uid' => Identifier::uid(),
            'subject_type' => 'system',
            'subject_id' => 0,
            'scope' => 'retention_sweep',
            'target_recording_uid' => null,
            'requested_at' => gmdate('Y-m-d H:i:s'),
            'requested_by_principal' => $actor,
            'status' => 'pending',
            'completed_at' => null,
            'failure_reason_code' => null,
            'evidence_digest' => hash('sha256', 'sweep:' . $dataClass . gmdate('Y-m-d H:i:s')),
            'idempotency_key_digest' => hash('sha256', 'sweep:' . $dataClass),
        ));
        $requestId = (int) $wpdb->insert_id;

        $service = new DeletionRequestService();
        foreach ($rows as $row) {
            try {
                $service->process($requestId, $actor);
                $purged++;
            } catch (\Throwable $e) {
                // continue
            }
        }
        $wpdb->update("{$p}deletion_requests", array('status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s')), array('id' => $requestId));

        $wpdb->insert("{$p}retention_runs", array(
            'uid' => Identifier::uid(),
            'data_class' => $dataClass,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'records_scanned' => $scanned,
            'records_purged' => $purged,
            'status' => 'completed',
            'failure_reason_code' => null,
            'evidence_digest' => hash('sha256', 'run:' . $dataClass . $scanned),
        ));
        return array('data_class' => $dataClass, 'scanned' => $scanned, 'purged' => $purged, 'status' => 'completed');
    }
}
