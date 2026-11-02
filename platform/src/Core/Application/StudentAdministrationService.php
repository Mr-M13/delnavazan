<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\StudentRepository;

/**
 * Administrative Student lifecycle and account workflows.
 *
 * This service never stores or displays a password, never cascades protected
 * history and never writes authority relationships directly. It composes the
 * existing canonical services with a server-side capability check.
 */
final class StudentAdministrationService {
    private const PROFILE_FIELDS = array(
        'first_name','last_name','display_name','email','phone','whatsapp_phone',
        'country_code','city','timezone','timezone_source','locale','calendar_preference',
    );

    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    public function createFromAdmin(array $input, string $formId = ''): int {
        $this->require('dzn_manage_students');
        $formId = trim($formId);
        $formKey = $formId === '' ? '' : hash('sha256', $formId);
        if ($formKey !== '' && function_exists('get_transient')) {
            $existing = (int) get_transient('dzn_student_create_' . $formKey);
            if ($existing > 0 && (new StudentRepository())->find($existing)) return $existing;
        }

        $email = Normalizer::email($input['email'] ?? null);
        $phone = Normalizer::phone($input['phone'] ?? null);
        $whatsapp = Normalizer::phone($input['whatsapp_phone'] ?? null);
        $duplicate = $this->duplicate($email, $phone, $whatsapp);
        if ($duplicate) throw new \InvalidArgumentException('student_identity_already_exists');

        $id = (new StudentService())->create($input);
        if ($formKey !== '' && function_exists('set_transient')) set_transient('dzn_student_create_' . $formKey, $id, 600);
        $this->audit($id, 'student.created', 'profile_created');
        return $id;
    }

    public function updateProfile(int $studentId, array $input): void {
        $this->require('dzn_manage_students');
        $student = (new StudentRepository())->find($studentId);
        if (!$student) throw new \InvalidArgumentException('student_not_found');
        $data = array_intersect_key($input, array_flip(self::PROFILE_FIELDS));
        foreach (array('first_name','last_name','city') as $field) $data[$field] = Normalizer::text($data[$field] ?? null);
        $data['display_name'] = Normalizer::text($data['display_name'] ?? '', 191, true);
        $data['email'] = Normalizer::email($data['email'] ?? null);
        foreach (array('phone','whatsapp_phone') as $field) $data[$field] = Normalizer::phone($data[$field] ?? null);
        $data['country_code'] = Normalizer::country($data['country_code'] ?? null);
        $data['timezone'] = Normalizer::timezone($data['timezone'] ?? null);
        $data['timezone_source'] = Normalizer::timezoneSource($data['timezone_source'] ?? null);
        $data['locale'] = Normalizer::locale($data['locale'] ?? null);
        $data['calendar_preference'] = Normalizer::one($data['calendar_preference'] ?? 'auto', array('auto','gregorian','persian'), 'calendar');
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_by'] = get_current_user_id() ?: null;
        global $wpdb;
        $changed = $wpdb->update($this->p . 'students', $data, array('id' => $studentId));
        if ($changed === false) throw new \RuntimeException('student_profile_update_failed');
        $this->audit($studentId, 'student.profile_updated', implode(',', array_keys($data)));
    }

    /** Operational participation is paused, but every historical fact and account link is retained. */
    public function deactivate(int $studentId): void {
        $this->require('dzn_manage_students');
        $this->transitionStatus($studentId, 'inactive', 'student.deactivated');
    }

    public function reactivate(int $studentId): void {
        $this->require('dzn_manage_students');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,status,archived_at FROM {$this->p}students WHERE id=%d", $studentId));
        if (!$row) throw new \InvalidArgumentException('student_not_found');
        if ($row->archived_at !== null || (string) $row->status === 'archived') throw new \InvalidArgumentException('student_is_archived');
        if ((string) $row->status === 'active') return;
        $this->transitionStatus($studentId, 'active', 'student.reactivated');
    }

    public function archive(int $studentId): void {
        $this->require('dzn_manage_students');
        (new ArchiveService())->archive('student', $studentId);
        $this->audit($studentId, 'student.archived', 'history_preserved_account_link_retained');
    }

