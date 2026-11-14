<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Administrator-assisted Teacher match for a new booking request.
 *
 * Ordinary availability preference may be overridden only with an explicit Teacher agreement,
 * a staff reason and attributable evidence. Hard constraints are never overridden: invalid local
 * time, ineligible/inactive Teacher, a real canonical occupancy collision, an active continuation
 * hold or an unresolved Identity blocker refuse the command.
 */
final class AdministratorBookingMatchService {
    private const CAPABILITY = 'dzn_manage_booking_request_coordination';
    private const ASSENT_CAPABILITY = 'dzn_manage_teacher_availability_assent';
    private const OVERRIDE_REASONS = array('teacher_agreed_outside_preference', 'mutual_reschedule', 'operational_exception');
    private const CHANNELS = array('phone', 'whatsapp', 'email', 'video_call', 'in_person', 'other_verified');

    public function __construct(
        private ?BookingTeacherDeliveryRepository $deliveries = null,
        private ?TeacherAvailabilityService $availability = null,
        private ?BookingTeacherOccupancyReadService $occupancy = null,
        private ?object $projector = null
    ) {
        $this->deliveries ??= new BookingTeacherDeliveryRepository();
        $this->availability ??= new TeacherAvailabilityService();
        $this->occupancy ??= new BookingTeacherOccupancyReadService();
    }

