<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Pure classroom grant policy.
 *
 * The authorizer hydrates canonical facts; this class makes the access decision. Keeping the
 * decision pure makes every refusal path testable without WordPress or a live LiveKit server.
 *
 * There are exactly two participant identities per resource: the assigned Teacher and the enrolled
 * Student. Identities are stable for reconnects and duplicate tabs, so LiveKit replaces an existing
 * connection with the same identity instead of admitting a third person.
 */
final class LiveKitClassroomGrantPolicy {
    public const EARLY_SECONDS = 3600;
    public const LATE_SECONDS = 7200;

    public function authorize(array $facts, string $role, array $principal, int $now, string $secret): array {
        if (!in_array($role, array('teacher', 'student'), true)) {
            throw new \InvalidArgumentException('livekit_role_not_permitted');
        }
        if ($secret === '') throw new \RuntimeException('livekit_configuration_unavailable');
        $kind = (string)($facts['kind'] ?? '');
        if (!in_array($kind, array('lesson', 'booking'), true)) throw new \InvalidArgumentException('livekit_resource_invalid');
        foreach (array('resource_id', 'resource_uid', 'schedule_key', 'teacher_id', 'student_id', 'starts_at_utc', 'ends_at_utc') as $key) {
            if (!array_key_exists($key, $facts) || trim((string)$facts[$key]) === '') throw new \InvalidArgumentException('livekit_resource_invalid');
        }
        if ((int)$facts['resource_id'] < 1 || (int)$facts['teacher_id'] < 1 || (int)$facts['student_id'] < 1) {
            throw new \InvalidArgumentException('livekit_resource_invalid');
        }
        if (($facts['joinable'] ?? false) !== true) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        if (isset($facts['requested_schedule_key']) && (string)$facts['requested_schedule_key'] !== (string)$facts['schedule_key']) {
            throw new \InvalidArgumentException('livekit_stale_schedule');
        }
        $expectedPrincipal = $role === 'teacher' ? (int)$facts['teacher_id'] : (int)$facts['student_id'];
        if ((int)($principal['id'] ?? 0) !== $expectedPrincipal || (string)($principal['kind'] ?? '') !== $role) {
            throw new \InvalidArgumentException('livekit_participant_not_authorized');
        }
        $start = strtotime((string)$facts['starts_at_utc'] . ' UTC');
        $end = strtotime((string)$facts['ends_at_utc'] . ' UTC');
        if (!$start || !$end || $end <= $start) throw new \InvalidArgumentException('livekit_resource_invalid');
        if ($now < $start - self::EARLY_SECONDS || $now > $end + self::LATE_SECONDS) {
            throw new \InvalidArgumentException('livekit_outside_window');
        }
        $teacherIdentity = $this->identity($secret, $kind, (int)$facts['resource_id'], 'teacher', (int)$facts['teacher_id']);
        $studentIdentity = $this->identity($secret, $kind, (int)$facts['resource_id'], 'student', (int)$facts['student_id']);
        $identity = $role === 'teacher' ? $teacherIdentity : $studentIdentity;
        $peer = $role === 'teacher' ? $studentIdentity : $teacherIdentity;
        $room = 'dzn-' . substr(hash_hmac('sha256', 'dzn-livekit-room|' . $kind . '|' . (string)$facts['resource_uid'] . '|' . (string)$facts['schedule_key'], $secret), 0, 40);
        $teacherName = $this->label((string)($facts['teacher_name'] ?? ''), 'مدرس');
        $studentName = $this->label((string)($facts['student_name'] ?? ''), 'هنرجو');
        $courseName = $this->label((string)($facts['course_name'] ?? ''), 'کلاس موسیقی');
        return array(
            'role' => $role,
            'kind' => $kind,
            'resource_id' => (int)$facts['resource_id'],
            'resource_uid' => (string)$facts['resource_uid'],
            'schedule_key' => (string)$facts['schedule_key'],
            'room' => $room,
            'identity' => $identity,
            'peer_identity' => $peer,
            'teacher_identity' => $teacherIdentity,
            'student_identity' => $studentIdentity,
            'teacher_id' => (int)$facts['teacher_id'],
            'student_id' => (int)$facts['student_id'],
            'display_name' => $role === 'teacher' ? $teacherName : $studentName,
            'peer_name' => $role === 'teacher' ? $studentName : $teacherName,
            'teacher_name' => $teacherName,
            'student_name' => $studentName,
            'course_name' => $courseName,
            'starts_at_utc' => (string)$facts['starts_at_utc'],
            'ends_at_utc' => (string)$facts['ends_at_utc'],
            'joinable' => true,
            'meet_available' => ($facts['meet_available'] ?? false) === true,
            'is_replacement' => ($facts['is_replacement'] ?? false) === true,
        );
    }

