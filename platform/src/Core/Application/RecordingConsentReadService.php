<?php
namespace Delnavazan\Platform\Core\Application;

final class RecordingConsentReadService {
    public function read(string $subjectType, int $subjectId, string $purposeCode): ?array {
        if (!current_user_can('dzn_view_recording_consent')) throw new \RuntimeException('capability_denied');
        $service = new RecordingConsentService();
        $grant = $service->currentConsent($subjectType, $subjectId, $purposeCode);
        if (!$grant) return null;
        return array(
            'subject_type' => $grant['subject_type'],
            'subject_id' => $grant['subject_id'],
            'purpose_code' => $grant['purpose_code'],
            'policy_version_id' => $grant['policy_version_id'],
            'granted_at' => $grant['granted_at'],
            'scope_snapshot' => $grant['scope_snapshot'],
            'consent_text_version' => $grant['consent_text_version'],
            'evidence_digest' => $grant['evidence_digest'],
        );
    }
}
