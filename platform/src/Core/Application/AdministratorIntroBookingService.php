<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Guided introductory booking for an already verified Student.
 *
 * It creates the same public-intake aggregate, identity evidence and administrative Teacher match
 * as the normal booking journey. It never creates payment, attendance or a Teacher agreement row:
 * the operator must record the attributable agreement and any explicit availability override.
 */
final class AdministratorIntroBookingService {
    public function __construct(
        private ?AdministratorBookingMatchService $match = null,
        private ?TeacherAvailabilityService $availability = null,
        private ?BookingTeacherOccupancyReadService $occupancy = null
    ) {
        $this->match ??= new AdministratorBookingMatchService();
        $this->availability ??= new TeacherAvailabilityService();
        $this->occupancy ??= new BookingTeacherOccupancyReadService();
    }

    /** @return array<string,mixed> */
    public function create(int $studentId, int $courseId, int $teacherId, array $slot, array $agreement, string $idempotencyKey): array {
        if (!current_user_can('dzn_manage_booking_request_coordination') || !current_user_can('dzn_manage_teacher_availability_assent')) throw new \RuntimeException('Unauthorized');
        if ($studentId < 1 || $courseId < 1 || $teacherId < 1) throw new \InvalidArgumentException('Student, Course and Teacher are required');
        global $wpdb;
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dzn_students WHERE id=%d AND archived_at IS NULL AND status<>'archived'",
            $studentId
        ));
        if (!$student) throw new \InvalidArgumentException('Choose an active Student');
        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dzn_courses WHERE id=%d AND archived_at IS NULL AND status='active'",
            $courseId
        ));
        if (!$course || (string) $course->course_type !== 'introductory') throw new \InvalidArgumentException('Choose an active introductory Course');
        foreach (array('email', 'phone', 'country_code', 'city', 'timezone') as $field) {
            if (trim((string) ($student->{$field} ?? '')) === '') throw new \InvalidArgumentException('The Student needs a verified email, phone, country, city and timezone before an introductory booking can be created');
        }
        $requestKey = 'admin-intro-request-' . $idempotencyKey;
        // Idempotency must win over the occupancy preflight: a repeated command
        // must return its existing confirmation, not mistake that confirmation
        // for a collision with a different class.
        $replay = $this->existingIntroConfirmation($requestKey, $teacherId);
        if ($replay !== null) return $replay;
        $date = (string) ($slot['local_date'] ?? '');
        $time = (string) ($slot['local_start_time'] ?? '');
        $timezone = (string) ($slot['timezone'] ?? $student->timezone);
        $this->assertAvailableForPreview($teacherId, $course, $date, $time, $timezone);
        $submissions = new BookingRequestSubmissionService();
        $submission = $submissions->submitPublic(array(
            'requested_instrument_id' => (int) $course->instrument_id,
            'selected_intro_course_id' => $courseId,
            'full_name' => (string) ($student->display_name ?: trim((string) $student->first_name . ' ' . (string) $student->last_name)),
            'email' => (string) $student->email,
            'mobile' => (string) $student->phone,
            'country' => (string) $student->country_code,
            'city' => (string) $student->city,
            'timezone' => (string) $student->timezone,
            'communication_language' => str_starts_with((string) ($student->locale ?? ''), 'fa') ? 'fa' : 'en',
            'whatsapp_same_as_mobile' => true,
            'whatsapp_number' => (string) ($student->whatsapp_phone ?: $student->phone),
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => '2026-09-05',
            'adult_self_attested' => true,
            'requested_times' => array(array(
                'local_date' => $date,
                'local_start_time' => $time,
                'timezone' => $this->safeTimezone($timezone),
            )),
        ), $requestKey, null, $studentId);
        $requestId = (int) ($submission['request_id'] ?? 0);
        if ($requestId < 1) {
            $requestId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s",
                (string) ($submission['request_reference'] ?? '')
            ));
        }
        if ($requestId < 1) throw new \RuntimeException('Introductory booking request could not be resolved');
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d", $requestId));
        if (!$request) throw new \RuntimeException('Introductory booking request could not be resolved');
        try {
        $existingConfirmation = $wpdb->get_row($wpdb->prepare(
            "SELECT id,state FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        if ($existingConfirmation) {
            return array(
                'request_id' => $requestId,
                'request_reference' => (string) ($request->reference_code ?? ''),
                'requested_time_id' => 0,
                'teacher_id' => $teacherId,
                'confirmation_id' => (int) $existingConfirmation->id,
                'state' => (string) $existingConfirmation->state,
                'idempotent' => true,
            );
        }
        if ((int) ($request->student_id ?? 0) < 1) {
            try {
                (new StudentIdentityResolutionService())->resolveExisting(
                    $requestId,
                    $studentId,
                    'verified_internal_record',
                    'verified_internal_record',
                    gmdate('Y-m-d H:i:s'),
                    (int) get_current_user_id()
                );
            } catch (\InvalidArgumentException $exception) {
                if (strpos($exception->getMessage(), 'already resolved') === false) throw $exception;
            }
        }
        $requestedTimeId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number LIMIT 1",
            $requestId
        ));
        if ($requestedTimeId < 1) throw new \RuntimeException('Introductory booking time could not be resolved');
        $match = $this->match->confirm($requestId, $requestedTimeId, $teacherId, $agreement, 'admin-intro-match-' . $idempotencyKey);
        return array(
            'request_id' => $requestId,
            'request_reference' => (string) ($request->reference_code ?? ''),
            'requested_time_id' => $requestedTimeId,
            'teacher_id' => $teacherId,
            'confirmation_id' => (int) ($match['confirmation_id'] ?? 0),
            'state' => (string) ($match['state'] ?? 'accepting'),
        );
        } catch (\Throwable $error) {
            if (empty($submission['replayed'])) {
                try {
                    $submissions->discardCreatedRequest($requestId, 'admin_intro_failed');
                } catch (\Throwable $cleanup) {
                    error_log('[delnavazan admin intro] compensation failed ' . get_class($cleanup) . ' ' . $cleanup->getMessage());
                }
            }
            throw $error;
        }
    }

    /** Return the confirmation already produced by this exact admin command, if any. */
    private function existingIntroConfirmation(string $requestKey, int $teacherId): ?array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $digest = BookingRequestIdempotency::keyDigest($requestKey);
        $requestId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT booking_request_id FROM {$p}booking_request_submission_keys WHERE key_digest=%s AND state='completed' AND booking_request_id IS NOT NULL LIMIT 1",
            $digest
        ));
        if ($requestId < 1) return null;
        $confirmation = $wpdb->get_row($wpdb->prepare(
            "SELECT id,state,teacher_id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        if (!$confirmation || (int) $confirmation->teacher_id !== $teacherId) return null;
        $request = $wpdb->get_row($wpdb->prepare("SELECT reference_code FROM {$p}booking_requests WHERE id=%d", $requestId));
        return array(
            'request_id' => $requestId,
            'request_reference' => (string) ($request->reference_code ?? ''),
            'requested_time_id' => 0,
            'teacher_id' => $teacherId,
            'confirmation_id' => (int) $confirmation->id,
            'state' => (string) $confirmation->state,
            'idempotent' => true,
        );
    }

    /** @return array<string,mixed> */
    public function preview(int $courseId, int $teacherId, string $localDate, string $localTime, string $timezone): array {
        if (!current_user_can('dzn_manage_booking_request_coordination')) throw new \RuntimeException('Unauthorized');
        if ($courseId < 1 || $teacherId < 1) return array('state' => 'incomplete', 'label' => 'Choose a Course and Teacher');
        global $wpdb;
        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_courses WHERE id=%d", $courseId));
        if (!$course) return array('state' => 'invalid', 'label' => 'Course unavailable');
        try {
            $interval = $this->interval($course, $localDate, $localTime, $timezone);
        } catch (\Throwable $exception) {
            return array('state' => 'invalid', 'label' => 'Invalid local time', 'detail' => $exception->getMessage());
        }
        $conflict = $this->occupancy->overlapping($teacherId, $interval['starts_at_utc'], $interval['occupied_ends_at_utc']);
        if ($conflict) return array('state' => 'conflict', 'label' => 'Teacher has a real schedule collision', 'detail' => 'Choose another slot; the booking will not be double-booked.');
        $covered = $this->covered($teacherId, $interval['starts_at_utc'], $interval['ends_at_utc'], $courseId);
        return array(
            'state' => $covered ? 'available' : 'override_required',
            'label' => $covered ? 'Teacher is available' : 'Outside normal availability — explicit override required',
            'detail' => $covered ? 'No collision. The normal confirmation notification is queued after the agreement and provider projection; transport acceptance is not recipient delivery.' : 'Record the Teacher agreement and override reason; a real collision is still refused. The normal confirmation notification path still applies.',
            'starts_at_utc' => $interval['starts_at_utc'],
            'ends_at_utc' => $interval['ends_at_utc'],
            'timezone' => $this->safeTimezone($timezone),
        );
    }

    private function assertAvailableForPreview(int $teacherId, object $course, string $date, string $time, string $timezone): void {
        $interval = $this->interval($course, $date, $time, $timezone);
        if ($interval['starts_at_utc'] <= gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('Choose a future introductory time');
        if (RequestedTimeNormalizer::overlapsStudentQuietHours($interval)) throw new \InvalidArgumentException(BookingRequestValidationService::BLOCKED_STUDENT_TIME_MESSAGE);
        if ($this->occupancy->overlapping($teacherId, $interval['starts_at_utc'], $interval['occupied_ends_at_utc'])) throw new \InvalidArgumentException('The Teacher already has a class at that time. Choose another slot.');
        try {
            CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId, $interval['starts_at_utc'], $interval['occupied_ends_at_utc']);
            CommercialCapacityAuthority::assertNoConflictingClaim($teacherId, $interval['starts_at_utc'], $interval['occupied_ends_at_utc']);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'teacher_slot_conflict') throw new \InvalidArgumentException('The Teacher has a reserved continuation slot at that time. Choose another slot.');
            throw $exception;
        }
    }

    /** @return array{local_date:string,local_start_time:string,timezone:string,starts_at_utc:string,ends_at_utc:string,occupied_ends_at_utc:string} */
    private function interval(object $course, string $date, string $time, string $timezone): array {
        $timezone = $this->safeTimezone($timezone);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new \InvalidArgumentException('Valid local date required');
        $time = preg_match('/^\d{2}:\d{2}$/D', $time) ? $time . ':00' : $time;
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time)) throw new \InvalidArgumentException('Valid local time required');
        $zone = new \DateTimeZone($timezone);
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $zone);
        if (!$local || $local->format('Y-m-d H:i:s') !== $date . ' ' . $time) throw new \InvalidArgumentException('This local time does not exist in the chosen timezone');
        $end = $local->modify('+' . max(5, (int) $course->default_duration_minutes) . ' minutes');
        $occupied = $end->modify('+' . max(0, (int) $course->default_buffer_minutes) . ' minutes');
        $utc = new \DateTimeZone('UTC');
        return array(
            'local_date' => $date,
            'local_start_time' => $time,
            'timezone' => $timezone,
            'starts_at_utc' => $local->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'occupied_ends_at_utc' => $occupied->setTimezone($utc)->format('Y-m-d H:i:s'),
        );
    }

    private function covered(int $teacherId, string $start, string $end, ?int $courseId = null): bool {
        $cursor = $start;
        foreach ($this->availability->effective($teacherId, $start, $end, $courseId ? $this->availability->instrumentIdForCourse($courseId) : null) as $segment) {
            if ((string) $segment['starts_at_utc'] > $cursor) return false;
            if (!in_array((string) $segment['state'], array('preferred', 'requestable'), true)) return false;
            if ((string) $segment['ends_at_utc'] > $cursor) $cursor = min($end, (string) $segment['ends_at_utc']);
            if ($cursor === $end) return true;
        }
        return false;
    }

    private function safeTimezone(string $timezone): string {
        $timezone = trim($timezone);
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
