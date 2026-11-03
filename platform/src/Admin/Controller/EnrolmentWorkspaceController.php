<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\EnrolmentWorkspaceView;
use Delnavazan\Platform\Core\Application\AdministratorBookingMatchService;
use Delnavazan\Platform\Core\Application\AdministratorIntroBookingService;
use Delnavazan\Platform\Core\Application\BookingNotificationRecoveryService;
use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;
use Delnavazan\Platform\Core\Application\CanonicalEnrolmentLifecycleService;
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleReadService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService;
use Delnavazan\Platform\Core\Application\HistoricalEnrolmentImportService;
use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;
use Delnavazan\Platform\Core\Application\LessonScheduleService;
use Delnavazan\Platform\Core\Application\PostIntroNotificationAdministrationService;
use Delnavazan\Platform\Core\Application\PostIntroPreparationService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\LiveKit\LiveKitAdministratorClassroomService;

/**
 * Consolidated Enrolment administration workspace.
 *
 * Two operational sub-tabs share one read projection and one class-action renderer. The old
 * protected routes remain registered; this controller only adds a contextual operator surface.
 */
final class EnrolmentWorkspaceController {
    public const PAGE = 'dzn-enrolment-workspace';
    public const ACTION = 'dzn_enrolment_workspace';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function screen(): void {
        if (!current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        $view = self::view();
        $requestId = absint($_GET['request_id'] ?? 0);
        $classId = absint($_GET['class_id'] ?? 0);
        $read = new EnrolmentWorkspaceReadService();
        if ($requestId > 0) {
            if (!current_user_can(Menu::ENROLMENT_TRIALS_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
            $case = $read->bookingCase($requestId);
            if (!$case) { self::notFound('Booking request not found'); return; }
            EnrolmentWorkspaceView::bookingCase($case, self::classroomForBooking($case));
            return;
        }
        if ($classId > 0) {
            if (!current_user_can(Menu::ENROLLED_CLASSES_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
            $class = $read->classDetail($classId);
            if (!$class) { self::notFound('Class not found'); return; }
            EnrolmentWorkspaceView::classDetail($class, self::classroomForClass($class));
            return;
        }
        if ($view === 'enrolled') {
            if (!current_user_can(Menu::ENROLLED_CLASSES_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
            EnrolmentWorkspaceView::enrolledClasses($read, $_GET);
        } elseif ($view === 'review') {
            if (!current_user_can(Menu::ENROLMENT_TRIALS_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
            EnrolmentWorkspaceView::review($read, $_GET);
        } else {
            if (!current_user_can(Menu::ENROLMENT_TRIALS_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
            EnrolmentWorkspaceView::newBookings($read, $_GET);
        }
    }

    public static function handle(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (!current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        check_admin_referer(self::ACTION);
        $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
        $return = self::returnUrl();
        try {
            $message = self::dispatch($operation, wp_unslash($_POST));
            self::redirect($return, $message, false);
        } catch (\Throwable $exception) {
            error_log('[Delnavazan enrolment workspace] ' . $operation . ' ' . get_class($exception) . ' ' . $exception->getMessage());
            $message = self::safeMessage($exception);
            if ($operation === 'create_intro') {
                set_transient('dzn_intro_form_' . get_current_user_id(), array(
                    'message' => $message,
                    'values' => self::introFormValues(wp_unslash($_POST)),
                ), 120);
                self::redirect($return, $message, true, array('dzn_form' => 'intro'), 'dzn-create-intro');
            }
            self::redirect($return, $message, true);
        }
    }

    /** @return string */
    private static function dispatch(string $operation, array $post): string {
        $idempotency = sanitize_text_field((string) ($post['idempotency_key'] ?? ''));
        if ($idempotency === '') $idempotency = wp_generate_uuid4();
        $requestId = absint($post['request_id'] ?? 0);
        $classId = absint($post['class_id'] ?? 0);
        switch ($operation) {
            case 'identity_resolve':
                self::requireCap('dzn_resolve_booking_request_student_identity');
                self::requireCap('dzn_view_student_acceptance_eligibility');
                $studentId = absint($post['student_id'] ?? 0);
                if ($studentId < 1) {
                    self::requireCap('dzn_manage_students');
                    $displayName = sanitize_text_field((string) ($post['display_name'] ?? ''));
                    if ($displayName === '') throw new \InvalidArgumentException('Choose an existing Student or provide a name for a reviewed new Student');
                    $studentId = (new StudentService())->create(array(
                        'display_name' => $displayName,
                        'email' => sanitize_email((string) ($post['email'] ?? '')),
                        'phone' => sanitize_text_field((string) ($post['phone'] ?? '')),
                        'country_code' => strtoupper(substr(sanitize_text_field((string) ($post['country_code'] ?? '')), 0, 2)),
                        'city' => sanitize_text_field((string) ($post['city'] ?? '')),
                        'timezone' => sanitize_text_field((string) ($post['timezone'] ?? '')),
                        'locale' => 'en_AU',
                        'calendar_preference' => 'auto',
                        'status' => 'active',
                    ));
                }
                (new StudentIdentityResolutionService())->resolveExisting(
                    $requestId,
                    $studentId,
                    'human_review',
                    'reviewer_attestation',
                    gmdate('Y-m-d H:i:s'),
                    (int) get_current_user_id()
                );
                return 'Identity resolved and recorded.';
            case 'identity_blocker':
                self::requireCap('dzn_resolve_booking_request_student_identity');
                $outcome = sanitize_key((string) ($post['outcome'] ?? ''));
                (new StudentIdentityResolutionService())->recordOutcome(
                    $requestId,
                    $outcome,
                    'human_review',
                    'reviewer_attestation',
                    gmdate('Y-m-d H:i:s'),
                    (int) get_current_user_id()
                );
                return 'Identity outcome recorded.';
            case 'open_coordination':
                self::requireCap('dzn_manage_booking_request_coordination');
                $result = (new CoordinationCaseService())->open($requestId, sanitize_key((string) ($post['reason'] ?? 'admin_opened')));
                return 'Coordination case opened.';
            case 'add_candidate':
                self::requireCap('dzn_manage_booking_request_coordination');
                (new CoordinationCaseService())->addCandidate(absint($post['case_id'] ?? 0), absint($post['teacher_id'] ?? 0), sanitize_key((string) ($post['source'] ?? 'manual_search')), sanitize_key((string) ($post['reason'] ?? 'manual_review')));
                return 'Teacher candidate added.';
            case 'record_assent':
                self::requireCap('dzn_manage_teacher_availability_assent');
                (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(self::assentInput($post));
                return 'Teacher agreement evidence recorded.';
            case 'issue_proposal':
                self::requireCap('dzn_issue_booking_request_proposals');
                (new ProposalService())->issueInitial(absint($post['candidate_id'] ?? 0), sanitize_text_field((string) ($post['arrangement_fingerprint'] ?? '')), $idempotency);
                return 'Proposal issued from the recorded Teacher agreement.';
            case 'manual_match':
                self::requireCap('dzn_manage_booking_request_coordination');
                self::requireCap('dzn_manage_teacher_availability_assent');
                $result = (new AdministratorBookingMatchService())->confirm(
                    $requestId,
                    absint($post['requested_time_id'] ?? 0),
                    absint($post['teacher_id'] ?? 0),
                    $post,
                    $idempotency
                );
                return $result['availability_overridden']
                    ? 'Manual match confirmed with an audited availability override; the booking now waits for the same provider projection and notification state as a normal match.'
                    : 'Teacher match confirmed; the booking now waits for the same provider projection and notification state as a normal match.';
            case 'create_intro':
                self::requireCap('dzn_manage_booking_request_coordination');
                self::requireCap('dzn_manage_teacher_availability_assent');
                $result = (new AdministratorIntroBookingService())->create(
                    absint($post['student_id'] ?? 0),
                    absint($post['course_id'] ?? 0),
                    absint($post['teacher_id'] ?? 0),
                    array(
                        'local_date' => sanitize_text_field((string) ($post['local_date'] ?? '')),
                        'local_start_time' => sanitize_text_field((string) ($post['local_start_time'] ?? '')),
                        'timezone' => sanitize_text_field((string) ($post['schedule_timezone'] ?? '')),
                    ),
                    $post,
                    $idempotency
                );
                return 'Introductory booking created once: ' . (string) ($result['request_reference'] ?: ('request #' . (int) $result['request_id'])) . '.';
            case 'intro_reschedule':
                self::requireCap('dzn_manage_booking_request_coordination');
                $result = (new IntroLessonLifecycleService())->reschedule(
                    $requestId,
                    'admin',
                    array(
                        'local_date' => sanitize_text_field((string) ($post['local_date'] ?? '')),
                        'local_start_time' => sanitize_text_field((string) ($post['local_start_time'] ?? '')),
                        'timezone' => sanitize_text_field((string) ($post['schedule_timezone'] ?? '')),
                    ),
                    sanitize_key((string) ($post['reason_code'] ?? 'operational_change')),
                    $idempotency,
                    absint($post['expected_confirmation_id'] ?? 0),
                    absint($post['expected_confirmation_version'] ?? 0)
                );
                $providerNote = (string) ($result['provider_sync'] ?? '') === 'updated'
                    ? ' The provider calendar was updated.'
                    : ((string) ($result['provider_sync'] ?? '') === 'not_required' ? '' : ' The portal and notifications are updated; the provider calendar sync is pending and requires support follow-up.');
                return 'Introductory lesson rescheduled to ' . (string) $result['local_label'] . '. The Student and Teacher were notified.' . $providerNote;
            case 'intro_cancel':
                self::requireCap('dzn_manage_booking_request_coordination');
                $result = (new IntroLessonLifecycleService())->cancel(
                    $requestId,
                    'admin',
                    sanitize_key((string) ($post['reason_code'] ?? 'operational_change')),
                    $idempotency,
                    absint($post['expected_confirmation_id'] ?? 0),
                    absint($post['expected_confirmation_version'] ?? 0)
                );
                return (string) $result['message'];
            case 'record_outcome':
                self::requireCap('dzn_manage_post_intro_automation');
                $confirmation = (new PostIntroRepository())->confirmationByBooking($requestId);
                if (!$confirmation) throw new \InvalidArgumentException('Confirmed introductory booking required');
                (new PostIntroPreparationService())->recordAdministrativeOutcome(
                    (string) $confirmation->uid,
                    (int) get_current_user_id(),
                    sanitize_key((string) ($post['outcome'] ?? '')),
                    sanitize_textarea_field((string) ($post['note'] ?? ''))
                );
                return 'Introductory outcome recorded.';
            case 'recover_booking_notification':
                self::requireCap('dzn_operate_notification_dispatch');
                $result = (new BookingNotificationRecoveryService())->recover(
                    absint($post['notification_id'] ?? 0),
                    'enrolment-notification-recovery:' . $idempotency
                );
                if ((string) $result['state'] !== 'queued') {
                    $reasons = array(
                        'invitation_stale' => 'The invitation is cancelled, resolved or no longer relevant; recovery was refused.',
                        'recipient_contact_unusable' => 'The current canonical recipient address is missing or invalid. Update the contact record before retrying.',
                        'notification_not_retryable' => 'Only a failed notification can be recovered.',
                    );
                    return (string) ($reasons[(string) ($result['reason'] ?? '')] ?? 'Notification recovery was refused.');
                }
                return $result['replayed'] ? 'This recovery was already recorded; no duplicate notification was queued.' : 'The failed notification was requeued once. Booking and Teacher delivery state were not changed.';
            case 'resend_payment_link':
                self::requireCap('dzn_manage_post_intro_automation');
                $result = (new PostIntroNotificationAdministrationService())->resendPaymentLink($requestId, $idempotency);
                return (string) $result['message'];
            case 'send_reminder':
                self::requireCap('dzn_manage_post_intro_automation');
                $result = (new PostIntroNotificationAdministrationService())->sendReminder($requestId, $idempotency);
                return (string) $result['message'];
            case 'class_reschedule':
                self::requireCap('dzn_manage_canonical_lesson_schedules');
                $wallTime = sanitize_text_field((string) ($post['local_wall_time'] ?? ''));
                if (preg_match('/^\d{2}:\d{2}$/D', $wallTime)) $wallTime .= ':00';
                $input = array(
                    'schedule_timezone' => sanitize_text_field((string) ($post['schedule_timezone'] ?? '')),
                    'local_wall_date' => sanitize_text_field((string) ($post['local_wall_date'] ?? '')),
                    'local_wall_time' => $wallTime,
                    'reason_code' => sanitize_key((string) ($post['reason_code'] ?? 'operator_reschedule')),
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                );
                if (sanitize_key((string) ($post['record_model'] ?? '')) === 'canonical_term_lesson_v1') {
                    $expectedVersion = absint($post['expected_schedule_version_id'] ?? 0);
                    $expectedAssignment = absint($post['expected_assignment_id'] ?? 0);
                    $service = new CanonicalLessonScheduleService();
                    if ($expectedVersion > 0) {
                        $input['expected_schedule_version_id'] = $expectedVersion;
                        $service->revise($classId, $expectedAssignment, $input, $idempotency);
                    } else {
                        $service->schedule($classId, $expectedAssignment, $input, $idempotency);
                    }
                } else {
                    (new LessonScheduleService())->reschedule($classId, array(
                        'schedule_timezone' => $input['schedule_timezone'],
                        'local_wall_date' => $input['local_wall_date'],
                        'local_wall_time' => $input['local_wall_time'],
                        'reason' => $input['reason_code'],
                    ));
                }
                return 'Class time change recorded through the existing scheduling authority.';
            case 'class_change_teacher':
                self::requireCap('dzn_manage_teacher_assignments');
                (new TeacherAssignmentService())->replace(
                    absint($post['enrolment_id'] ?? 0),
                    absint($post['new_teacher_id'] ?? 0),
                    array(
                        'expected_assignment_id' => absint($post['expected_assignment_id'] ?? 0),
                        'route' => 'staff_attestation',
                        'evidence_channel' => sanitize_key((string) ($post['evidence_channel'] ?? 'phone')),
                        'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                        'evidence_at' => sanitize_text_field((string) ($post['evidence_at'] ?? gmdate('Y-m-d H:i:s'))),
                    ),
                    $idempotency
                );
                return 'Teacher assignment replaced with recorded agreement and retained history. Re-schedule future classes if needed.';
            case 'class_cancel':
                self::requireCap('dzn_manage_canonical_lessons');
                $cancelReason = sanitize_key((string) ($post['reason_code'] ?? 'academy_unavailable'));
                $cancelReference = sanitize_text_field((string) ($post['evidence_reference'] ?? ''));
                $expectedSchedule = absint($post['expected_schedule_version_id'] ?? 0);
                if ($expectedSchedule > 0) {
                    // Cancellation is a lifecycle decision; the current occurrence must be
                    // released through the schedule authority first so the audit retains both facts.
                    (new CanonicalLessonScheduleService())->release($classId, array(
                        'expected_schedule_version_id' => $expectedSchedule,
                        'reason_code' => 'class_cancelled',
                        'evidence_channel' => 'staff_record',
                        'evidence_reference' => $cancelReference !== '' ? $cancelReference : 'class-cancel-release',
                        'evidence_at' => gmdate('Y-m-d H:i:s'),
                    ), $idempotency . '-release');
                }
                (new CanonicalLessonAuthorityService())->cancel(
                    $classId,
                    'authorised',
                    array(
                        'reason_code' => $cancelReason,
                        'evidence_channel' => 'staff_record',
                        'evidence_reference' => $cancelReference !== '' ? $cancelReference : 'class-cancel',
                        'evidence_at' => gmdate('Y-m-d H:i:s'),
                    ),
                    $idempotency
                );
                return 'Class cancelled. The schedule release, audit, attendance evidence, replacement eligibility and financial history are preserved.';
            case 'class_complete':
                self::requireCap('dzn_manage_canonical_lessons');
                $result = (new CanonicalLessonAuthorityService())->complete($classId, 'authorised', array(
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), $idempotency);
                try { (new \Delnavazan\Platform\Core\Application\Finance\LessonFinanceOrchestrationService())->captureAfterCompletion((int) $result['lesson_id'], 'administrator_completion'); } catch (\Throwable) {}
                return 'Class completed. Finance remains pending if its own authority has not settled.';
            case 'class_delivery':
                self::requireCap('dzn_manage_canonical_lesson_delivery');
                $outcome = sanitize_key((string) ($post['outcome_code'] ?? 'delivered'));
                if (!in_array($outcome, array('delivered', 'student_no_show', 'interruption', 'review_required'), true)) throw new \InvalidArgumentException('Controlled attendance outcome required');
                (new CanonicalLessonDeliveryService())->record($classId, sanitize_key((string) ($post['lesson_state'] ?? 'completed')), array(
                    'outcome_code' => $outcome,
                    'reason_code' => sanitize_key((string) ($post['reason_code'] ?? 'administrator_attendance_review')),
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), $idempotency);
                return 'Attendance outcome recorded from reviewed staff evidence. Administrator presence alone was not used as proof.';
            case 'class_attendance':
                self::requireCap('dzn_manage_canonical_attendance_review');
                $adjudication = sanitize_key((string) ($post['adjudication'] ?? ''));
                if (!in_array($adjudication, array('settle_delivered', 'record_no_change', 'review_required', 'teacher_non_delivery', 'reassess'), true)) throw new \InvalidArgumentException('Choose a reviewed attendance decision');
                $service = new CanonicalAttendanceIntakeService();
                $caseId = absint($post['case_id'] ?? 0);
                $version = absint($post['expected_case_version'] ?? 0);
                if ($adjudication === 'reassess') $service->reassess($caseId, array('expected_case_version' => $version), $idempotency);
                else $service->adjudicate($caseId, array('expected_case_version' => $version, 'adjudication' => $adjudication), $idempotency);
                return 'Attendance decision recorded against the reviewed case version.';
            case 'import_commit':
                self::requireCap('dzn_manage_enrolments');
                self::requireCap('dzn_manage_terms');
                self::requireCap('dzn_manage_lessons');
                $result = (new HistoricalEnrolmentImportService())->import($post, $idempotency);
                return ($result['idempotent'] ? 'Import already completed; no duplicate records were created.' : 'Imported historical course with ' . count($result['lesson_ids']) . ' future classes.')
                    . ' Completed classes: ' . (int) $result['completed_classes'] . '.';
            default:
                throw new \InvalidArgumentException('Unsupported operation');
        }
    }

    /** @return array<string,mixed> */
    private static function assentInput(array $post): array {
        return array(
            'candidate_id' => absint($post['candidate_id'] ?? 0),
            'candidate_version' => absint($post['candidate_version'] ?? 0),
            'teacher_id' => absint($post['teacher_id'] ?? 0),
            'course_id' => absint($post['course_id'] ?? 0),
            'delivery_mode' => 'online',
            'location_scope' => 'not_applicable',
            'frequency_per_week' => max(1, absint($post['frequency_per_week'] ?? 1)),
            'expected_duration_minutes' => max(5, absint($post['expected_duration_minutes'] ?? 30)),
            'commencement_window_start' => sanitize_text_field((string) ($post['commencement_window_start'] ?? '')),
            'commencement_window_end' => sanitize_text_field((string) ($post['commencement_window_end'] ?? '')),
            'timezone' => sanitize_text_field((string) ($post['assent_timezone'] ?? 'UTC')),
            'conditions_code' => 'none',
            'valid_until' => sanitize_text_field((string) ($post['valid_until'] ?? '')),
            'review_by' => sanitize_text_field((string) ($post['review_by'] ?? '')),
            'attribution_basis' => sanitize_key((string) ($post['attribution_basis'] ?? 'direct_teacher_statement')),
            'evidence_channel' => sanitize_key((string) ($post['evidence_channel'] ?? 'phone')),
            'evidence_at' => sanitize_text_field((string) ($post['evidence_at'] ?? gmdate('Y-m-d H:i:s'))),
        );
    }

    /** @return array<string,mixed>|null */
    private static function classroomForClass(array $class): ?array {
        if ((string) $class['record_model'] !== 'canonical_term_lesson_v1') return null;
        try { return (new LiveKitAdministratorClassroomService())->forLesson((int) $class['id']); }
        catch (\Throwable) { return null; }
    }

    /** @return array<string,mixed>|null */
    private static function classroomForBooking(array $case): ?array {
        $confirmation = $case['confirmed'] ?? null;
        if (!$confirmation || (string) ($confirmation['state'] ?? '') !== 'confirmed') return null;
        try { return (new LiveKitAdministratorClassroomService())->forBooking((string) $confirmation['confirmation_uid']); }
        catch (\Throwable) { return null; }
    }

    private static function view(): string {
        $view = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
        return in_array($view, array('enrolled', 'review'), true) ? $view : 'new';
    }

    private static function returnUrl(): string {
        $args = array('page' => self::PAGE);
        foreach (array('view', 'request_id', 'class_id', 'q', 'stage', 'teacher_id', 'student_id', 'course_id', 'term_id', 'date', 'action') as $key) {
            if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) continue;
            $value = wp_unslash((string) $_GET[$key]);
            $args[$key] = in_array($key, array('request_id', 'class_id', 'teacher_id', 'student_id', 'course_id', 'term_id'), true) ? absint($value) : sanitize_text_field($value);
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function redirect(string $url, string $message, bool $error, array $extra = array(), string $fragment = ''): never {
        $url = add_query_arg(array_merge(array('dzn_workspace_notice' => $message, 'dzn_workspace_error' => $error ? '1' : '0'), $extra), $url);
        if ($fragment !== '') $url .= '#' . rawurlencode($fragment);
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die(esc_html($message), 'Delnavazan', array('response' => $error ? 400 : 200, 'back_link' => true));
    }

    /** @return array<string,string> */
    private static function introFormValues(array $post): array {
        $values = array();
        foreach (array('student_id', 'course_id', 'teacher_id', 'local_date', 'local_start_time', 'schedule_timezone', 'agreement_channel', 'agreement_reference', 'agreement_at', 'override_reason_code', 'staff_reason') as $key) {
            $values[$key] = sanitize_text_field((string) ($post[$key] ?? ''));
        }
        return $values;
    }

    private static function safeMessage(\Throwable $exception): string {
        $message = $exception->getMessage();
        return match ($message) {
            'Unauthorized' => 'You no longer have permission for this action.',
            'teacher_slot_conflict' => 'The Teacher already has a class at that time. Choose another slot; nothing was double-booked.',
            'active_future_schedule_exists' => 'This Enrolment still has future scheduled classes. Cancel or reschedule them before changing the Teacher assignment.',
            'intro_not_finished' => 'The introductory lesson has not finished yet.',
            'intro_outcome_already_recorded' => 'A different outcome is already recorded for this introductory lesson.',
            'post_intro_invitation_expired' => 'The continuation window has expired.',
            'stale_schedule_version' => 'The class schedule changed while you were editing. Reload and review the current version.',
            'schedule_start_not_future' => 'Choose a future class time.',
            'Booking confirmation changed concurrently' => 'This introductory booking changed while you were editing. Reload and review the current confirmation before acting.',
            'intro_booking_confirmation_required' => 'A confirmed introductory booking is required before this action.',
            'intro_lesson_not_reschedulable' => 'This introductory lesson can no longer be rescheduled.',
            'Choose a valid service-area timezone' => 'Choose one of the service-area timezones before saving.',
            'Choose a valid local date and start time' => 'Choose a valid local date and a start time from the list.',
            'Choose a future introductory time' => 'Choose a future introductory time.',
            'The Teacher already has a class at that time. Choose another slot.' => 'The Teacher already has a class at that time. Choose another slot; nothing was double-booked.',
            'The Teacher has a reserved continuation slot at that time. Choose another slot.' => 'The Teacher has a reserved continuation slot at that time. Choose another slot.',
            'The Student already has a class at that time. Choose another slot.' => 'The Student already has a class at that time. Choose another slot.',
            'A payment reminder was already created in the last 24 hours' => 'A payment reminder was already created in the last 24 hours. No duplicate was queued.',
            'A link was sent less than 24 hours ago. Use Send reminder when a reminder is appropriate.' => 'A payment link was sent less than 24 hours ago. Use Send reminder if a reminder is appropriate.',
            'Verified payment already exists; no payment prompt is permitted' => 'Verified payment already exists. No payment prompt is permitted.',
            'The continuation is not in an open decision or payment period' => 'The continuation is not in an open decision or payment period.',
            default => preg_match('/^(?:The|A|An|This|No|Choose|Resolve|Record|Verified|Confirm|Select|Enter|Cannot)\b.{8,220}$/u', $message) === 1
                ? $message
                : (str_starts_with($message, 'teacher_') || str_contains($message, 'required')
                    ? ucfirst(str_replace('_', ' ', $message)) . '.'
                    : 'The action could not be completed; no change was saved.'),
        };
    }

    private static function requireCap(string $capability): void {
        if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');
    }

    private static function notFound(string $message): void {
        echo '<div class="wrap dzn-enrolment"><p><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">← Back to Enrolment</a></p><div class="dzn-empty-state"><h1>' . esc_html($message) . '</h1><p>The record may have been archived or is no longer available.</p></div></div>';
    }
}
