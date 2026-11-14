<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\AcceptedServiceArrangementRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalAcceptanceRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityAssentRepository;

/**
 * Capability-gated, write-free projection for the Booking Coordination command centre.
 *
 * It joins only canonical records, resolves technical references server-side and returns
 * human-readable state. Every mutation remains with the owning application service.
 */
final class CoordinationWorkspaceReadService {
    private const ACCESS_CAPABILITY = 'dzn_manage_booking_request_coordination';
    private ?string $p = null;

    public function __construct(
        private ?EnrolmentWorkspaceReadService $workspace = null,
        private ?CoordinationBlockerAssessment $blockers = null,
        private ?CoordinationCaseRepository $cases = null,
        private ?ProposalRepository $proposals = null,
        private ?ProposalAcceptanceRepository $acceptances = null,
        private ?AcceptedServiceArrangementRepository $arrangements = null,
        private ?TeacherAvailabilityAssentRepository $assents = null
    ) {
        $this->workspace ??= new EnrolmentWorkspaceReadService();
        $this->blockers ??= new CoordinationBlockerAssessment();
        $this->cases ??= new CoordinationCaseRepository();
        $this->proposals ??= new ProposalRepository();
        $this->acceptances ??= new ProposalAcceptanceRepository();
        $this->arrangements ??= new AcceptedServiceArrangementRepository();
        $this->assents ??= new TeacherAvailabilityAssentRepository();
    }

