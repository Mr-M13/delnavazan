<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/** Student-owned read projection for a provider-confirmed free introductory lesson. */
final class StudentUpcomingBookingReadService {
    public function __construct(
        private ?BookingTeacherDeliveryRepository $repository = null,
        private ?BookingUpcomingReadProjector $projector = null
    ) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
        $this->projector ??= new BookingUpcomingReadProjector();
    }

    public function forStudent(int $studentId): array {
        if ($studentId < 1) throw new \InvalidArgumentException('portal_principal_unresolved');
        $principal = (new PortalPrincipalResolver())->resolve('student');
        if ((int) $principal['id'] !== $studentId) throw new \InvalidArgumentException('portal_object_not_owned');
        $classes = array();
        foreach ($this->repository->confirmedUpcomingForStudent($studentId) as $row) {
            if (!$this->isConfirmedOwnerRow($row, $studentId)) continue;
            $classes[] = $this->projector->project($row, 'student_booking_class_v1');
        }
        return $classes;
    }

    private function isConfirmedOwnerRow(object $row, int $studentId): bool {
        $start = strtotime((string) ($row->starts_at_utc ?? '') . ' UTC');
        $end = strtotime((string) ($row->ends_at_utc ?? '') . ' UTC');
        return (int) ($row->student_id ?? 0) === $studentId
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
            && trim((string) ($row->teacher_display_reference ?? '')) !== ''
            && trim((string) ($row->course_reference ?? '')) !== '';
    }
}
