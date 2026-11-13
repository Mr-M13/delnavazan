<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonScheduleRepository,ConfirmedIntroBookingCancellationRepository,LessonRescheduleRepository};
use Delnavazan\Platform\Core\Support\PersianCalendar;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Cancellation and reschedule authority for a confirmed introductory booking.
 *
 * An introductory booking is a booking-request confirmation, not a canonical Term
 * Lesson. This service therefore owns exactly that aggregate: it never invents a
 * Term, Enrolment or synthetic Lesson. Ownership is proven for the administrator,
 * the Student and the confirming Teacher. A reschedule revalidates the real
 * Teacher/Student occupancy, protected continuation holds and commercial capacity
 * before changing the confirmation. Every decision is append-only audited and
 * queues the same durable notification rows as the rest of the booking lifecycle.
 */
final class IntroLessonLifecycleService {
    public const REASONS = array('student_requested', 'teacher_requested', 'operational_change', 'duplicate_request');

    private string $p;

    public function __construct(private ?BookingCalendarProjectionService $projector = null, private ?ConfirmedIntroBookingCancellationRepository $cancellations = null) {
        $this->cancellations ??= new ConfirmedIntroBookingCancellationRepository();
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @return array{confirmation_id:int,state:string,message:string,provider_sync:string} */
    public function cancel(int $requestId, string $actorRole, string $reason, string $idempotencyKey, int $expectedId = 0, int $expectedVersion = 0): array {
        if ($requestId < 1) throw new \InvalidArgumentException('Choose an introductory booking');
        $reason = $this->reason($reason);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') throw new \InvalidArgumentException('A command key is required');
        global $wpdb;
        $repository = $this->cancellations;
        $keyDigest = hash_hmac('sha256', 'intro-cancel-command:' . $idempotencyKey, wp_salt('dzn_intro_cancellation'));
        $evidenceDigest = hash_hmac('sha256', 'intro-cancel-evidence:' . $idempotencyKey, wp_salt('dzn_intro_cancellation'));
        $payloadDigest = hash_hmac('sha256', 'intro-cancel-payload:' . $requestId . '|' . $reason . '|' . $expectedId . '|' . $expectedVersion, wp_salt('dzn_intro_cancellation'));
        $repository->begin();
        try {
            if ($winner = $repository->command($keyDigest)) {
                $result = $this->replayCancellation($winner, $payloadDigest);
                $repository->commit();
                return $result;
            }
            $request = $repository->requestForUpdate($requestId);
            if (!$request || $request->privacy_erased_at !== null) throw new \InvalidArgumentException('Introductory booking not found');
            $confirmation = $repository->confirmationForRequest($requestId, true);
            if (!$confirmation) throw new \InvalidArgumentException('intro_booking_confirmation_required');
            [$actorId, $actorType] = $this->authorise($actorRole, $request, $confirmation);
            if ($expectedId > 0 && (int) $confirmation->id !== $expectedId) throw new \RuntimeException('Booking confirmation changed concurrently');
            if ($expectedVersion > 0 && (int) $confirmation->version !== $expectedVersion) throw new \RuntimeException('Booking confirmation changed concurrently');
            if ((string) $confirmation->state === 'cancelled') {
                $existing = $repository->cancellationForConfirmation((int) $confirmation->id, true);
                $repository->commit();
                return array('confirmation_id' => (int) $confirmation->id, 'state' => 'cancelled', 'message' => 'This introductory lesson was already cancelled. No duplicate cancellation, notice or retraction job was created.', 'provider_sync' => 'not_required');
            }
            $studentId = (int) ($confirmation->student_id ?? 0);
            if ($studentId < 1) throw new \InvalidArgumentException('Resolved Student required');
            // Serialise cancellation with intake, acceptance and scheduling before releasing the slot.
            (new CanonicalLessonScheduleRepository())->ensureAndLockStudentRoot($studentId, gmdate('Y-m-d H:i:s'), $actorId);
            $now = gmdate('Y-m-d H:i:s');
            $cancellationId = $repository->insertCancellation(array(
                'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
                'confirmation_id' => (int) $confirmation->id,
                'booking_request_id' => $requestId,
                'delivery_id' => (int) $confirmation->delivery_id,
                'teacher_id' => (int) $confirmation->teacher_id,
                'student_id' => $studentId,
                'reason_code' => $reason,
                'evidence_reference_digest' => $evidenceDigest,
                'previous_confirmation_state' => (string) $confirmation->state,
                'previous_delivery_state' => (string) $confirmation->delivery_state,
                'previous_request_lifecycle' => (string) $confirmation->lifecycle_status,
                'previous_request_resolution' => (string) $confirmation->resolution_state,
                'cancelled_at' => $now,
                'cancelled_by' => $actorId,
                'command_key_digest' => $keyDigest,
                'created_at' => $now,
            ));
            $repository->transitionConfirmation((int) $confirmation->id, (int) $confirmation->version, (string) $confirmation->state, 'cancelled', $now, $actorId);
            $repository->transitionDelivery((int) $confirmation->delivery_id, (string) $confirmation->delivery_state, 'cancelled', $now);
            $repository->cancelRequest($requestId, (string) $confirmation->lifecycle_status, (string) $confirmation->resolution_state, 'cancelled', $now, $actorId);
            $repository->closeCoordinationCase($requestId, $now, $actorId);
            $this->queueNotification((int) $confirmation->delivery_id, (int) $confirmation->id, 'cancelled', $now, false);
            $this->queueNotification((int) $confirmation->delivery_id, (int) $confirmation->id, 'cancelled_teacher', $now, false);
            $secret = $repository->projectionSecret((int) $confirmation->id);
            $centralClassroom = null;
            if (class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceRepository')) {
                $centralClassroom = (new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceRepository())
                    ->activeClassroomForBooking((int) $confirmation->id);
            }
            $needsProviderRetraction = (bool) $secret || (bool) $centralClassroom;
            $jobId = $repository->insertRetractionJob(array(
                'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
                'cancellation_id' => $cancellationId,
                'confirmation_id' => (int) $confirmation->id,
                'connection_id' => $centralClassroom && ! $secret ? 0 : (int) $confirmation->connection_id,
                'teacher_id' => (int) $confirmation->teacher_id,
                'state' => 'queued',
                'idempotency_key' => hash_hmac('sha256', 'intro-cancel-retraction:' . $cancellationId, wp_salt('dzn_intro_cancellation')),
                'attempt_count' => 0,
                'next_attempt_at' => $now,
                'lease_token_digest' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $repository->insertCommand(array(
                'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
                'command_key_digest' => $keyDigest,
                'command_payload_digest' => $payloadDigest,
                'cancellation_id' => $cancellationId,
                'result_state' => 'cancelled',
                'created_at' => $now,
                'created_by' => $actorId,
            ));
            $this->audit($requestId, 'booking_request.intro_cancelled', $actorType, $actorId, $reason, array(
                'confirmation_id' => (int) $confirmation->id,
                'actor_role' => $actorRole,
                'cancellation_id' => $cancellationId,
                'retraction_job_id' => $jobId,
                'starts_at_utc' => (string) $confirmation->starts_at_utc,
                'timezone' => (string) $confirmation->schedule_timezone,
                'provider_sync' => 'queued',
            ), 'intro-cancel:' . $idempotencyKey, $now);
            $repository->commit();
            $providerSync = $needsProviderRetraction ? 'queued' : 'not_required';
            return array(
                'confirmation_id' => (int) $confirmation->id,
                'state' => 'cancelled',
                'message' => $needsProviderRetraction
                    ? 'Introductory lesson cancelled. The Student and Teacher notices are queued; Google Calendar/Meet retraction is queued for the provider worker.'
                    : 'Introductory lesson cancelled. The Student and Teacher notices are queued; no active provider projection required retraction.',
                'provider_sync' => $providerSync,
            );
        } catch (\Throwable $exception) {
            $repository->rollback();
            if ($winner = $repository->command($keyDigest)) return $this->replayCancellation($winner, $payloadDigest);
            throw $exception;
        }
    }

    private function replayCancellation(object $command, string $payloadDigest): array {
        if (!hash_equals((string) $command->command_payload_digest, $payloadDigest)) throw new IdempotencyConflictException('Idempotency conflict');
        $cancellation = $this->cancellations->cancellation((int) $command->cancellation_id);
        if (!$cancellation) throw new \RuntimeException('Contaminated cancellation command');
        return array('confirmation_id' => (int) $cancellation->confirmation_id, 'state' => 'cancelled', 'message' => 'This introductory lesson was already cancelled. No duplicate cancellation, notice or retraction job was created.', 'provider_sync' => 'not_required');
    }

    /** @return array{confirmation_id:int,state:string,local_label:string,provider_sync:string} */
    public function reschedule(int $requestId, string $actorRole, array $slot, string $reason, string $idempotencyKey, int $expectedId = 0, int $expectedVersion = 0): array {
        if ($requestId < 1) throw new \InvalidArgumentException('Choose an introductory booking');
        if (!in_array($actorRole, array('admin', 'teacher'), true)) throw new \RuntimeException('Unauthorized');
        $reason = $this->reason($reason);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') throw new \InvalidArgumentException('A command key is required');
        global $wpdb;
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_requests WHERE id=%d AND privacy_erased_at IS NULL", $requestId));
        if (!$request) throw new \InvalidArgumentException('Introductory booking not found');
        $confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
        if (!$confirmation) throw new \InvalidArgumentException('intro_booking_confirmation_required');
        if (!in_array((string) $confirmation->state, array('confirmed', 'awaiting_payment', 'pending_projection', 'retryable', 'awaiting_conference'), true)) throw new \InvalidArgumentException('intro_lesson_not_reschedulable');
        [$actorId, $actorType] = $this->authorise($actorRole, $request, $confirmation);
        if ($expectedId > 0 && (int) $confirmation->id !== $expectedId) throw new \RuntimeException('Booking confirmation changed concurrently');
        if ($expectedVersion > 0 && (int) $confirmation->version !== $expectedVersion) throw new \RuntimeException('Booking confirmation changed concurrently');
        $timezone = trim((string) ($slot['timezone'] ?? $confirmation->schedule_timezone));
        if (!in_array($timezone, timezone_identifiers_list(), true)) throw new \InvalidArgumentException('Choose a valid service-area timezone');
        $calendar = 'persian' === strtolower(trim((string) ($slot['calendar'] ?? 'gregorian'))) ? 'persian' : 'gregorian';
        $date = $this->gregorianWallDate($calendar, $slot);
        $time = trim((string) ($slot['local_start_time'] ?? ''));
        if (preg_match('/^\d{2}:\d{2}$/D', $time)) $time .= ':00';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time) !== 1) throw new \InvalidArgumentException('Choose a valid local date and start time');
        $zone = new \DateTimeZone($timezone);
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $zone);
        if (!$local || $local->format('Y-m-d H:i:s') !== $date . ' ' . $time) throw new \InvalidArgumentException('This local time does not exist in the chosen timezone');
        $requestedTime = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}booking_request_requested_times WHERE id=%d AND booking_request_id=%d",
            (int) $confirmation->requested_time_id,
            $requestId
        ));
        $duration = max(5, (int) round((strtotime((string) $confirmation->ends_at_utc . ' UTC') - strtotime((string) $confirmation->starts_at_utc . ' UTC')) / 60));
        if ($requestedTime) $duration = max(5, (int) $requestedTime->instructional_duration_minutes);
        $buffer = max(0, (int) ($requestedTime->buffer_minutes ?? 0));
        $end = $local->modify('+' . $duration . ' minutes');
        $occupiedEnd = $end->modify('+' . $buffer . ' minutes');
        $utc = new \DateTimeZone('UTC');
        $startsAtUtc = $local->setTimezone($utc)->format('Y-m-d H:i:s');
        $endsAtUtc = $end->setTimezone($utc)->format('Y-m-d H:i:s');
        $occupiedEndsAtUtc = $occupiedEnd->setTimezone($utc)->format('Y-m-d H:i:s');
        if ($startsAtUtc <= gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('Choose a future introductory time');
        $coveredByPublishedAvailability = $this->coveredByPublishedAvailability((int) $confirmation->teacher_id, $startsAtUtc, $endsAtUtc);
        if (! $coveredByPublishedAvailability && $actorType !== 'teacher') {
            throw new \InvalidArgumentException('The Teacher must explicitly choose a time outside published availability.');
        }
        $conflict = (new CanonicalTeacherOccupancyReadService())->overlapping((int) $confirmation->teacher_id, $startsAtUtc, $occupiedEndsAtUtc);
        if ($conflict) throw new \InvalidArgumentException('The Teacher already has a class at that time. Choose another slot.');
        try {
            CanonicalContinuationCapacityAuthority::assertNoActiveHold((int) $confirmation->teacher_id, $startsAtUtc, $occupiedEndsAtUtc);
            CommercialCapacityAuthority::assertNoConflictingClaim((int) $confirmation->teacher_id, $startsAtUtc, $occupiedEndsAtUtc);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'teacher_slot_conflict') throw new \InvalidArgumentException('The Teacher has a reserved continuation slot at that time. Choose another slot.');
            throw $exception;
        }
        foreach ((new LessonRescheduleRepository())->studentConflict((int) $request->student_id, $startsAtUtc, $occupiedEndsAtUtc, 0) as $studentConflict) {
            if ((string) ($studentConflict->source ?? '') === 'booking' && (int) ($studentConflict->lesson_id ?? 0) === (int) $confirmation->id) continue;
            throw new \InvalidArgumentException('The Student already has a class at that time. Choose another slot.');
        }
        $providerSync = 'not_required';
        if ((string) $confirmation->state === 'confirmed') {
            try {
                $this->projector()->reschedule((int) $confirmation->id, $startsAtUtc, $endsAtUtc, $timezone);
                $providerSync = 'updated';
            } catch (\Throwable $exception) {
                $providerSync = 'pending:' . $this->safeProviderCode($exception);
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_confirmations WHERE id=%d FOR UPDATE", (int) $confirmation->id));
            if (!$locked) throw new \RuntimeException('Booking confirmation changed concurrently');
            if (!in_array((string) $locked->state, array('confirmed', 'awaiting_payment', 'pending_projection', 'retryable', 'awaiting_conference'), true)) throw new \InvalidArgumentException('intro_lesson_not_reschedulable');
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->p}booking_request_confirmations SET starts_at_utc=%s,ends_at_utc=%s,schedule_timezone=%s,local_wall_date=%s,local_wall_time=%s,version=version+1,updated_at=%s,updated_by=%d WHERE id=%d AND version=%d",
                $startsAtUtc,
                $endsAtUtc,
                $timezone,
                $date,
                $time,
                $now,
                $actorId,
                (int) $locked->id,
                (int) $locked->version
            ));
            if ($updated !== 1) throw new \RuntimeException('Booking confirmation changed concurrently');
            if ($requestedTime) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->p}booking_request_requested_times SET local_date=%s,local_start_time=%s,timezone=%s,starts_at_utc=%s,instructional_ends_at_utc=%s,occupied_ends_at_utc=%s,version=version+1 WHERE id=%d",
                    $date,
                    $time,
                    $timezone,
                    $startsAtUtc,
                    $endsAtUtc,
                    $occupiedEndsAtUtc,
                    (int) $requestedTime->id
                ));
            }
            if (IntroRecurringSlotOverridePolicy::requiresRecord($actorType, $coveredByPublishedAvailability)) {
                $this->recordRecurringSlotOverride((int) $locked->id, (int) $locked->booking_request_id, (int) $locked->teacher_id, $actorId, $startsAtUtc, $endsAtUtc, $timezone, $date, $time, $now);
            }
            $this->queueNotification((int) $locked->delivery_id, (int) $locked->id, 'rescheduled', $now, true);
            $this->queueNotification((int) $locked->delivery_id, (int) $locked->id, 'rescheduled_teacher', $now, true);
            $this->audit($requestId, 'booking_request.intro_rescheduled', $actorType, $actorId, $reason, array(
                'confirmation_id' => (int) $locked->id,
                'actor_role' => $actorRole,
                'previous_starts_at_utc' => (string) $locked->starts_at_utc,
                'starts_at_utc' => $startsAtUtc,
                'ends_at_utc' => $endsAtUtc,
                'timezone' => $timezone,
                'provider_sync' => $providerSync,
            ), 'intro-reschedule:' . $idempotencyKey, $now);
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
        return array(
            'confirmation_id' => (int) $confirmation->id,
            'state' => (string) $confirmation->state,
            'local_label' => $local->format('D j M Y, H:i') . ' ' . $timezone,
            'provider_sync' => $providerSync,
        );
    }

    private function coveredByPublishedAvailability(int $teacherId, string $startsAtUtc, string $endsAtUtc): bool {
        try {
            $segments = (new TeacherAvailabilityService())->effective($teacherId, $startsAtUtc, $endsAtUtc, (int)($confirmation->course_id ?? 0) > 0 ? (new TeacherAvailabilityService())->instrumentIdForCourse((int)$confirmation->course_id) : null);
        } catch (\Throwable) {
            return false;
        }
        $cursor = $startsAtUtc;
        foreach ($segments as $segment) {
            if (!in_array((string) ($segment['state'] ?? ''), array('preferred', 'requestable'), true)) return false;
            if ((string) ($segment['starts_at_utc'] ?? '') > $cursor) return false;
            if ((string) ($segment['ends_at_utc'] ?? '') > $cursor) $cursor = min($endsAtUtc, (string) $segment['ends_at_utc']);
            if ($cursor === $endsAtUtc) return true;
        }
        return false;
    }

    private function recordRecurringSlotOverride(int $confirmationId, int $bookingRequestId, int $teacherId, int $actorId, string $startsAtUtc, string $endsAtUtc, string $timezone, string $date, string $time, string $now): void {
        global $wpdb;
        $digest = hash_hmac('sha256', implode('|', array('teacher-recurring-slot-override', $confirmationId, $teacherId, $startsAtUtc, $endsAtUtc, $timezone, $date, $time, $actorId)), wp_salt('dzn_intro_recurring_slot_override'));
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->p}booking_request_recurring_slot_overrides (uid,confirmation_id,booking_request_id,teacher_id,actor_type,actor_id,authority_basis,schedule_timezone,local_wall_date,local_wall_time,starts_at_utc,ends_at_utc,evidence_reference_digest,recorded_at,created_at) VALUES (%s,%d,%d,%d,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id",
            \Delnavazan\Platform\Core\Support\Identifier::uid(), $confirmationId, $bookingRequestId, $teacherId, 'teacher', $actorId,
            IntroRecurringSlotOverridePolicy::BASIS, $timezone, $date, $time, $startsAtUtc, $endsAtUtc, $digest, $now, $now
        ));
        if ($inserted === false) throw new \RuntimeException('intro_recurring_slot_override_persistence_failed');
    }

    /** @return array{0:int,1:string} */
    private function authorise(string $role, object $request, object $confirmation): array {
        if ($role === 'admin') {
            if (!current_user_can('dzn_manage_booking_request_coordination')) throw new \RuntimeException('Unauthorized');
            $actorId = (int) get_current_user_id();
            if ($actorId < 1) throw new \RuntimeException('Unauthorized');
            return array($actorId, 'administrator');
        }
        if (!in_array($role, array('teacher', 'student'), true)) throw new \RuntimeException('Unauthorized');
        $principal = (new PortalPrincipalResolver())->resolve($role);
        $id = (int) ($principal['id'] ?? 0);
        if ($role === 'teacher') {
            if ($id < 1 || $id !== (int) $confirmation->teacher_id) throw new \InvalidArgumentException('portal_object_not_owned');
            return array($id, 'teacher');
        }
        if ($id < 1 || $id !== (int) $request->student_id) throw new \InvalidArgumentException('portal_object_not_owned');
        return array($id, 'student');
    }

    private function reason(string $reason): string {
        $reason = sanitize_key($reason);
        return in_array($reason, self::REASONS, true) ? $reason : 'operational_change';
    }

    private function queueNotification(int $deliveryId, int $confirmationId, string $kind, string $now, bool $replace): void {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,state FROM {$this->p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind=%s",
            $deliveryId,
            $kind
        ));
        if ($existing) {
            if (!$replace && (string) $existing->state !== 'failed') return;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->p}booking_request_student_notifications SET confirmation_id=%d,state='queued',attempt_count=0,next_attempt_at=%s,lease_token_digest=NULL,lease_expires_at=NULL,last_error_code=NULL,handed_off_at=NULL,updated_at=%s WHERE id=%d",
                $confirmationId,
                $now,
                $now,
                (int) $existing->id
            ));
            return;
        }
        $inserted = $wpdb->insert($this->p . 'booking_request_student_notifications', array(
            'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
            'delivery_id' => $deliveryId,
            'confirmation_id' => $confirmationId,
            'notification_kind' => $kind,
            'state' => 'queued',
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'lease_token_digest' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
            'handed_off_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('intro_notification_queue_failed: ' . $wpdb->last_error);
    }

    private function audit(int $requestId, string $eventType, string $actorType, int $actorId, string $reason, array $detail, string $key, string $now): void {
        global $wpdb;
        $digest = hash_hmac('sha256', $key, function_exists('wp_salt') ? wp_salt('dzn_platform_audit') : 'dzn-platform-audit');
        $inserted = $wpdb->insert($this->p . 'platform_audit_events', array(
            'aggregate_type' => 'booking_request',
            'aggregate_id' => $requestId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason_code' => $reason,
            'safe_detail' => function_exists('wp_json_encode') ? wp_json_encode($detail) : json_encode($detail),
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('intro_audit_write_failed');
    }

    private function projector(): BookingCalendarProjectionService {
        return $this->projector ??= new BookingCalendarProjectionService();
    }

    private function safeProviderCode(\Throwable $exception): string {
        $message = trim($exception->getMessage());
        return preg_match('/^[a-z0-9_:\-]{1,80}$/D', $message) === 1 ? $message : 'provider_sync_failed';
    }

    /**
     * The Gregorian local wall date (`Y-m-d`) for the requested slot.
     *
     * A Persian selection is converted with the pure Jalali arithmetic; the
     * caller then interprets the wall date in the chosen IANA timezone and
     * converts it to UTC exactly as the Gregorian path does. Invalid Jalali
     * dates fail closed with the same user-facing message as an invalid
     * Gregorian date.
     */
    private function gregorianWallDate(string $calendar, array $slot): string {
        if ('persian' !== $calendar) return trim((string) ($slot['local_date'] ?? ''));
        $year = (int) ($slot['jalali_year'] ?? 0);
        $month = (int) ($slot['jalali_month'] ?? 0);
        $day = (int) ($slot['jalali_day'] ?? 0);
        if ($year < 1300 || $year > 1600 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Choose a valid local date and start time');
        }
        try {
            [$gy, $gm, $gd] = PersianCalendar::toGregorian($year, $month, $day);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Choose a valid local date and start time');
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}
