<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/**
 * Teacher-owned read projection for accepted introductory bookings whose classroom is not ready.
 *
 * Acceptance is never inferred from an allocation offer. The confirmation row is the durable proof,
 * so a consumed offer (active_slot IS NULL) or a provisioning failure cannot make the booking vanish.
 */
final class TeacherBookingProvisioningReadService {
    public function __construct(
        private ?BookingTeacherDeliveryRepository $repository = null
    ) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
    }

    public function forTeacher(int $teacherId, int $limit = 20): array {
        if ($teacherId < 1) throw new \InvalidArgumentException('portal_principal_unresolved');
        $principal = (new PortalPrincipalResolver())->resolve('teacher');
        if ((int) $principal['id'] !== $teacherId) throw new \InvalidArgumentException('portal_object_not_owned');
        if (!current_user_can('dzn_view_own_portal_schedule')) throw new \InvalidArgumentException('portal_object_not_owned');
        $rows = array();
        foreach ($this->repository->acceptedProvisioningForTeacher($teacherId, $limit) as $row) {
            $projection = $this->project($row, $teacherId);
            if ($projection !== null) $rows[] = $projection;
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function project(object $row, int $teacherId): ?array {
        $start = strtotime((string) ($row->starts_at_utc ?? '') . ' UTC');
        $end = strtotime((string) ($row->ends_at_utc ?? '') . ' UTC');
        if ((int) ($row->teacher_id ?? 0) !== $teacherId
            || (string) ($row->lifecycle_status ?? '') !== 'submitted'
            || (string) ($row->resolution_state ?? '') !== 'unresolved'
            || ($row->privacy_erased_at ?? null) !== null
            || $start === false || $end === false || $end < $start
            || trim((string) ($row->student_name ?? '')) === ''
            || trim((string) ($row->confirmation_uid ?? '')) === '') return null;

        $confirmationState = (string) ($row->confirmation_state ?? '');
        $classroomState = (string) ($row->classroom_state ?? '');
        $failureCode = trim((string) ($row->failure_reason_code ?? ''));
        if ($failureCode === '') $failureCode = trim((string) ($row->classroom_failure_reason_code ?? ''));
        $permanent = class_exists('\\Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\MeetProvisioningRule')
            ? \Delnavazan\Platform\Integrations\GoogleWorkspace\MeetProvisioningRule::isPermanentCode($failureCode)
            : preg_match('/(?:_refused|_blocked)$/D', $failureCode) === 1;
        $failed = $confirmationState === 'failed'
            || in_array($classroomState, array('failed', 'blocked'), true)
            || $permanent;
        $provisioningState = $confirmationState === 'awaiting_payment'
            ? 'payment_pending'
            : ($failed ? 'failed' : ($confirmationState === 'awaiting_conference' ? 'meet_pending' : 'provisioning'));

        return array(
            'version' => 'teacher_booking_provisioning_v1',
            'confirmation_uid' => (string) $row->confirmation_uid,
            'request_reference' => (string) ($row->request_reference ?? ''),
            'student_name' => (string) $row->student_name,
            'instrument_name' => (string) ($row->instrument_name ?? $row->course_name ?? ''),
            'course_reference' => (string) ($row->course_name ?? $row->instrument_name ?? ''),
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'schedule_timezone' => (string) ($row->teacher_timezone ?: $row->schedule_timezone ?: 'UTC'),
            'acceptance_state' => 'accepted',
            'provisioning_state' => $provisioningState,
            'failure_reason_code' => $failureCode !== '' ? $failureCode : null,
            'attempt_count' => (int) ($row->attempt_count ?? 0),
            'last_attempt_at' => (string) (($row->classroom_updated_at ?? null) ?: ($row->confirmation_updated_at ?? '')),
            'join_available' => false,
        );
    }
}