    /** @return array<int,array<string,mixed>> */
    public function listCases(string $search = '', string $scope = 'active', int $limit = 100): array {
        $this->authorize();
        global $wpdb;
        $p = $this->tablePrefix();
        $search = trim($search);
        $like = '%' . $wpdb->esc_like($search) . '%';
        $closed = "('withdrawn','declined','unable_to_arrange','abandoned')";
        $where = $scope === 'closed' ? "c.state IN {$closed}" : ($scope === 'all' ? '1=1' : "c.state NOT IN {$closed}");
        $args = array();
        if ($search !== '') {
            $where .= " AND (c.reference_code LIKE %s OR r.reference_code LIKE %s OR contact.full_name LIKE %s OR s.display_name LIKE %s OR i.name_en LIKE %s OR i.name_fa LIKE %s OR course.name_en LIKE %s OR course.name_fa LIKE %s)";
            array_push($args, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT c.id case_id,c.reference_code case_reference,c.state,c.state_reason_code,c.version,c.updated_at,c.created_at,
                       r.id request_id,r.reference_code request_reference,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,r.student_id,
                       contact.full_name contact_name,contact.email contact_email,
                       s.display_name student_name,s.status student_status,
                       COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                       COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,course.course_type,
                       (SELECT COUNT(*) FROM {$p}coordination_case_candidates cc WHERE cc.coordination_case_id=c.id AND cc.status NOT IN ('not_available','not_suitable','withdrawn_from_consideration','superseded','closed')) active_candidates,
                       (SELECT COUNT(*) FROM {$p}teacher_availability_assents a WHERE a.coordination_case_id=c.id AND a.state='recorded' AND (a.valid_until IS NULL OR a.valid_until>UTC_TIMESTAMP()) AND (a.review_by IS NULL OR a.review_by>UTC_TIMESTAMP())) current_assents,
                       (SELECT COUNT(*) FROM {$p}proposal_families f INNER JOIN {$p}proposal_options o ON o.proposal_family_id=f.id WHERE f.coordination_case_id=c.id AND o.current_version_id IS NOT NULL) current_proposals,
                       (SELECT COUNT(*) FROM {$p}proposal_acceptance_events e WHERE e.coordination_case_id=c.id) acceptance_events,
                       (SELECT COUNT(*) FROM {$p}accepted_service_arrangements a WHERE a.coordination_case_id=c.id) arrangements,
                       (SELECT COUNT(*) FROM {$p}operational_exceptions oe WHERE oe.entity_type='booking_request' AND oe.entity_id=r.id AND oe.status IN ('open','acknowledged')) open_exceptions
                FROM {$p}coordination_cases c
                INNER JOIN {$p}booking_requests r ON r.id=c.booking_request_id
                LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=r.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1)
                LEFT JOIN {$p}students s ON s.id=r.student_id
                LEFT JOIN {$p}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$p}courses course ON course.id=r.selected_intro_course_id
                WHERE {$where}
                ORDER BY c.updated_at DESC,c.id DESC LIMIT %d";
        $args[] = max(1, min(250, $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: array();
        return array_map(fn(object $row): array => $this->composeRow($row), $rows);
    }

    /** @param array<string,mixed> $query @return array<int,array<string,mixed>> */
    public function openableRequests(string $search = '', int $limit = 80): array {
        $this->authorize();
        global $wpdb;
        $p = $this->tablePrefix();
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $limit = max(1, min(150, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.reference_code,r.lifecycle_status,r.resolution_state,r.student_id,r.created_at,
                    contact.full_name,contact.email,s.display_name student_name,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    EXISTS(SELECT 1 FROM {$p}coordination_cases cc WHERE cc.booking_request_id=r.id) has_case
             FROM {$p}booking_requests r
             LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=r.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1)
             LEFT JOIN {$p}students s ON s.id=r.student_id
             LEFT JOIN {$p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$p}courses c ON c.id=r.selected_intro_course_id
             WHERE r.privacy_erased_at IS NULL
               AND ((r.lifecycle_status='submitted' AND r.resolution_state='unresolved') OR (r.lifecycle_status='closed' AND r.resolution_state='teacher_confirmed'))
               AND (%s='' OR r.reference_code LIKE %s OR contact.full_name LIKE %s OR s.display_name LIKE %s OR i.name_en LIKE %s OR i.name_fa LIKE %s)
             ORDER BY r.id DESC LIMIT %d",
            trim($search), $like, $like, $like, $like, $like, $limit
        )) ?: array();
        return array_map(static function (object $row): array {
            $name = (string) ($row->student_name ?: $row->full_name ?: 'Learner');
            return array(
                'id' => (int) $row->id,
                'reference' => (string) ($row->reference_code ?: 'Request ' . (int) $row->id),
                'learner' => $name,
                'contact' => (string) ($row->email ?? ''),
                'instrument' => (string) ($row->instrument_name ?: 'Instrument'),
                'course' => (string) ($row->course_name ?: 'Introductory course'),
                'state' => (string) $row->lifecycle_status . ' / ' . (string) $row->resolution_state,
                'has_case' => (bool) $row->has_case,
                'created_at' => (string) $row->created_at,
            );
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public function caseDetail(int $caseId): ?array {
        $this->authorize();
        $case = $this->cases->caseForRead($caseId);
        if (!$case) return null;
        $base = $this->workspace->bookingCase((int) $case->booking_request_id);
        if (!$base) return null;
        $p = $this->tablePrefix();
        global $wpdb;
        $requestRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id,student_id,requested_instrument_id,selected_intro_course_id,lifecycle_status,resolution_state,privacy_erased_at,created_at,updated_at
             FROM {$p}booking_requests WHERE id=%d",
            (int) $case->booking_request_id
        ));
        if (!$requestRow) return null;
        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id,c.course_type,c.default_duration_minutes,c.default_buffer_minutes,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,i.id instrument_id
             FROM {$p}courses c LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE c.id=%d",
            (int) ($requestRow->selected_intro_course_id ?? 0)
        ));
        $courseId = (int) ($course->id ?? 0);
        $student = (int) ($requestRow->student_id ?? 0) > 0 ? $this->student((int) $requestRow->student_id) : null;
        $base['student_id'] = (int) ($requestRow->student_id ?? 0);
        $base['privacy_erased_at'] = $requestRow->privacy_erased_at;
        $identity = $this->identityState($base, $student);
        $capacity = (string) ($student->capacity_classification ?? '');
        if ($capacity === '') $capacity = 'unknown';
        $principals = $student ? $this->currentPrincipals((int) $student->id) : array();
        $guardians = $student ? $this->activeGuardians((int) $student->id) : array();
        $authority = $this->authorityRoute($capacity, $principals, $guardians);
        $candidates = $this->candidates($caseId, $courseId, $student ? (int) $student->id : 0, (array) ($base['requested_times'] ?? array()));
        $family = $this->familyForCase($caseId);
        $options = $family ? $this->proposalOptions((int) $family->id) : array();
        $acceptances = $this->acceptances->eventsForCase($caseId);
        $arrangement = $this->arrangements->arrangementForCase($caseId);
        $enrolment = $arrangement ? $this->enrolmentForArrangement((int) $arrangement->id) : null;
        $assignment = $enrolment ? $this->currentAssignment((int) $enrolment->id) : null;
        $claim = $student ? $this->claimState((int) $student->id) : array('state' => 'none', 'label' => 'No Student account');
        $payment = $this->introPayment((int) $case->booking_request_id);
        $notifications = $this->retryableNotifications((int) $case->booking_request_id);
        $exceptions = $this->operationalExceptions((int) $case->booking_request_id, (int) ($base['confirmation']['confirmation_id'] ?? 0));
        $conversion = $this->conversionState($arrangement);
        if ($enrolment) $conversion = 'converted';
        $audit = $this->auditEvents($case, $candidates, $arrangement, (int) $case->booking_request_id);
        $audit = $this->lifecycleActivity($audit, $acceptances, $arrangement, $enrolment);
        $permissions = $this->permissions();
        $facts = $this->assessmentFacts($case, $base, $identity, $student, $capacity, $authority, $candidates, $options, $acceptances, $arrangement, $enrolment, $assignment, $claim, $payment, $notifications, $exceptions, $conversion);
        $assessment = $this->blockers->assess($facts, $permissions);

        $teacherConflicts = 0; $studentConflicts = 0;
        foreach ($candidates as $candidate) {
            foreach ((array) $candidate['conflicts'] as $conflict) {
                if (!empty($conflict['teacher'])) $teacherConflicts++;
                if (!empty($conflict['student'])) $studentConflicts++;
            }
        }
        $facts['teacher_conflicts'] = $teacherConflicts;
        $facts['student_conflicts'] = $studentConflicts;
        $assessment = $this->blockers->assess($facts, $permissions);

        return array(
            'case' => array(
                'id' => (int) $case->id,
                'reference' => (string) $case->reference_code,
                'state' => (string) $case->state,
                'state_label' => $this->stateLabel((string) $case->state),
                'reason' => (string) ($case->state_reason_code ?? ''),
                'version' => (int) $case->version,
                'updated_at' => (string) $case->updated_at,
                'updated_by_name' => $this->actorName((int) ($case->updated_by ?? 0)),
                'created_at' => (string) $case->created_at,
                'closed' => in_array((string) $case->state, array('withdrawn', 'declined', 'unable_to_arrange', 'abandoned'), true),
                'privacy_erased' => !empty($base['privacy_erased_at']),
            ),
            'request' => $this->requestShape($base, $course),
            'student' => $student ? $this->studentShape($student) : null,
            'identity' => $identity,
            'capacity' => $this->capacityShape($student, $capacity),
            'authority' => array('route' => $authority, 'principals' => $principals, 'guardians' => $guardians),
            'candidates' => $candidates,
            'proposal' => array(
                'family' => $family ? array('id' => (int) $family->id, 'reference' => (string) ($family->reference_code ?? ''), 'uid' => (string) $family->uid) : null,
                'options' => $options,
            ),
            'acceptances' => array_map(fn(object $event): array => $this->acceptanceShape($event), $acceptances),
            'provisional_current' => (bool) ($facts['provisional_current'] ?? false),
            'arrangement' => $arrangement ? $this->arrangementShape($arrangement) : null,
            'enrolment' => $enrolment ? array('id' => (int) $enrolment->id, 'reference' => (string) $enrolment->reference_code, 'state' => (string) $enrolment->lifecycle_state, 'label' => $this->stateLabel((string) $enrolment->lifecycle_state)) : null,
            'assignment' => $assignment ? array('id' => (int) $assignment->id, 'state' => (string) $assignment->state, 'label' => $this->stateLabel((string) $assignment->state), 'teacher_name' => (string) ($assignment->teacher_name ?? '')) : null,
            'claim' => $claim,
            'payment' => $payment,
            'notifications' => $notifications,
            'exceptions' => $exceptions,
            'conversion' => $conversion,
            'schedule' => $this->scheduleShape($base, $candidates),
            'activity' => $audit,
            'permissions' => $permissions,
            'assessment' => $assessment,
            'facts' => $facts,
            'options' => array(
                'students' => $this->studentChoices('', 120),
                'teachers' => $this->teacherChoices($courseId),
                'accounts' => $student ? $this->accountChoices((int) $student->id, $capacity) : array(),
                'assignable_accounts' => $this->assignableAccounts(),
            ),
            'advanced' => array(
                'case_uid' => (string) $case->uid,
                'request_id' => (int) $case->booking_request_id,
                'case_id' => (int) $case->id,
                'version' => (int) $case->version,
                'family_uid' => $family ? (string) $family->uid : '',
                'option_uids' => array_map(static fn(array $option): string => (string) $option['uid'], $options),
                'assent_ids' => array_map(static fn(array $candidate): array => array('candidate_id' => (int) $candidate['id'], 'assent_id' => (int) ($candidate['assent']['id'] ?? 0)), $candidates),
            ),
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function teacherChoices(int $courseId = 0): array {
        $this->authorize();
        global $wpdb;
        $p = $this->tablePrefix();
        $eligibleSql = $courseId > 0
            ? "EXISTS(SELECT 1 FROM {$p}teacher_course_eligibilities e WHERE e.teacher_id=t.id AND e.course_id=" . (int) $courseId . " AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP()))"
            : '1=1';
        $rows = $wpdb->get_results(
            "SELECT t.id,t.reference_code,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) display_name,t.email,t.timezone,
                    o.state onboarding_state,o.readiness_state,COALESCE(a.state,'accepting') accepting_state,
                    p.timezone availability_timezone,{$eligibleSql} eligible_for_course
             FROM {$p}teachers t
             LEFT JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
             LEFT JOIN {$p}teacher_availability_profiles p ON p.teacher_id=t.id AND p.status='active'
             WHERE t.archived_at IS NULL AND t.status='active'
             ORDER BY display_name ASC LIMIT 200"
        ) ?: array();
        return array_map(static function (object $row): array {
            $ready = (string) ($row->onboarding_state ?? '') === 'active' && (string) ($row->readiness_state ?? '') === 'ready';
            return array(
                'id' => (int) $row->id,
                'name' => (string) ($row->display_name ?: 'Teacher'),
                'email' => (string) ($row->email ?? ''),
                'timezone' => (string) ($row->availability_timezone ?: $row->timezone ?: 'UTC'),
                'ready' => $ready,
                'accepting' => in_array((string) $row->accepting_state, array('accepting', 'limited'), true),
                'eligible' => (bool) $row->eligible_for_course,
                'label' => trim((string) ($row->display_name ?: 'Teacher') . (($row->email ?? '') !== '' ? ' · ' . $row->email : '')),
            );
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function studentChoices(string $search = '', int $limit = 80): array {
        $this->authorize();
        global $wpdb;
        $p = $this->tablePrefix();
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,display_name,email,status FROM {$p}students
             WHERE archived_at IS NULL AND status='active' AND (%s='' OR display_name LIKE %s OR email LIKE %s OR reference_code LIKE %s)
             ORDER BY display_name ASC LIMIT %d",
            trim($search), $like, $like, $like, max(1, min(150, $limit))
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'id' => (int) $row->id,
            'name' => (string) $row->display_name,
            'email' => (string) ($row->email ?? ''),
            'reference' => (string) ($row->reference_code ?? ''),
            'label' => trim((string) $row->display_name . (($row->reference_code ?? '') !== '' ? ' · ' . $row->reference_code : '') . (($row->email ?? '') !== '' ? ' · ' . $row->email : '')),
        ), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function accountChoices(int $studentId, string $classification, string $search = ''): array {
        $this->authorize();
        if ($studentId < 1) return array();
        global $wpdb;
        $p = $this->tablePrefix();
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $query = trim($search);
        if ($classification === 'adult') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,'Student principal' authority_kind
                 FROM {$p}student_principal_links l INNER JOIN {$wpdb->users} u ON u.ID=l.wordpress_user_id
                 WHERE l.student_id=%d AND l.status='active' AND l.active_slot=1 AND (%s='' OR u.display_name LIKE %s OR u.user_email LIKE %s)
                 ORDER BY display_name LIMIT 30",
                $studentId, $query, $like, $like
            )) ?: array();
        } else {
            $now = gmdate('Y-m-d H:i:s');
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,'Guardian representative' authority_kind
                 FROM {$p}student_acceptance_authority_grants g INNER JOIN {$wpdb->users} u ON u.ID=g.acting_wordpress_user_id
                 WHERE g.student_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope='service_acceptance'
                   AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s)
                   AND (%s='' OR u.display_name LIKE %s OR u.user_email LIKE %s)
                 ORDER BY display_name LIMIT 30",
                $studentId, $now, $now, $query, $like, $like
            )) ?: array();
        }
        return array_map(static fn(object $row): array => array(
            'id' => (int) $row->user_id,
            'name' => (string) $row->display_name,
            'kind' => (string) $row->authority_kind,
            'label' => (string) $row->display_name . ' · ' . (string) $row->authority_kind,
        ), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function assignableAccounts(string $search = ''): array {
        $this->authorize();
        global $wpdb;
        $p = $this->tablePrefix();
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name
             FROM {$wpdb->users} u
             WHERE (%s='' OR u.display_name LIKE %s OR u.user_email LIKE %s)
               AND NOT EXISTS (SELECT 1 FROM {$p}teacher_principal_links tl WHERE tl.wordpress_user_id=u.ID AND tl.status='active')
             ORDER BY display_name LIMIT 40",
            trim($search), $like, $like
        )) ?: array();
        return array_map(static fn(object $row): array => array('id' => (int) $row->user_id, 'name' => (string) $row->display_name), $rows);
    }

    public function currentAssentForCandidate(int $candidateId): ?object {
        $this->authorize();
        global $wpdb; $p = $this->tablePrefix();
        $caseId = (int) $wpdb->get_var($wpdb->prepare("SELECT coordination_case_id FROM {$p}coordination_case_candidates WHERE id=%d", $candidateId));
        if ($caseId < 1) return null;
        foreach ($this->assents->assents($caseId) as $assent) {
            if ((int) $assent->candidate_id === $candidateId && (string) $assent->state === 'recorded'
                && ($assent->valid_until === null || (string) $assent->valid_until > gmdate('Y-m-d H:i:s'))
                && ($assent->review_by === null || (string) $assent->review_by > gmdate('Y-m-d H:i:s'))) {
                return $assent;
            }
        }
        return null;
    }

    public function currentOptionVersion(int $optionId): ?array {
        $this->authorize();
        $option = $this->proposals->optionForRead($optionId);
        if (!$option) return null;
        $version = $this->proposals->currentVersionForOption($option);
        if (!$version) return null;
        global $wpdb; $p = $this->tablePrefix();
        $familyUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}proposal_families WHERE id=%d", (int) $option->proposal_family_id));
        return array('option' => $option, 'version' => $version, 'family_uid' => $familyUid, 'option_uid' => (string) $option->uid);
    }

    public function currentProvisional(int $caseId): ?object {
        $this->authorize();
        $events = $this->acceptances->eventsForCase($caseId);
        foreach (array_reverse($events) as $event) {
            if ((string) $event->event_kind === 'accepted_pending_conditions') return $event;
        }
        return null;
    }

    public function caseRow(int $caseId): ?object { return $this->cases->caseForRead($caseId); }

    private function candidates(int $caseId, int $courseId, int $studentId, array $times): array {
        $p = $this->tablePrefix();
        global $wpdb;
        $assents = $this->assents->assents($caseId);
        $family = $this->familyForCase($caseId);
        $options = $family ? $this->proposalOptions((int) $family->id) : array();
        $optionByCandidate = array();
        foreach ($options as $option) $optionByCandidate[(int) $option['candidate_id']] = $option;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    t.email teacher_email,t.timezone teacher_timezone,t.reference_code teacher_reference,
                    o.state onboarding_state,o.readiness_state,COALESCE(a.state,'accepting') accepting_state,
                    p.timezone availability_timezone,
                    EXISTS(SELECT 1 FROM {$p}teacher_course_eligibilities e WHERE e.teacher_id=t.id AND e.course_id=%d AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP())) eligible
             FROM {$p}coordination_case_candidates c
             INNER JOIN {$p}teachers t ON t.id=c.teacher_id
             LEFT JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
             LEFT JOIN {$p}teacher_availability_profiles p ON p.teacher_id=t.id AND p.status='active'
             WHERE c.coordination_case_id=%d ORDER BY c.id ASC",
            $courseId, $caseId
        )) ?: array();
        $out = array();
        foreach ($rows as $row) {
            $teacherId = (int) $row->teacher_id;
            $currentAssent = null;
            foreach ($assents as $assent) {
                if ((int) $assent->candidate_id !== (int) $row->id || (string) $assent->state !== 'recorded') continue;
                if ($assent->valid_until !== null && (string) $assent->valid_until <= gmdate('Y-m-d H:i:s')) continue;
                if ($assent->review_by !== null && (string) $assent->review_by <= gmdate('Y-m-d H:i:s')) continue;
                $currentAssent = $assent;
                break;
            }
            $availability = $this->availabilityForTeacher($teacherId, $times, $courseId);
            $conflicts = $this->conflictsFor($teacherId, $studentId, $times);
            $option = $optionByCandidate[(int) $row->id] ?? null;
            $busy = $this->futureWorkload($teacherId);
            $viable = (string) ($row->status ?? '') !== '' && !in_array((string) $row->status, array('not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed'), true)
                && (string) ($row->onboarding_state ?? '') === 'active' && (string) ($row->readiness_state ?? '') === 'ready'
                && in_array((string) $row->accepting_state, array('accepting', 'limited'), true)
                && (bool) $row->eligible && $conflicts['teacher_count'] === 0;
            $out[] = array(
                'id' => (int) $row->id,
                'teacher_id' => $teacherId,
                'teacher_reference' => (string) ($row->teacher_reference ?? ''),
                'teacher_name' => (string) ($row->teacher_name ?: 'Teacher'),
                'teacher_email' => (string) ($row->teacher_email ?? ''),
                'timezone' => (string) ($row->availability_timezone ?: $row->teacher_timezone ?: 'UTC'),
                'source' => (string) $row->source,
                'source_label' => $this->label((string) $row->source),
                'status' => (string) $row->status,
                'status_label' => $this->label((string) $row->status),
                'version' => (int) $row->version,
                'status_at' => (string) $row->status_at,
                'ready' => (string) ($row->onboarding_state ?? '') === 'active' && (string) ($row->readiness_state ?? '') === 'ready',
                'accepting' => (string) $row->accepting_state,
                'eligible' => (bool) $row->eligible,
                'viable' => $viable,
                'workload' => $busy,
                'availability' => $availability,
                'conflicts' => $conflicts['rows'],
                'teacher_conflicts' => $conflicts['teacher_count'],
                'student_conflicts' => $conflicts['student_count'],
                'assent' => $currentAssent ? array(
                    'id' => (int) $currentAssent->id,
                    'version' => (int) $currentAssent->version,
                    'fingerprint' => (string) $currentAssent->arrangement_fingerprint,
                    'recorded_at' => (string) $currentAssent->recorded_at,
                    'valid_until' => (string) ($currentAssent->valid_until ?? ''),
                    'review_by' => (string) ($currentAssent->review_by ?? ''),
                    'evidence_channel' => (string) $currentAssent->evidence_channel,
                    'label' => 'Agreement recorded · ' . $this->label((string) $currentAssent->evidence_channel),
                ) : null,
                'option' => $option,
                'blockers' => array_values(array_filter(array(
                    !$viable ? 'Candidate is not currently viable' : '',
                    $conflicts['teacher_count'] > 0 ? 'Teacher already has a class at a requested time' : '',
                    !$currentAssent ? 'No current Teacher agreement' : '',
                ))),
            );
        }
        return $out;
    }

    /** @return array{state:string,label:string,covered:int,total:int,segments:array<int,array<string,string>>,note:string} */
    private function availabilityForTeacher(int $teacherId, array $times, ?int $courseId = null): array {
        if (!$times) return array('state' => 'unknown', 'label' => 'No requested times', 'covered' => 0, 'total' => 0, 'segments' => array(), 'note' => 'Add or confirm a preferred time first.');
        $starts = array_map(static fn(array $time): string => (string) $time['starts_at_utc'], $times);
        $ends = array_map(static fn(array $time): string => (string) ($time['occupied_ends_at_utc'] ?: $time['ends_at_utc']), $times);
        $from = min($starts); $until = max($ends);
        try {
            $availability = new TeacherAvailabilityService(); $segments = $availability->effective($teacherId, $from, $until, $courseId ? $availability->instrumentIdForCourse($courseId) : null);
        } catch (\Throwable) {
            return array('state' => 'unavailable', 'label' => 'Availability cannot be evaluated', 'covered' => 0, 'total' => count($times), 'segments' => array(), 'note' => 'The Teacher availability profile is missing or invalid. It must be corrected through its own authority.');
        }
        if (!$segments) return array('state' => 'not_set', 'label' => 'Availability not configured', 'covered' => 0, 'total' => count($times), 'segments' => array(), 'note' => 'No applicable availability rule covers this period.');
        $covered = 0;
        foreach ($times as $time) {
            $start = (string) $time['starts_at_utc'];
            $end = (string) ($time['ends_at_utc'] ?? $time['instructional_ends_at_utc'] ?? '');
            foreach ($segments as $segment) {
                if (!in_array((string) $segment['state'], array('preferred', 'requestable'), true)) continue;
                if ((string) $segment['starts_at_utc'] <= $start && (string) $segment['ends_at_utc'] >= $end) { $covered++; break; }
            }
        }
        $state = $covered === count($times) ? 'covered' : ($covered > 0 ? 'partial' : 'outside');
        $label = match ($state) {
            'covered' => 'Available for every preferred time',
            'partial' => 'Available for ' . $covered . ' of ' . count($times) . ' preferred times',
            default => 'Outside declared availability',
        };
        return array('state' => $state, 'label' => $label, 'covered' => $covered, 'total' => count($times), 'segments' => array_map(static fn(array $segment): array => array(
            'state' => (string) $segment['state'],
            'starts_at_utc' => (string) $segment['starts_at_utc'],
            'ends_at_utc' => (string) $segment['ends_at_utc'],
        ), $segments), 'note' => $state === 'outside' ? 'A manual match needs an explicit agreement and availability override in the canonical match authority.' : '');
    }

    /** @return array{rows:array<int,array<string,mixed>>,teacher_count:int,student_count:int} */
    private function conflictsFor(int $teacherId, int $studentId, array $times): array {
        $rows = array(); $teacherCount = 0; $studentCount = 0;
        $occupancy = new CanonicalTeacherOccupancyReadService();
        $reschedule = new LessonRescheduleRepository();
        foreach ($times as $time) {
            $start = (string) $time['starts_at_utc']; $end = (string) ($time['occupied_ends_at_utc'] ?: $time['ends_at_utc']);
            $teacher = array(); $student = array();
            try { $teacher = $occupancy->overlapping($teacherId, $start, $end); }
            catch (\Throwable) { $teacher = array(array('integrity' => true)); }
            if ($studentId > 0) {
                try { $student = $reschedule->studentConflict($studentId, $start, $end, 0); }
                catch (\Throwable) { $student = array(array('integrity' => true)); }
            }
            if ($teacher) { $teacherCount++; }
            if ($student) { $studentCount++; }
            if ($teacher || $student) {
                $rows[] = array(
                    'sequence' => (int) ($time['sequence'] ?? 0),
                    'label' => (string) ($time['label'] ?? ''),
                    'teacher' => (bool) $teacher,
                    'student' => (bool) $student,
                    'teacher_count' => count($teacher),
                    'student_count' => count($student),
                );
            }
        }
        return array('rows' => $rows, 'teacher_count' => $teacherCount, 'student_count' => $studentCount);
    }

    private function futureWorkload(int $teacherId): int {
        global $wpdb; $p = $this->tablePrefix(); $now = gmdate('Y-m-d H:i:s'); $until = gmdate('Y-m-d H:i:s', strtotime('+60 days'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM {$p}lessons l INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.teacher_id=%d AND l.archived_at IS NULL AND v.starts_at_utc>=%s AND v.starts_at_utc<%s",
            $teacherId, $now, $until
        ));
    }

    private function familyForCase(int $caseId): ?object {
        global $wpdb; $p = $this->tablePrefix();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_families WHERE coordination_case_id=%d", $caseId));
    }

    /** @return array<int,array<string,mixed>> */
    private function proposalOptions(int $familyId): array {
        global $wpdb; $p = $this->tablePrefix();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id,o.uid,o.candidate_id,o.teacher_id,o.current_version_id,o.version option_version,o.created_at option_created_at,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    v.id version_id,v.uid version_uid,v.version_number,v.issued_at,v.issuance_reason_code,v.prospective_subject_ref,
                    f.uid family_uid,o.uid option_uid,
                    v.delivery_mode,v.frequency_per_week,v.expected_duration_minutes,v.timezone,v.commencement_window_start,v.commencement_window_end,
                    v.arrangement_fingerprint,v.version_fingerprint,v.source_assent_id,o.current_version_id=v.id is_current
             FROM {$p}proposal_options o
             INNER JOIN {$p}proposal_families f ON f.id=o.proposal_family_id
             INNER JOIN {$p}teachers t ON t.id=o.teacher_id
             LEFT JOIN {$p}proposal_versions v ON v.id=o.current_version_id
             WHERE o.proposal_family_id=%d ORDER BY o.id ASC",
            $familyId
        )) ?: array();
        return array_map(function (object $row): array {
            return array(
                'id' => (int) $row->id,
                'uid' => (string) $row->uid,
                'candidate_id' => (int) $row->candidate_id,
                'teacher_id' => (int) $row->teacher_id,
                'teacher_name' => (string) ($row->teacher_name ?: 'Teacher'),
                'version_id' => (int) ($row->version_id ?? 0),
                'version_uid' => (string) ($row->version_uid ?? ''),
                'version_number' => (int) ($row->version_number ?? 0),
                'issued_at' => (string) ($row->issued_at ?? ''),
                'reason' => (string) ($row->issuance_reason_code ?? ''),
                'prospective_subject_ref' => (string) ($row->prospective_subject_ref ?? ''),
                'delivery_mode' => (string) ($row->delivery_mode ?? ''),
                'frequency_per_week' => (int) ($row->frequency_per_week ?? 0),
                'duration_minutes' => (int) ($row->expected_duration_minutes ?? 0),
                'timezone' => (string) ($row->timezone ?? ''),
                'window_start' => (string) ($row->commencement_window_start ?? ''),
                'window_end' => (string) ($row->commencement_window_end ?? ''),
                'fingerprint' => (string) ($row->arrangement_fingerprint ?? ''),
                'version_fingerprint' => (string) ($row->version_fingerprint ?? ''),
                'source_assent_id' => (int) ($row->source_assent_id ?? 0),
                'current' => (bool) $row->is_current,
                'label' => 'Version ' . (int) ($row->version_number ?? 0) . ' · ' . (string) ($row->teacher_name ?: 'Teacher') . (($row->issued_at ?? '') !== '' ? ' · ' . $this->local((string) $row->issued_at, (string) ($row->timezone ?? 'UTC')) : ''),
            );
        }, $rows);
    }

    private function student(int $studentId): ?object {
        global $wpdb; $p = $this->tablePrefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*,c.classification capacity_classification,c.evidence_channel capacity_evidence_channel,c.classified_at capacity_recorded_at
             FROM {$p}students s
             LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id
             WHERE s.id=%d", $studentId
        ));
    }

    /** @return array<int,array<string,mixed>> */
    private function currentPrincipals(int $studentId): array {
        global $wpdb; $p = $this->tablePrefix();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.version,l.status,l.active_slot,u.ID user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,u.user_email
             FROM {$p}student_principal_links l INNER JOIN {$wpdb->users} u ON u.ID=l.wordpress_user_id
             WHERE l.student_id=%d AND l.status='active' AND l.active_slot=1 ORDER BY l.id DESC", $studentId
        )) ?: array();
        return array_map(static fn(object $row): array => array('id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'name' => (string) $row->display_name, 'email' => (string) $row->user_email, 'version' => (int) $row->version), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function activeGuardians(int $studentId): array {
        global $wpdb; $p = $this->tablePrefix(); $now = gmdate('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.id,g.version,g.effective_from,g.effective_until,u.ID user_id,COALESCE(NULLIF(u.display_name,''),u.user_email) display_name,u.user_email
             FROM {$p}student_acceptance_authority_grants g INNER JOIN {$wpdb->users} u ON u.ID=g.acting_wordpress_user_id
             WHERE g.student_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope='service_acceptance'
               AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s)
             ORDER BY g.id DESC", $studentId, $now, $now
        )) ?: array();
        return array_map(static fn(object $row): array => array('id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'name' => (string) $row->display_name, 'email' => (string) $row->user_email, 'version' => (int) $row->version, 'effective_until' => (string) ($row->effective_until ?? '')), $rows);
    }

    private function authorityRoute(string $capacity, array $principals, array $guardians): string {
        if ($capacity === 'adult') return $principals ? 'adult_principal' : 'missing';
        if ($capacity === 'minor') return $guardians ? 'guardian' : 'missing';
        return 'missing';
    }

    private function identityState(array $base, ?object $student): string {
        if (($base['identity_state']['key'] ?? '') === 'verified' && $student) return 'resolved';
        $key = (string) ($base['identity_state']['key'] ?? 'unresolved');
        return in_array($key, array('ambiguous', 'rejected'), true) ? $key : 'unresolved';
    }

    private function requestShape(array $base, $course): array {
        $times = array_map(function (array $time): array {
            $expired = (string) $time['starts_at_utc'] <= gmdate('Y-m-d H:i:s');
            return $time + array('expired' => $expired, 'status_label' => $expired ? 'Passed' : 'Future');
        }, (array) ($base['requested_times'] ?? array()));
        return array(
            'id' => (int) $base['id'],
            'reference' => (string) $base['reference'],
            'learner_name' => (string) $base['learner_name'],
            'email' => (string) $base['email'],
            'phone' => (string) $base['phone'],
            'country' => (string) $base['country'],
            'city' => (string) $base['city'],
            'timezone' => (string) $base['timezone'],
            'instrument' => (string) $base['instrument'],
            'course' => (string) $base['course'],
            'course_id' => (int) ($course->id ?? 0),
            'course_type' => (string) ($course->course_type ?? 'introductory'),
            'lesson_kind' => ($course && (string) $course->course_type === 'introductory') || !$course ? 'Introductory lesson' : 'Regular lesson',
            'created_at' => (string) $base['created_at'],
            'age_label' => (string) ($base['age_label'] ?? ''),
            'stage_label' => (string) ($base['stage_label'] ?? ''),
            'next_action' => (string) ($base['next_action'] ?? ''),
            'times' => $times,
            'confirmed' => $base['confirmed'] ?? null,
            'post_intro' => $base['post_intro'] ?? null,
            'prior_bookings' => $base['prior_bookings'] ?? array(),
            'identity_state' => $base['identity_state'] ?? array(),
        );
    }

    private function studentShape(object $student): array {
        return array(
            'id' => (int) $student->id,
            'reference' => (string) ($student->reference_code ?? ''),
            'name' => (string) $student->display_name,
            'email' => (string) ($student->email ?? ''),
            'phone' => (string) ($student->phone ?? ''),
            'timezone' => (string) ($student->timezone ?? 'UTC'),
            'status' => (string) $student->status,
            'status_label' => $this->label((string) $student->status),
        );
    }

    private function capacityShape(?object $student, string $capacity): array {
        return array(
            'classification' => $capacity,
            'label' => $capacity === '' ? 'Not classified' : $this->label($capacity),
            'resolved' => in_array($capacity, array('adult', 'minor'), true),
            'evidence_channel' => $student ? (string) ($student->capacity_evidence_channel ?? '') : '',
            'recorded_at' => $student ? (string) ($student->capacity_recorded_at ?? '') : '',
        );
    }

    private function claimState(int $studentId): array {
        global $wpdb; $p = $this->tablePrefix();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_account_claim_invitations WHERE student_id=%d ORDER BY id DESC LIMIT 1", $studentId));
        if (!$row) return array('state' => 'none', 'label' => 'No account invitation');
        return array(
            'id' => (int) $row->id,
            'state' => (string) $row->state,
            'label' => $this->label((string) $row->state),
            'claimed_at' => (string) ($row->claimed_at ?? ''),
            'updated_at' => (string) $row->updated_at,
        );
    }

    private function introPayment(int $requestId): array {
        global $wpdb; $p = $this->tablePrefix();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}intro_payment_obligations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId
        ));
        if (!$row) return array('state' => 'none', 'label' => 'No introductory payment obligation', 'uid' => '', 'amount' => '', 'currency' => '', 'paid_at' => '', 'failure' => '');
        return array(
            'id' => (int) $row->id,
            'uid' => (string) $row->uid,
            'reference' => (string) $row->reference_code,
            'state' => (string) $row->state,
            'label' => $this->paymentLabel((string) $row->state),
            'amount_minor' => (int) $row->amount_minor,
            'amount' => number_format(((int) $row->amount_minor) / 100, 2) . ' ' . (string) $row->currency,
            'currency' => (string) $row->currency,
            'paid_at' => (string) ($row->paid_at ?? ''),
            'failure' => (string) ($row->failure_reason_code ?? ''),
        );
    }

    private function retryableNotifications(int $requestId): array {
        global $wpdb; $p = $this->tablePrefix();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,state,intent_key,audience,created_at,updated_at,delivered_at,failure_reason_code,expires_at
             FROM {$p}notifications WHERE subject_aggregate='booking_request' AND subject_aggregate_id=%d
             ORDER BY id DESC LIMIT 40", $requestId
        )) ?: array();
        $items = array_map(function (object $row): array {
            $state = (string) $row->state;
            $eligible = in_array($state, array('failed', 'expired'), true);
            return array(
                'id' => (int) $row->id,
                'state' => $state,
                'label' => $this->notificationLabel($state),
                'event' => $this->label((string) $row->intent_key),
                'audience' => $this->label((string) $row->audience),
                'updated_at' => (string) $row->updated_at,
                'delivered_at' => (string) ($row->delivered_at ?? ''),
                'failure' => (string) ($row->failure_reason_code ?? ''),
                'retryable' => $eligible,
            );
        }, $rows);
        // The booking notification queue is operational evidence too, even where its rows are
        // not individually retryable through the canonical notification command table.
        try {
            $status = (new NotificationDeliveryStatusReadService())->forBookingRequest($requestId);
            foreach ((array) ($status['rows'] ?? array()) as $row) {
                $category = (string) ($row['category'] ?? 'unknown');
                $items[] = array(
                    'id' => 0,
                    'state' => $category,
                    'label' => $this->notificationCategoryLabel($category),
                    'event' => $this->label((string) ($row['event_key'] ?? '')),
                    'audience' => $this->label((string) ($row['audience'] ?? '')),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'delivered_at' => (string) ($row['delivery_confirmed_at'] ?? ''),
                    'failure' => (string) ($row['last_error_code'] ?? ''),
                    'retryable' => false,
                );
            }
        } catch (\Throwable) {
            // The canonical retry list remains available if the legacy queue projection fails.
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        $failed = count(array_filter($items, static fn(array $item): bool => $item['retryable'] || in_array((string) $item['state'], array('failed', 'expired'), true)));
        return array('rows' => $items, 'failed_count' => $failed);
    }

    /** @return array<int,array<string,mixed>> */
    private function operationalExceptions(int $requestId, int $confirmationId): array {
        global $wpdb; $p = $this->tablePrefix();
        $where = "entity_type='booking_request' AND entity_id=%d AND status IN ('open','acknowledged')";
        $args = array($requestId);
        if ($confirmationId > 0) { $where .= " OR (entity_type='booking_request_confirmation' AND entity_id=%d AND status IN ('open','acknowledged'))"; $args[] = $confirmationId; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,exception_type,severity,status,summary,safe_detail,error_code,detected_at,last_seen_at,retry_available
             FROM {$p}operational_exceptions WHERE {$where} ORDER BY last_seen_at DESC LIMIT 20", ...$args
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'id' => (int) $row->id,
            'type' => (string) $row->exception_type,
            'type_label' => ucfirst(str_replace('_', ' ', (string) $row->exception_type)),
            'severity' => (string) $row->severity,
            'status' => (string) $row->status,
            'summary' => (string) $row->summary,
            'detail' => (string) ($row->safe_detail ?? ''),
            'error_code' => (string) ($row->error_code ?? ''),
            'detected_at' => (string) $row->detected_at,
            'last_seen_at' => (string) $row->last_seen_at,
            'retry_available' => (bool) $row->retry_available,
        ), $rows);
    }

    private function conversionState(?object $arrangement): string {
        if (!$arrangement) return 'not_applicable';
        if (!$this->can('dzn_convert_service_arrangements_to_enrolments')) return 'not_checked';
        try {
            $state = (new EnrolmentConversionReadinessService())->assess((int) $arrangement->id);
            return $state === 'already_converted' ? 'converted' : $state;
        } catch (\Throwable) {
            return 'not_checked';
        }
    }

    private function enrolmentForArrangement(int $arrangementId): ?object {
        global $wpdb; $p = $this->tablePrefix();
        return $wpdb->get_row($wpdb->prepare("SELECT id,reference_code,lifecycle_state,status,student_id,teacher_id,course_id,created_at FROM {$p}enrolments WHERE accepted_service_arrangement_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT 1", $arrangementId));
    }

    private function currentAssignment(int $enrolmentId): ?object {
        global $wpdb; $p = $this->tablePrefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT a.id,a.state,a.assignment_sequence,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$p}teacher_assignments a LEFT JOIN {$p}teachers t ON t.id=a.teacher_id
             WHERE a.enrolment_id=%d AND a.applicable_slot=1 LIMIT 1", $enrolmentId
        ));
    }

    private function acceptanceShape(object $event): array {
        return array(
            'id' => (int) $event->id,
            'reference' => (string) ($event->reference_code ?? ''),
            'uid' => (string) $event->uid,
            'family_uid' => (string) $event->family_uid,
            'option_uid' => (string) $event->option_uid,
            'version_number' => (int) $event->version_number,
            'version_id' => (int) $event->proposal_version_id,
            'event_kind' => (string) $event->event_kind,
            'channel' => (string) $event->evidence_channel,
            'channel_label' => $this->label((string) $event->evidence_channel),
            'evidence_at' => (string) $event->evidence_at,
            'recorded_at' => (string) $event->recorded_at,
            'recorded_by_name' => $this->actorName((int) $event->recorded_by),
        );
    }

    private function arrangementShape(object $arrangement): array {
        return array(
            'id' => (int) $arrangement->id,
            'reference' => (string) $arrangement->reference_code,
            'teacher_id' => (int) $arrangement->teacher_id,
            'teacher_name' => $this->teacherName((int) $arrangement->teacher_id),
            'version_number' => (int) $arrangement->version_number,
            'authority_route' => (string) $arrangement->authority_route,
            'authority_label' => $this->label((string) $arrangement->authority_route),
            'capacity' => (string) $arrangement->capacity_classification,
            'confirmation_channel' => (string) $arrangement->confirmation_channel,
            'accepted_at' => (string) $arrangement->accepted_at,
            'confirmed_at' => (string) $arrangement->confirmed_at,
        );
    }

    private function scheduleShape(array $base, array $candidates): array {
        $confirmed = $base['confirmed'] ?? null;
        return array(
            'requested' => $base['requested_times'] ?? array(),
            'confirmed' => $confirmed,
            'calendar_state' => (string) ($confirmed['state'] ?? 'none'),
            'calendar_label' => $confirmed ? $this->label((string) $confirmed['state']) : 'No confirmed booking',
            'provider_code' => (string) ($confirmed['provider_code'] ?? ''),
            'failure' => (string) ($confirmed['failure_reason_code'] ?? ''),
            'candidate_availability' => array_map(static fn(array $candidate): array => array(
                'name' => $candidate['teacher_name'],
                'label' => $candidate['availability']['label'],
                'conflicts' => $candidate['conflicts'],
            ), $candidates),
        );
    }

    private function auditEvents(object $case, array $candidates, ?object $arrangement, int $requestId): array {
        global $wpdb; $p = $this->tablePrefix();
        $aggregates = array(
            'coordination_case' => array((int) $case->id),
            'coordination_case_candidate' => array_map(static fn(array $candidate): int => (int) $candidate['id'], $candidates),
            'booking_request' => array($requestId),
        );
        if ($arrangement) $aggregates['accepted_service_arrangement'][] = (int) $arrangement->id;
        foreach ($candidates as $candidate) {
            if (!empty($candidate['assent']['id'])) $aggregates['teacher_availability_assent'][] = (int) $candidate['assent']['id'];
            if (!empty($candidate['option']['version_id'])) $aggregates['proposal_version'][] = (int) $candidate['option']['version_id'];
        }
        $clauses = array(); $args = array();
        foreach ($aggregates as $type => $ids) {
            if (!$ids) continue;
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) continue;
            $clauses[] = '(e.aggregate_type=%s AND e.aggregate_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . '))';
            $args[] = $type;
            foreach ($ids as $id) $args[] = $id;
        }
        if (!$clauses) return array();
        $sql = "SELECT e.id,e.aggregate_type,e.aggregate_id,e.event_type,e.actor_type,e.actor_id,e.reason_code,e.safe_detail,e.occurred_at,
                       COALESCE(NULLIF(u.display_name,''),u.user_email,NULL) actor_name
                FROM {$p}platform_audit_events e LEFT JOIN {$wpdb->users} u ON u.ID=e.actor_id
                WHERE " . implode(' OR ', $clauses) . " ORDER BY e.occurred_at DESC,e.id DESC LIMIT 120";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: array();
        return array_map(function (object $row): array {
            return array(
                'id' => (int) $row->id,
                'type' => (string) $row->aggregate_type,
                'type_label' => $this->label((string) $row->aggregate_type),
                'event' => (string) $row->event_type,
                'event_label' => $this->eventLabel((string) $row->event_type),
                'actor' => (string) ($row->actor_name ?: 'System'),
                'reason' => $this->label((string) ($row->reason_code ?? '')),
                'detail' => (string) ($row->safe_detail ?? ''),
                'occurred_at' => (string) $row->occurred_at,
                'occurred_label' => $this->local((string) $row->occurred_at, 'UTC'),
            );
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function assessmentFacts(object $case, array $base, string $identity, ?object $student, string $capacity, string $authority, array $candidates, array $options, array $acceptances, ?object $arrangement, ?object $enrolment, ?object $assignment, array $claim, array $payment, array $notifications, array $exceptions, string $conversion): array {
        $viable = count(array_filter($candidates, static fn(array $candidate): bool => !empty($candidate['viable'])));
        $currentAssent = (bool) array_filter($candidates, static fn(array $candidate): bool => !empty($candidate['assent']));
        $currentProposal = (bool) array_filter($options, static fn(array $option): bool => !empty($option['version_id']) && !empty($option['current']));
        $provisional = (bool) $acceptances;
        $times = (array) ($base['requested_times'] ?? array());
        $expired = false;
        foreach ($times as $time) if ((string) $time['starts_at_utc'] <= gmdate('Y-m-d H:i:s')) { $expired = true; break; }
        return array(
            'case_state' => (string) $case->state,
            'case_closed' => in_array((string) $case->state, array('withdrawn', 'declined', 'unable_to_arrange', 'abandoned'), true),
            'privacy_erased' => !empty($base['privacy_erased_at']),
            'identity' => $identity,
            'student_status' => $student ? (string) $student->status : '',
            'capacity' => $capacity,
            'authority' => $authority,
            'candidate_count' => count($candidates),
            'viable_candidate_count' => $viable,
            'current_assent' => $currentAssent,
            'current_proposal' => $currentProposal,
            'provisional_current' => $provisional,
            'arrangement' => (bool) $arrangement,
            'conversion_state' => $conversion,
            'assignment' => (bool) $assignment,
            'claim' => (string) ($claim['state'] ?? 'none'),
            'payment' => (string) ($payment['state'] ?? 'none'),
            'notification_failures' => (int) ($notifications['failed_count'] ?? 0),
            'calendar_projection' => (string) ($base['confirmed']['state'] ?? 'none'),
            'operational_exceptions' => count($exceptions),
            'has_requested_times' => (bool) $times,
            'requested_time_expired' => $expired,
        );
    }

    private function composeRow(object $row): array {
        $closed = in_array((string) $row->state, array('withdrawn', 'declined', 'unable_to_arrange', 'abandoned'), true);
        return array(
            'id' => (int) $row->case_id,
            'reference' => (string) $row->case_reference,
            'request_id' => (int) $row->request_id,
            'request_reference' => (string) ($row->request_reference ?: 'Request ' . (int) $row->request_id),
            'learner' => (string) ($row->student_name ?: $row->contact_name ?: 'Learner'),
            'contact' => (string) ($row->contact_email ?? ''),
            'instrument' => (string) ($row->instrument_name ?: 'Instrument'),
            'course' => (string) ($row->course_name ?: 'Course'),
            'lesson_kind' => (string) ($row->course_type ?? '') === 'introductory' || !$row->course_type ? 'Introductory' : 'Regular',
            'state' => (string) $row->state,
            'state_label' => $this->stateLabel((string) $row->state),
            'closed' => $closed,
            'privacy_erased' => !empty($row->privacy_erased_at),
            'identity' => (int) ($row->student_id ?? 0) > 0 ? 'resolved' : 'unresolved',
            'identity_label' => (int) ($row->student_id ?? 0) > 0 ? 'Student verified' : 'Identity unresolved',
            'active_candidates' => (int) $row->active_candidates,
            'current_assents' => (int) $row->current_assents,
            'current_proposals' => (int) $row->current_proposals,
            'acceptance_events' => (int) $row->acceptance_events,
            'arrangements' => (int) $row->arrangements,
            'open_exceptions' => (int) $row->open_exceptions,
            'updated_at' => (string) $row->updated_at,
            'updated_label' => $this->local((string) $row->updated_at, 'UTC'),
            'version' => (int) $row->version,
        );
    }

    /** @return array<string,bool> */
    public function permissions(): array {
        return array(
            'coordination' => $this->can(self::ACCESS_CAPABILITY),
            'identity_resolve' => $this->can('dzn_resolve_booking_request_student_identity') && $this->can('dzn_view_student_acceptance_eligibility'),
            'authority_read' => $this->can('dzn_view_student_acceptance_eligibility'),
            'authority_manage' => $this->can('dzn_manage_student_acceptance_authority'),
            'assent' => $this->can('dzn_manage_teacher_availability_assent'),
            'proposal' => $this->can('dzn_issue_booking_request_proposals'),
            'provisional' => $this->can('dzn_record_booking_request_provisional_acceptance'),
            'final_acceptance' => $this->can('dzn_finalize_service_arrangements'),
            'conversion' => $this->can('dzn_convert_service_arrangements_to_enrolments'),
            'assignment' => $this->can('dzn_manage_teacher_assignments'),
            'claim' => $this->can('dzn_issue_student_account_claims'),
            'notification_retry' => $this->can('dzn_operate_notification_dispatch'),
            'payment' => $this->can('dzn_manage_payment_execution'),
            'exceptions' => $this->can('dzn_manage_exceptions'),
            'diagnostics' => $this->can('dzn_view_diagnostics') || $this->can('dzn_view_provider_integrations'),
            'eligibility' => $this->can('dzn_manage_teaching_eligibility'),
        );
    }

    private function authorize(): void {
        if (!$this->can(self::ACCESS_CAPABILITY)) throw new \RuntimeException('Unauthorized');
    }

    private function can(string $capability): bool { return function_exists('current_user_can') && current_user_can($capability); }

    private function tablePrefix(): string {
        if ($this->p !== null) return $this->p;
        global $wpdb;
        return $this->p = $wpdb->prefix . 'dzn_';
    }

    private function teacherName(int $teacherId): string {
        global $wpdb; $p = $this->tablePrefix();
        return (string) ($wpdb->get_var($wpdb->prepare("SELECT COALESCE(NULLIF(display_name,''),NULLIF(english_name,''),persian_name,'Teacher') FROM {$p}teachers WHERE id=%d", $teacherId)) ?: 'Teacher');
    }

    private function actorName(int $actorId): string {
        if ($actorId < 1) return 'System';
        $user = get_userdata($actorId);
        return $user ? (string) ($user->display_name ?: $user->user_email) : 'Staff member';
    }

    private function local(string $utc, string $timezone): string {
        if ($utc === '' || str_starts_with($utc, '0000')) return '—';
        try {
            $zone = new \DateTimeZone($timezone ?: 'UTC');
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $date->setTimezone($zone)->format('j M Y, H:i') . ' ' . $zone->getName();
        } catch (\Throwable) {
            return $utc . ' UTC';
        }
    }

    private function stateLabel(string $state): string {
        return match ($state) {
            'open' => 'Open',
            'candidate_search' => 'Searching for a Teacher',
            'manual_search' => 'Manual Teacher search',
            'waiting_for_availability' => 'Waiting for availability',
            'withdrawn' => 'Withdrawn',
            'declined' => 'Declined',
            'unable_to_arrange' => 'Unable to arrange',
            'abandoned' => 'Abandoned',
            'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived',
            'assigned' => 'Assigned', 'replaced' => 'Replaced', 'ended' => 'Ended', 'cancelled' => 'Cancelled',
            'canonical' => 'Enrolled', 'authorised' => 'Authorised',
            default => $state === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private function paymentLabel(string $state): string {
        return match ($state) {
            'pending' => 'Payment pending',
            'paid' => 'Payment verified',
            'failed' => 'Payment failed',
            'expired' => 'Payment window expired',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled',
            default => 'No payment obligation',
        };
    }

    private function notificationLabel(string $state): string {
        return match ($state) {
            'pending', 'scheduled', 'queued', 'dispatching' => 'Queued',
            'dispatched' => 'Sent to transport (not receipt)',
            'delivered' => 'Delivered — confirmed by provider',
            'failed' => 'Failed',
            'expired' => 'Expired',
            'suppressed' => 'Suppressed',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private function notificationCategoryLabel(string $category): string {
        return match ($category) {
            'queued' => 'Queued',
            'handed_off' => 'Sent to transport (not receipt)',
            'delivery_confirmed' => 'Delivered — confirmed by provider',
            'portal_recorded' => 'Portal notification recorded',
            'failed' => 'Failed',
            'closed' => 'Closed',
            default => 'Recorded',
        };
    }

    /** @param array<int,array<string,mixed>> $activity @param array<int,object> $acceptances */
    private function lifecycleActivity(array $activity, array $acceptances, ?object $arrangement, ?object $enrolment): array {
        foreach ($acceptances as $event) {
            $activity[] = array(
                'id' => 0,
                'type' => 'proposal_acceptance',
                'type_label' => 'Provisional acceptance',
                'event' => 'accepted_pending_conditions',
                'event_label' => 'Learner/guardian response recorded',
                'actor' => $this->actorName((int) $event->recorded_by),
                'reason' => $this->label((string) $event->evidence_channel),
                'detail' => 'Proposal Version ' . (int) $event->version_number,
                'occurred_at' => (string) $event->recorded_at,
                'occurred_label' => $this->local((string) $event->recorded_at, 'UTC'),
            );
        }
        if ($arrangement) {
            $activity[] = array(
                'id' => 0,
                'type' => 'accepted_service_arrangement',
                'type_label' => 'Final arrangement',
                'event' => 'accepted_service_arrangement.created',
                'event_label' => 'Final arrangement created — ' . (string) $arrangement->reference_code,
                'actor' => $this->actorName((int) $arrangement->accepted_by),
                'reason' => $this->label((string) $arrangement->authority_route),
                'detail' => 'Proposal Version ' . (int) $arrangement->version_number,
                'occurred_at' => (string) $arrangement->accepted_at,
                'occurred_label' => $this->local((string) $arrangement->accepted_at, 'UTC'),
            );
        }
        if ($enrolment) {
            $activity[] = array(
                'id' => 0,
                'type' => 'enrolment',
                'type_label' => 'Enrolment',
                'event' => 'enrolment.created',
                'event_label' => 'Enrolment created — ' . (string) $enrolment->reference_code,
                'actor' => 'Conversion authority',
                'reason' => $this->label((string) $enrolment->lifecycle_state),
                'detail' => 'Canonical Student/Course Enrolment',
                'occurred_at' => (string) $enrolment->created_at,
                'occurred_label' => $this->local((string) $enrolment->created_at, 'UTC'),
            );
        }
        usort($activity, static fn(array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
        return $activity;
    }

    private function eventLabel(string $event): string {
        $event = str_replace(array('.', '_'), ' ', $event);
        return ucfirst(trim($event));
    }

    private function label(string $value): string {
        return $value === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $value));
    }
}
