<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * One administrator execution path for an agreement that already happened offline.
 *
 * The operator chooses the outcome, Teacher and slot. Every technical authority record
 * (Teacher agreement, proposal, provisional/final acceptance, conversion, Term and payment)
 * is still written through its existing service; this class only sequences them and records
 * the administrator's attestation. The strict Student/Teacher portal path is untouched.
 */
final class AdministratorAgreementService {
    public const OUTCOME_INTRO = 'intro';
    public const OUTCOME_FULL_TERM = 'full_term';

    private const CAPABILITIES = array(
        'dzn_manage_booking_request_coordination',
        'dzn_manage_teacher_availability_assent',
        'dzn_manage_post_intro_automation',
        'dzn_finalize_service_arrangements',
        'dzn_convert_service_arrangements_to_enrolments',
        'dzn_issue_commercial_offers',
        'dzn_manage_commercial_capacity',
        'dzn_manage_student_acceptance_authority',
    );

    private BookingTeacherResponseRepository $repository;

    public function __construct(
        private ?BookingTeacherResponseService $introResponses = null,
        private ?IntroLessonLifecycleService $lifecycle = null,
        private ?PostIntroPreparationService $preparation = null,
        private ?PostIntroContinuationService $continuation = null,
        private ?PostIntroRepository $postIntro = null
    ) {
        $this->repository = new BookingTeacherResponseRepository();
        $this->lifecycle ??= new IntroLessonLifecycleService();
        $this->preparation ??= new PostIntroPreparationService();
        $this->continuation ??= new PostIntroContinuationService();
        $this->postIntro ??= new PostIntroRepository();
        if ($this->introResponses === null) {
            $projector = class_exists(BookingCalendarProjectionService::class) ? new BookingCalendarProjectionService() : null;
            $this->introResponses = new BookingTeacherResponseService(null, $projector);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(int $requestId, array $input, string $idempotencyKey): array {
        foreach (self::CAPABILITIES as $capability) {
            if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');
        }
        $administratorId = (int) get_current_user_id();
        if ($administratorId < 1) throw new \RuntimeException('Administrator execution requires an authenticated user');
        if ($requestId < 1) throw new \InvalidArgumentException('Choose a booking request');
        $outcome = sanitize_key((string) ($input['outcome'] ?? ''));
        if (!in_array($outcome, array(self::OUTCOME_INTRO, self::OUTCOME_FULL_TERM), true)) throw new \InvalidArgumentException('Choose Intro or Full term');
        if (empty($input['attestation'])) throw new \InvalidArgumentException('Confirm that the Teacher and Student/guardian already agreed offline');
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') throw new \InvalidArgumentException('Record the offline agreement note');
        if (strlen($note) > 1000) throw new \InvalidArgumentException('The agreement note must be 1000 characters or fewer');
        $teacherId = absint($input['teacher_id'] ?? 0);
        if ($teacherId < 1) throw new \InvalidArgumentException('Choose the Teacher');
        $date = trim((string) ($input['local_date'] ?? ''));
        $time = trim((string) ($input['local_start_time'] ?? ''));
        if (preg_match('/^\d{2}:\d{2}$/D', $time)) $time .= ':00';
        $timezone = trim((string) ($input['timezone'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time)) throw new \InvalidArgumentException('Choose a valid local date and time');
        if (!in_array($timezone, timezone_identifiers_list(), true)) throw new \InvalidArgumentException('Choose a valid local timezone');
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 120) throw new \InvalidArgumentException('Agreement command key required');

        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d", $requestId));
        if (!$request) throw new \InvalidArgumentException('Booking request not found');
        if ($request->privacy_erased_at !== null) throw new \InvalidArgumentException('Booking request is no longer available');
        $studentId = (int) ($request->student_id ?? 0);
        if ($studentId < 1) throw new \InvalidArgumentException('Resolve the Student identity first');
        $lifecycle = (string) $request->lifecycle_status;
        $resolution = (string) $request->resolution_state;
        $active = $lifecycle === 'submitted' && $resolution === 'unresolved';
        $confirmedRequest = $lifecycle === 'closed' && $resolution === 'teacher_confirmed';
        if (!$active && !$confirmedRequest) throw new \InvalidArgumentException('Booking request is no longer active');

        $teacher = $wpdb->get_row($wpdb->prepare("SELECT id,status,archived_at,timezone FROM {$p}teachers WHERE id=%d", $teacherId));
        if (!$teacher || (string) $teacher->status !== 'active' || $teacher->archived_at !== null) throw new \InvalidArgumentException('Choose an active Teacher');
        try { new \DateTimeZone((string) $teacher->timezone); } catch (\Throwable) { throw new \InvalidArgumentException('The Teacher needs a valid timezone'); }

        $courseId = (int) ($request->selected_intro_course_id ?? 0);
        if ($courseId < 1) throw new \InvalidArgumentException('The booking request has no introductory Course');
        $course = $wpdb->get_row($wpdb->prepare("SELECT id,course_type,status,archived_at,default_duration_minutes,default_buffer_minutes FROM {$p}courses WHERE id=%d", $courseId));
        if (!$course || (string) $course->course_type !== 'introductory' || (string) $course->status !== 'active' || $course->archived_at !== null) throw new \InvalidArgumentException('An active introductory Course is required');
        $duration = max(5, (int) $course->default_duration_minutes);
        $buffer = max(0, (int) $course->default_buffer_minutes);
        $zone = new \DateTimeZone($timezone);
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $zone);
        if (!$local || $local->format('Y-m-d H:i:s') !== $date . ' ' . $time) throw new \InvalidArgumentException('This local time does not exist in the chosen timezone');
        $utc = new \DateTimeZone('UTC');
        $start = $local->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = $local->modify('+' . $duration . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');
        $occupiedEnd = $local->modify('+' . $duration . ' minutes')->modify('+' . $buffer . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');
        if ($start <= gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('Choose a future time');

        // Real double-booking is still refused. The administrator removes approval steps,
        // not the Teacher's or Student's existing classes or live holds.
        if ((new BookingTeacherOccupancyReadService())->overlapping($teacherId, $start, $occupiedEnd, 0, $requestId, true)) throw new \InvalidArgumentException('The Teacher already has a class at that time');
        if ($this->repository->studentOccupancyConflicts($studentId, array(array('starts_at_utc' => $start, 'occupied_ends_at_utc' => $occupiedEnd)), array('exclude_booking_request_id' => $requestId, 'include_open_requests' => true))) throw new \InvalidArgumentException('The Student already has a class at that time');

        $originalTime = $wpdb->get_row($wpdb->prepare(
            "SELECT local_date,local_start_time,timezone,starts_at_utc FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number LIMIT 1",
            $requestId
        ));
        $slot = array('local_date' => $date, 'local_start_time' => $time, 'timezone' => $timezone);
        $arrangementId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}accepted_service_arrangements WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
        if ($arrangementId > 0) $slot['arrangement_ack'] = '1';

        $confirmation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        $result = array();
        if ($confirmation === null) {
            if (!current_user_can('dzn_override_canonical_lesson_schedule_availability')) throw new \RuntimeException('Unauthorized');
            if (!$active) throw new \InvalidArgumentException('A confirmed introductory booking is required');
            $result = $this->introResponses->administratorTotalOverrideConfirmDelivery($requestId, $teacherId, $slot, $note, 'admin-agreement-intro-' . $key);
        } elseif ((int) $confirmation->teacher_id !== $teacherId) {
            throw new \InvalidArgumentException('A different Teacher is already confirmed. Cancel the intro before choosing another Teacher.');
        } else {
            $same = (string) $confirmation->starts_at_utc === $start;
            if (!$same) {
                $result = $this->lifecycle->reschedule(
                    $requestId,
                    'admin',
                    $slot,
                    'operational_change',
                    'admin-agreement-reschedule-' . $key,
                    (int) $confirmation->id,
                    (int) $confirmation->version,
                    true
                );
                $confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
            } else {
                $result = array('confirmation_id' => (int) $confirmation->id, 'state' => (string) $confirmation->state, 'idempotent' => true);
            }
        }

        $confirmation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        if ($confirmation) {
            // The total-override creation path bypasses the Teacher's availability preference;
            // mirror that authority into the recurring-slot override so the post-intro
            // preparation can trust the same agreed slot.
            $this->lifecycle->recordAdministratorAgreementOverride(
                (int) $confirmation->id,
                $requestId,
                $teacherId,
                $administratorId,
                (string) ($confirmation->starts_at_utc ?? $start),
                (string) ($confirmation->ends_at_utc ?? $end),
                (string) ($confirmation->schedule_timezone ?? $timezone),
                (string) ($confirmation->local_wall_date ?? $date),
                (string) ($confirmation->local_wall_time ?? $time),
                (string) $courseId
            );
        }
        $state = (string) ($result['state'] ?? '');
        if ($outcome === self::OUTCOME_INTRO) {
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'intro', '', (string) (($result['intro_obligation_uid'] ?? '') ?: ''), $state, $key);
            $providerSync = (string) ($result['provider_sync'] ?? '');
            $message = $state === 'awaiting_payment'
                ? 'Intro agreed. The introductory payment is pending; the classroom follows verified payment.'
                : 'Intro agreed and the normal classroom/notification path has started.';
            if (str_starts_with($providerSync, 'pending:')) $message .= ' The provider calendar sync is still pending and will retry in the background.';
            return array(
                'state' => $state,
                'outcome' => $outcome,
                'confirmation_id' => (int) ($result['confirmation_id'] ?? ($confirmation->id ?? 0)),
                'payment_required' => !empty($result['payment_required']),
                'intro_obligation_uid' => (string) ($result['intro_obligation_uid'] ?? ''),
                'message' => $message,
            );
        }

        if ($state === 'awaiting_payment') {
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'pending', 'intro_payment_pending', (string) ($result['intro_obligation_uid'] ?? ''), $state, $key);
            throw new \InvalidArgumentException('intro_payment_pending');
        }
        if (in_array($state, array('pending_projection', 'retryable', 'awaiting_conference', 'failed'), true)) {
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'pending', 'intro_projection_pending', '', $state, $key);
            throw new \InvalidArgumentException('intro_projection_pending');
        }
        if ($state !== 'confirmed') {
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'pending', 'intro_booking_not_confirmed', '', $state, $key);
            throw new \InvalidArgumentException('intro_booking_not_confirmed');
        }