    /** Restoring makes the profile visible again. It deliberately does not restore portal access. */
    public function restore(int $studentId): void {
        $this->require('dzn_manage_students');
        (new ArchiveService())->restore('student', $studentId);
        $this->audit($studentId, 'student.restored', 'access_remains_unchanged');
    }

    /**
     * Sends the canonical WordPress reset request. The response never reveals
     * whether the account exists and never claims confirmed delivery.
     */
    public function sendPasswordReset(int $studentId): string {
        $this->require('dzn_manage_students');
        $user = $this->linkedUser($studentId);
        if (!$user) throw new \InvalidArgumentException('no_linked_account');
        $cooldown = 'dzn_student_password_reset_' . $studentId;
        if (function_exists('get_transient') && get_transient($cooldown)) throw new \RuntimeException('password_reset_rate_limited');
        $result = retrieve_password((string) $user->user_login);
        if (function_exists('is_wp_error') && is_wp_error($result)) throw new \RuntimeException('password_reset_request_failed');
        if (function_exists('set_transient')) set_transient($cooldown, '1', 300);
        $this->audit($studentId, 'student.password_reset_requested', 'canonical_wordpress_reset');
        return 'Password reset request accepted for delivery through the WordPress account workflow. Delivery is not confirmed until the student reports receiving it.';
    }

    public function issueAccountClaim(int $studentId, string $idempotencyKey): string {
        $this->require('dzn_issue_student_account_claims');
        if ($this->linkedUser($studentId)) throw new \InvalidArgumentException('account_already_linked');
        $result = (new StudentAccountClaimInvitationService())->issue(
            $studentId,
            604800,
            'admin_student_workspace:' . $studentId . ':' . $idempotencyKey
        );
        $this->audit($studentId, 'student.account_claim_issued', 'invitation_id:' . (int) ($result['invitation_id'] ?? 0));
        return 'Account claim invitation accepted for delivery. Delivery is not confirmed by this request; the invitation status records the transport evidence.';
    }

