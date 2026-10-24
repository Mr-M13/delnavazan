<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/**
 * Read-only operator projection of provider-confirmed introductory bookings.
 *
 * A confirmed intro is not a Lesson until the post-intro workflow records the delivered outcome.
 * This projection fills the operator schedule gap without creating or mutating any Lesson,
 * schedule, capacity, enrolment or commercial record.
 */
final class ConfirmedIntroBookingReadService {
    public function __construct(
        private ?ConfirmedIntroBookingReadPort $repository = null
    ) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
    }

    /** @return array<int,object> */
    public function upcoming(int $limit = 80): array {
        if (!current_user_can('dzn_manage_lessons') || !current_user_can('dzn_view_booking_requests')) {
            throw new \RuntimeException('Unauthorized');
        }
        $rows = array();
        foreach ($this->repository->confirmedUpcomingForOperator($limit) as $row) {
            if (!$this->valid($row)) continue;
            $timezone = (string) $row->schedule_timezone;
            $row->record_model = 'confirmed_intro_booking_v1';
            $row->id = 0; // Explicitly not a Lesson identity.
            $row->reference_code = (string) $row->request_reference;
            $row->lesson_type = 'introductory';
            $row->status = 'confirmed';
            $row->lifecycle_state = 'confirmed';
            $row->canonical_sequence = null;
            $row->current_schedule_version_id = 0;
            $row->student_name = (string) $row->student_display_reference;
            $row->teacher_name = (string) $row->teacher_display_reference;
            $row->course_name = (string) $row->course_reference;
            $row->class_status = 'Confirmed intro';
            $row->schedule_starts_at_utc = (string) $row->starts_at_utc;
            $row->schedule_label = (string) $row->local_wall_date . ' · ' . substr((string) $row->local_wall_time, 0, 5) . ' ' . $timezone;
            $row->next_action = 'Open booking';
            $row->booking_request_id = (int) $row->booking_request_id;
            $row->confirmation_uid = (string) $row->confirmation_uid;
            $row->has_join_reference = (int) ($row->projection_secret_id ?? 0) > 0;
            unset(
                $row->confirmation_id,
                $row->confirmation_state,
                $row->delivery_state,
                $row->provider_code,
                $row->confirmed_at,
                $row->lifecycle_status,
                $row->resolution_state,
                $row->privacy_erased_at,
                $row->student_display_reference,
                $row->teacher_display_reference,
                $row->course_reference,
                $row->projection_secret_id
            );
            $rows[] = $row;
        }
        usort($rows, static fn(object $a, object $b): int => strcmp((string) $a->starts_at_utc, (string) $b->starts_at_utc) ?: ((int) $a->booking_request_id <=> (int) $b->booking_request_id));
        return $rows;
    }

    private function valid(object $row): bool {
        $start = strtotime((string) ($row->starts_at_utc ?? '') . ' UTC');
        $end = strtotime((string) ($row->ends_at_utc ?? '') . ' UTC');
        if (($row->confirmation_state ?? null) !== 'confirmed'
            || ($row->delivery_state ?? null) !== 'confirmed'
            || ($row->lifecycle_status ?? null) !== 'closed'
            || ($row->resolution_state ?? null) !== 'teacher_confirmed'
            || ($row->privacy_erased_at ?? null) !== null
            || ($row->provider_code ?? null) !== 'google_calendar'
            || trim((string) ($row->confirmed_at ?? '')) === ''
            || preg_match('/^[0-9A-Z]{26}$/D', (string) ($row->confirmation_uid ?? '')) !== 1
            || (int) ($row->booking_request_id ?? 0) < 1
            || trim((string) ($row->teacher_display_reference ?? '')) === ''
            || trim((string) ($row->course_reference ?? '')) === ''
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', (string) ($row->local_wall_date ?? '')) !== 1
            || preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', (string) ($row->local_wall_time ?? '')) !== 1
            || $start === false || $end === false || $end <= $start || $end + 7200 < time()) {
            return false;
        }
        $timezone = (string) ($row->schedule_timezone ?? '');
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return false;
        }
        return $timezone !== '';
    }
}
