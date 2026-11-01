<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;

/**
 * Administrator confirmation of an attended class.
 *
 * This is a thin application boundary over Phase-P attendance authority. The administrator records
 * explicit human evidence, then applies the canonical `settle_delivered` adjudication. LiveKit
 * presence is never used as evidence. The canonical attendance commands, decisions and audit rows
 * remain the only durable record.
 */
final class AdminAttendanceConfirmationService {
    public const CAPABILITY = 'dzn_manage_canonical_attendance_review';

    public function confirm(int $lessonId, int $scheduleVersionId, array $input, string $rawKey): array {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
        if ($lessonId < 1 || $scheduleVersionId < 1) throw new \InvalidArgumentException('canonical_occurrence_required');
        $reference = trim((string) ($input['evidence_reference'] ?? ''));
        if ($reference === '' || strlen($reference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new \InvalidArgumentException('attendance_evidence_reference_required');
        }
        $observedAt = trim((string) ($input['observed_at'] ?? gmdate('Y-m-d H:i:s')));
        if (!CanonicalAttendanceValidator::utc($observedAt)) throw new \InvalidArgumentException('attendance_observed_at_invalid');
        $channel = (string) ($input['evidence_channel'] ?? 'staff_record');
        if (!in_array($channel, array('staff_record', 'authenticated_platform', 'document_reference'), true)) {
            throw new \InvalidArgumentException('attendance_evidence_channel_invalid');
        }
        $key = trim($rawKey);
        if ($key === '') throw new \InvalidArgumentException('attendance_command_key_required');

        $intake = new CanonicalAttendanceIntakeService();
        $claim = $intake->submitClaim($lessonId, $scheduleVersionId, array(
            'claim_kind' => 'attendance_claim',
            'observed_at' => $observedAt,
            'reason_code' => 'administrator_attendance_confirmation',
            'evidence_reference' => $reference,
        ), $key . ':claim');

        $repository = new CanonicalAttendanceRepository();
        $case = $repository->caseFor($lessonId, $scheduleVersionId, true);
        if (!$case) throw new \RuntimeException('canonical_attendance_case_required');
        $caseId = (int) $case->id;
        $state = (string) $case->state;
        if ($state === 'settled') {
            $this->audit($lessonId, $scheduleVersionId, $caseId, 'already_settled', $channel);
            return array('case_id' => $caseId, 'state' => 'settled', 'idempotent' => true, 'claim' => $claim);
        }
        if (!in_array($state, array('open', 'ready_for_review', 'settlement_pending'), true)) {
            throw new \RuntimeException('attendance_case_not_confirmable');
        }
        $decision = $intake->adjudicate($caseId, array(
            'expected_case_version' => (int) $case->case_version,
            'adjudication' => 'settle_delivered',
        ), $key . ':decision');
        $this->audit($lessonId, $scheduleVersionId, $caseId, 'settled', $channel);
        return array('case_id' => $caseId, 'state' => 'settled', 'idempotent' => false, 'claim' => $claim, 'decision' => $decision);
    }

    /** Best-effort administrator audit; canonical attendance commands remain authoritative. */
    private function audit(int $lessonId, int $scheduleVersionId, int $caseId, string $outcome, string $channel): void {
        global $wpdb;
        $actor = (int) get_current_user_id();
        $table = $wpdb->prefix . 'dzn_platform_audit_events';
        $key = hash('sha256', 'admin-attendance-confirm|' . $lessonId . '|' . $scheduleVersionId . '|' . $caseId . '|' . $actor . '|' . $outcome);
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE idempotency_key=%s", $key)) > 0) return;
        $detail = wp_json_encode(array(
            'resource' => 'lesson',
            'lesson_id' => $lessonId,
            'schedule_version_id' => $scheduleVersionId,
            'attendance_case_id' => $caseId,
            'outcome' => $outcome,
            'evidence_channel' => $channel,
            'evidence' => 'explicit_administrator_confirmation',
        ), JSON_UNESCAPED_SLASHES);
        $ok = $wpdb->insert($table, array(
            'aggregate_type' => 'lesson',
            'aggregate_id' => $lessonId,
            'event_type' => 'admin_classroom.attendance_confirmed',
            'actor_type' => 'user',
            'actor_id' => $actor,
            'reason_code' => 'administrator_attendance_confirmation',
            'safe_detail' => is_string($detail) ? $detail : null,
            'idempotency_key' => $key,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ));
        if ($ok === false) do_action('dzn_admin_attendance_audit_failed', $lessonId, $caseId);
    }
}
