<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourTeacherWindowProjection;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherRateReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceStatementRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

/**
 * Read-only projection for the consolidated Teachers workspace.
 *
 * Every value is derived from the owning canonical record: Teacher profile,
 * onboarding state, principal link, invitation generation, availability facts,
 * canonical enrolment/assignment/lesson/attendance, booking responses and the
 * finance rate/statement authority. This service never writes and never guesses
 * a payment, conversion or teaching outcome that the recorded facts cannot prove.
 */
final class TeacherWorkspaceReadService {
    private const MANAGE_TEACHERS = 'dzn_manage_teachers';
    private const MANAGE_ONBOARDING = 'dzn_manage_onboarding';
    private const ISSUE_INVITATIONS = 'dzn_issue_teacher_invitations';
    private const REVOKE_INVITATIONS = 'dzn_revoke_teacher_invitations';
    private const VIEW_FINANCE = 'dzn_view_finance_authority';
    private const CONVERSION_WINDOW_DAYS = 30;
    private const RESPONSE_WINDOW_DAYS = 90;

    /** Whether this actor may read and maintain the full Teacher record. */
    public static function canManageTeachers(): bool {
        return current_user_can(self::MANAGE_TEACHERS);
    }

    public static function canReadOnboarding(): bool {
        return current_user_can(self::MANAGE_ONBOARDING)
            || current_user_can(self::ISSUE_INVITATIONS)
            || current_user_can(self::REVOKE_INVITATIONS);
    }

    public static function canReadFinance(): bool {
        return current_user_can(self::VIEW_FINANCE);
    }

    /** Current calendar-month bounds in the academy timezone, returned as UTC instants. */
    public function monthBounds(): array {
        $timezone = 'Asia/Tehran';
        try { $timezone = (string) (BookingAvailabilityPolicy::current()['academy_timezone'] ?? $timezone); } catch (\Throwable) {}
        try { $zone = new \DateTimeZone($timezone); } catch (\Throwable) { $zone = new \DateTimeZone('Asia/Tehran'); $timezone = 'Asia/Tehran'; }
        $start = new \DateTimeImmutable('first day of this month 00:00:00', $zone);
        $end = $start->modify('+1 month');
        $utc = new \DateTimeZone('UTC');
        return array(
            'timezone' => $timezone,
            'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'label' => $start->format('F Y'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->modify('-1 day')->format('Y-m-d'),
        );
    }