        $preparation = $this->postIntro->preparationByBooking($requestId);
        if ($preparation === null) {
            $completion = $this->preparation->recordAdministrativeCompletion($requestId, $administratorId, $note);
            $preparationState = (string) ($completion['state'] ?? '');
            $preparation = $this->postIntro->preparationByBooking($requestId);
        } else {
            $preparationState = (string) $preparation->state;
        }
        if (!$preparation || in_array($preparationState, array('exception', 'expired'), true)) {
            $code = $preparation ? (string) ($preparation->exception_code ?: 'post_intro_preparation_failed') : 'post_intro_preparation_unavailable';
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'pending', $code, '', $preparationState, $key);
            throw new \InvalidArgumentException($code);
        }
        if (!in_array($preparationState, array('prepared', 'checkout_pending', 'payment_received', 'activated'), true)) {
            $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, 'pending', 'post_intro_invitation_not_ready', '', $preparationState, $key);
            throw new \InvalidArgumentException('post_intro_invitation_not_ready');
        }

        $planKind = sanitize_key((string) ($input['plan_kind'] ?? 'full'));
        if (!in_array($planKind, array('full', 'two_instalments'), true)) throw new \InvalidArgumentException('Choose full payment or two instalments');
        $paymentMode = sanitize_key((string) ($input['payment_mode'] ?? 'pending'));
        if (!in_array($paymentMode, array('pending', 'offline_paid'), true)) throw new \InvalidArgumentException('Choose the payment state');
        $continuation = $this->continuation->continueAsAdministrator((string) $preparation->uid, $administratorId, array(
            'plan_kind' => $planKind,
            'payment_mode' => $paymentMode,
            'note' => $note,
            'receipt_reference' => sanitize_text_field((string) ($input['receipt_reference'] ?? '')),
            'received_on' => sanitize_text_field((string) ($input['received_on'] ?? '')),
        ));

        $this->audit($requestId, $administratorId, $outcome, $teacherId, $originalTime, $slot, $note, $paymentMode, '', '', (string) ($continuation['state'] ?? ''), $key);
        return array(
            'state' => (string) ($continuation['state'] ?? ''),
            'outcome' => $outcome,
            'confirmation_id' => (int) ($confirmation->id ?? 0),
            'preparation_state' => $preparationState,
            'enrolment_id' => (int) ($continuation['enrolment_id'] ?? 0),
            'offer_uid' => (string) ($continuation['offer_uid'] ?? ''),
            'message' => (string) ($continuation['message'] ?? ''),
        );
    }

    private function audit(int $requestId, int $administratorId, string $outcome, int $teacherId, ?object $originalTime, array $slot, string $note, string $paymentMode, string $failureCode, string $introObligationUid, string $resultState, string $key): void {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $now = gmdate('Y-m-d H:i:s');
        $detail = array(
            'outcome' => $outcome,
            'teacher_id' => $teacherId,
            'administrator_id' => $administratorId,
            'original_requested_time' => $originalTime ? array(
                'local_date' => (string) $originalTime->local_date,
                'local_start_time' => (string) $originalTime->local_start_time,
                'timezone' => (string) $originalTime->timezone,
                'starts_at_utc' => (string) $originalTime->starts_at_utc,
            ) : null,
            'administrator_slot' => $slot,
            'note_digest' => hash('sha256', $note),
            'payment_mode' => $paymentMode,
            'failure_code' => $failureCode,
            'intro_obligation_uid' => $introObligationUid,
            'result_state' => $resultState,
        );
        $auditKey = hash('sha256', 'administrator-agreement:' . $key . ':' . $outcome . ':' . $resultState);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$p}platform_audit_events (aggregate_type,aggregate_id,event_type,actor_type,actor_id,reason_code,safe_detail,idempotency_key,occurred_at) VALUES ('booking_request',%d,'booking_request.administrator_agreement_executed','administrator',%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id",
            $requestId,
            $administratorId,
            $outcome,
            (string) wp_json_encode($detail),
            $auditKey,
            $now
        ));
        if ($inserted === false) {
            error_log('[Delnavazan administrator agreement] audit persistence failed for request ' . $requestId);
        }
    }
}
