<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/** Safe Teacher-owned read projection for provider-confirmed introductory bookings. */
final class TeacherUpcomingBookingReadService {
    public function __construct(
        private ?BookingTeacherDeliveryRepository $repository = null,
        private ?BookingUpcomingReadProjector $projector = null
    ) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
        $this->projector ??= new BookingUpcomingReadProjector();
    }

    public function forTeacher(int $teacherId, ?string $fromUtc = null, ?string $toUtc = null): array {
        if ($teacherId < 1) throw new \InvalidArgumentException('portal_principal_unresolved');
        $principal = (new PortalPrincipalResolver())->resolve('teacher');
        if ((int) $principal['id'] !== $teacherId) throw new \InvalidArgumentException('portal_object_not_owned');
        if (!current_user_can('dzn_view_own_portal_schedule')) throw new \InvalidArgumentException('portal_object_not_owned');
        $classes = array();
        foreach ($this->repository->confirmedUpcomingForTeacher($teacherId, 50, $fromUtc, $toUtc) as $row) {
            if (!$this->isConfirmedOwnerRow($row, $teacherId)) continue;
            $classes[] = $this->projector->project($row, 'teacher_booking_class_v1');
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
            && preg_match('/^[0-9A-Z]{26}$/D', (string) ($row->confirmation_uid ?? '')) === 1
            && $start !== false && $end !== false && $end > $start
            && $end + 7200 >= time()
            && trim((string) ($row->student_display_reference ?? '')) !== ''
            && trim((string) ($row->course_reference ?? '')) !== '';
    }
}
