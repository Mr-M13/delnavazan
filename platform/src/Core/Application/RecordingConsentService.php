<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

final class RecordingConsentService {
    private const DOMAIN = 'recording_consent_v1';
    private const SALT = 'dzn_recording_consent';

    public function __construct(private ?RecordingConsentRepository $repository = null) {
        // repository optional; services may call global $wpdb directly
    }

    public static function keyDigest(string $key): string {
        if (trim($key) === '') throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256', 'recording_consent_key:' . $key, wp_salt(self::SALT));
    }

    public static function payloadDigest(array $payload): string {
        ksort($payload);
        return hash_hmac('sha256', (string) wp_json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES), wp_salt(self::SALT));
    }

    private static function canonical(array $value): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = self::canonical($item);
            elseif (is_bool($item)) $value[$key] = $item ? 1 : 0;
            elseif (is_object($item)) $value[$key] = self::canonical((array) $item);
        }
        return $value;
    }

    public function grant(array $input, string $idempotencyKey): array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $subjectType = (string) $input['subject_type'];
        $subjectId = (int) $input['subject_id'];
        $purposeCode = (string) ($input['purpose_code'] ?? 'training');
        $grantedBy = (int) ($input['granted_by_principal'] ?? 0);
        $grantedAt = (string) ($input['granted_at'] ?? gmdate('Y-m-d H:i:s'));
        $guardianFor = isset($input['guardian_for_student_id']) ? (int) $input['guardian_for_student_id'] : null;

        $policyService = new RecordingConsentPolicyService();
        $policy = $policyService->activePolicy($purposeCode);
        $policyVersionId = $policy ? (int) $policy['id'] : 0;
        $scopeSnapshot = $policy ? (string) $policy['scope_snapshot'] : (string) ($input['scope_snapshot'] ?? 'audio_raw,mixed_recording,metadata,labels');
        $consentTextVersion = $policy ? (string) $policy['consent_text_version'] : (string) ($input['consent_text_version'] ?? 'v1');

        $evidenceDigest = hash('sha256', $purposeCode . $subjectType . $subjectId . $policyVersionId . $grantedAt . $consentTextVersion);
        $idempotencyDigest = self::keyDigest($idempotencyKey);
        $commandKeyDigest = self::keyDigest('grant:' . $subjectType . ':' . $subjectId . ':' . $purposeCode . ':' . $idempotencyKey);
        $commandPayloadDigest = self::payloadDigest(array(
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'purpose_code' => $purposeCode,
            'policy_version_id' => $policyVersionId, 'granted_at' => $grantedAt,
        ));

        // idempotency check
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_consent_grants WHERE subject_type=%s AND subject_id=%d AND purpose_code=%s AND idempotency_key_digest=%s", $subjectType, $subjectId, $purposeCode, $idempotencyDigest));
        if ($existing) {
            return array('grant_id' => (int) $existing->id, 'uid' => (string) $existing->uid, 'status' => 'granted');
        }

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->insert("{$p}recording_consent_grants", array(
                'uid' => Identifier::uid(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'guardian_for_student_id' => $guardianFor,
                'policy_version_id' => $policyVersionId,
                'purpose_code' => $purposeCode,
                'scope_snapshot' => $scopeSnapshot,
                'consent_text_version' => $consentTextVersion,
                'granted_at' => $grantedAt,
                'granted_by_principal' => $grantedBy,
                'evidence_digest' => $evidenceDigest,
                'idempotency_key_digest' => $idempotencyDigest,
                'command_id' => null,
            ));
            $grantId = (int) $wpdb->insert_id;
            $grantUid = $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}recording_consent_grants WHERE id=%d", $grantId));
            $wpdb->insert("{$p}recording_consent_commands", array(
                'uid' => Identifier::uid(),
                'domain' => self::DOMAIN,
                'operation' => 'grant',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'purpose_code' => $purposeCode,
                'command_key_digest' => $commandKeyDigest,
                'command_payload_digest' => $commandPayloadDigest,
                'result_grant_id' => $grantId,
                'result_state' => 'granted',
                'created_at' => $grantedAt,
                'created_by' => $grantedBy,
            ));
            $commandId = (int) $wpdb->insert_id;
            $wpdb->update("{$p}recording_consent_grants", array('command_id' => $commandId), array('id' => $grantId));
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return array('grant_id' => $grantId, 'uid' => (string) $grantUid, 'status' => 'granted');
    }

    public function revoke(array $input, string $idempotencyKey): array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $grantId = (int) $input['grant_id'];
        $revokedBy = (int) ($input['revoked_by_principal'] ?? 0);
        $reasonCode = (string) ($input['reason_code'] ?? 'subject_request');
        $revokedAt = (string) ($input['revoked_at'] ?? gmdate('Y-m-d H:i:s'));
        $idempotencyDigest = self::keyDigest($idempotencyKey);

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_consent_revocations WHERE grant_id=%d AND idempotency_key_digest=%s", $grantId, $idempotencyDigest));
        if ($existing) {
            return array('status' => 'revoked');
        }

        $evidenceDigest = hash('sha256', 'revoke' . $grantId . $revokedAt . $reasonCode);
        $commandKeyDigest = self::keyDigest('revoke:' . $grantId . ':' . $idempotencyKey);
        $commandPayloadDigest = self::payloadDigest(array('grant_id' => $grantId, 'reason_code' => $reasonCode, 'revoked_at' => $revokedAt));

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->insert("{$p}recording_consent_revocations", array(
                'uid' => Identifier::uid(),
                'grant_id' => $grantId,
                'revoked_at' => $revokedAt,
                'revoked_by_principal' => $revokedBy,
                'reason_code' => $reasonCode,
                'evidence_digest' => $evidenceDigest,
                'idempotency_key_digest' => $idempotencyDigest,
            ));
            $wpdb->insert("{$p}recording_consent_commands", array(
                'uid' => Identifier::uid(),
                'domain' => self::DOMAIN,
                'operation' => 'revoke',
                'subject_type' => 'teacher',
                'subject_id' => 0,
                'purpose_code' => 'training',
                'command_key_digest' => $commandKeyDigest,
                'command_payload_digest' => $commandPayloadDigest,
                'result_grant_id' => $grantId,
                'result_state' => 'revoked',
                'created_at' => $revokedAt,
                'created_by' => $revokedBy,
            ));
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return array('status' => 'revoked');
    }

    public function currentConsent(string $subjectType, int $subjectId, string $purposeCode): ?array {
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_consent_grants WHERE subject_type=%s AND subject_id=%d AND purpose_code=%s ORDER BY granted_at DESC LIMIT 1", $subjectType, $subjectId, $purposeCode));
        if (!$grant) return null;
        $revoked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_consent_revocations WHERE grant_id=%d", (int) $grant->id));
        if ($revoked) return null;
        return (array) $grant;
    }

    public function hasValidConsent(string $subjectType, int $subjectId, string $purposeCode = 'training'): bool {
        return $this->currentConsent($subjectType, $subjectId, $purposeCode) !== null;
    }
}