    private function identity(string $secret, string $kind, int $resourceId, string $role, int $principalId): string {
        return substr($role, 0, 1) . '-' . substr(hash_hmac('sha256', 'dzn-livekit-participant|' . $kind . '|' . $resourceId . '|' . $role . '|' . $principalId, $secret), 0, 24);
    }

    /**
     * Administrator pre-class inspection grant.
     *
     * The administrator is a third, separately identified participant in the same canonical room.
     * This deliberately does not apply the participant joining window, but it still requires a
     * joinable canonical resource, derives the identical room name, and never returns an API secret.
     * The grant is short-lived at the token boundary and is not a shareable participant link.
     */
    public function authorizeAdministrator(array $facts, int $adminUserId, int $now, string $secret): array {
        if ($adminUserId < 1) throw new \InvalidArgumentException('livekit_admin_not_authorized');
        if ($secret === '') throw new \RuntimeException('livekit_configuration_unavailable');
        $kind = (string) ($facts['kind'] ?? '');
        if (!in_array($kind, array('lesson', 'booking'), true)) throw new \InvalidArgumentException('livekit_resource_invalid');
        foreach (array('resource_id', 'resource_uid', 'schedule_key', 'teacher_id', 'student_id', 'starts_at_utc', 'ends_at_utc') as $key) {
            if (!array_key_exists($key, $facts) || trim((string) $facts[$key]) === '') throw new \InvalidArgumentException('livekit_resource_invalid');
        }
        if ((int) $facts['resource_id'] < 1 || (int) $facts['teacher_id'] < 1 || (int) $facts['student_id'] < 1) {
            throw new \InvalidArgumentException('livekit_resource_invalid');
        }
        if (($facts['joinable'] ?? false) !== true) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        if (isset($facts['requested_schedule_key']) && (string) $facts['requested_schedule_key'] !== (string) $facts['schedule_key']) {
            throw new \InvalidArgumentException('livekit_stale_schedule');
        }
        $start = strtotime((string) $facts['starts_at_utc'] . ' UTC');
        $end = strtotime((string) $facts['ends_at_utc'] . ' UTC');
        if (!$start || !$end || $end <= $start) throw new \InvalidArgumentException('livekit_resource_invalid');

        $teacherIdentity = $this->identity($secret, $kind, (int) $facts['resource_id'], 'teacher', (int) $facts['teacher_id']);
        $studentIdentity = $this->identity($secret, $kind, (int) $facts['resource_id'], 'student', (int) $facts['student_id']);
        $adminIdentity = $this->identity($secret, $kind, (int) $facts['resource_id'], 'administrator', $adminUserId);
        $room = 'dzn-' . substr(hash_hmac('sha256', 'dzn-livekit-room|' . $kind . '|' . (string) $facts['resource_uid'] . '|' . (string) $facts['schedule_key'], $secret), 0, 40);
        return array(
            'role' => 'administrator',
            'kind' => $kind,
            'resource_id' => (int) $facts['resource_id'],
            'resource_uid' => (string) $facts['resource_uid'],
            'schedule_key' => (string) $facts['schedule_key'],
            'room' => $room,
            'identity' => $adminIdentity,
            'peer_identity' => $studentIdentity,
            'teacher_identity' => $teacherIdentity,
            'student_identity' => $studentIdentity,
            'admin_identity' => $adminIdentity,
            'teacher_id' => (int) $facts['teacher_id'],
            'student_id' => (int) $facts['student_id'],
            'admin_user_id' => $adminUserId,
            'display_name' => 'مدیر دلنوازان',
            'peer_name' => $this->label((string) ($facts['student_name'] ?? ''), 'هنرجو'),
            'teacher_name' => $this->label((string) ($facts['teacher_name'] ?? ''), 'مدرس'),
            'student_name' => $this->label((string) ($facts['student_name'] ?? ''), 'هنرجو'),
            'course_name' => $this->label((string) ($facts['course_name'] ?? ''), 'کلاس موسیقی'),
            'starts_at_utc' => (string) $facts['starts_at_utc'],
            'ends_at_utc' => (string) $facts['ends_at_utc'],
            'joinable' => true,
            'meet_available' => ($facts['meet_available'] ?? false) === true,
            'is_replacement' => ($facts['is_replacement'] ?? false) === true,
            'administrator' => true,
        );
    }

    private function label(string $value, string $fallback): string {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if ($value === '') return $fallback;
        return function_exists('mb_substr') ? mb_substr($value, 0, 64, 'UTF-8') : substr($value, 0, 64);
    }
}
