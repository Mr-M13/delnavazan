<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only identity for Teacher Assignment commands. */
final class TeacherAssignmentIdempotency {
    public static function keyDigest(string $raw): string {
        $raw = trim($raw);
        if (strlen($raw) < 24 || strlen($raw) > 255 || !preg_match('/^[A-Za-z0-9._~-]+$/D', $raw)) {
            throw new \InvalidArgumentException('Valid Teacher Assignment idempotency key required');
        }
        return hash_hmac('sha256', $raw, wp_salt('dzn_teacher_assignment'));
    }

    public static function payloadDigest(string $operation, int $enrolmentId, ?int $teacherId, array $evidence = array()): string {
        ksort($evidence);
        return hash('sha256', wp_json_encode(array(
            'domain' => 'teacher_assignment_v1',
            'operation' => $operation,
            'enrolment_id' => $enrolmentId,
            'teacher_id' => $teacherId,
            'evidence' => $evidence,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function evidenceDigest(string $reference): string {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 255) {
            throw new \InvalidArgumentException('Teacher agreement evidence reference required');
        }
        return hash_hmac('sha256', $reference, wp_salt('dzn_teacher_assignment_evidence'));
    }
}