    public function deactivateLinkedAccount(int $studentId): string {
        $this->require('dzn_manage_student_acceptance_authority');
        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT id,version,wordpress_user_id FROM {$this->p}student_principal_links
             WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL FOR UPDATE",
            $studentId
        ));
        if (!$link) throw new \InvalidArgumentException('no_linked_account');
        (new StudentAcceptanceAuthorityService())->revokePrincipal((int) $link->id, (int) $link->version, 'admin_offboarded', $this->actor());
        $this->audit($studentId, 'student.account_deactivated', 'principal_link_revoked');
        return 'The linked account no longer has Student access. Historical records and the WordPress user were preserved.';
    }

    public function linkAccount(int $studentId, int $userId): string {
        $this->require('dzn_manage_student_acceptance_authority');
        $this->assertEligibleAccount($userId);
        (new StudentAcceptanceAuthorityService())->establishPrincipal(
            $studentId,
            $userId,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $this->actor()
        );
        $this->audit($studentId, 'student.account_linked', 'verified_existing_account');
        return 'Verified WordPress account linked as the Student principal.';
    }

    public function supersedeLinkedAccount(int $studentId, int $userId): string {
        $this->require('dzn_manage_student_acceptance_authority');
        $this->assertEligibleAccount($userId);
        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT id,version FROM {$this->p}student_principal_links
             WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL FOR UPDATE",
            $studentId
        ));
        if (!$link) throw new \InvalidArgumentException('student_principal_link_not_available');
        (new StudentAcceptanceAuthorityService())->supersedePrincipal(
            (int) $link->id,
            (int) $link->version,
            $userId,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            'admin_verified_replacement',
            $this->actor()
        );
        $this->audit($studentId, 'student.account_superseded', 'verified_replacement_account');
        return 'Student principal link superseded with the verified account.';
    }

    public function classifyCapacity(int $studentId, string $classification): string {
        $this->require('dzn_manage_student_acceptance_authority');
        (new StudentAcceptanceAuthorityService())->classify(
            $studentId,
            $classification,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $this->actor()
        );
        $this->audit($studentId, 'student.capacity_classified', $classification);
        return 'Age and authority category recorded from a reviewed classification.';
    }

    public function grantGuardian(int $studentId, int $userId, ?string $effectiveUntil = null): string {
        $this->require('dzn_manage_student_acceptance_authority');
        $this->assertEligibleAccount($userId);
        (new StudentAcceptanceAuthorityService())->grantGuardian(
            $studentId,
            $userId,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $effectiveUntil,
            $this->actor()
        );
        $this->audit($studentId, 'student.guardian_granted', 'verified_guardian_representative');
        return 'Guardian representative authority granted to the verified account.';
    }

    public function revokeGuardian(int $studentId, int $grantId): string {
        $this->require('dzn_manage_student_acceptance_authority');
        $version = $this->activeGrantVersion($studentId, $grantId);
        (new StudentAcceptanceAuthorityService())->revokeGuardian($grantId, $version, 'admin_revoked', $this->actor());
        $this->audit($studentId, 'student.guardian_revoked', 'guardian_grant_revoked');
        return 'Guardian representative authority revoked. Historical evidence remains.';
    }

    public function supersedeGuardian(int $studentId, int $grantId, int $userId, ?string $effectiveUntil = null): string {
        $this->require('dzn_manage_student_acceptance_authority');
        $version = $this->activeGrantVersion($studentId, $grantId);
        (new StudentAcceptanceAuthorityService())->supersedeGuardian(
            $grantId,
            $version,
            $userId,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $effectiveUntil,
            'admin_verified_replacement',
            $this->actor()
        );
        $this->audit($studentId, 'student.guardian_superseded', 'verified_replacement_guardian');
        return 'Guardian representative authority superseded with the verified account.';
    }

    public function transitionTask(int $studentId, int $exceptionId, string $to, ?string $note = null): string {
        $this->require('dzn_manage_exceptions');
        if (!$this->taskBelongsToStudent($studentId, $exceptionId)) throw new \InvalidArgumentException('task_not_available');
        (new ExceptionService())->transition($exceptionId, $to, $note);
        return match ($to) {
            'acknowledged' => 'Task acknowledged.',
            'dismissed' => 'Task dismissed with the recorded reason.',
            default => 'Task resolved with the recorded reason.',
        };
    }

    /** Activate, pause, resume or close one canonical enrolment belonging to this student. */
    public function transitionEnrolment(int $studentId, int $enrolmentId, string $operation, string $reason, string $idempotencyKey): string {
        $this->require('dzn_manage_canonical_enrolment_lifecycle');
        if (!in_array($operation, array('activate','pause','resume','close'), true)) throw new \InvalidArgumentException('enrolment_operation_required');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,lifecycle_state FROM {$this->p}enrolments
             WHERE id=%d AND student_id=%d AND record_model='canonical_student_course_v1' AND archived_at IS NULL",
            $enrolmentId, $studentId
        ));
        if (!$row) throw new \InvalidArgumentException('enrolment_not_available');
        $expected = (string) $row->lifecycle_state;
        $service = new CanonicalEnrolmentLifecycleService();
        $evidence = $this->staffEvidence('student-workspace-enrolment-' . $operation . '-' . $enrolmentId);
        $result = match ($operation) {
            'activate' => $service->activate($enrolmentId, $expected, $evidence, $idempotencyKey),
            'pause' => $service->pause($enrolmentId, $expected, $evidence, $idempotencyKey),
            'resume' => $service->resume($enrolmentId, $expected, $evidence, $idempotencyKey),
            default => $service->close($enrolmentId, $expected, $evidence, $idempotencyKey),
        };
        $this->audit($studentId, 'student.enrolment_' . $operation, 'enrolment:' . $enrolmentId . ':' . $reason);
        return 'Enrolment ' . $operation . 'd through the canonical lifecycle authority.';
    }

    /** Activate, close or cancel one canonical Term belonging to this student. */
    public function transitionTerm(int $studentId, int $termId, string $operation, string $reason, string $idempotencyKey): string {
        $this->require('dzn_manage_canonical_terms');
        if (!in_array($operation, array('activate','close','cancel'), true)) throw new \InvalidArgumentException('term_operation_required');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id,t.lifecycle_state FROM {$this->p}terms t
             INNER JOIN {$this->p}enrolments e ON e.id=t.enrolment_id
             WHERE t.id=%d AND e.student_id=%d AND t.record_model='canonical_enrolment_term_v1' AND t.archived_at IS NULL",
            $termId, $studentId
        ));
        if (!$row) throw new \InvalidArgumentException('term_not_available');
        $expected = (string) $row->lifecycle_state;
        $service = new CanonicalTermAuthorityService();
        $evidence = $this->staffEvidence('student-workspace-term-' . $operation . '-' . $termId);
        match ($operation) {
            'activate' => $service->activate($termId, $expected, $evidence, $idempotencyKey),
            'close' => $service->close($termId, $expected, $evidence, $idempotencyKey),
            default => $service->cancel($termId, $expected, $evidence, $idempotencyKey),
        };
        $this->audit($studentId, 'student.term_' . $operation, 'term:' . $termId . ':' . $reason);
        return 'Term ' . $operation . 'd through the canonical Term authority.';
    }

    /** Revise the schedule of one authorised canonical Lesson for this student. */
    public function rescheduleLesson(int $studentId, int $lessonId, string $localDate, string $localTime, int $durationMinutes, string $reason, string $idempotencyKey): string {
        $this->require('dzn_manage_canonical_lesson_schedules');
        if (!in_array($durationMinutes, array(15,20,30,45,60,90,120), true)) throw new \InvalidArgumentException('lesson_duration_invalid');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $localDate) !== 1 || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $localTime) !== 1) throw new \InvalidArgumentException('lesson_time_invalid');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id,l.teacher_assignment_id,l.current_schedule_version_id,
                    COALESCE(csv.schedule_timezone, lsv.schedule_timezone, 'UTC') schedule_timezone
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}canonical_lesson_schedule_versions csv ON csv.lesson_id=l.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL
             LEFT JOIN {$this->p}lesson_schedule_versions lsv ON lsv.id=l.current_schedule_version_id AND lsv.lesson_id=l.id AND lsv.superseded_at IS NULL
             WHERE l.id=%d AND l.student_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL AND l.lifecycle_state='authorised'",
            $lessonId, $studentId
        ));
        if (!$row || (int) $row->teacher_assignment_id < 1) throw new \InvalidArgumentException('lesson_not_reschedulable');
        $currentVersion = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1 AND superseded_at IS NULL LIMIT 1",
            $lessonId
        ));
        if ($currentVersion < 1) throw new \InvalidArgumentException('lesson_schedule_required');
        (new CanonicalLessonScheduleService())->revise($lessonId, (int) $row->teacher_assignment_id, array(
            'expected_schedule_version_id' => $currentVersion,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'local_wall_date' => $localDate,
            'local_wall_time' => $localTime . ':00',
            'duration_minutes' => $durationMinutes,
            'reason_code' => 'student_workspace_reschedule',
            'evidence_channel' => 'staff_record',
            'evidence_reference' => 'student-workspace-reschedule-' . $lessonId,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), $idempotencyKey);
        $this->audit($studentId, 'student.lesson_rescheduled', 'lesson:' . $lessonId . ':' . $reason);
        return 'Lesson schedule revised through the canonical schedule authority.';
    }

    /** Adjudicate or reassess one attendance case for this student. */
    public function reviewAttendance(int $studentId, int $caseId, string $operation, string $idempotencyKey): string {
        $this->require('dzn_manage_canonical_attendance_review');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,case_version,state FROM {$this->p}canonical_attendance_cases WHERE id=%d AND student_id=%d",
            $caseId, $studentId
        ));
        if (!$row) throw new \InvalidArgumentException('attendance_case_not_available');
        $service = new CanonicalAttendanceIntakeService();
        if ($operation === 'reassess') {
            $service->reassess($caseId, array('expected_case_version' => (int) $row->case_version), $idempotencyKey);
            $this->audit($studentId, 'student.attendance_reassessed', 'case:' . $caseId);
            return 'Attendance evidence reassessed through the canonical intake authority.';
        }
        if (!in_array($operation, array('settle_delivered','record_no_change','review_required','teacher_non_delivery'), true)) throw new \InvalidArgumentException('attendance_operation_required');
        $service->adjudicate($caseId, array('expected_case_version' => (int) $row->case_version, 'adjudication' => $operation), $idempotencyKey);
        $this->audit($studentId, 'student.attendance_adjudicated', 'case:' . $caseId . ':' . $operation);
        return 'Attendance case adjudicated through the canonical intake authority.';
    }

    /** Confirm or cancel one pending reschedule proposal for this student. */
    public function decideRescheduleProposal(int $studentId, string $proposalUid, string $decision, string $idempotencyKey): string {
        if (!current_user_can('dzn_manage_lessons') && !current_user_can('dzn_manage_canonical_lesson_schedules')) throw new \RuntimeException('Unauthorized');
        global $wpdb;
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}lesson_reschedule_proposals WHERE uid=%s AND student_id=%d LIMIT 1",
            $proposalUid, $studentId
        ));
        if ($owned < 1) throw new \InvalidArgumentException('reschedule_proposal_not_available');
        $service = new LessonRescheduleService();
        if ($decision === 'confirm') $service->confirmAsAdministrator($proposalUid, $idempotencyKey);
        elseif ($decision === 'cancel') $service->cancelAsAdministrator($proposalUid, $idempotencyKey);
        else throw new \InvalidArgumentException('reschedule_decision_required');
        $this->audit($studentId, 'student.reschedule_' . $decision, 'proposal:' . $owned);
        return $decision === 'confirm' ? 'Reschedule proposal confirmed through the canonical reschedule authority.' : 'Reschedule proposal cancelled through the canonical reschedule authority.';
    }

    /** Resolve an unresolved introductory booking to this verified student. */
    public function resolveBookingIdentity(int $studentId, int $bookingRequestId, string $idempotencyKey): string {
        $this->require('dzn_resolve_booking_request_student_identity');
        (new StudentIdentityResolutionService())->resolveExisting(
            $bookingRequestId,
            $studentId,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $this->actor()
        );
        $this->audit($studentId, 'student.booking_identity_resolved', 'request:' . $bookingRequestId);
        return 'Booking request resolved to the verified student through the identity-resolution authority.';
    }

    /** Record a reviewed non-resolved identity outcome for a candidate booking. */
    public function recordBookingIdentityOutcome(int $studentId, int $bookingRequestId, string $outcome, string $idempotencyKey): string {
        $this->require('dzn_resolve_booking_request_student_identity');
        if (!in_array($outcome, array('unresolved','ambiguous','rejected'), true)) throw new \InvalidArgumentException('identity_outcome_required');
        (new StudentIdentityResolutionService())->recordOutcome(
            $bookingRequestId,
            $outcome,
            'human_review',
            'reviewer_attestation',
            gmdate('Y-m-d H:i:s'),
            $this->actor()
        );
        $this->audit($studentId, 'student.booking_identity_outcome', 'request:' . $bookingRequestId . ':' . $outcome);
        return 'Reviewed identity outcome recorded.';
    }

    /** @return array<string,mixed> */
    private function staffEvidence(string $reference): array {
        return array(
            'evidence_channel' => 'staff_record',
            'evidence_reference' => $reference,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        );
    }

    /** Staff-attested Teacher replacement through the existing Teacher Assignment authority. */
    public function reassignTeacher(int $studentId, int $enrolmentId, int $assignmentId, int $teacherId, string $channel, string $reference, string $idempotencyKey): string {
        $this->require('dzn_manage_teacher_assignments');
        if (!in_array($channel, array('phone','whatsapp','email','video_call','in_person','other_verified'), true)) {
            throw new \InvalidArgumentException('teacher_agreement_channel_required');
        }
        global $wpdb;
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.id FROM {$this->p}enrolments e
             WHERE e.id=%d AND e.student_id=%d AND e.record_model='canonical_student_course_v1' AND e.archived_at IS NULL
               AND EXISTS(SELECT 1 FROM {$this->p}teacher_assignments ta WHERE ta.id=%d AND ta.enrolment_id=e.id AND ta.applicable_slot=1)",
            $enrolmentId, $studentId, $assignmentId
        ));
        if ($owned < 1) throw new \InvalidArgumentException('assignment_not_available');
        (new TeacherAssignmentService())->replace($enrolmentId, $teacherId, array(
            'expected_assignment_id' => $assignmentId,
            'route' => 'staff_attestation',
            'evidence_channel' => $channel,
            'evidence_reference' => $reference,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), 'student_admin_teacher_replacement:' . $studentId . ':' . $idempotencyKey);
        $this->audit($studentId, 'student.teacher_reassigned', 'enrolment:' . $enrolmentId . ' assignment:' . $assignmentId);
        return 'Teacher assignment replaced through the canonical Teacher Assignment authority. Existing future schedules must have been released or rescheduled first.';
    }

    private function transitionStatus(int $studentId, string $status, string $event): void {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,status,archived_at FROM {$this->p}students WHERE id=%d FOR UPDATE", $studentId));
            if (!$row) throw new \InvalidArgumentException('student_not_found');
            if ($row->archived_at !== null || (string) $row->status === 'archived') throw new \InvalidArgumentException('student_is_archived');
            if ((string) $row->status === $status) { $wpdb->query('COMMIT'); return; }
            $changed = $wpdb->update(
                $this->p . 'students',
                array('status'=>$status,'updated_at'=>gmdate('Y-m-d H:i:s'),'updated_by'=>$this->actor()),
                array('id'=>$studentId)
            );
            if ($changed === false) throw new \RuntimeException('student_status_update_failed');
            $this->audit($studentId, $event, 'status:' . $status);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function linkedUser(int $studentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT u.ID,u.user_login,u.user_email,u.display_name
             FROM {$this->p}student_principal_links l INNER JOIN {$wpdb->users} u ON u.ID=l.wordpress_user_id
             WHERE l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL LIMIT 1",
            $studentId
        ));
    }

    private function duplicate(?string $email, ?string $phone, ?string $whatsapp): ?object {
        global $wpdb;
        $clauses = array();
        $args = array();
        if ($email !== null && $email !== '') { $clauses[] = 'LOWER(email)=LOWER(%s)'; $args[] = $email; }
        foreach (array($phone, $whatsapp) as $number) {
            $digits = StudentWorkspaceFilters::phoneDigits((string) $number);
            if ($digits === '') continue;
            $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
            $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_phone,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
            $args[] = $digits;
            $args[] = $digits;
        }
        if (!$clauses) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id,reference_code,display_name,email FROM {$this->p}students WHERE archived_at IS NULL AND (" . implode(' OR ', $clauses) . ") ORDER BY id DESC LIMIT 1",
            ...$args
        ));
    }

    private function assertEligibleAccount(int $userId): void {
        if ($userId < 1 || !function_exists('get_userdata')) throw new \InvalidArgumentException('wordpress_account_required');
        $user = get_userdata($userId);
        if (!$user) throw new \InvalidArgumentException('wordpress_account_required');
        if (function_exists('user_can') && user_can($user, 'manage_options')) {
            throw new \InvalidArgumentException('administrator_account_not_permitted');
        }
    }

    private function activeGrantVersion(int $studentId, int $grantId): int {
        global $wpdb;
        $version = $wpdb->get_var($wpdb->prepare(
            "SELECT version FROM {$this->p}student_acceptance_authority_grants
             WHERE id=%d AND student_id=%d AND authority_type='guardian_representative' AND state='active' AND active_slot=1 FOR UPDATE",
            $grantId, $studentId
        ));
        if ($version === null) throw new \InvalidArgumentException('guardian_grant_not_available');
        return (int) $version;
    }

    private function taskBelongsToStudent(int $studentId, int $exceptionId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT x.id FROM {$this->p}operational_exceptions x
             WHERE x.id=%d AND x.status IN ('open','acknowledged') AND (
                 (x.entity_type='student' AND x.entity_id=%d)
                 OR (x.entity_type='enrolment' AND x.entity_id IN (SELECT e.id FROM {$this->p}enrolments e WHERE e.student_id=%d))
                 OR (x.entity_type='lesson' AND x.entity_id IN (SELECT l.id FROM {$this->p}lessons l WHERE l.student_id=%d))
             ) LIMIT 1",
            $exceptionId, $studentId, $studentId, $studentId
        ));
    }

    private function audit(int $studentId, string $event, string $detail): void {
        global $wpdb;
        $key = hash('sha256', 'student-admin:' . $studentId . ':' . $event . ':' . gmdate('Y-m-d H:i:s') . ':' . random_bytes(16));
        $wpdb->insert($this->p . 'platform_audit_events', array(
            'aggregate_type' => 'student',
            'aggregate_id' => $studentId,
            'event_type' => $event,
            'actor_type' => 'user',
            'actor_id' => $this->actor(),
            'reason_code' => 'administrator_action',
            'safe_detail' => $detail,
            'idempotency_key' => $key,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    private function actor(): int {
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Unauthorized');
        return $actor;
    }

    private function require(string $capability): void {
        if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');
    }
}
