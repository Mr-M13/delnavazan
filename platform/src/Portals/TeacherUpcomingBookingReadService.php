<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/** Safe Teacher-owned read projection for provider-confirmed introductory bookings. */
final class TeacherUpcomingBookingReadService {
    public function __construct(private ?BookingTeacherDeliveryRepository $repository = null) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
    }

    public function forTeacher(int $teacherId): array {
        if ($teacherId < 1) throw new \InvalidArgumentException('portal_principal_unresolved');
        if (!current_user_can('dzn_view_own_portal_schedule')) throw new \InvalidArgumentException('portal_object_not_owned');
        $classes = array();
        foreach ($this->repository->confirmedUpcomingForTeacher($teacherId) as $row) {
            if (!$this->isConfirmedOwnerRow($row, $teacherId)) continue;
            $classes[] = array(
                'version' => 'teacher_booking_class_v1',
                'confirmation_uid' => (string) $row->confirmation_uid,
                'request_reference' => (string) $row->request_reference,
                'lesson_kind' => 'intro',
                'confirmation_state' => 'confirmed',
                'student_display_reference' => (string) $row->student_display_reference,
                'course_reference' => (string) $row->course_reference,
                'starts_at_utc' => (string) $row->starts_at_utc,
                'ends_at_utc' => (string) $row->ends_at_utc,
                'schedule_timezone' => (string) $row->schedule_timezone,
            );
        }
        return $classes;
    }

    private function isConfirmedOwnerRow(object $row, int $teacherId): bool {
        $start = strtotime((string) ($row->starts_at_utc ?? '') . ' UTC');
        $end = strtotime((string) ($row->ends_at_utc ?? '') . ' UTC');
        return (int) ($row->teacher_id ?? 0) === $teacherId
            && (int) ($row->delivery_teacher_id ?? 0) === $teacherId
            && (string) ($row->confirmation_state ?? '') === 'confirmed'
            && (string) ($row->delivery_state ?? '') === 'confirmed'
            && (string) ($row->lifecycle_status ?? '') === 'closed'
            && (string) ($row->resolution_state ?? '') === 'teacher_confirmed'
            && ($row->privacy_erased_at ?? null) === null
            && (string) ($row->provider_code ?? '') === 'google_calendar'
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($row->provider_event_digest ?? '')) === 1
            && trim((string) ($row->confirmed_at ?? '')) !== ''
            && (int) ($row->projection_secret_id ?? 0) > 0
            && preg_match('/^[0-9A-Z]{26}$/D', (string) ($row->confirmation_uid ?? '')) === 1
            && $start !== false && $start >= time()
            && $end !== false && $end > $start
            && trim((string) ($row->student_display_reference ?? '')) !== ''
            && trim((string) ($row->course_reference ?? '')) !== '';
    }
}
