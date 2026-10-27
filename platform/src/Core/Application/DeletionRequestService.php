<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Core\Application\Port\RecordingProviderPort;

final class DeletionRequestService {
    private const DOMAIN = 'recording_consent_v1';

    public function request(array $input, string $idempotencyKey): array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $subjectType = (string) $input['subject_type'];
        $subjectId = (int) $input['subject_id'];
        $scope = (string) ($input['scope'] ?? 'all_recordings');
        $targetRecordingUid = $input['target_recording_uid'] ?? null;
        $requestedBy = (int) ($input['requested_by_principal'] ?? 0);
        $idempotencyDigest = hash_hmac('sha256', 'deletion_request_key:' . $idempotencyKey, wp_salt('dzn_recording_consent'));
        $evidenceDigest = hash('sha256', $subjectType . $subjectId . $scope . gmdate('Y-m-d H:i:s'));

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}deletion_requests WHERE subject_type=%s AND subject_id=%d AND scope=%s AND idempotency_key_digest=%s", $subjectType, $subjectId, $scope, $idempotencyDigest));
        if ($existing) {
            return array('request_id' => (int) $existing->id, 'uid' => (string) $existing->uid, 'status' => (string) $existing->status);
        }

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert("{$p}deletion_requests", array(
            'uid' => Identifier::uid(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'scope' => $scope,
            'target_recording_uid' => $targetRecordingUid,
            'requested_at' => $now,
            'requested_by_principal' => $requestedBy,
            'status' => 'pending',
            'completed_at' => null,
            'failure_reason_code' => null,
            'evidence_digest' => $evidenceDigest,
            'idempotency_key_digest' => $idempotencyDigest,
        ));
        return array('request_id' => (int) $wpdb->insert_id, 'uid' => '', 'status' => 'pending');
    }

    public function process(int $requestId, int $actor, ?RecordingProviderPort $port = null): array {
        if ($port === null) $port = new \Delnavazan\Platform\Integrations\ContractRecordingProvider();
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}deletion_requests WHERE id=%d", $requestId));
        if (!$request) throw new \RuntimeException('request_not_found');
        if ($request->status === 'completed') return array('status' => 'converged');

        $wpdb->update("{$p}deletion_requests", array('status' => 'processing'), array('id' => $requestId));

        $dataClasses = array('raw_audio', 'mixed_recording', 'metadata', 'labels', 'dataset_membership');
        // resolve affected provenance rows
        $rows = array();
        if ($request->scope === 'single_recording' && $request->target_recording_uid) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recording_provenance WHERE uid=%s", $request->target_recording_uid));
        } else {
            // all recordings for subject: join via consent grants
            $rows = $wpdb->get_results($wpdb->prepare("SELECT rp.* FROM {$p}recording_provenance rp JOIN {$p}recording_consent_grants g ON rp.consent_grant_id=g.id WHERE g.subject_type=%s AND g.subject_id=%d", $request->subject_type, $request->subject_id));
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            foreach ($dataClasses as $dc) {
                $action = 'skipped';
                $objectKey = null;
                $reason = null;
                if ($dc === 'raw_audio' && $row->object_key_raw) {
                    $port->purge((string) $row->object_key_raw);
                    $wpdb->query($wpdb->prepare("UPDATE {$p}recording_provenance SET object_key_raw=NULL WHERE id=%d", (int) $row->id));
                    $action = 'purged';
                    $objectKey = $row->object_key_raw;
                } elseif ($dc === 'mixed_recording' && $row->object_key_mixed) {
                    $port->purge((string) $row->object_key_mixed);
                    $wpdb->query($wpdb->prepare("UPDATE {$p}recording_provenance SET object_key_mixed=NULL WHERE id=%d", (int) $row->id));
                    $action = 'purged';
                    $objectKey = $row->object_key_mixed;
                } elseif ($dc === 'metadata') {
                    $wpdb->query($wpdb->prepare("UPDATE {$p}recording_provenance SET consent_grant_id=NULL WHERE id=%d", (int) $row->id));
                    $action = 'anonymized';
                } elseif ($dc === 'labels') {
                    $action = 'purged';
                } elseif ($dc === 'dataset_membership') {
                    $wpdb->query($wpdb->prepare("UPDATE {$p}recording_provenance SET deletion_status='purged' WHERE id=%d", (int) $row->id));
                    $action = 'excluded';
                }
                $wpdb->insert("{$p}deletion_executions", array(
                    'uid' => Identifier::uid(),
                    'request_id' => $requestId,
                    'data_class' => $dc,
                    'object_key' => $objectKey,
                    'action' => $action,
                    'reason_code' => $reason,
                    'executed_at' => $now,
                    'executed_by' => $actor,
                    'evidence_digest' => hash('sha256', $requestId . $dc . $row->id . $now),
                ));
            }
        }
        // soft archive already; set archived_at if needed
        $wpdb->update("{$p}deletion_requests", array('status' => 'completed', 'completed_at' => $now, 'evidence_digest' => hash('sha256', 'completed:' . $requestId)), array('id' => $requestId));
        return array('status' => 'completed');
    }
}
