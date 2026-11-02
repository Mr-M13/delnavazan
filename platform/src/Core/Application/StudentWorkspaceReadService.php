<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\OperationalExceptionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentRepository;

/**
 * Read-only composition for the Students administration workspace.
 *
 * The service never decides a lifecycle, finance, attendance, notification or
 * consent outcome. Each section is either read from its owning table or omitted
 * with an explicit restricted state when the operator lacks the matching
 * authority. All lists are bounded; the directory is always page-sized.
 */
final class StudentWorkspaceReadService {
    private const CAP = 'dzn_manage_students';
    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @param array{query:string,status:string,page:int,sort:string,direction:string,per_page:int} $filters */
    public function directory(array $filters): array {
        $this->require();
        $result = (new StudentRepository())->adminWorkspaceDirectory(
            (string) $filters['query'],
            (string) $filters['status'],
            (int) $filters['page'],
            (string) $filters['sort'],
            (string) $filters['direction'],
            (int) $filters['per_page']
        );
        $this->applyDirectoryNotificationCounts($result['rows']);
        foreach ($result['rows'] as $row) $this->decorateDirectoryRow($row);
        return $result;
    }

    /** One grouped notification-evidence query for the page, keyed by the student recipient digest. */
    private function applyDirectoryNotificationCounts(array $rows): void {
        global $wpdb;
        $digests = array();
        foreach ($rows as $row) {
            $row->notification_failed = 0;
            $row->notification_delivered = 0;
            $digest = $this->notificationRecipientDigest((int) $row->id);
            if ($digest !== '') $digests[$digest] = true;
        }
        if (!$digests) return;
        $keys = array_keys($digests);
        $marks = implode(',', array_fill(0, count($keys), '%s'));
        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT recipient_digest,
                    SUM(CASE WHEN state IN ('failed','expired') THEN 1 ELSE 0 END) failed_count,
                    SUM(CASE WHEN state='delivered' AND delivered_at IS NOT NULL THEN 1 ELSE 0 END) delivered_count
             FROM {$this->p}notifications WHERE recipient_digest IN ({$marks}) GROUP BY recipient_digest",
            ...$keys
        )) ?: array();
        $byDigest = array();
        foreach ($counts as $count) $byDigest[(string) $count->recipient_digest] = array((int) $count->failed_count, (int) $count->delivered_count);
        foreach ($rows as $row) {
            $digest = $this->notificationRecipientDigest((int) $row->id);
            if (isset($byDigest[$digest])) {
                $row->notification_failed = $byDigest[$digest][0];
                $row->notification_delivered = $byDigest[$digest][1];
            }
        }
    }

    public function profile(int $studentId, int $lessonPage = 1, int $lessonPerPage = 50): ?array {
        $this->require();
        if ($studentId < 1) return null;
        $lessonPage = max(1, $lessonPage);
        $lessonPerPage = max(10, min(100, $lessonPerPage));
        $student = (new StudentRepository())->find($studentId);
        if (!$student) return null;
        $summary = $this->summary($student);
        $account = $this->account($student);
        $identity = $this->identity($student);
        $finance = $this->finance($studentId);
        $notifications = $this->notifications($studentId);
        $guardians = $this->guardians($studentId);
        $tasks = $this->tasks($student, $summary, $identity, $finance, $notifications);

        return array(
            'student' => $student,
            'summary' => $summary,
            'account' => $account,
            'identity' => $identity,
            'guardians' => $guardians,
            'intro_requests' => $this->introRequests($studentId),
            'enrolments' => $this->enrolments($studentId),
            'terms' => $this->terms($studentId),
            'lessons' => $this->lessons($studentId, $lessonPage, $lessonPerPage),
            'reschedules' => $this->pendingReschedules($studentId),
            'attendance_cases' => $this->attendanceCases($studentId),
            'finance' => $finance,
            'notifications' => $notifications,
            'recording' => $this->recording($studentId),
            'tasks' => $tasks,
        );
    }

    /** Student-linked booking requests whose identity statement has no resolution evidence. */
    public function unresolvedBookingRequests(int $studentId): array {
        $this->require();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT br.id,br.reference_code,br.created_at,br.lifecycle_status,br.resolution_state,br.current_identity_resolution_id,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name
             FROM {$this->p}booking_requests br
             LEFT JOIN {$this->p}instruments i ON i.id=br.requested_instrument_id
             WHERE br.student_id=%d AND br.privacy_erased_at IS NULL AND br.current_identity_resolution_id IS NULL
             ORDER BY br.id DESC LIMIT 25",
            $studentId
        )) ?: array();
    }

    /** Action-field helper: the current reviewed acceptance capacity, if any. */
    public function capacityForStudent(int $studentId): string {
        $this->require();
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT c.classification FROM {$this->p}students s
             LEFT JOIN {$this->p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id
             WHERE s.id=%d LIMIT 1",
            $studentId
        ));
        return in_array((string) $value, array('adult', 'minor', 'unknown'), true) ? (string) $value : '';
    }

    /** Lightweight visibility check for account-specific actions. */
    public function hasActiveAccount(int $studentId): bool {
        $this->require();
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1",
            $studentId
        ));
    }

    /** Action-field helper: active guardian grants with their human owner. */
    public function activeGuardianOptions(int $studentId): array {
        if (!$this->can('dzn_manage_student_acceptance_authority')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.id,g.version,g.acting_wordpress_user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,u.user_email,g.effective_until
             FROM {$this->p}student_acceptance_authority_grants g
             LEFT JOIN {$wpdb->users} u ON u.ID=g.acting_wordpress_user_id
             WHERE g.student_id=%d AND g.authority_type='guardian_representative' AND g.state='active' AND g.active_slot=1
             ORDER BY g.id DESC",
            $studentId
        )) ?: array();
    }

    /** Action-field helper: open task rows attached to this student and its operational records. */
    public function openTaskOptions(int $studentId): array {
        if (!$this->can('dzn_manage_exceptions')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT x.id,x.exception_type,x.severity,x.summary,x.status
             FROM {$this->p}operational_exceptions x
             WHERE x.status IN ('open','acknowledged') AND (
                 (x.entity_type='student' AND x.entity_id=%d)
                 OR (x.entity_type='enrolment' AND x.entity_id IN (SELECT e.id FROM {$this->p}enrolments e WHERE e.student_id=%d))
                 OR (x.entity_type='lesson' AND x.entity_id IN (SELECT l.id FROM {$this->p}lessons l WHERE l.student_id=%d))
             )
             ORDER BY x.last_seen_at DESC,x.id DESC LIMIT 25",
            $studentId,$studentId,$studentId
        )) ?: array();
    }

    /** Current canonical/legacy enrolments that may receive a staff-attested Teacher replacement. */
    public function assignmentOptions(int $studentId): array {
        if (!$this->can('dzn_manage_teacher_assignments')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id enrolment_id,e.record_model,e.reference_code,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    ta.id assignment_id,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}enrolments e
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             LEFT JOIN {$this->p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
             LEFT JOIN {$this->p}teachers t ON t.id=ta.teacher_id
             WHERE e.student_id=%d AND e.archived_at IS NULL AND e.record_model='canonical_student_course_v1' AND e.lifecycle_state IN ('authorised','current','paused')
             ORDER BY e.id DESC LIMIT 25",
            $studentId
        )) ?: array();
    }

    /** Canonical enrolment lifecycle choices for this student. */
    public function enrolmentActionOptions(int $studentId): array {
        if (!$this->can('dzn_manage_canonical_enrolment_lifecycle')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id,e.reference_code,COALESCE(e.lifecycle_state,e.status) lifecycle_state,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}enrolments e LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             WHERE e.student_id=%d AND e.archived_at IS NULL AND e.record_model='canonical_student_course_v1'
               AND e.lifecycle_state IN ('authorised','current','paused')
             ORDER BY e.id DESC LIMIT 25",
            $studentId
        )) ?: array();
    }

    /** Canonical Term lifecycle choices for this student. */
    public function termActionOptions(int $studentId): array {
        if (!$this->can('dzn_manage_canonical_terms')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.reference_code,t.sequence_number,t.lifecycle_state,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}terms t
             INNER JOIN {$this->p}enrolments e ON e.id=t.enrolment_id
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             WHERE e.student_id=%d AND t.archived_at IS NULL AND t.record_model='canonical_enrolment_term_v1'
               AND t.lifecycle_state IN ('authorised','current')
             ORDER BY t.sequence_number DESC,t.id DESC LIMIT 25",
            $studentId
        )) ?: array();
    }

    /** Canonical authorised Lessons that can be rescheduled. */
    public function lessonActionOptions(int $studentId): array {
        if (!$this->can('dzn_manage_canonical_lesson_schedules')) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.reference_code,l.teacher_assignment_id,l.current_schedule_version_id,
                    COALESCE(csv.starts_at_utc,lsv.starts_at_utc) starts_at_utc,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}courses c ON c.id=l.course_id
             LEFT JOIN {$this->p}canonical_lesson_schedule_versions csv ON csv.lesson_id=l.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL
             LEFT JOIN {$this->p}lesson_schedule_versions lsv ON lsv.id=l.current_schedule_version_id AND lsv.lesson_id=l.id AND lsv.superseded_at IS NULL
             WHERE l.student_id=%d AND l.archived_at IS NULL AND l.record_model='canonical_term_lesson_v1'
               AND l.lifecycle_state='authorised' AND l.teacher_assignment_id IS NOT NULL
             ORDER BY starts_at_utc DESC,l.id DESC LIMIT 50",
            $studentId
        )) ?: array();
    }

    /** Attendance cases open to the canonical review authority. */
    public function attendanceCaseOptions(int $studentId): array {
        if (!$this->can('dzn_manage_canonical_attendance_review')) return array();
        return $this->attendanceCases($studentId);
    }

    /** Pending reschedule proposals open to administrator decision. */
    public function rescheduleProposalOptions(int $studentId): array {
        if (!$this->can('dzn_manage_lessons') && !$this->can('dzn_manage_canonical_lesson_schedules')) return array();
        return $this->pendingReschedules($studentId);
    }

    /**
     * Unresolved booking requests whose contact snapshot matches this student.
     * Only exposed with identity-resolution and acceptance read authority.
     */
    public function pendingBookingIdentityMatches(int $studentId): array {
        if (!$this->can('dzn_resolve_booking_request_student_identity') || !$this->can('dzn_view_student_acceptance_eligibility')) return array();
        global $wpdb;
        $student = (new StudentRepository())->find($studentId);
        if (!$student) return array();
        $email = trim((string) ($student->email ?? ''));
        $phone = StudentWorkspaceFilters::phoneDigits((string) ($student->phone ?? ''));
        $whatsapp = StudentWorkspaceFilters::phoneDigits((string) ($student->whatsapp_phone ?? ''));
        $clauses = array();
        $args = array();
        if ($email !== '') { $clauses[] = 'LOWER(cs.email)=LOWER(%s)'; $args[] = $email; }
        foreach (array($phone, $whatsapp) as $digits) {
            if ($digits === '') continue;
            $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cs.mobile,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
            $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cs.whatsapp_number,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
            $args[] = $digits;
            $args[] = $digits;
        }
        if (!$clauses) return array();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT br.id,br.reference_code,br.created_at,br.lifecycle_status,br.resolution_state,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name
             FROM {$this->p}booking_requests br
             INNER JOIN {$this->p}booking_request_contact_snapshots cs ON cs.booking_request_id=br.id AND cs.snapshot_sequence=1
             LEFT JOIN {$this->p}instruments i ON i.id=br.requested_instrument_id
             WHERE br.student_id IS NULL AND br.privacy_erased_at IS NULL AND br.lifecycle_status='submitted' AND br.resolution_state='unresolved'
               AND (" . implode(' OR ', $clauses) . ")
             ORDER BY br.id DESC LIMIT 20",
            ...$args
        )) ?: array();
    }

    /** Eligible active teachers for a replacement selector. */
    public function activeTeacherOptions(): array {
        if (!$this->can('dzn_manage_teacher_assignments')) return array();
        global $wpdb;
        return $wpdb->get_results(
            "SELECT t.id,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) display_name
             FROM {$this->p}teachers t
             WHERE t.archived_at IS NULL AND t.status='active'
             ORDER BY display_name ASC LIMIT 200"
        ) ?: array();
    }

    /** @return array<string,mixed> */
    private function summary(object $student): array {
        global $wpdb;
        $studentId = (int) $student->id;
        $enrolment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.id,e.reference_code,e.course_id,e.teacher_id,e.started_at,e.record_model,
                    COALESCE(e.lifecycle_state,e.status) enrolment_state,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(ta_teacher.display_name,''),NULLIF(ta_teacher.english_name,''),ta_teacher.persian_name,
                             NULLIF(e_teacher.display_name,''),NULLIF(e_teacher.english_name,''),e_teacher.persian_name) teacher_name
             FROM {$this->p}enrolments e
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             LEFT JOIN {$this->p}instruments i ON i.id=c.instrument_id
             LEFT JOIN {$this->p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
             LEFT JOIN {$this->p}teachers ta_teacher ON ta_teacher.id=ta.teacher_id
             LEFT JOIN {$this->p}teachers e_teacher ON e_teacher.id=e.teacher_id
             WHERE e.student_id=%d AND e.archived_at IS NULL AND COALESCE(e.lifecycle_state,e.status) IN ('authorised','current','paused','active')
             ORDER BY e.id DESC LIMIT 1",
            $studentId
        ));
        $term = $enrolment ? $wpdb->get_row($wpdb->prepare(
            "SELECT t.id,t.reference_code,t.sequence_number,t.lesson_allocation,t.replacement_allowance,
                    COALESCE(t.lifecycle_state,t.status) lifecycle_state,t.starts_at,t.ends_at,t.payment_state,
                    (SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.term_id=t.id AND l.archived_at IS NULL AND l.lesson_type='standard' AND COALESCE(l.lifecycle_state,l.status)='completed') completed_count,
                    (SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.term_id=t.id AND l.archived_at IS NULL AND l.lesson_type='standard' AND COALESCE(l.lifecycle_state,l.status) IN ('authorised','scheduled')) scheduled_count,
                    (SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.term_id=t.id AND l.archived_at IS NULL AND l.lesson_type='replacement' AND COALESCE(l.lifecycle_state,l.status)<>'cancelled') replacement_count
             FROM {$this->p}terms t
             WHERE t.enrolment_id=%d AND t.archived_at IS NULL
             ORDER BY t.sequence_number DESC,t.id DESC LIMIT 1",
            (int) $enrolment->id
        )) : null;

        $lastLesson = $wpdb->get_var($wpdb->prepare("SELECT MAX(l.completed_at) FROM {$this->p}lessons l WHERE l.student_id=%d AND l.archived_at IS NULL AND COALESCE(l.lifecycle_state,l.status)='completed'", $studentId));
        $nextLesson = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(COALESCE(csv.starts_at_utc,lsv.starts_at_utc))
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}canonical_lesson_schedule_versions csv ON csv.lesson_id=l.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL
             LEFT JOIN {$this->p}lesson_schedule_versions lsv ON lsv.id=l.current_schedule_version_id AND lsv.lesson_id=l.id AND lsv.superseded_at IS NULL
             WHERE l.student_id=%d AND l.archived_at IS NULL AND COALESCE(l.lifecycle_state,l.status) IN ('authorised','scheduled')
               AND COALESCE(csv.starts_at_utc,lsv.starts_at_utc)>=UTC_TIMESTAMP()",
            $studentId
        ));

        $replacement = array('allowance'=>0,'used'=>0,'pending'=>0,'available'=>0);
        if ($term && (int) $term->id > 0) {
            try { $replacement = (new LessonRescheduleRepository())->termReplacementAllocation((int) $term->id); }
            catch (\Throwable) { $replacement = array('allowance'=>max(0,(int)$term->replacement_allowance),'used'=>(int)$term->replacement_count,'pending'=>0,'available'=>max(0,(int)$term->replacement_allowance-(int)$term->replacement_count)); }
        }

        $absenceReported = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT c.id) FROM {$this->p}canonical_attendance_cases c
             INNER JOIN {$this->p}canonical_attendance_evidence ev ON ev.case_id=c.id AND ev.source_kind='student'
                AND ev.reason_code IN ('student_advance_absence','student_reports_missed_class','student_disputes_class')
             WHERE c.student_id=%d",
            $studentId
        ));
        $noShow = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}canonical_lesson_delivery_outcomes d INNER JOIN {$this->p}lessons l ON l.id=d.lesson_id WHERE l.student_id=%d AND d.applicable_slot=1 AND d.outcome_code='student_no_show'", $studentId));
        $teacherNonDelivery = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}canonical_lesson_delivery_outcomes d INNER JOIN {$this->p}lessons l ON l.id=d.lesson_id WHERE l.student_id=%d AND d.applicable_slot=1 AND d.outcome_code='teacher_non_delivery'", $studentId));

        $renewal = $wpdb->get_row($wpdb->prepare(
            "SELECT rc.id,rc.state,rc.currency,rc.amount_minor,rc.boundary_derived_at,rc.automatic_charge_at,rc.guarantee_deadline_at,rc.next_term_id,rc.sequence
             FROM {$this->p}renewal_cycles rc
             INNER JOIN {$this->p}recurring_enrolments re ON re.id=rc.recurring_enrolment_id
             WHERE re.student_id=%d ORDER BY rc.sequence DESC,rc.id DESC LIMIT 1",
            $studentId
        ));

        return array(
            'enrolment' => $enrolment,
            'term' => $term,
            'term_progress' => array(
                'completed' => $term ? (int) $term->completed_count : 0,
                'scheduled' => $term ? (int) $term->scheduled_count : 0,
                'replacement_count' => $term ? (int) $term->replacement_count : 0,
                'allocation' => $term ? (int) $term->lesson_allocation : 0,
                'remaining' => $term ? max(0, (int) $term->lesson_allocation - (int) $term->completed_count) : 0,
                'replacement_allowance' => (int) ($replacement['allowance'] ?? 0),
                'replacement_used' => (int) ($replacement['used'] ?? 0),
                'replacement_pending' => (int) ($replacement['pending'] ?? 0),
                'replacement_remaining' => (int) ($replacement['available'] ?? 0),
            ),
            'last_lesson_at' => $lastLesson ? (string) $lastLesson : null,
            'next_lesson_at' => $nextLesson ? (string) $nextLesson : null,
            'absences' => array(
                'recorded' => $absenceReported + $noShow,
                'student_reported' => $absenceReported,
                'no_show' => $noShow,
                'teacher_non_delivery' => $teacherNonDelivery,
                'eligible_makeup' => (int) ($replacement['pending'] ?? 0),
                'used' => (int) ($replacement['used'] ?? 0),
                'remaining' => (int) ($replacement['available'] ?? 0),
            ),
            'renewal' => $renewal ? array(
                'id' => (int) $renewal->id,
                'sequence' => (int) $renewal->sequence,
                'state' => (string) $renewal->state,
                'currency' => (string) $renewal->currency,
                'amount_minor' => (int) $renewal->amount_minor,
                'boundary_at' => $renewal->boundary_derived_at,
                'automatic_charge_at' => $renewal->automatic_charge_at,
                'guarantee_deadline_at' => $renewal->guarantee_deadline_at,
                'next_term_id' => $renewal->next_term_id === null ? null : (int) $renewal->next_term_id,
            ) : null,
        );
    }

    private function decorateDirectoryRow(object $row): void {
        $row->student_name = (string) ($row->student_name ?? 'Student');
        $row->directory_status_label = StudentWorkspaceFilters::directoryStatus(
            (string) ($row->status ?? ''),
            $row->archived_at !== null,
            (bool) ($row->intro_active ?? false),
            (bool) ($row->active_enrolment ?? false)
        );
        $row->contact_label = trim(implode(' · ', array_filter(array(
            (string) ($row->phone ?? ''),
            (string) ($row->whatsapp_phone ?? ''),
        ))));
        if ($row->contact_label === '') $row->contact_label = 'No phone recorded';
        $row->location_label = trim(implode(', ', array_filter(array((string) ($row->city ?? ''), (string) ($row->country_code ?? '')))));
        if ($row->location_label === '') $row->location_label = 'Location not recorded';
        $row->instrument_label = (string) ($row->latest_course_name ?? '') !== '' ? (string) $row->latest_course_name : 'No current course';
        $row->teacher_label = (string) ($row->latest_teacher_name ?? '') !== '' ? (string) $row->latest_teacher_name : 'Not assigned';
        $row->term_label = $row->current_term_id !== null && $row->current_term_id !== ''
            ? 'Term ' . (string) $row->current_term_sequence . ' · ' . (int) $row->current_term_completed . '/' . (int) $row->current_term_allocation
            : 'No current term';
        if ($row->current_term_id !== null && $row->current_term_id !== '') {
            $row->term_label .= ' · ' . ucfirst(str_replace('_', ' ', (string) $row->current_term_state));
        }
        $row->lessons_label = $row->current_term_id !== null && $row->current_term_id !== ''
            ? (int) $row->current_term_completed . ' completed · ' . max(0, (int) $row->current_term_allocation - (int) $row->current_term_completed) . ' remaining'
            : 'No entitled term';
        $row->renewal_label = $this->renewalLabel($row->renewal_state, $row->renewal_at);
        $row->payment_status = $this->paymentState((string) ($row->payment_offer_state ?? ''), (int) ($row->payment_outstanding_minor ?? 0), (int) ($row->payment_paid_minor ?? 0), $row->payment_due_at, (string) ($row->payment_plan_kind ?? ''));
        $row->payment_label = $this->paymentLabel($row->payment_status, (int) $row->payment_outstanding_minor, (string) ($row->payment_currency ?? ''), $row->payment_due_at, (string) ($row->payment_plan_kind ?? ''));
        $row->absence_label = (int) $row->absence_count . ' reported · ' . (int) ($row->no_show_count ?? 0) . ' no-show · ' . $this->replacementSummary((int) ($row->current_term_id ?? 0), (int) ($row->current_term_replacement_allowance ?? 0), (int) ($row->current_term_replacement_count ?? 0));
        $failed = (int) $row->notification_failed;
        $delivered = (int) $row->notification_delivered;
        $row->notification_label = $failed > 0 ? number_format_i18n($failed) . ' needs attention' : ($delivered > 0 ? number_format_i18n($delivered) . ' confirmed delivered' : 'No delivery evidence');
        $row->notification_state = $failed > 0 ? 'failed' : ($delivered > 0 ? 'healthy' : 'none');
        $row->last_activity_label = $this->humanDate($row->last_activity_at ?? null);
    }

    private function replacementSummary(int $termId, int $allowance, int $used): string {
        if ($termId < 1) return 'no term allowance';
        try {
            $allocation = (new LessonRescheduleRepository())->termReplacementAllocation($termId);
            return (int) ($allocation['available'] ?? 0) . ' of ' . (int) ($allocation['allowance'] ?? 0) . ' make-up slots available';
        } catch (\Throwable) {
            return max(0, $allowance - $used) . ' of ' . $allowance . ' make-up slots available';
        }
    }

    /** @return array<string,mixed> */
    private function account(object $student): array {
        global $wpdb;
        $studentId = (int) $student->id;
        $principal = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id,l.wordpress_user_id,l.status,l.linked_at,l.revoked_at,l.version
             FROM {$this->p}student_principal_links l
             WHERE l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL LIMIT 1",
            $studentId
        ));
        $user = null;
        if ($principal) {
            $user = $wpdb->get_row($wpdb->prepare("SELECT ID,user_login,display_name,user_email,user_registered FROM {$wpdb->users} WHERE ID=%d", (int) $principal->wordpress_user_id));
        }
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT i.state,i.current_generation,i.claimed_at,i.claimed_principal_id,i.revoked_at,i.revocation_reason_code,
                    g.expires_at,g.state generation_state,g.generation_number
             FROM {$this->p}student_account_claim_invitations i
             LEFT JOIN {$this->p}student_account_claim_generations g ON g.invitation_id=i.id AND g.generation_number=i.current_generation
             WHERE i.student_id=%d LIMIT 1",
            $studentId
        ));
        $verifiedByClaim = $claim && (string) $claim->state === 'claimed' && (int) $claim->claimed_principal_id === (int) ($principal->wordpress_user_id ?? 0);
        return array(
            'principal' => $principal ? array(
                'link_id' => (int) $principal->id,
                'wordpress_user_id' => (int) $principal->wordpress_user_id,
                'version' => (int) $principal->version,
                'linked_at' => (string) $principal->linked_at,
                'status' => (string) $principal->status,
            ) : null,
            'legacy_wordpress_user_id' => $student->wordpress_user_id === null ? null : (int) $student->wordpress_user_id,
            'user' => $user ? array(
                'id' => (int) $user->ID,
                'login' => (string) $user->user_login,
                'display_name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'registered_at' => (string) $user->user_registered,
            ) : null,
            'status' => $principal ? 'linked' : 'not_linked',
            'email_verification' => $verifiedByClaim ? 'verified_by_account_claim' : ($claim ? 'claim_' . (string) $claim->state : 'not_verified'),
            'verified_at' => $verifiedByClaim ? (string) $claim->claimed_at : null,
            'claim' => $claim ? array(
                'state' => (string) $claim->state,
                'generation_state' => $claim->generation_state === null ? null : (string) $claim->generation_state,
                'generation' => $claim->generation_number === null ? null : (int) $claim->generation_number,
                'expires_at' => $claim->expires_at,
                'claimed_at' => $claim->claimed_at,
            ) : null,
            'password_reset_available' => $user !== null,
        );
    }

    /** @return array<string,mixed> */
    private function identity(object $student): array {
        $available = $this->can('dzn_view_student_acceptance_eligibility') || $this->can('dzn_manage_student_acceptance_authority');
        if (!$available) return array('available'=>false,'anomalies'=>array(),'candidates'=>array(),'capacity'=>null,'principal'=>null);
        global $wpdb;
        $studentId = (int) $student->id;
        $capacity = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id,c.classification,c.verification_basis,c.evidence_channel,c.classified_at
             FROM {$this->p}students s
             LEFT JOIN {$this->p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id
             WHERE s.id=%d LIMIT 1",
            $studentId
        ));
        $principal = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id,l.wordpress_user_id,l.version,l.linked_at,u.user_email,u.display_name
             FROM {$this->p}student_principal_links l LEFT JOIN {$wpdb->users} u ON u.ID=l.wordpress_user_id
             WHERE l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL LIMIT 1",
            $studentId
        ));
        $grants = $this->guardians($studentId);
        $activeGrants = array_values(array_filter($grants, static fn(array $g): bool => $g['state'] === 'active'));
        $unresolvedBookings = $this->unresolvedBookingRequests($studentId);
        $claim = $wpdb->get_row($wpdb->prepare("SELECT id,state,claimed_principal_id,claimed_at FROM {$this->p}student_account_claim_invitations WHERE student_id=%d LIMIT 1", $studentId));
        $candidates = $this->duplicateCandidates($studentId, (string) ($student->email ?? ''), (string) ($student->phone ?? ''), (string) ($student->whatsapp_phone ?? ''));
        $pendingMatches = $this->pendingBookingIdentityMatches($studentId);
        $legacyUser = null;
        if ($student->wordpress_user_id !== null) {
            $legacyUser = $wpdb->get_row($wpdb->prepare("SELECT ID,display_name,user_email,user_login FROM {$wpdb->users} WHERE ID=%d", (int) $student->wordpress_user_id));
        }
        $anomalies = array();

        if ($student->wordpress_user_id !== null && $principal && (int) $student->wordpress_user_id !== (int) $principal->wordpress_user_id) {
            $anomalies[] = array(
                'code' => 'principal_link_conflict',
                'title' => 'Conflicting student account links',
                'description' => 'The student profile and the active Student principal authority point to different WordPress accounts. Access must be resolved from the principal authority, not by editing either record directly.',
                'evidence' => array(
                    'Profile account field' => $legacyUser ? (string) $legacyUser->display_name . ' · ' . (string) $legacyUser->user_email : 'Account #' . (int) $student->wordpress_user_id,
                    'Active principal authority' => trim((string) ($principal->display_name ?? '') . ' · ' . (string) ($principal->user_email ?? '')),
                    'Principal linked at' => (string) $principal->linked_at,
                ),
                'options' => array('Replace the principal link with the verified account that should own Student access.', 'Do not edit the profile field directly; the principal authority is the access source of truth.'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-supersede-account') : array(),
                'central' => null,
            );
        }
        if ($principal && $student->email !== null && (string) $student->email !== '' && strcasecmp((string) $principal->user_email, (string) $student->email) !== 0) {
            $anomalies[] = array(
                'code' => 'account_email_mismatch',
                'title' => 'Account email does not match the student email',
                'description' => 'The linked WordPress account email differs from the student contact email. This can indicate a stale or incorrect account link.',
                'evidence' => array(
                    'Student contact email' => (string) $student->email,
                    'Linked account email' => (string) $principal->user_email,
                    'Linked account' => trim((string) ($principal->display_name ?? '') . ' · ' . (string) ($principal->user_email ?? '')),
                ),
                'options' => array('Verify the person’s identity, then replace the linked account with the verified account.', 'Keep the current account only after recording a verified internal reason.'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-supersede-account') : array(),
                'central' => null,
            );
        }
        if ($principal && (!$claim || (string) $claim->state !== 'claimed' || (int) $claim->claimed_principal_id !== (int) $principal->wordpress_user_id)) {
            $anomalies[] = array(
                'code' => 'account_ownership_unverified',
                'title' => 'Account ownership is not proven',
                'description' => 'There is no completed student account-claim evidence for the linked account. The relationship may still be correct, but the platform has no token-backed proof of email ownership.',
                'evidence' => array(
                    'Linked account' => trim((string) ($principal->display_name ?? '') . ' · ' . (string) ($principal->user_email ?? '')),
                    'Account-claim state' => $claim ? ucfirst(str_replace('_', ' ', (string) $claim->state)) : 'No claim invitation',
                    'Claimed principal matches link' => $claim && (int) $claim->claimed_principal_id === (int) $principal->wordpress_user_id ? 'Yes' : 'No',
                ),
                'options' => array('Replace the link only after verifying the account owner through an authorised identity workflow.', 'A password reset proves access to the mailbox but is not token-backed ownership evidence by itself.'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-supersede-account') : array(),
                'central' => current_user_can('dzn_view_student_acceptance_eligibility') ? array('label' => 'Open protected identity authority', 'page' => 'dzn-student-identity-authority', 'args' => array('student_id' => $studentId)) : null,
            );
        }
        if ((string) ($capacity->classification ?? '') === 'minor' && !$activeGrants) {
            $anomalies[] = array(
                'code' => 'guardian_authority_missing',
                'title' => 'Guardian authority is required but missing',
                'description' => 'The student is classified as a minor under the current acceptance rules, but no active guardian representative grant is recorded. The platform does not infer a guardian from age alone; a reviewed classification and a verified representative grant are both required.',
                'evidence' => array(
                    'Reviewed capacity' => 'Minor',
                    'Active guardian grants' => '0',
                    'Classification reviewed at' => (string) ($capacity->classified_at ?? 'Not recorded'),
                ),
                'options' => array('Link a verified guardian account and grant representative authority.', 'If the age/authority classification is wrong, record a reviewed capacity classification with evidence.'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-guardian-grant','student-classify-capacity') : array(),
                'central' => null,
            );
        }
        if (!$capacity || in_array((string) ($capacity->classification ?? ''), array('', 'unknown'), true)) {
            $anomalies[] = array(
                'code' => 'capacity_unknown',
                'title' => 'Age and authority category not reviewed',
                'description' => 'The current acceptance capacity is unknown. Booking acceptance and guardian requirements cannot be proven until it is reviewed. This is a reviewed classification, not an age inferred from any other field.',
                'evidence' => array(
                    'Reviewed capacity' => $capacity ? ucfirst((string) $capacity->classification) : 'No classification',
                    'Evidence basis' => $capacity ? (string) $capacity->verification_basis : 'None',
                ),
                'options' => array('Record a reviewed capacity classification (adult, minor, or keep unknown with a reason).'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-classify-capacity') : array(),
                'central' => null,
            );
        }
        if (count($activeGrants) > 1) {
            $grantNames = array_map(static fn(array $g): string => (string) $g['display_name'] . ' · ' . (string) $g['email'], $activeGrants);
            $anomalies[] = array(
                'code' => 'guardian_authority_conflict',
                'title' => 'Multiple active guardian authorities',
                'description' => 'More than one guardian representative grant is active for this student. Replace or revoke the incorrect relationship so a single current authority is clear.',
                'evidence' => array('Active guardian grants' => implode(' | ', $grantNames)),
                'options' => array('Supersede the incorrect grant with the verified guardian.', 'Revoke the incorrect grant with a recorded reason.'),
                'action_keys' => $this->can('dzn_manage_student_acceptance_authority') ? array('student-guardian-supersede','student-guardian-revoke') : array(),
                'central' => null,
            );
        }
        if ($pendingMatches) {
            $anomalies[] = array(
                'code' => 'booking_identity_candidate',
                'title' => 'An unresolved booking matches this student',
                'description' => 'An unresolved introductory booking request uses the same email or normalised phone as this student. Confirm the identity only after comparing the request evidence with the verified student identity.',
                'evidence' => array('Matching requests' => implode(' | ', array_map(static fn(object $r): string => (string) ($r->reference_code ?: 'Introductory request') . ' · ' . (string) ($r->instrument_name ?? 'Instrument'), $pendingMatches))),
                'options' => array('Resolve the request to this verified student.', 'Record a non-resolved outcome if the evidence does not match.'),
                'action_keys' => $this->can('dzn_resolve_booking_request_student_identity') ? array('student-resolve-booking-identity','student-booking-identity-outcome') : array(),
                'central' => null,
            );
        }
        if ($unresolvedBookings) {
            $anomalies[] = array(
                'code' => 'booking_identity_unresolved',
                'title' => 'Introductory booking identity has no resolution evidence',
                'description' => 'A booking request is linked to this student but has no recorded identity-resolution event. The current identity authority cannot safely re-resolve an already-linked request automatically.',
                'evidence' => array('Linked requests' => implode(' | ', array_map(static fn(object $r): string => (string) ($r->reference_code ?: 'Introductory request'), $unresolvedBookings))),
                'options' => array('Review the booking evidence in the protected identity authority. Do not create a second student or reassign the booking without verified evidence.'),
                'action_keys' => array(),
                'central' => current_user_can('dzn_view_student_acceptance_eligibility') ? array('label' => 'Open protected identity authority', 'page' => 'dzn-student-identity-authority', 'args' => array('student_id' => $studentId)) : null,
            );
        }
        if ($candidates) {
            $anomalies[] = array(
                'code' => 'duplicate_identity_candidate',
                'title' => 'Possible duplicate student identities',
                'description' => 'Another student record shares a contact value with this profile. This is a candidate only; no automatic merge authority exists in this release.',
                'evidence' => array('Candidate records' => implode(' | ', array_map(static fn(array $c): string => (string) $c['display_name'] . ' · ' . (string) ($c['reference_code'] ?: 'No public ID'), $candidates))),
                'options' => array('Review each candidate and verify whether this is the same person.', 'Do not merge or create a second account without verified evidence. Use the protected identity authority for exceptional review.'),
                'action_keys' => array(),
                'central' => current_user_can('dzn_view_student_acceptance_eligibility') ? array('label' => 'Open protected identity authority', 'page' => 'dzn-student-identity-authority', 'args' => array('student_id' => $studentId)) : null,
            );
        }
        return array(
            'available' => true,
            'capacity' => $capacity && in_array((string) ($capacity->classification ?? ''), array('adult','minor','unknown'), true) ? array(
                'id' => (int) $capacity->id,
                'classification' => (string) $capacity->classification,
                'basis' => (string) $capacity->verification_basis,
                'channel' => (string) $capacity->evidence_channel,
                'classified_at' => (string) $capacity->classified_at,
            ) : null,
            'principal' => $principal ? array(
                'link_id' => (int) $principal->id,
                'wordpress_user_id' => (int) $principal->wordpress_user_id,
                'version' => (int) $principal->version,
                'display_name' => (string) ($principal->display_name ?? ''),
                'email' => (string) ($principal->user_email ?? ''),
                'linked_at' => (string) $principal->linked_at,
            ) : null,
            'active_guardian_count' => count($activeGrants),
            'anomalies' => $anomalies,
            'candidates' => $candidates,
            'pending_booking_matches' => $pendingMatches,
            'unresolved_booking_requests' => $unresolvedBookings,
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function duplicateCandidates(int $studentId, string $email, string $phone, string $whatsapp): array {
        global $wpdb;
        $clauses = array();
        $args = array($studentId);
        if (trim($email) !== '') { $clauses[] = 'LOWER(s.email)=LOWER(%s)'; $args[] = trim($email); }
        foreach (array($phone, $whatsapp) as $number) {
            $digits = StudentWorkspaceFilters::phoneDigits($number);
            if ($digits !== '') {
                $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.phone,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
                $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.whatsapp_phone,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
                $args[] = $digits;
                $args[] = $digits;
            }
        }
        if (!$clauses) return array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.reference_code,s.display_name,s.status,s.email,s.phone,s.whatsapp_phone,s.archived_at
             FROM {$this->p}students s WHERE s.id<>%d AND " . implode(' OR ', $clauses) . " ORDER BY s.id DESC LIMIT 10",
            ...$args
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'id' => (int) $row->id,
            'reference_code' => (string) ($row->reference_code ?? ''),
            'display_name' => (string) $row->display_name,
            'status' => (string) $row->status,
            'email' => (string) ($row->email ?? ''),
            'phone' => (string) ($row->phone ?? ''),
            'archived' => $row->archived_at !== null,
        ), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function guardians(int $studentId): array {
        if (!$this->can('dzn_view_student_acceptance_eligibility') && !$this->can('dzn_manage_student_acceptance_authority')) return array();
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.id,g.acting_wordpress_user_id,g.authority_type,g.authority_scope,g.state,g.active_slot,
                    g.effective_from,g.effective_until,g.version,g.granted_at,g.revoked_at,g.reason_code,
                    COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,u.user_email,u.user_login
             FROM {$this->p}student_acceptance_authority_grants g
             LEFT JOIN {$wpdb->users} u ON u.ID=g.acting_wordpress_user_id
             WHERE g.student_id=%d ORDER BY CASE WHEN g.state='active' AND g.active_slot=1 THEN 0 ELSE 1 END,g.id DESC",
            $studentId
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'grant_id' => (int) $row->id,
            'wordpress_user_id' => (int) $row->acting_wordpress_user_id,
            'display_name' => (string) ($row->display_name ?? 'WordPress account'),
            'email' => (string) ($row->user_email ?? ''),
            'login' => (string) ($row->user_login ?? ''),
            'authority_type' => (string) $row->authority_type,
            'authority_scope' => (string) $row->authority_scope,
            'relationship' => 'Representative (service acceptance)',
            'state' => (string) $row->state,
            'active' => (string) $row->state === 'active' && (int) $row->active_slot === 1,
            'version' => (int) $row->version,
            'effective_from' => (string) $row->effective_from,
            'effective_until' => $row->effective_until,
            'granted_at' => (string) $row->granted_at,
            'revoked_at' => $row->revoked_at,
            'reason_code' => $row->reason_code,
        ), $rows);
    }

    /** @return array<int,object> */
    private function introRequests(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT br.id,br.reference_code,br.lifecycle_status,br.resolution_state,br.created_at,br.updated_at,
                    br.retention_due_at,COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    (SELECT COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name)
                     FROM {$this->p}booking_request_confirmations bc INNER JOIN {$this->p}teachers t ON t.id=bc.teacher_id
                     WHERE bc.booking_request_id=br.id ORDER BY bc.id DESC LIMIT 1) teacher_name,
                    (SELECT GROUP_CONCAT(CONCAT(rt.local_date,' ',TIME_FORMAT(rt.local_start_time,'%%H:%%i'),' ',rt.timezone) ORDER BY rt.sequence_number SEPARATOR ' · ')
                     FROM {$this->p}booking_request_requested_times rt WHERE rt.booking_request_id=br.id) preferred_times,
                    (SELECT bc.state FROM {$this->p}booking_request_confirmations bc WHERE bc.booking_request_id=br.id ORDER BY bc.id DESC LIMIT 1) confirmation_state,
                    (SELECT cc.id FROM {$this->p}coordination_cases cc WHERE cc.booking_request_id=br.id ORDER BY cc.id DESC LIMIT 1) coordination_case_id,
                    (SELECT l.id FROM {$this->p}lessons l WHERE l.student_id=br.student_id AND l.course_id=COALESCE(br.selected_intro_course_id,l.course_id) AND l.lesson_type='introductory' ORDER BY l.id DESC LIMIT 1) intro_lesson_id
             FROM {$this->p}booking_requests br
             LEFT JOIN {$this->p}instruments i ON i.id=br.requested_instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=br.selected_intro_course_id
             WHERE br.student_id=%d AND br.privacy_erased_at IS NULL
             ORDER BY br.id DESC LIMIT 50",
            $studentId
        )) ?: array();
        foreach ($rows as $row) {
            $row->status_label = $this->bookingStatusLabel($row);
            $row->next_action = $this->bookingNextAction($row);
            $row->expires_label = $this->humanDate($row->retention_due_at);
        }
        return $rows;
    }

    private function bookingStatusLabel(object $row): string {
        if ((string) $row->resolution_state === 'unresolved') return 'Identity review required';
        if ((string) $row->lifecycle_status === 'submitted') return 'Awaiting teacher response';
        if ((string) $row->lifecycle_status === 'closed' && (string) $row->resolution_state === 'teacher_confirmed') {
            return (string) ($row->intro_lesson_id ?? '') !== '' ? 'Introductory lesson created' : 'Teacher confirmed';
        }
        if ((string) $row->lifecycle_status === 'closed') return 'Closed · ' . ucfirst(str_replace('_', ' ', (string) $row->resolution_state));
        return ucfirst(str_replace('_', ' ', (string) $row->lifecycle_status));
    }

    private function bookingNextAction(object $row): string {
        if ((string) $row->resolution_state === 'unresolved') return 'Resolve the verified student identity before continuing.';
        if ((string) $row->lifecycle_status === 'submitted') return 'Review the teacher response in the Enrolments workspace.';
        if ((string) $row->lifecycle_status === 'closed' && (string) $row->resolution_state === 'teacher_confirmed') {
            return (string) ($row->intro_lesson_id ?? '') !== '' ? 'Review the introductory lesson outcome.' : 'Confirm and schedule the introductory lesson through the canonical workflow.';
        }
        return 'No further action is required for this request.';
    }

    /** @return array<int,object> */
    private function enrolments(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id,e.reference_code,e.record_model,e.started_at,e.ended_at,
                    COALESCE(e.lifecycle_state,e.status) enrolment_state,e.status legacy_status,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(ta_teacher.display_name,''),NULLIF(ta_teacher.english_name,''),ta_teacher.persian_name,
                             NULLIF(e_teacher.display_name,''),NULLIF(e_teacher.english_name,''),e_teacher.persian_name) teacher_name,
                    ta.state assignment_state,
                    (SELECT t.id FROM {$this->p}terms t WHERE t.enrolment_id=e.id AND t.archived_at IS NULL ORDER BY t.sequence_number DESC,t.id DESC LIMIT 1) latest_term_id,
                    (SELECT t.sequence_number FROM {$this->p}terms t WHERE t.enrolment_id=e.id AND t.archived_at IS NULL ORDER BY t.sequence_number DESC,t.id DESC LIMIT 1) latest_term_sequence,
                    (SELECT COALESCE(t.lifecycle_state,t.status) FROM {$this->p}terms t WHERE t.enrolment_id=e.id AND t.archived_at IS NULL ORDER BY t.sequence_number DESC,t.id DESC LIMIT 1) latest_term_state
             FROM {$this->p}enrolments e
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             LEFT JOIN {$this->p}instruments i ON i.id=c.instrument_id
             LEFT JOIN {$this->p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
             LEFT JOIN {$this->p}teachers ta_teacher ON ta_teacher.id=ta.teacher_id
             LEFT JOIN {$this->p}teachers e_teacher ON e_teacher.id=e.teacher_id
             WHERE e.student_id=%d AND e.archived_at IS NULL
             ORDER BY COALESCE(e.started_at,e.created_at) DESC,e.id DESC LIMIT 50",
            $studentId
        )) ?: array();
        foreach ($rows as $row) {
            $row->status_label = ucfirst(str_replace('_', ' ', (string) $row->enrolment_state));
            $row->term_label = $row->latest_term_id ? 'Term ' . (int) $row->latest_term_sequence . ' · ' . ucfirst(str_replace('_', ' ', (string) $row->latest_term_state)) : 'No term yet';
        }
        return $rows;
    }

    /** @return array<int,object> */
    private function terms(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.reference_code,t.sequence_number,t.lesson_allocation,t.replacement_allowance,
                    COALESCE(t.lifecycle_state,t.status) lifecycle_state,t.starts_at,t.ends_at,t.activated_at,t.completed_at,t.payment_state,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    (SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.term_id=t.id AND l.archived_at IS NULL AND l.lesson_type='standard' AND COALESCE(l.lifecycle_state,l.status)='completed') completed_count,
                    (SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.term_id=t.id AND l.archived_at IS NULL AND l.lesson_type='replacement' AND COALESCE(l.lifecycle_state,l.status)<>'cancelled') replacement_count
             FROM {$this->p}terms t
             INNER JOIN {$this->p}enrolments e ON e.id=t.enrolment_id
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             WHERE e.student_id=%d AND t.archived_at IS NULL
             ORDER BY t.id DESC LIMIT 50",
            $studentId
        )) ?: array();
        foreach ($rows as $row) {
            $row->progress_label = (int) $row->completed_count . ' of ' . (int) $row->lesson_allocation . ' lessons completed';
            $row->state_label = ucfirst(str_replace('_', ' ', (string) $row->lifecycle_state));
        }
        return $rows;
    }

    /** @return array{rows:array<int,object>,total:int,page:int,pages:int,per_page:int} */
    private function lessons(int $studentId, int $page, int $perPage): array {
        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}lessons l WHERE l.student_id=%d AND l.archived_at IS NULL",
            $studentId
        ));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.reference_code,l.lesson_type,COALESCE(l.lifecycle_state,l.status) lesson_state,l.status legacy_status,
                    l.term_id,l.replacement_for_lesson_id,l.completed_at,l.cancelled_at,l.teacher_assignment_id,l.current_schedule_version_id,
                    t.sequence_number term_sequence,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(teacher.display_name,''),NULLIF(teacher.english_name,''),teacher.persian_name) teacher_name,
                    COALESCE(csv.starts_at_utc,lsv.starts_at_utc) starts_at_utc,
                    COALESCE(csv.local_wall_date,lsv.local_wall_date) local_wall_date,
                    COALESCE(csv.local_wall_time,lsv.local_wall_time) local_wall_time,
                    COALESCE(csv.schedule_timezone,lsv.schedule_timezone) schedule_timezone,
                    COALESCE(csv.id,lsv.id) schedule_version_id,
                    outcome.outcome_code,outcome.attendance_state,outcome.delivery_state
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}terms t ON t.id=l.term_id
             LEFT JOIN {$this->p}courses c ON c.id=l.course_id
             LEFT JOIN {$this->p}teachers teacher ON teacher.id=l.teacher_id
             LEFT JOIN {$this->p}canonical_lesson_schedule_versions csv ON csv.lesson_id=l.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL
             LEFT JOIN {$this->p}lesson_schedule_versions lsv ON lsv.id=l.current_schedule_version_id AND lsv.lesson_id=l.id AND lsv.superseded_at IS NULL
             LEFT JOIN {$this->p}canonical_lesson_delivery_outcomes outcome ON outcome.lesson_id=l.id AND outcome.applicable_slot=1
             WHERE l.student_id=%d AND l.archived_at IS NULL
             ORDER BY COALESCE(csv.starts_at_utc,lsv.starts_at_utc) DESC,l.id DESC LIMIT %d OFFSET %d",
            $studentId, $perPage, $offset
        )) ?: array();
        foreach ($rows as $row) $this->decorateLesson($row);
        return array('rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage);
    }

    /** Pending Teacher reschedule proposals for this student. */
    private function pendingReschedules(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT rp.id,rp.uid,rp.lesson_id,rp.state,rp.proposed_starts_at_utc,rp.schedule_timezone,rp.expires_at,rp.created_at,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}lesson_reschedule_proposals rp
             LEFT JOIN {$this->p}lessons l ON l.id=rp.lesson_id
             LEFT JOIN {$this->p}courses c ON c.id=l.course_id
             LEFT JOIN {$this->p}teachers t ON t.id=rp.teacher_id
             WHERE rp.student_id=%d AND rp.state IN ('pending','committing')
             ORDER BY rp.created_at DESC,rp.id DESC LIMIT 25",
            $studentId
        )) ?: array();
        foreach ($rows as $row) $row->label = (string) ($row->course_name ?: 'Class') . ' · proposed ' . $this->humanDate((string) $row->proposed_starts_at_utc) . ' ' . (string) $row->schedule_timezone;
        return $rows;
    }

    /** Attendance cases requiring an operator decision for this student. */
    private function attendanceCases(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id,c.lesson_id,c.schedule_version_id,c.state,c.case_version,c.occurrence_start_utc,c.window_end_utc,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name
             FROM {$this->p}canonical_attendance_cases c
             LEFT JOIN {$this->p}lessons l ON l.id=c.lesson_id
             LEFT JOIN {$this->p}courses course ON course.id=l.course_id
             WHERE c.student_id=%d AND c.state IN ('open','ready_for_review','settlement_pending')
             ORDER BY c.occurrence_start_utc DESC,c.id DESC LIMIT 50",
            $studentId
        )) ?: array();
        foreach ($rows as $row) $row->label = (string) ($row->course_name ?: 'Class') . ' · ' . $this->humanDate((string) $row->occurrence_start_utc) . ' · ' . ucfirst(str_replace('_', ' ', (string) $row->state));
        return $rows;
    }

    private function decorateLesson(object $row): void {
        $state = (string) $row->lesson_state;
        $start = $row->starts_at_utc ? (string) $row->starts_at_utc : '';
        $row->when_label = $start !== ''
            ? $this->humanDate($start) . ' · ' . substr((string) $row->local_wall_time, 0, 5) . ' ' . (string) $row->schedule_timezone
            : 'Not scheduled';
        $row->type_label = ucfirst(str_replace('_', ' ', (string) $row->lesson_type));
        $row->state_label = ucfirst(str_replace('_', ' ', $state));
        $row->attendance_label = $row->attendance_state !== null && (string) $row->attendance_state !== ''
            ? ucfirst(str_replace('_', ' ', (string) $row->attendance_state))
            : 'No recorded attendance issue';
        $row->term_label = $row->term_id ? 'Term ' . (int) $row->term_sequence : 'Introductory / unassigned';
        $row->replacement_label = $row->replacement_for_lesson_id ? 'Replacement lesson' : '';
        $isCancelled = in_array($state, array('cancelled'), true);
        $isCompleted = $state === 'completed';
        $isUpcoming = $start !== '' && $start >= gmdate('Y-m-d H:i:s') && !$isCancelled && !$isCompleted;
        $row->group = $row->lesson_type === 'introductory' ? 'introductory' : ($isCancelled ? 'cancelled' : ($isCompleted ? 'completed' : ($row->lesson_type === 'replacement' ? 'replacement' : ($isUpcoming ? 'upcoming' : 'other'))));
    }

    /** @return array<string,mixed> */
    private function finance(int $studentId): array {
        $canFinance = $this->can('dzn_view_finance_authority') || $this->can('dzn_view_payment_execution_authority');
        if (!$canFinance) return array('available'=>false,'reason'=>'Finance and payment details require finance or payment authority.','summary'=>null,'obligations'=>array(),'payments'=>array(),'discounts'=>array(),'intro_payments'=>array());
        global $wpdb;
        $offer = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}commercial_offers o LEFT JOIN {$this->p}courses c ON c.id=o.course_id
             WHERE o.beneficiary_student_id=%d AND (o.state='accepted' OR (o.state='issued' AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP())))
             ORDER BY o.id DESC LIMIT 1",
            $studentId
        ));
        $obligations = array();
        $paid = 0; $outstanding = 0; $refunded = 0; $nextDue = null; $currency = ''; $method = 'Not recorded';
        $payments = array();
        if ($offer) {
            $currency = (string) $offer->currency;
            $obligations = $wpdb->get_results($wpdb->prepare(
                "SELECT ob.id,ob.obligation_sequence,ob.sessions_from,ob.sessions_to,ob.sessions_covered,ob.amount_minor,ob.currency,ob.due_at,
                        st.amount_minor settled_amount,st.settled_at,ev.provider_key
                 FROM {$this->p}commercial_offer_obligations ob
                 LEFT JOIN {$this->p}commercial_obligation_settlements st ON st.obligation_id=ob.id
                 LEFT JOIN {$this->p}commercial_payment_evidence ev ON ev.id=st.evidence_id
                 WHERE ob.offer_id=%d ORDER BY ob.obligation_sequence,ob.id",
                (int) $offer->id
            )) ?: array();
            foreach ($obligations as $obligation) {
                $settled = (int) ($obligation->settled_amount ?? 0);
                $paid += $settled;
                if ($settled <= 0) {
                    $outstanding += (int) $obligation->amount_minor;
                    if ($obligation->due_at !== null && ($nextDue === null || (string) $obligation->due_at < (string) $nextDue)) $nextDue = (string) $obligation->due_at;
                }
                if ($obligation->provider_key !== null && (string) $obligation->provider_key !== '') $method = ucfirst((string) $obligation->provider_key);
            }
            $refunded = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount_minor),0) FROM {$this->p}commercial_payment_evidence
                 WHERE offer_id=%d AND evidence_kind='refund' AND processing_state='accepted'",
                (int) $offer->id
            ));
        }
        $evidence = $wpdb->get_results($wpdb->prepare(
            "SELECT evidence_kind,processing_state,amount_minor,currency,provider_key,provider_occurred_at,ingested_at,reason_code
             FROM {$this->p}commercial_payment_evidence
             WHERE offer_id IN (SELECT id FROM {$this->p}commercial_offers WHERE beneficiary_student_id=%d)
             ORDER BY id DESC LIMIT 100",
            $studentId
        )) ?: array();
        foreach ($evidence as $row) {
            $payments[] = array(
                'kind' => (string) $row->evidence_kind,
                'state' => (string) $row->processing_state,
                'amount_minor' => $row->amount_minor === null ? null : (int) $row->amount_minor,
                'currency' => (string) ($row->currency ?? ''),
                'provider' => (string) ($row->provider_key ?? ''),
                'occurred_at' => $row->provider_occurred_at,
                'recorded_at' => (string) $row->ingested_at,
                'label' => (string) $row->evidence_kind === 'refund'
                    ? ((string) $row->processing_state === 'accepted' ? 'Refunded' : 'Refund pending')
                    : ((string) $row->processing_state === 'accepted' ? 'Paid' : ((string) $row->processing_state === 'rejected' ? 'Payment rejected' : 'Payment pending')),
            );
        }
        $checkouts = $wpdb->get_results($wpdb->prepare(
            "SELECT state,amount_minor,currency,created_at,expires_at,closed_at,close_reason,provider_key
             FROM {$this->p}checkout_sessions
             WHERE student_id=%d ORDER BY id DESC LIMIT 50",
            $studentId
        )) ?: array();
        foreach ($checkouts as $checkout) {
            $payments[] = array(
                'kind' => 'checkout',
                'state' => (string) $checkout->state,
                'amount_minor' => (int) $checkout->amount_minor,
                'currency' => (string) $checkout->currency,
                'provider' => (string) $checkout->provider_key,
                'occurred_at' => (string) $checkout->created_at,
                'recorded_at' => (string) $checkout->created_at,
                'label' => in_array((string) $checkout->state, array('active','open','created'), true) ? 'Checkout initiated — not paid' : ('Checkout ' . ucfirst(str_replace('_', ' ', (string) $checkout->state))),
            );
        }
        $discounts = $wpdb->get_results($wpdb->prepare(
            "SELECT pr.id,pr.state,pr.consumed_at,pr.revoked_at,pr.reason_code,pm.name,pm.kind,pm.percentage_bp,pm.fixed_amount_minor,pm.currency promotion_currency
             FROM {$this->p}commercial_promotion_redemptions pr
             LEFT JOIN {$this->p}commercial_promotions pm ON pm.id=pr.promotion_id
             WHERE pr.beneficiary_student_id=%d ORDER BY pr.id DESC LIMIT 25",
            $studentId
        )) ?: array();
        $introPayments = $wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,amount_minor,currency,state,paid_at,failed_at,refunded_at,failure_reason_code,created_at
             FROM {$this->p}intro_payment_obligations WHERE student_id=%d ORDER BY id DESC LIMIT 25",
            $studentId
        )) ?: array();
        $status = $this->paymentState((string) ($offer->state ?? ''), $outstanding, $paid, $nextDue, (string) ($offer->plan_kind ?? ''));
        usort($payments, static fn(array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));
        return array(
            'available' => true,
            'reason' => null,
            'currency' => $currency,
            'offer' => $offer,
            'summary' => array(
                'current_term_price_minor' => $offer ? (int) $offer->amount_due_minor : 0,
                'base_amount_minor' => $offer ? (int) $offer->base_amount_minor : 0,
                'discount_total_minor' => $offer ? (int) $offer->discount_total_minor : 0,
                'paid_minor' => $paid,
                'outstanding_minor' => $outstanding,
                'refunded_minor' => $refunded,
                'next_due_at' => $nextDue,
                'status' => $status,
                'method' => $method,
                'plan_kind' => $offer ? (string) $offer->plan_kind : '',
            ),
            'obligations' => $obligations,
            'payments' => array_slice($payments, 0, 60),
            'discounts' => $discounts,
            'intro_payments' => $introPayments,
        );
    }

    /** @return array<string,mixed> */
    private function notifications(int $studentId): array {
        if (!$this->can(NotificationRule::READ_CAPABILITY)) {
            return array('available'=>false,'reason'=>'Notification and communication details require communications authority.','summary'=>array(),'rows'=>array(),'portal'=>array(),'channels'=>array(),'suppression'=>null);
        }
        global $wpdb;
        $digest = $this->notificationRecipientDigest($studentId);
        $portal = $wpdb->get_results($wpdb->prepare(
            "SELECT event_key,category,title,read_at,created_at FROM {$this->p}portal_notifications
             WHERE audience='student' AND recipient_id=%d ORDER BY id DESC LIMIT 25",
            $studentId
        )) ?: array();
        $rows = array();
        $summary = array(
            NotificationDeliveryStatusReadService::QUEUED => 0,
            NotificationDeliveryStatusReadService::HANDED_OFF => 0,
            NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED => 0,
            NotificationDeliveryStatusReadService::PORTAL_RECORDED => 0,
            NotificationDeliveryStatusReadService::FAILED => 0,
            NotificationDeliveryStatusReadService::CLOSED => 0,
        );
        if ($digest !== '') {
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT n.id,n.intent_key,n.state,n.delivered_at,n.updated_at,n.subject_aggregate,n.failure_reason_code,
                        (SELECT COUNT(*) FROM {$this->p}notification_attempts a WHERE a.notification_id=n.id) attempt_count
                 FROM {$this->p}notifications n
                 WHERE n.recipient_digest=%s ORDER BY n.id DESC LIMIT 100",
                $digest
            )) ?: array();
            foreach ($records as $record) {
                $category = NotificationDeliveryStatusReadService::categoryFor('canonical', (string) $record->state, $record->delivered_at === null ? null : (string) $record->delivered_at);
                $summary[$category] = (int) ($summary[$category] ?? 0) + 1;
                $rows[] = array(
                    'source' => 'canonical',
                    'event_key' => (string) $record->intent_key,
                    'audience' => 'student',
                    'channel' => 'email',
                    'state' => (string) $record->state,
                    'category' => $category,
                    'attempt_count' => (int) $record->attempt_count,
                    'subject_aggregate' => (string) $record->subject_aggregate,
                    'subject_id' => 0,
                    'handed_off_at' => null,
                    'delivery_confirmed_at' => $category === NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED ? (string) $record->delivered_at : null,
                    'last_error_code' => $record->failure_reason_code === null ? null : (string) $record->failure_reason_code,
                    'updated_at' => (string) $record->updated_at,
                );
            }
        }
        $channels = array();
        if ($digest !== '') {
            $channels = $wpdb->get_results($wpdb->prepare(
                "SELECT tm.channel,tm.state,COUNT(*) total FROM {$this->p}telnyx_messages tm
                 INNER JOIN {$this->p}notifications n ON n.id=tm.notification_id
                 WHERE n.recipient_digest=%s
                 GROUP BY tm.channel,tm.state ORDER BY tm.channel,tm.state",
                $digest
            )) ?: array();
        }
        return array(
            'available' => true,
            'reason' => null,
            'summary' => $summary,
            'rows' => $rows,
            'portal' => $portal,
            'channels' => $channels,
            'sms_policy' => 'SMS fallback is available only after an eligible, evidenced WhatsApp failure with the required consent and eligibility checks. It is never a manual shortcut.',
            'suppression' => $this->suppression($digest),
        );
    }

    private function notificationRecipientDigest(int $studentId): string {
        try { return hash_hmac('sha256', 'recipient:student:' . $studentId, NotificationSupport::salt()); }
        catch (\Throwable) { return ''; }
    }

    private function suppression(string $digest): ?array {
        if ($digest === '') return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,purpose,reason_code,state,effective_from,expires_at FROM {$this->p}notification_suppressions
             WHERE subject_kind='student' AND subject_digest=%s AND state='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
            $digest
        ));
        return $row ? array('id'=>(int)$row->id,'purpose'=>(string)$row->purpose,'reason_code'=>(string)$row->reason_code,'state'=>(string)$row->state,'effective_from'=>(string)$row->effective_from,'expires_at'=>$row->expires_at) : null;
    }

    /** @return array<string,mixed> */
    private function recording(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,purpose_code,decision_code,decided_at,notice_uid,notice_language_code,notice_version_number,guardian_actor_user_id,decided_by_user_id
             FROM {$this->p}recording_decisions WHERE subject_type='student' AND subject_id=%d
             ORDER BY decided_at DESC,id DESC LIMIT 50",
            $studentId
        )) ?: array();
        $latest = array();
        foreach ($rows as $row) {
            $purpose = (string) $row->purpose_code;
            if (!isset($latest[$purpose])) $latest[$purpose] = $row;
        }
        return array(
            'available' => true,
            'capture_enabled' => RecordingDecisionAuthorityService::CAPTURE_ENABLED,
            'research_export_enabled' => RecordingDecisionAuthorityService::RESEARCH_EXPORT_ENABLED,
            'recording' => $this->recordingChoice($latest['lesson_recording'] ?? null),
            'research' => $this->recordingChoice($latest['research_model_training'] ?? null),
            'history' => array_map(static fn(object $row): array => array(
                'id' => (int) $row->id,
                'purpose' => (string) $row->purpose_code,
                'decision' => (string) $row->decision_code,
                'decided_at' => (string) $row->decided_at,
                'notice_version' => (int) $row->notice_version_number,
                'language' => (string) $row->notice_language_code,
                'guardian_actor_user_id' => $row->guardian_actor_user_id === null ? null : (int) $row->guardian_actor_user_id,
            ), $rows),
            'learning_content' => array('practice_recordings'=>false,'teacher_feedback'=>false,'learning_content_uploads'=>false,'reason'=>'No canonical storage or processing workflow exists in this release.'),
        );
    }

    private function recordingChoice(?object $row): array {
        return $row ? array(
            'decision' => (string) $row->decision_code,
            'decided_at' => (string) $row->decided_at,
            'notice_version' => (int) $row->notice_version_number,
            'language' => (string) $row->notice_language_code,
            'guardian_actor' => $row->guardian_actor_user_id === null ? null : (int) $row->guardian_actor_user_id,
        ) : array('decision'=>null,'decided_at'=>null,'notice_version'=>null,'language'=>null,'guardian_actor'=>null);
    }

    /** @return array<int,array<string,mixed>> */
    private function tasks(object $student, array $summary, array $identity, array $finance, array $notifications): array {
        $tasks = array();
        if ($identity['available'] ?? false) {
            foreach (($identity['anomalies'] ?? array()) as $anomaly) {
                $tasks[] = array(
                    'key' => (string) $anomaly['code'],
                    'title' => (string) $anomaly['title'],
                    'description' => (string) $anomaly['description'],
                    'status' => 'Open',
                    'source' => 'identity',
                    'options' => (array) ($anomaly['options'] ?? array()),
                    'action_keys' => array(),
                );
            }
        }
        if (($finance['available'] ?? false) && ($finance['summary']['status'] ?? '') === 'Overdue') {
            $tasks[] = array(
                'key' => 'payment_overdue',
                'title' => 'Payment is overdue',
                'description' => 'An outstanding payment obligation is past its due date. Use the canonical finance workflow; do not mark a checkout attempt as paid.',
                'status' => 'Open',
                'source' => 'finance',
                'options' => array('Review the outstanding obligation in the Finance workspace and record only confirmed payment evidence.'),
                'action_keys' => array(),
            );
        }
        if (($summary['renewal']['state'] ?? '') !== '' && !in_array((string) $summary['renewal']['state'], array('term_bound','closed','lapsed','cancelled'), true)) {
            $tasks[] = array(
                'key' => 'renewal_due',
                'title' => 'Renewal needs review',
                'description' => 'The latest renewal cycle is not yet closed or bound to a successor term.',
                'status' => 'Open',
                'source' => 'renewal',
                'options' => array('Review renewal eligibility, reminders, payment obligation and next-term scheduling in the Enrolments workspace.'),
                'action_keys' => array(),
            );
        }
        if (($notifications['available'] ?? false) && (int) (($notifications['summary'][NotificationDeliveryStatusReadService::FAILED] ?? 0)) > 0) {
            $tasks[] = array(
                'key' => 'notification_failure',
                'title' => 'Notification delivery needs attention',
                'description' => 'At least one notification has a recorded failure. A queued or handed-off message is not evidence of delivery.',
                'status' => 'Open',
                'source' => 'notifications',
                'options' => array('Inspect the failed notification history and retry only when the canonical retry policy allows it.'),
                'action_keys' => array(),
            );
        }
        foreach ($this->openTaskOptions((int) $student->id) as $exception) {
            $tasks[] = array(
                'key' => 'exception:' . (int) $exception->id,
                'title' => (string) $exception->summary,
                'description' => 'Operational exception recorded by the canonical task authority.',
                'status' => ucfirst((string) $exception->status),
                'source' => 'operational_exception',
                'options' => array('Acknowledge, resolve or dismiss through the canonical exception transition.'),
                'action_keys' => array('student-task-acknowledge','student-task-resolve','student-task-dismiss'),
                'exception_id' => (int) $exception->id,
                'severity' => (string) $exception->severity,
            );
        }
        return $tasks;
    }

    private function paymentState(string $offerState, int $outstanding, int $paid, ?string $nextDue, string $plan): string {
        if ($offerState === '') return 'No payment due';
        if ($paid > 0 && $outstanding <= 0) return 'Paid';
        if ($outstanding <= 0) return 'No payment due';
        if ($nextDue !== null && $nextDue !== '' && $nextDue < gmdate('Y-m-d H:i:s')) return 'Overdue';
        if ($plan === 'two_instalments') return $paid > 0 ? 'Instalment due' : 'Instalment plan';
        return 'Due';
    }

    private function paymentLabel(string $status, int $outstanding, string $currency, ?string $dueAt, string $plan): string {
        if ($status === 'Paid' || $status === 'No payment due') return $status;
        $amount = $currency !== '' ? $currency . ' ' . number_format_i18n($outstanding / 100, 2) : number_format_i18n($outstanding / 100, 2) . ' minor units';
        $suffix = $dueAt ? ' · due ' . $this->humanDate($dueAt, 'j M Y') : '';
        if ($status === 'Overdue') return 'Overdue · ' . $amount . $suffix;
        if ($status === 'Instalment due') return 'Instalment · ' . $amount . $suffix;
        if ($status === 'Instalment plan') return 'Instalment plan · ' . $amount . $suffix;
        return 'Due · ' . $amount . $suffix;
    }

    private function renewalLabel(mixed $state, mixed $date): string {
        if ($state === null || $state === '') return 'No renewal cycle';
        $label = ucfirst(str_replace('_', ' ', (string) $state));
        return $date ? $label . ' · ' . $this->humanDate($date, 'j M Y') : $label;
    }

    private function humanDate(mixed $value, string $format = 'j M Y H:i'): string {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || str_starts_with($value, '1970-')) return '—';
        try {
            $utc = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return function_exists('wp_date') ? wp_date($format, $utc->getTimestamp(), new \DateTimeZone('UTC')) : $utc->format($format);
        } catch (\Throwable) { return $value; }
    }

    private function require(): void {
        if (!$this->can(self::CAP)) throw new \RuntimeException('Unauthorized');
    }

    private function can(string $capability): bool {
        return function_exists('current_user_can') ? current_user_can($capability) : false;
    }
}