    /**
     * Complete active Teacher directory (archived records require the explicit recovery view).
     *
     * @return list<object>
     */
    public function directory(bool $archived = false): array {
        if (!self::canManageTeachers()) throw new \RuntimeException('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $where = $archived
            ? "(t.status='archived' OR t.archived_at IS NOT NULL)"
            : "t.archived_at IS NULL AND t.status<>'archived' AND NOT (o.state='reset_pending' AND (i.id IS NULL OR i.status IN ('revoked','superseded')) AND l.id IS NULL)";
        $rows = $wpdb->get_results(
            "SELECT t.*,o.state onboarding_state,o.readiness_state,o.profile_state,o.availability_state,o.submitted_at,o.reviewed_at,
                    l.wordpress_user_id linked_user_id,l.status principal_status,
                    a.state accepting_state,ap.timezone availability_timezone,ap.status availability_profile_status,
                    i.id invitation_id,i.status invitation_status,i.current_generation_number,
                    g.status generation_status,g.expires_at generation_expires_at,g.delivery_attempt_count,
                    ob.status delivery_status,ob.failure_reason_code delivery_failure_reason,ob.processed_at delivery_processed_at,
                    (SELECT c.connection_state FROM {$p}integration_connections c WHERE c.teacher_id=t.id AND c.provider_code='google_calendar' AND c.active_slot=1 ORDER BY c.id DESC LIMIT 1) google_calendar_state
             FROM {$p}teachers t
             LEFT JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$p}teacher_principal_links l ON l.teacher_id=t.id AND l.status='active' AND l.revoked_at IS NULL
             LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
             LEFT JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id
             LEFT JOIN {$p}teacher_invitations i ON i.teacher_id=t.id
             LEFT JOIN {$p}teacher_invitation_generations g ON g.invitation_id=i.id AND g.generation_number=i.current_generation_number
             LEFT JOIN {$p}platform_outbox ob ON ob.id=(SELECT MAX(o2.id) FROM {$p}platform_outbox o2 WHERE o2.generation_id=g.id AND o2.event_type='teacher_invitation.delivery_requested')
             WHERE {$where}
             ORDER BY t.id"
        ) ?: array();
        if ($wpdb->last_error !== '') throw new \RuntimeException('Teacher directory could not be read.');
        if (!$rows) return array();
        $ids = array_map(static fn(object $row): int => (int) $row->id, $rows);
        $stats = $this->bulkStats($ids);
        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->stats = $stats['teachers'][(int) $row->id] ?? $this->emptyStats();
            $row->display_name_effective = $this->displayName($row);
            $row->directory_status = $this->directoryStatus($row);
            $row->payment = self::canReadFinance()
                ? ($stats['payment'][(int) $row->id] ?? $this->paymentState(null, false))
                : array('state' => 'restricted', 'label' => 'Not available', 'detail' => 'Finance authority is required to view payment status.');
            $row->stats['payment'] = $row->payment;
            $row->availability_label = $this->availabilityLabel($row);
            $row->onboarding_label = $this->onboardingLabel($row);
            $row->finance = array('current_rate' => self::canReadFinance() ? ($row->stats['current_rate'] ?? null) : null);
            $row->stats['golden'] = $this->goldenCoverage((int) $row->id, (string) ($row->availability_timezone ?: $row->timezone));
            $row->stats['students'] = (int) ($row->stats['students'] ?? 0);
            $row->stats['instruments'] = (string) ($row->stats['instruments'] ?? '');
        }
        usort($rows, function (object $left, object $right): int {
            $rank = static function (object $row): int {
                if ($row->directory_status === 'active') return 0;
                if (in_array($row->directory_status, array('invitation_queued', 'invitation_available', 'onboarding_required', 'not_invited'), true)) return 1;
                return 2;
            };
            return $rank($left) <=> $rank($right)
                ?: strnatcasecmp($left->display_name_effective, $right->display_name_effective)
                ?: ((int) $left->id <=> (int) $right->id);
        });
        return $rows;
    }

    /** @return list<object> Archived records, newest archive first. */
    public function archivedDirectory(): array {
        $rows = $this->directory(true);
        usort($rows, static fn(object $a, object $b): int => strcmp((string) ($b->archived_at ?? ''), (string) ($a->archived_at ?? '')));
        return $rows;
    }

    /** Full detail for one Teacher, composed from every owning read model. */
    public function profile(int $teacherId): ?array {
        if (!self::canManageTeachers()) throw new \RuntimeException('Unauthorized');
        $all = $this->directory(false);
        $record = null;
        foreach ($all as $row) if ((int) $row->id === $teacherId) { $record = $row; break; }
        if ($record === null) {
            foreach ($this->archivedDirectory() as $row) if ((int) $row->id === $teacherId) { $record = $row; break; }
        }
        if ($record === null) return null;
        $onboarding = $this->onboardingForTeacher($teacherId);
        return array(
            'record' => $record,
            'onboarding' => $onboarding,
            'availability' => $this->availability($teacherId),
            'eligibility' => $this->eligibility($teacherId),
            'students' => $this->students($teacherId),
            'classes' => $this->classes($teacherId, (string) ($record->availability_timezone ?: $record->timezone ?: 'UTC')),
            'tasks' => $this->tasks($record, $onboarding),
            'response' => $record->stats['response'] ?? $this->emptyResponse(),
            'finance' => $this->finance($teacherId),
            'account' => $this->account($record),
            'unavailability_conflicts' => $this->futureUnavailabilityConflicts($teacherId),
            'protected_history' => (new TeacherOnboardingLifecycleService())->protectedHistory($teacherId),
        );
    }

    /** @return array<string,mixed>|null */
    public function onboardingForTeacher(int $teacherId): ?array {
        if (!self::canReadOnboarding()) return null;
        try {
            foreach ((new TeacherOnboardingWorkspaceReadService())->rows(200) as $row) {
                if ((int) ($row['teacher']->teacher_id ?? 0) === $teacherId) return $row;
            }
        } catch (\Throwable) {}
        return null;
    }

    /**
     * One consolidated current-state onboarding row per Teacher.
     *
     * @return array{rows:list<array<string,mixed>>,users:list<object>,can_link:bool,can_invite:bool,can_revoke:bool}
     */
    public function onboardingOverview(): array {
        if (!self::canReadOnboarding()) return array('rows' => array(), 'users' => array(), 'can_link' => false, 'can_invite' => false, 'can_revoke' => false);
        $rows = array();
        $workspace = array();
        if (current_user_can(self::MANAGE_ONBOARDING)) {
            try {
                foreach ((new TeacherOnboardingWorkspaceReadService())->rows(200) as $item) {
                    $workspace[(int) ($item['teacher']->teacher_id ?? 0)] = $item;
                }
            } catch (\Throwable) {}
        }
        $invitations = array();
        try {
            foreach ((new PrincipalInvitationReadService())->rows(1, 100) as $invitation) {
                $invitations[(int) $invitation->teacher_id] = $invitation;
            }
        } catch (\Throwable) {}
        $teacherIds = array_values(array_unique(array_merge(array_keys($workspace), array_keys($invitations))));
        if ($teacherIds === array()) return array('rows' => array(), 'users' => $this->eligibleWordpressUsers(), 'can_link' => current_user_can(self::MANAGE_ONBOARDING), 'can_invite' => current_user_can(self::ISSUE_INVITATIONS), 'can_revoke' => current_user_can(self::REVOKE_INVITATIONS));
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $marks = implode(',', array_map('intval', $teacherIds));
        $teacherRows = $wpdb->get_results(
            "SELECT t.id,t.display_name,t.english_name,t.persian_name,t.email,t.timezone,t.status,t.archived_at,
                    o.state onboarding_state,o.readiness_state,o.profile_state,o.availability_state,
                    l.wordpress_user_id linked_user_id,l.status principal_status,
                    ap.timezone availability_timezone,a.state accepting_state,
                    (SELECT COUNT(*) FROM {$p}teacher_availability_rules r WHERE r.teacher_id=t.id AND r.status='active' AND r.state IN ('preferred','requestable')) availability_rules
             FROM {$p}teachers t
             LEFT JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$p}teacher_principal_links l ON l.teacher_id=t.id AND l.status='active' AND l.revoked_at IS NULL
             LEFT JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id
             LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
             WHERE t.id IN ({$marks})"
        ) ?: array();
        $teachers = array();
        foreach ($teacherRows as $teacher) $teachers[(int) $teacher->id] = $teacher;
        foreach ($teacherIds as $teacherId) {
            $item = $workspace[$teacherId] ?? null;
            $invitation = $invitations[$teacherId] ?? null;
            $teacher = $item['teacher'] ?? $teachers[$teacherId] ?? null;
            if (!$teacher) continue;
            if ((string) ($teacher->archived_at ?? '') !== '' || (string) ($teacher->status ?? '') === 'archived') continue;
            $terminalInvitation = $invitation !== null && in_array((string) ($invitation->lifecycle_status ?? ''), array('revoked', 'superseded', 'teacher_archived'), true);
            $hasOnboarding = $item !== null && trim((string) ($item['teacher']->state ?? '')) !== '';
            if ($terminalInvitation && !$hasOnboarding) continue;
            if ($terminalInvitation) $invitation = null;
            if ($item !== null && (string) ($item['teacher']->state ?? '') === 'reset_pending' && $invitation === null) continue;
            $name = $this->displayName($teacher);
            $email = (string) ($teacher->email ?? '');
            $progress = is_array($item['progress'] ?? null) ? $item['progress'] : array();
            $google = is_array($item['google'] ?? null) ? $item['google'] : array();
            $linked = (string) ($teacher->principal_status ?? '') === 'active' || (int) ($teacher->linked_user_id ?? 0) > 0;
            $availabilityRules = (int) ($teachers[$teacherId]->availability_rules ?? 0);
            $availabilityTimezone = (string) ($teachers[$teacherId]->availability_timezone ?: $teacher->timezone ?? '');
            $availabilityState = (string) ($progress['availability_state'] ?? ((string) ($teacher->availability_state ?? '') !== '' ? $teacher->availability_state : ''));
            $validation = $this->invitationState($invitation, $teacher);
            $delivery = $this->deliveryState($invitation);
            $blockers = is_array($item['blockers'] ?? null) ? $item['blockers'] : array();
            if (!$blockers && !$linked && $validation['state'] === 'not_sent') $blockers[] = 'No invitation has been issued.';
            $nextAction = (string) ($item['next_action'] ?? $validation['next_action']);
            $rows[] = array(
                'teacher_id' => $teacherId,
                'name' => $name,
                'email' => $email,
                'linked' => $linked,
                'account_label' => $linked ? 'Linked' : 'Not linked',
                'account_state' => $linked ? 'complete' : ($validation['state'] === 'not_sent' ? 'pending' : 'warning'),
                'invitation' => $validation,
                'accepted' => $validation['accepted'],
                'profile' => $this->readinessState((string) ($progress['profile_state'] ?? $teacher->profile_state ?? '')),
                'availability' => $this->readinessState($availabilityState),
                'availability_detail' => array(
                    'timezone' => $availabilityTimezone,
                    'rules' => $availabilityRules,
                    'golden' => $this->goldenCoverage($teacherId, $availabilityTimezone),
                ),
                'google' => $google !== array() ? $google : array('label' => 'Google account not connected', 'ready_for_activation' => false, 'meet_caveat' => 'A Google account connection does not prove that Google Meet is operational.'),
                'expiry' => $this->expiryState($invitation),
                'delivery' => $delivery,
                'blockers' => $blockers,
                'next_action' => $nextAction,
                'review' => $this->reviewState($teacher, $progress),
                'protected_history' => current_user_can(self::MANAGE_TEACHERS) ? (new TeacherOnboardingLifecycleService())->protectedHistory($teacherId) : array(),
                'archived' => ((string) ($teacher->archived_at ?? '') !== '' || (string) ($teacher->status ?? '') === 'archived'),
            );
        }
        usort($rows, static function (array $left, array $right): int {
            $rank = static function (array $row): int {
                if (($row['review']['state'] ?? '') === 'pending_review') return 0;
                if (($row['blockers'] ?? array()) !== array()) return 1;
                if (($row['linked'] ?? false) !== true) return 2;
                return 3;
            };
            return $rank($left) <=> $rank($right) ?: strnatcasecmp((string) $left['name'], (string) $right['name']);
        });
        return array(
            'rows' => $rows,
            'users' => $this->eligibleWordpressUsers(),
            'can_link' => current_user_can(self::MANAGE_ONBOARDING),
            'can_invite' => current_user_can(self::ISSUE_INVITATIONS),
            'can_revoke' => current_user_can(self::REVOKE_INVITATIONS),
            'can_manage' => current_user_can(self::MANAGE_TEACHERS),
        );
    }

    /** Eligible WordPress accounts for the verified manual-link flow. */
    public function eligibleWordpressUsers(): array {
        if (!current_user_can(self::MANAGE_ONBOARDING)) return array();
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return $wpdb->get_results(
            "SELECT u.ID,u.display_name,u.user_login,u.user_email
             FROM {$wpdb->users} u
             WHERE NOT EXISTS (SELECT 1 FROM {$p}teacher_principal_links l WHERE l.wordpress_user_id=u.ID AND l.status='active')
               AND NOT EXISTS (SELECT 1 FROM {$p}student_principal_links s WHERE s.wordpress_user_id=u.ID AND s.status='active')
             ORDER BY u.display_name,u.ID LIMIT 300"
        ) ?: array();
    }

    /** @return array<string,mixed> */
    private function availability(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teacher_availability_profiles WHERE teacher_id=%d", $teacherId));
        $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND status='active' ORDER BY weekday,local_start_time,id", $teacherId)) ?: array();
        $exceptions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}teacher_availability_exceptions WHERE teacher_id=%d AND status='active' ORDER BY local_date,local_start_time,id", $teacherId)) ?: array();
        return array('profile' => $profile, 'rules' => $rules, 'exceptions' => $exceptions, 'golden' => $this->goldenCoverage($teacherId, $profile ? (string) $profile->timezone : null));
    }

    /** @return list<object> Active and historical course eligibility rows with catalogue labels. */
    private function eligibility(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id,e.status,e.effective_from,e.effective_until,e.reason_code,
                    c.id course_id,COALESCE(NULLIF(c.name_en,''),c.name_fa,'Course') course_name,c.course_type,c.default_duration_minutes,c.default_buffer_minutes,
                    i.id instrument_id,COALESCE(NULLIF(i.name_en,''),i.name_fa,'Instrument') instrument_name,
                    (SELECT COUNT(*) FROM {$p}enrolments en WHERE en.teacher_id=e.teacher_id AND en.course_id=e.course_id AND en.archived_at IS NULL AND (en.lifecycle_state IN ('authorised','current','paused') OR (en.record_model='legacy_phase1' AND en.status IN ('active','paused','scheduled')))) current_students
             FROM {$p}teacher_course_eligibilities e
             INNER JOIN {$p}courses c ON c.id=e.course_id
             INNER JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE e.teacher_id=%d
             ORDER BY e.status='active' DESC,i.name_en,i.name_fa,c.name_en,c.name_fa",
            $teacherId
        )) ?: array();
    }

    /** @return list<object> */
    private function students(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $canonical = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id enrolment_id,e.reference_code enrolment_reference,e.lifecycle_state enrolment_state,
                    s.id student_id,COALESCE(NULLIF(s.display_name,''),CONCAT_WS(' ',s.first_name,s.last_name),'Student') student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa,'Course') course_name,
                    COALESCE(NULLIF(instr.name_en,''),instr.name_fa,'Instrument') instrument_name,
                    t.id term_id,t.sequence_number,t.lifecycle_state term_state,t.lesson_allocation,t.starts_at,t.ends_at,
                    (SELECT COUNT(*) FROM {$p}lessons l WHERE l.term_id=t.id AND l.lifecycle_state='completed' AND l.archived_at IS NULL) completed_lessons,
                    (SELECT MIN(v.starts_at_utc) FROM {$p}lessons l2 INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l2.id AND v.applicable_slot=1 WHERE l2.term_id=t.id AND l2.lifecycle_state='authorised' AND v.starts_at_utc>UTC_TIMESTAMP()) next_lesson_utc
             FROM {$p}enrolments e
             INNER JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
             INNER JOIN {$p}students s ON s.id=e.student_id
             INNER JOIN {$p}courses c ON c.id=e.course_id
             LEFT JOIN {$p}instruments instr ON instr.id=c.instrument_id
             LEFT JOIN {$p}terms t ON t.id=(SELECT t2.id FROM {$p}terms t2 WHERE t2.enrolment_id=e.id ORDER BY t2.sequence_number DESC,t2.id DESC LIMIT 1)
             WHERE e.teacher_id=%d AND e.archived_at IS NULL AND e.record_model='canonical_student_course_v1'
               AND e.lifecycle_state IN ('authorised','current','paused')
             ORDER BY s.display_name,e.id",
            $teacherId
        )) ?: array();
        foreach ($canonical as $row) {
            $row->completed_lessons = (int) $row->completed_lessons;
            $row->remaining_lessons = max(0, (int) $row->lesson_allocation - $row->completed_lessons);
            $row->instrument_name = (string) ($row->instrument_name ?: $row->course_name);
        }
        return $canonical;
    }

    /** @return array<string,list<object>> */
    private function classes(int $teacherId, string $timezone = 'UTC'): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.reference_code class_reference,l.lesson_type,l.record_model,l.lifecycle_state,l.status,
                    s.display_name student_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,v.local_wall_date,v.local_wall_time,
                    d.outcome_code,d.delivery_state
             FROM {$p}lessons l
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}canonical_lesson_delivery_outcomes d ON d.lesson_id=l.id AND d.applicable_slot=1
             WHERE l.teacher_id=%d AND l.archived_at IS NULL
             ORDER BY COALESCE(v.starts_at_utc,d.occurrence_starts_at_utc) DESC,l.id DESC LIMIT 300",
            $teacherId
        )) ?: array();
        $now = gmdate('Y-m-d H:i:s');
        try { $today = (new \DateTimeImmutable('today', new \DateTimeZone($timezone)))->format('Y-m-d'); } catch (\Throwable) { $today = gmdate('Y-m-d'); }
        $result = array('today' => array(), 'upcoming' => array(), 'recent' => array(), 'replacements' => array(), 'attendance' => array());
        foreach ($lessons as $lesson) {
            $start = (string) ($lesson->starts_at_utc ?? '');
            $isFuture = $start !== '' && $start > $now;
            $isToday = (string) ($lesson->local_wall_date ?? '') === $today;
            if ($lesson->lesson_type === 'replacement') $result['replacements'][] = $lesson;
            if ($isFuture) $result['upcoming'][] = $lesson;
            elseif ((string) $lesson->lifecycle_state === 'completed' || (string) ($lesson->status ?? '') === 'completed') $result['recent'][] = $lesson;
            if ($isToday) $result['today'][] = $lesson;
        }
        $result['attendance'] = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id,c.lesson_id,c.schedule_version_id,c.case_version,c.state,c.occurrence_start_utc,c.occurrence_end_utc,c.window_end_utc,
                    s.display_name student_name,l.reference_code
             FROM {$p}canonical_attendance_cases c
             INNER JOIN {$p}students s ON s.id=c.student_id
             LEFT JOIN {$p}lessons l ON l.id=c.lesson_id
             WHERE c.teacher_id=%d AND c.state IN ('open','ready_for_review','settlement_pending')
             ORDER BY c.occurrence_start_utc,c.id LIMIT 20",
            $teacherId
        )) ?: array();
        $result['reschedules'] = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.uid proposal_uid,r.lesson_id,r.state,r.created_at,r.proposed_starts_at_utc,r.proposed_ends_at_utc,r.schedule_timezone,
                    COALESCE(NULLIF(s.display_name,''),'Student') student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa,'Class') course_name
             FROM {$p}lesson_reschedule_proposals r
             LEFT JOIN {$p}students s ON s.id=r.student_id
             LEFT JOIN {$p}lessons l ON l.id=r.lesson_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             WHERE r.teacher_id=%d AND r.state IN ('pending','rejected','failed')
             ORDER BY r.state='pending' DESC,r.created_at DESC LIMIT 30",
            $teacherId
        )) ?: array();
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function tasks(object $record, ?array $onboarding): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $teacherId = (int) $record->id;
        $tasks = array();
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id,d.booking_request_id,d.created_at,r.reference_code,r.requested_instrument_id
             FROM {$p}booking_request_teacher_deliveries d
             INNER JOIN {$p}booking_requests r ON r.id=d.booking_request_id
             WHERE d.teacher_id=%d AND d.state='pending_request' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL
             ORDER BY d.created_at LIMIT 10",
            $teacherId
        )) ?: array();
        foreach ($pending as $row) $tasks[] = array('task' => 'Booking request awaiting a Teacher decision', 'created_at' => (string) $row->created_at, 'priority' => 'high', 'status' => 'Pending', 'action' => 'Open booking request', 'url' => admin_url('admin.php?page=dzn-booking-requests&id=' . (int) ($row->booking_request_id ?? $row->id)));
        $reschedules = $wpdb->get_results($wpdb->prepare(
            "SELECT id,created_at,state,lesson_id FROM {$p}lesson_reschedule_proposals WHERE teacher_id=%d AND state IN ('pending','rejected','failed') ORDER BY created_at DESC LIMIT 10",
            $teacherId
        )) ?: array();
        foreach ($reschedules as $row) $tasks[] = array('task' => 'Replacement or reschedule lesson needs resolution', 'created_at' => (string) $row->created_at, 'priority' => (string) $row->state === 'pending' ? 'normal' : 'high', 'status' => ucfirst((string) $row->state), 'action' => 'Open class', 'url' => admin_url('admin.php?page=dzn-lesson&id=' . (int) $row->lesson_id));
        if ((int) ($record->stats['students'] ?? 0) === 0 && (string) ($record->availability_profile_status ?? '') !== 'active') $tasks[] = array('task' => 'Weekly availability is not configured', 'created_at' => '', 'priority' => 'high', 'status' => 'Blocked', 'action' => 'Configure availability', 'url' => '#teacher-availability');
        if ((string) ($record->accepting_state ?? '') === 'paused') $tasks[] = array('task' => 'Student intake is paused', 'created_at' => '', 'priority' => 'normal', 'status' => 'Paused', 'action' => 'Review intake', 'url' => '#teacher-status');
        if ((string) ($record->delivery_status ?? '') === 'failed') $tasks[] = array('task' => 'Invitation delivery failed; a replacement may be required', 'created_at' => (string) ($record->delivery_processed_at ?? ''), 'priority' => 'high', 'status' => 'Failed', 'action' => 'Review onboarding', 'url' => '#teacher-onboarding');
        if ((string) ($record->generation_status ?? '') === 'active' && (string) ($record->generation_expires_at ?? '') !== '' && (string) $record->generation_expires_at <= gmdate('Y-m-d H:i:s')) $tasks[] = array('task' => 'Invitation expired before acceptance', 'created_at' => (string) $record->generation_expires_at, 'priority' => 'high', 'status' => 'Expired', 'action' => 'Issue replacement', 'url' => '#teacher-account');
        if ((string) ($record->google_calendar_state ?? '') === 'not_connected') $tasks[] = array('task' => 'Google account is not connected', 'created_at' => '', 'priority' => 'high', 'status' => 'Blocked', 'action' => 'Review account', 'url' => '#teacher-account');
        if ($onboarding && !empty($onboarding['blockers'])) {
            foreach (array_slice((array) $onboarding['blockers'], 0, 6) as $blocker) $tasks[] = array('task' => (string) $blocker, 'created_at' => '', 'priority' => 'high', 'status' => 'Blocked', 'action' => 'Review onboarding', 'url' => '#teacher-account');
        }
        if (self::canReadFinance()) {
            $issues = $wpdb->get_results($wpdb->prepare("SELECT id,summary,detected_at,severity FROM {$p}finance_exceptions WHERE teacher_id=%d AND state='open' ORDER BY detected_at DESC LIMIT 10", $teacherId)) ?: array();
            foreach ($issues as $row) $tasks[] = array('task' => 'Finance exception: ' . (string) $row->summary, 'created_at' => (string) $row->detected_at, 'priority' => (string) $row->severity, 'status' => 'Open', 'action' => 'Open finance', 'url' => '#teacher-finance');
            $drafts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statements WHERE teacher_id=%d AND state='draft'", $teacherId));
            if ($drafts > 0) $tasks[] = array('task' => $drafts . ' draft statement' . ($drafts === 1 ? '' : 's') . ' awaiting issue', 'created_at' => '', 'priority' => 'normal', 'status' => 'Draft', 'action' => 'Review statements', 'url' => '#teacher-finance');
        }
        usort($tasks, static function (array $a, array $b): int {
            $rank = static fn(string $priority): int => $priority === 'high' ? 0 : ($priority === 'normal' ? 1 : 2);
            return $rank($a['priority']) <=> $rank($b['priority']) ?: strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        return $tasks;
    }

    /** @return array<string,mixed> */
    private function finance(int $teacherId): array {
        $result = array('available' => self::canReadFinance(), 'rates' => array(), 'statements' => array(), 'lines' => array(), 'current_rate' => null);
        if (!self::canReadFinance()) return $result;
        try { $result['rates'] = (new TeacherRateReadService())->timeline($teacherId); } catch (\Throwable) {}
        try {
            $statementService = new TeacherStatementReadService();
            $statements = $statementService->statements($teacherId);
            $repository = new FinanceStatementRepository();
            foreach ($statements as &$statement) {
                $payment = $repository->paymentFor((int) $statement['statement_id']);
                $statement['paid_at'] = $payment ? (string) $payment->occurred_at : null;
                $statement['payment_recorded'] = $payment !== null;
                try { $statement['reconciliation'] = $statementService->statementReconciliation((int) $statement['statement_id']); }
                catch (\Throwable) { $statement['reconciliation'] = null; }
            }
            unset($statement);
            $result['statements'] = array_reverse($statements);
        } catch (\Throwable) {}
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $result['lines'] = $wpdb->get_results($wpdb->prepare(
            "SELECT line.id,line.statement_id,line.lesson_id,line.occurrence_starts_at_utc,line.lesson_kind,line.duration_minutes,
                    line.disposition,line.basis_code,line.rate_amount_minor,line.compensation_basis,line.line_amount_minor,line.currency,
                    st.reference_code statement_reference,st.state statement_state,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa,'Course') course_name,
                    COALESCE(NULLIF(inst.name_en,''),inst.name_fa,'') instrument_name,
                    s.display_name student_name
             FROM {$p}finance_statement_lines line
             INNER JOIN {$p}finance_statements st ON st.id=line.statement_id
             LEFT JOIN {$p}courses c ON c.id=line.course_id
             LEFT JOIN {$p}instruments inst ON inst.id=c.instrument_id
             LEFT JOIN {$p}lessons l ON l.id=line.lesson_id
             LEFT JOIN {$p}students s ON s.id=l.student_id
             WHERE line.teacher_id=%d
             ORDER BY line.occurrence_starts_at_utc DESC,line.id DESC LIMIT 40",
            $teacherId
        )) ?: array();
        $now = gmdate('Y-m-d H:i:s');
        foreach ((array) ($result['rates']['rates'] ?? array()) as $rate) {
            if ((string) $rate['status'] !== 'active') continue;
            if ((string) $rate['effective_from'] > $now) continue;
            if (!empty($rate['effective_until']) && (string) $rate['effective_until'] <= $now) continue;
            $result['current_rate'] = $rate;
            break;
        }
        return $result;
    }

    /** @return list<object> Active course options for scoped finance and eligibility controls. */
    public function activeCourses(): array {
        if (!self::canReadFinance()) return array();
        global $wpdb;
        return $wpdb->get_results("SELECT id,COALESCE(NULLIF(name_en,''),name_fa) name FROM {$wpdb->prefix}dzn_courses WHERE status='active' AND archived_at IS NULL ORDER BY name") ?: array();
    }

    /** @return array<string,mixed> */
    private function account(object $record): array {
        $userId = (int) ($record->linked_user_id ?: $record->wordpress_user_id ?: 0);
        $user = $userId > 0 ? get_userdata($userId) : null;
        return array(
            'wordpress_user_id' => $userId,
            'display_name' => $user ? (string) $user->display_name : '',
            'email' => $user ? (string) $user->user_email : '',
            'roles' => $user ? array_values((array) $user->roles) : array(),
            'active' => $user !== null,
        );
    }

    /** @return array<string,mixed> */
    private function invitationState(?object $invitation, object $teacher): array {
        $state = $invitation ? (string) ($invitation->lifecycle_status ?? 'unavailable') : 'not_sent';
        $accepted = in_array($state, array('account_linked', 'claimed'), true)
            || (string) ($teacher->principal_status ?? '') === 'active'
            || (int) ($teacher->linked_user_id ?? 0) > 0;
        $next = match ($state) {
            'queued_for_delivery' => 'Await delivery and account claim.',
            'claim_available' => 'Await the Teacher to accept the invitation.',
            'account_linked', 'claimed' => 'Continue onboarding.',
            'expired' => 'Issue a replacement invitation.',
            'revoked', 'superseded' => 'Issue a fresh invitation or link a verified account.',
            'teacher_archived' => 'Restore the Teacher before inviting again.',
            'unavailable', 'not_sent' => 'Send an invitation or link a verified account.',
            default => 'Review the invitation state.',
        };
        return array(
            'state' => $state,
            'label' => $this->invitationLabel($state),
            'accepted' => $accepted,
            'next_action' => $next,
        );
    }

    private function invitationLabel(string $state): string {
        return match ($state) {
            'queued_for_delivery' => 'Queued for delivery',
            'claim_available' => 'Sent · awaiting acceptance',
            'account_linked', 'claimed' => 'Accepted',
            'expired' => 'Expired',
            'revoked' => 'Revoked',
            'superseded' => 'Replaced',
            'teacher_archived' => 'Revoked (archived)',
            'unavailable' => 'No claimable invitation',
            'not_sent' => 'Not sent',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private function expiryState(?object $invitation): array {
        if (!$invitation) return array('state' => 'unknown', 'label' => 'Not available');
        $expires = (string) ($invitation->generation_expires_at ?? '');
        $lifecycle = (string) ($invitation->lifecycle_status ?? '');
        if ($expires === '') return array('state' => 'unknown', 'label' => 'No expiry recorded');
        if (in_array($lifecycle, array('expired', 'revoked', 'superseded', 'teacher_archived'), true)) return array('state' => 'error', 'label' => 'Expired or closed');
        if ($expires <= gmdate('Y-m-d H:i:s')) return array('state' => 'error', 'label' => 'Expired');
        return array('state' => 'complete', 'label' => 'Valid until ' . $this->shortDate($expires));
    }

    private function deliveryState(?object $invitation): array {
        $status = $invitation ? (string) ($invitation->delivery_status ?? '') : '';
        $failure = $invitation ? (string) ($invitation->delivery_failure_reason ?? '') : '';
        if (in_array($status, array('delivered'), true)) return array('state' => 'complete', 'label' => 'Accepted by mail transport');
        if (in_array($status, array('failed'), true)) return array('state' => 'error', 'label' => 'Failed' . ($failure !== '' ? ': ' . $failure : ''));
        if (in_array($status, array('pending', 'prepared'), true)) return array('state' => 'pending', 'label' => $status === 'prepared' ? 'Prepared' : 'Queued');
        if (($invitation ? ($invitation->lifecycle_status ?? '') : '') === 'claim_available') return array('state' => 'complete', 'label' => 'Accepted by mail transport');
        return array('state' => 'unknown', 'label' => 'No delivery evidence');
    }

    private function readinessState(string $state): array {
        if ($state === 'complete') return array('state' => 'complete', 'label' => 'Complete');
        if ($state === 'incomplete') return array('state' => 'pending', 'label' => 'Incomplete');
        if ($state === '') return array('state' => 'unknown', 'label' => 'Not available');
        return array('state' => 'pending', 'label' => ucfirst(str_replace('_', ' ', $state)));
    }

    private function reviewState(object $teacher, array $progress): array {
        $state = (string) ($teacher->onboarding_state ?? $teacher->state ?? '');
        $readiness = (string) ($teacher->readiness_state ?? '');
        if ($state === 'pending_review') return array('state' => 'pending_review', 'label' => 'Awaiting review');
        if ($state === 'active' && $readiness === 'ready') return array('state' => 'approved', 'label' => 'Approved');
        if ($state === 'returned') return array('state' => 'warning', 'label' => 'Returned for changes');
        if ($state === 'rejected') return array('state' => 'error', 'label' => 'Rejected');
        if ($state === 'offboarded') return array('state' => 'error', 'label' => 'Access revoked');
        if (!empty($progress['ready_for_activation'])) return array('state' => 'ready', 'label' => 'Ready to submit');
        return array('state' => 'pending', 'label' => 'Not ready');
    }

    private function directoryStatus(object $row): string {
        if ((string) ($row->archived_at ?? '') !== '' || (string) ($row->status ?? '') === 'archived') return 'archived';
        if ((string) ($row->status ?? '') === 'inactive') return 'inactive';
        if ((string) ($row->onboarding_state ?? '') === 'active' && (string) ($row->readiness_state ?? '') === 'ready' && (string) ($row->principal_status ?? '') === 'active') return 'active';
        if ((string) ($row->onboarding_state ?? '') === 'offboarded') return 'access_revoked';
        if ((string) ($row->generation_status ?? '') === 'queued_for_delivery') return 'invitation_queued';
        if ((string) ($row->generation_status ?? '') === 'active' && (string) ($row->generation_expires_at ?? '') > gmdate('Y-m-d H:i:s')) return 'invitation_available';
        if ((int) ($row->linked_user_id ?? 0) > 0) return 'onboarding_required';
        return 'not_invited';
    }

    private function onboardingLabel(object $row): string {
        return match ($this->directoryStatus($row)) {
            'active' => 'Active',
            'inactive' => 'Unavailable',
            'archived' => 'Archived',
            'invitation_queued' => 'Invitation queued',
            'invitation_available' => 'Invitation pending',
            'onboarding_required' => 'Onboarding',
            'access_revoked' => 'Access revoked',
            'not_invited' => 'Not invited',
            default => 'Not recorded',
        };
    }

    private function availabilityLabel(object $row): string {
        $rules = (int) ($row->stats['availability_rules'] ?? 0);
        if ($rules < 1) return 'No weekly windows';
        $timezone = (string) ($row->availability_timezone ?: $row->timezone ?: '');
        $summary = trim((string) ($row->stats['availability_summary'] ?? ''));
        return ($summary !== '' ? $summary : number_format_i18n($rules) . ' window' . ($rules === 1 ? '' : 's')) . ($timezone !== '' ? ' · ' . $timezone : '');
    }

    /** @return array<string,mixed> */
    private function goldenCoverage(int $teacherId, ?string $timezone): array {
        $fallback = array('minutes' => 0, 'minutes_label' => '0m', 'label' => 'Not available', 'windows' => array(), 'state' => 'unavailable', 'percentage_label' => '0');
        if (($timezone ?? '') === '') return $fallback;
        try {
            $policy = (new GoldenHourPolicyService())->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, gmdate('Y-m-d H:i:s'));
            $projection = GoldenHourTeacherWindowProjection::forPolicy($policy, (string) $timezone, new \DateTimeImmutable('today', new \DateTimeZone((string) $timezone)), 7, true);
            global $wpdb;
            $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_teacher_availability_rules WHERE teacher_id=%d AND status='active' AND state IN ('preferred','requestable') ORDER BY weekday,local_start_time", $teacherId)) ?: array();
            $summary = TeacherAvailabilitySummary::build($rules, $projection);
            $windows = array();
            $weekdayLabels = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
            foreach ((array) ($summary['golden']['days'] ?? array()) as $day) {
                foreach ((array) ($day['intervals'] ?? array()) as $interval) {
                    if ((int) ($interval['selected_minutes'] ?? 0) < 1) continue;
                    $windows[] = ($weekdayLabels[(int) ($day['weekday'] ?? 0)] ?? 'Day') . ' ' . (string) ($interval['label'] ?? '');
                }
            }
            $windows = array_values(array_unique($windows));
            $minutes = (int) ($summary['golden_minutes'] ?? 0);
            return array(
                'minutes' => $minutes,
                'minutes_label' => $this->durationLabel($minutes),
                'label' => $minutes > 0 ? $this->durationLabel($minutes) . ' selected this week' : 'No golden-hour overlap',
                'windows' => array_slice($windows, 0, 6),
                'state' => (string) ($projection['state'] ?? 'unavailable'),
                'percentage_label' => (string) ($projection['percentage_label'] ?? '0'),
            );
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** @return array{teachers:array<int,array<string,mixed>>,payment:array<int,array<string,mixed>>} */
    private function bulkStats(array $teacherIds): array {
        $teachers = array();
        $payment = array();
        if ($teacherIds === array()) return array('teachers' => $teachers, 'payment' => $payment);
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $marks = implode(',', array_map('intval', $teacherIds));
        $month = $this->monthBounds();
        foreach ($teacherIds as $id) $teachers[$id] = $this->emptyStats();
        $studentRows = $wpdb->get_results(
            "SELECT teacher_id,COUNT(DISTINCT student_id) students FROM {$p}enrolments
             WHERE teacher_id IN ({$marks}) AND archived_at IS NULL
               AND ((record_model='canonical_student_course_v1' AND lifecycle_state IN ('authorised','current','paused'))
                    OR (record_model='legacy_phase1' AND status IN ('active','paused','scheduled')))
             GROUP BY teacher_id"
        ) ?: array();
        foreach ($studentRows as $row) $teachers[(int) $row->teacher_id]['students'] = (int) $row->students;
        $instruments = $wpdb->get_results(
            "SELECT e.teacher_id,GROUP_CONCAT(DISTINCT COALESCE(NULLIF(i.name_en,''),i.name_fa) ORDER BY COALESCE(NULLIF(i.name_en,''),i.name_fa) SEPARATOR ', ') instruments
             FROM {$p}teacher_course_eligibilities e
             INNER JOIN {$p}courses c ON c.id=e.course_id AND c.status='active' AND c.archived_at IS NULL
             INNER JOIN {$p}instruments i ON i.id=c.instrument_id AND i.status='active' AND i.archived_at IS NULL
             WHERE e.teacher_id IN ({$marks}) AND e.status='active'
               AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP())
               AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP())
             GROUP BY e.teacher_id"
        ) ?: array();
        foreach ($instruments as $row) $teachers[(int) $row->teacher_id]['instruments'] = (string) $row->instruments;
        $availability = $wpdb->get_results(
            "SELECT teacher_id,COUNT(*) rules FROM {$p}teacher_availability_rules WHERE teacher_id IN ({$marks}) AND status='active' AND state IN ('preferred','requestable') GROUP BY teacher_id"
        ) ?: array();
        foreach ($availability as $row) $teachers[(int) $row->teacher_id]['availability_rules'] = (int) $row->rules;
        $ruleRows = $wpdb->get_results(
            "SELECT teacher_id,weekday,local_start_time,local_end_time FROM {$p}teacher_availability_rules WHERE teacher_id IN ({$marks}) AND status='active' AND state IN ('preferred','requestable') ORDER BY teacher_id,weekday,local_start_time"
        ) ?: array();
        $summaryParts = array();
        $weekdayShort = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
        foreach ($ruleRows as $rule) {
            $summaryParts[(int) $rule->teacher_id][] = ($weekdayShort[(int) $rule->weekday] ?? 'Day') . ' ' . substr((string) $rule->local_start_time, 0, 5) . '–' . substr((string) $rule->local_end_time, 0, 5);
        }
        foreach ($summaryParts as $id => $parts) $teachers[$id]['availability_summary'] = implode('; ', array_slice($parts, 0, 6)) . (count($parts) > 6 ? ' +' . (count($parts) - 6) . ' more' : '');
        $teaching = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,SUM(TIMESTAMPDIFF(MINUTE,occurrence_starts_at_utc,occurrence_ends_at_utc)) minutes
             FROM {$p}canonical_lesson_delivery_outcomes
             WHERE teacher_id IN ({$marks}) AND applicable_slot=1 AND delivery_state='delivered'
               AND occurrence_starts_at_utc>=%s AND occurrence_starts_at_utc<%s GROUP BY teacher_id",
            $month['start_utc'], $month['end_utc']
        )) ?: array();
        foreach ($teaching as $row) $teachers[(int) $row->teacher_id]['teaching_minutes'] = (int) $row->minutes;
        $completedCounts = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,COUNT(*) completed FROM {$p}canonical_lesson_delivery_outcomes
             WHERE teacher_id IN ({$marks}) AND applicable_slot=1 AND delivery_state='delivered'
               AND occurrence_starts_at_utc>=%s AND occurrence_starts_at_utc<%s GROUP BY teacher_id",
            $month['start_utc'], $month['end_utc']
        )) ?: array();
        foreach ($completedCounts as $row) $teachers[(int) $row->teacher_id]['completed_lessons_month'] = (int) $row->completed;
        $legacyTeaching = $wpdb->get_results($wpdb->prepare(
            "SELECT l.teacher_id,SUM(TIMESTAMPDIFF(MINUTE,v.starts_at_utc,v.ends_at_utc)) minutes
             FROM {$p}lessons l INNER JOIN {$p}lesson_schedule_versions v ON v.id=l.current_schedule_version_id AND v.lesson_id=l.id AND v.superseded_at IS NULL
             WHERE l.teacher_id IN ({$marks}) AND l.record_model='legacy_phase1' AND l.status='completed' AND l.archived_at IS NULL
               AND v.starts_at_utc>=%s AND v.starts_at_utc<%s GROUP BY l.teacher_id",
            $month['start_utc'], $month['end_utc']
        )) ?: array();
        foreach ($legacyTeaching as $row) $teachers[(int) $row->teacher_id]['teaching_minutes'] += (int) $row->minutes;
        $introRows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.teacher_id,
                    COUNT(DISTINCT o.student_id) total,
                    COUNT(DISTINCT CASE WHEN p.enrolment_id IS NOT NULL THEN o.student_id END) converted,
                    COUNT(DISTINCT CASE WHEN p.enrolment_id IS NULL AND o.occurred_at>=%s THEN o.student_id END) pending
             FROM {$p}booking_request_intro_outcomes o
             LEFT JOIN {$p}post_intro_preparations p ON p.outcome_id=o.id
             WHERE o.teacher_id IN ({$marks}) AND o.outcome='delivered' AND o.occurred_at>=%s AND o.occurred_at<%s
             GROUP BY o.teacher_id",
            gmdate('Y-m-d H:i:s', time() - (self::CONVERSION_WINDOW_DAYS * DAY_IN_SECONDS)), $month['start_utc'], $month['end_utc']
        )) ?: array();
        foreach ($introRows as $row) {
            $total = (int) $row->total;
            $converted = (int) $row->converted;
            $pendingCount = (int) $row->pending;
            $unsuccessful = max(0, $total - $converted - $pendingCount);
            $teachers[(int) $row->teacher_id]['intro'] = array(
                'total' => $total,
                'converted' => $converted,
                'pending' => $pendingCount,
                'unsuccessful' => $unsuccessful,
                'window_days' => self::CONVERSION_WINDOW_DAYS,
                'rate_label' => ($converted + $unsuccessful) > 0 ? number_format_i18n(($converted / ($converted + $unsuccessful)) * 100, 1) . '%' : 'Not available',
                'rate_value' => ($converted + $unsuccessful) > 0 ? $converted / ($converted + $unsuccessful) : null,
            );
        }
        $pending = $wpdb->get_results("SELECT teacher_id,COUNT(*) c,MIN(created_at) oldest FROM {$p}booking_request_teacher_deliveries WHERE teacher_id IN ({$marks}) AND state='pending_request' GROUP BY teacher_id") ?: array();
        foreach ($pending as $row) {
            $teachers[(int) $row->teacher_id]['outstanding_requests'] = (int) $row->c;
            $teachers[(int) $row->teacher_id]['oldest_request'] = (string) $row->oldest;
        }
        $pendingReschedules = $wpdb->get_results("SELECT teacher_id,COUNT(*) c FROM {$p}lesson_reschedule_proposals WHERE teacher_id IN ({$marks}) AND state='pending' GROUP BY teacher_id") ?: array();
        foreach ($pendingReschedules as $row) $teachers[(int) $row->teacher_id]['pending_reschedules'] = (int) $row->c;
        $replacementRows = $wpdb->get_results("SELECT teacher_id,COUNT(*) c FROM {$p}lessons WHERE teacher_id IN ({$marks}) AND lesson_type='replacement' AND lifecycle_state='authorised' AND archived_at IS NULL GROUP BY teacher_id") ?: array();
        foreach ($replacementRows as $row) $teachers[(int) $row->teacher_id]['replacement_lessons'] = (int) $row->c;
        $upcoming = $wpdb->get_results("SELECT l.teacher_id,COUNT(*) c,MIN(v.starts_at_utc) next_at FROM {$p}lessons l INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1 WHERE l.teacher_id IN ({$marks}) AND l.lifecycle_state='authorised' AND l.archived_at IS NULL AND v.starts_at_utc>UTC_TIMESTAMP() GROUP BY l.teacher_id") ?: array();
        foreach ($upcoming as $row) {
            $teachers[(int) $row->teacher_id]['upcoming'] = (int) $row->c;
            $teachers[(int) $row->teacher_id]['next_lesson_utc'] = (string) $row->next_at;
        }
        $last = $wpdb->get_results("SELECT teacher_id,MAX(occurrence_ends_at_utc) last_at FROM {$p}canonical_lesson_delivery_outcomes WHERE teacher_id IN ({$marks}) AND applicable_slot=1 AND delivery_state='delivered' GROUP BY teacher_id") ?: array();
        foreach ($last as $row) $teachers[(int) $row->teacher_id]['last_class_utc'] = (string) $row->last_at;
        $responseRows = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,TIMESTAMPDIFF(SECOND,created_at,responded_at) seconds
             FROM {$p}booking_request_teacher_deliveries
             WHERE teacher_id IN ({$marks}) AND responded_at IS NOT NULL AND responded_at>=created_at AND created_at>=%s",
            gmdate('Y-m-d H:i:s', time() - (self::RESPONSE_WINDOW_DAYS * DAY_IN_SECONDS))
        )) ?: array();
        $responseTimes = array();
        foreach ($responseRows as $row) $responseTimes[(int) $row->teacher_id][] = max(0, (int) $row->seconds);
        foreach ($responseTimes as $id => $times) {
            sort($times, SORT_NUMERIC);
            $count = count($times);
            $average = (int) round(array_sum($times) / max(1, $count));
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1 ? $times[$middle] : (int) round(($times[$middle - 1] + $times[$middle]) / 2);
            $teachers[$id]['response'] = array('count' => $count, 'average_seconds' => $average, 'median_seconds' => $median, 'window_days' => self::RESPONSE_WINDOW_DAYS);
        }
        $rates = self::canReadFinance() ? ($wpdb->get_results($wpdb->prepare(
            "SELECT id,teacher_id,rate_version,scope_kind,course_scope_id,amount_minor,currency,compensation_basis,rate_origin,effective_from,effective_until,status
             FROM {$p}finance_teacher_rates
             WHERE teacher_id IN ({$marks}) AND status='active' AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s)
             ORDER BY teacher_id,CASE WHEN scope_kind='teacher' THEN 0 ELSE 1 END,effective_from DESC,id DESC",
            gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')
        )) ?: array()) : array();
        foreach ($rates as $rate) {
            $id = (int) $rate->teacher_id;
            if (!isset($teachers[$id]['current_rate'])) $teachers[$id]['current_rate'] = array(
                'rate_id' => (int) $rate->id,
                'rate_version' => (int) $rate->rate_version,
                'scope_kind' => (string) $rate->scope_kind,
                'course_scope_id' => (int) $rate->course_scope_id,
                'amount_minor' => (int) $rate->amount_minor,
                'currency' => (string) $rate->currency,
                'compensation_basis' => (string) $rate->compensation_basis,
                'rate_origin' => (string) ($rate->rate_origin ?? 'explicit'),
                'effective_from' => (string) $rate->effective_from,
                'effective_until' => $rate->effective_until === null ? null : (string) $rate->effective_until,
                'status' => (string) $rate->status,
            );
        }
        $statements = self::canReadFinance() ? ($wpdb->get_results(
            "SELECT s.teacher_id,s.id,s.state,s.period_label,s.period_start_utc,s.period_end_utc,s.payable_amount_minor,s.currency,s.issued_at,s.statement_version,
                    pe.occurred_at paid_at
             FROM {$p}finance_statements s
             LEFT JOIN {$p}finance_statement_events pe ON pe.statement_id=s.id AND pe.event_type='payment_recorded'
             WHERE s.teacher_id IN ({$marks})
               AND NOT EXISTS (SELECT 1 FROM {$p}finance_statements s2 WHERE s2.teacher_id=s.teacher_id AND (s2.period_start_utc>s.period_start_utc OR (s2.period_start_utc=s.period_start_utc AND s2.id>s.id)))"
        ) ?: array()) : array();
        $statementByTeacher = array();
        foreach ($statements as $statement) $statementByTeacher[(int) $statement->teacher_id] = $statement;
        foreach ($teacherIds as $id) {
            $statement = $statementByTeacher[$id] ?? null;
            $hasTeaching = (int) ($teachers[$id]['teaching_minutes'] ?? 0) > 0;
            $payment[$id] = $this->paymentState($statement, $hasTeaching);
            $teachers[$id]['golden_minutes'] = 0;
        }
        return array('teachers' => $teachers, 'payment' => $payment);
    }

    /** @return array<string,mixed> */
    private function paymentState(?object $statement, bool $hasTeaching): array {
        if (!$statement) {
            if ($hasTeaching) return array('state' => 'awaiting_statement', 'label' => 'Statement awaiting issuance', 'detail' => 'Teaching is recorded for this month, but no statement has been issued.');
            return array('state' => 'not_applicable', 'label' => 'Not applicable', 'detail' => 'No statement or payable teaching is recorded.');
        }
        $state = (string) $statement->state;
        $amount = (int) ($statement->payable_amount_minor ?? 0);
        $currency = (string) ($statement->currency ?? '');
        $period = (string) ($statement->period_label ?: $this->shortDate((string) $statement->period_start_utc) . ' – ' . $this->shortDate((string) $statement->period_end_utc));
        $paidAt = isset($statement->paid_at) ? (string) $statement->paid_at : '';
        if ($paidAt !== '' || $state === 'paid') return array('state' => 'paid', 'label' => 'Up to date', 'detail' => 'Payment recorded' . ($paidAt !== '' ? ' on ' . $this->shortDate($paidAt) : '') . '.', 'amount_minor' => $amount, 'currency' => $currency, 'period' => $period, 'statement_id' => (int) $statement->id);
        if ($state === 'issued') return array('state' => 'pending', 'label' => 'Payment pending', 'detail' => 'Statement issued; no payment is recorded.', 'amount_minor' => $amount, 'currency' => $currency, 'period' => $period, 'statement_id' => (int) $statement->id);
        if ($state === 'draft') return array('state' => 'awaiting_statement', 'label' => 'Statement awaiting issuance', 'detail' => 'A draft statement exists but is not issued.', 'amount_minor' => $amount, 'currency' => $currency, 'period' => $period, 'statement_id' => (int) $statement->id);
        return array('state' => 'not_applicable', 'label' => 'Not applicable', 'detail' => 'The latest statement is ' . str_replace('_', ' ', $state) . '.', 'amount_minor' => $amount, 'currency' => $currency, 'period' => $period, 'statement_id' => (int) $statement->id);
    }

    /** @return array<string,mixed> */
    private function emptyStats(): array {
        return array(
            'students' => 0, 'instruments' => '', 'availability_rules' => 0, 'availability_summary' => '', 'teaching_minutes' => 0, 'completed_lessons_month' => 0,
            'intro' => array('total' => 0, 'converted' => 0, 'pending' => 0, 'unsuccessful' => 0, 'window_days' => self::CONVERSION_WINDOW_DAYS, 'rate_label' => 'Not available', 'rate_value' => null),
            'outstanding_requests' => 0, 'oldest_request' => '', 'pending_reschedules' => 0, 'replacement_lessons' => 0,
            'upcoming' => 0, 'next_lesson_utc' => '', 'last_class_utc' => '', 'response' => $this->emptyResponse(), 'golden_minutes' => 0,
        );
    }

    private function emptyResponse(): array {
        return array('count' => 0, 'average_seconds' => null, 'median_seconds' => null, 'window_days' => self::RESPONSE_WINDOW_DAYS);
    }

    private function displayName(object $teacher): string {
        foreach (array('display_name', 'english_name', 'persian_name') as $field) {
            $value = trim((string) ($teacher->{$field} ?? ''));
            if ($value !== '') return $value;
        }
        return 'Teacher';
    }

    private function durationLabel(int $minutes): string {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours > 0 && $rest > 0) return $hours . 'h ' . $rest . 'm';
        if ($hours > 0) return $hours . 'h';
        return $rest . 'm';
    }

    private function shortDate(string $value): string {
        $timestamp = trim($value) === '' ? false : strtotime($value . ' UTC');
        return $timestamp === false ? $value : wp_date('j M Y', $timestamp);
    }

    /** Future scheduled lessons that would conflict with an unavailable period. */
    private function futureUnavailabilityConflicts(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.id lesson_id,l.reference_code,l.lesson_type,v.starts_at_utc,v.schedule_timezone,COALESCE(NULLIF(s.display_name,''),'Student') student_name,COALESCE(NULLIF(c.name_en,''),c.name_fa,'Class') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             WHERE l.teacher_id=%d AND l.archived_at IS NULL AND l.lifecycle_state IN ('authorised','completed') AND v.starts_at_utc>%s
             ORDER BY v.starts_at_utc LIMIT 20",
            $teacherId, $now
        )) ?: array();
    }
}