    /** @return array<string,mixed> */
    public function confirm(int $requestId, int $requestedTimeId, int $teacherId, array $input, string $idempotencyKey): array {
        if (!current_user_can(self::CAPABILITY) || !current_user_can(self::ASSENT_CAPABILITY)) throw new \RuntimeException('Unauthorized');
        if ($requestId < 1 || $requestedTimeId < 1 || $teacherId < 1) throw new \InvalidArgumentException('Request, requested time and Teacher are required');
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 191) throw new \InvalidArgumentException('Idempotency key required');
        global $wpdb;
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d", $requestId));
        if (!$request) throw new \InvalidArgumentException('Booking request is no longer pending');
        $idempotentDelivery = $wpdb->get_row($wpdb->prepare(
            "SELECT id,state FROM {$wpdb->prefix}dzn_booking_request_teacher_deliveries WHERE booking_request_id=%d LIMIT 1",
            $requestId
        ));
        if ($idempotentDelivery && (string) $idempotentDelivery->state !== 'pending_request') {
            $idempotentConfirmation = $wpdb->get_row($wpdb->prepare(
                "SELECT id,state,teacher_id FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE delivery_id=%d ORDER BY id DESC LIMIT 1",
                (int) $idempotentDelivery->id
            ));
            if ($idempotentConfirmation && (int) $idempotentConfirmation->teacher_id === $teacherId) {
                return array(
                    'delivery_id' => (int) $idempotentDelivery->id,
                    'confirmation_id' => (int) $idempotentConfirmation->id,
                    'state' => (string) $idempotentConfirmation->state,
                    'availability_overridden' => false,
                    'override_reason_code' => '',
                    'teacher_agreement_channel' => sanitize_key((string) ($input['agreement_channel'] ?? '')),
                    'idempotent' => true,
                );
            }
        }
        if ((string) $request->lifecycle_status !== 'submitted' || (string) $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null) throw new \InvalidArgumentException('Booking request is no longer pending');
        if ((int) ($request->student_id ?? 0) < 1) throw new \InvalidArgumentException('Resolve the student identity before confirming a Teacher match');
        $time = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dzn_booking_request_requested_times WHERE id=%d AND booking_request_id=%d",
            $requestedTimeId, $requestId
        ));
        if (!$time) throw new \InvalidArgumentException('Choose one of the requested times');
        $start = (string) $time->starts_at_utc;
        if ($start <= gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('The chosen time must be in the future');
        $courseId = (int) ($request->selected_intro_course_id ?? 0);
        if ($courseId < 1) {
            $default = $wpdb->get_row($wpdb->prepare(
                "SELECT course_id FROM {$wpdb->prefix}dzn_instrument_intro_course_defaults WHERE instrument_id=%d AND status='active'",
                (int) $request->requested_instrument_id
            ));
            $courseId = (int) ($default->course_id ?? 0);
        }
        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT id,instrument_id,course_type,status,archived_at FROM {$wpdb->prefix}dzn_courses WHERE id=%d",
            $courseId
        ));
        if (!$course || (string) $course->status !== 'active' || $course->archived_at !== null || (string) $course->course_type !== 'introductory' || (int) $course->instrument_id !== (int) $request->requested_instrument_id) {
            throw new \InvalidArgumentException('A valid active introductory Course is required for this request');
        }
        $teacher = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id,t.status,t.archived_at,t.timezone,o.state onboarding_state,o.readiness_state,
                    p.timezone availability_timezone
             FROM {$wpdb->prefix}dzn_teachers t
             INNER JOIN {$wpdb->prefix}dzn_teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$wpdb->prefix}dzn_teacher_availability_profiles p ON p.teacher_id=t.id AND p.status='active'
             WHERE t.id=%d",
            $teacherId
        ));
        if (!$teacher || (string) $teacher->status !== 'active' || $teacher->archived_at !== null) throw new \InvalidArgumentException('Choose an active Teacher');
        if ((string) $teacher->onboarding_state !== 'active' || (string) $teacher->readiness_state !== 'ready') throw new \InvalidArgumentException('The Teacher is not ready to accept bookings');
        if ($courseId < 1 || !(new TeachingEligibilityService())->effectivelyEligible($teacherId, $courseId)) throw new \InvalidArgumentException('The Teacher is not eligible for the introductory Course');
        $end = (string) $time->instructional_ends_at_utc;
        $occupiedEnd = (string) $time->occupied_ends_at_utc;
        if ($end <= $start || $occupiedEnd < $end) throw new \InvalidArgumentException('The requested time is invalid');
        $conflict = $this->occupancy->overlapping($teacherId, $start, $occupiedEnd, 0, $requestId);
        if ($conflict) throw new \InvalidArgumentException('The Teacher already has a class at that time. Choose another slot.');
        try {
            CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId, $start, $occupiedEnd);
            CommercialCapacityAuthority::assertNoConflictingClaim($teacherId, $start, $occupiedEnd);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'teacher_slot_conflict') throw new \InvalidArgumentException('The Teacher already has a reserved class or continuation slot at that time. Choose another slot.');
            throw $exception;
        }
        $covered = $this->availabilityCovered($teacherId, $start, $end, $courseId);
        $overrideReason = sanitize_key((string) ($input['override_reason_code'] ?? ''));
        $staffReason = trim((string) ($input['staff_reason'] ?? ''));
        $channel = sanitize_key((string) ($input['agreement_channel'] ?? ''));
        $reference = trim((string) ($input['agreement_reference'] ?? ''));
        $agreementAt = trim((string) ($input['agreement_at'] ?? ''));
        if (!in_array($channel, self::CHANNELS, true)) throw new \InvalidArgumentException('Record how the Teacher agreed');
        if ($reference === '') throw new \InvalidArgumentException('Record an attributable Teacher agreement reference');
        if (!$this->utc($agreementAt) || $agreementAt > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('Teacher agreement time must be a valid past or present UTC instant');
        if ($staffReason === '') throw new \InvalidArgumentException('Record the staff reason for this manual match');
        if (!$covered && !in_array($overrideReason, self::OVERRIDE_REASONS, true)) throw new \InvalidArgumentException('Select the availability-preference override reason');
        if ($covered) $overrideReason = '';
        $now = gmdate('Y-m-d H:i:s');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_booking_request_teacher_deliveries WHERE booking_request_id=%d LIMIT 1",
            $requestId
        ));
        if ($existing) {
            $state = $wpdb->get_var($wpdb->prepare("SELECT state FROM {$wpdb->prefix}dzn_booking_request_teacher_deliveries WHERE id=%d", (int) $existing));
            if ((string) $state !== 'pending_request') {
                $confirmation = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,state,teacher_id,requested_time_id FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE delivery_id=%d ORDER BY id DESC LIMIT 1",
                    (int) $existing
                ));
                if ($confirmation && (int) $confirmation->teacher_id === $teacherId) {
                    return array(
                        'delivery_id' => (int) $existing,
                        'confirmation_id' => (int) $confirmation->id,
                        'state' => (string) $confirmation->state,
                        'availability_overridden' => false,
                        'override_reason_code' => '',
                        'teacher_agreement_channel' => $channel,
                        'idempotent' => true,
                    );
                }
                throw new \InvalidArgumentException('This request already has a Teacher confirmation and cannot be manually reassigned');
            }
            $this->deliveries->reassignPending($requestId, $requestedTimeId, $teacherId, $now);
            $deliveryId = (int) $existing;
        } else {
            $deliveryId = $this->deliveries->create(array(
                'uid' => Identifier::uid(),
                'booking_request_id' => $requestId,
                'requested_time_id' => $requestedTimeId,
                'teacher_id' => $teacherId,
                'state' => 'pending_request',
                'match_strength' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }
        // Audit the manual decision before the booking confirmation. The booking authority
        // remains the source of truth; this row is append-only evidence of why preference was
        // overridden and which staff member attested the Teacher conversation.
        $auditKey = hash_hmac('sha256', 'manual-booking-match:' . $key, wp_salt('dzn_platform_audit'));
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_platform_audit_events WHERE idempotency_key=%s", $auditKey)) === null) {
            $inserted = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
                'aggregate_type' => 'booking_request',
                'aggregate_id' => $requestId,
                'event_type' => 'booking_request.manual_teacher_match',
                'actor_type' => 'administrator',
                'actor_id' => (int) get_current_user_id(),
                'reason_code' => $overrideReason !== '' ? $overrideReason : 'within_preference',
                'safe_detail' => wp_json_encode(array(
                    'teacher_id' => $teacherId,
                    'requested_time_id' => $requestedTimeId,
                    'timezone' => (string) $time->timezone,
                    'starts_at_utc' => $start,
                    'availability_override' => !$covered,
                    'agreement_channel' => $channel,
                    'agreement_reference_digest' => hash('sha256', $reference),
                    'agreement_at' => $agreementAt,
                    'staff_reason_digest' => hash('sha256', $staffReason),
                )),
                'idempotency_key' => $auditKey,
                'occurred_at' => $now,
            ));
            if ($inserted === false) throw new \RuntimeException('Manual match audit evidence could not be recorded');
        }
        // The administrator path must use the same provider projection as a Teacher-owned
        // acceptance. Without it the confirmation never becomes portal-visible and no
        // confirmation notification is queued.
        $projector = $this->projector;
        if ($projector === null && class_exists(\Delnavazan\Platform\Integrations\BookingCalendarProjectionService::class)) {
            $projector = new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService();
        }
        $result = (new BookingTeacherResponseService(null, $projector))->administratorConfirmDelivery($deliveryId, 'admin-manual-match-' . $key);
        return array(
            'delivery_id' => $deliveryId,
            'confirmation_id' => (int) ($result['confirmation_id'] ?? 0),
            'state' => (string) ($result['state'] ?? 'accepting'),
            'availability_overridden' => !$covered,
            'override_reason_code' => $overrideReason,
            'teacher_agreement_channel' => $channel,
        );
    }

    private function availabilityCovered(int $teacherId, string $start, string $end, ?int $courseId = null): bool {
        $cursor = $start;
        foreach ($this->availability->effective($teacherId, $start, $end, $courseId ? $this->availability->instrumentIdForCourse($courseId) : null) as $segment) {
            if ((string) $segment['starts_at_utc'] > $cursor) return false;
            if (!in_array((string) $segment['state'], array('preferred', 'requestable'), true)) return false;
            if ((string) $segment['ends_at_utc'] > $cursor) $cursor = min($end, (string) $segment['ends_at_utc']);
            if ($cursor === $end) return true;
        }
        return false;
    }

    private function utc(string $value): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1 && strtotime($value . ' UTC') !== false;
    }
}
