<?php
namespace Delnavazan\Platform\Portals;

/** Shared fail-closed projection of a confirmed introductory booking. */
final class BookingUpcomingReadProjector {
    public function project(object $row, string $version): array {
        $join = $this->joinState($row);
        return array(
            'version' => $version,
            'confirmation_uid' => (string) $row->confirmation_uid,
            'request_reference' => (string) $row->request_reference,
            'lesson_kind' => 'intro',
            'confirmation_state' => 'confirmed',
            'student_display_reference' => (string) ($row->student_display_reference ?? ''),
            'teacher_display_reference' => (string) ($row->teacher_display_reference ?? ''),
            'course_reference' => (string) $row->course_reference,
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'join_available' => $join['available'],
            'join_issue' => $join['issue'],
        );
    }

    private function joinState(object $row): array {
        if ((int) ($row->projection_secret_id ?? 0) < 1
            || (string) ($row->projection_secret_state ?? '') !== 'active'
            || trim((string) ($row->projection_secret_key_version ?? '')) === ''
            || trim((string) ($row->projection_secret_cipher_version ?? '')) === ''
            || trim((string) ($row->projection_secret_nonce ?? '')) === ''
            || trim((string) ($row->projection_secret_ciphertext ?? '')) === '') {
            return array('available' => false, 'issue' => 'provider_reference_unavailable');
        }
        return array('available' => true, 'issue' => null);
    }
}
